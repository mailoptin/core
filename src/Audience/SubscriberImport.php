<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\Connections\AbstractConnect;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use MailOptin\MailOptinConnect\Connect;

/**
 * CSV import of subscribers, in batches so a large file never times out a request. Each import is
 * a job kept in an option: the uploaded file, how its columns map, the choices made, progress and
 * the report. The file is deleted when the import ends; the report is kept for REPORT_DAYS.
 *
 * Addresses that unsubscribed, bounced or complained are never added back. Existing subscribers
 * are matched by email and updated.
 */
class SubscriberImport
{
    const OPTION_PREFIX = 'mo_subscriber_import_';
    const INDEX_OPTION = 'mo_subscriber_imports';
    const CRON_HOOK = 'mo_subscriber_import_run';

    const STATUS_MAPPING = 'mapping';
    const STATUS_RUNNING = 'running';
    const STATUS_DONE = 'done';
    const STATUS_CANCELLED = 'cancelled';

    const PERMISSION_SUBSCRIBED = 'subscribed';
    const PERMISSION_CONFIRM = 'confirm';

    const EXISTING_FILL = 'fill';
    const EXISTING_REPLACE = 'replace';

    const MAX_BYTES = 20 * MB_IN_BYTES;
    const REPORT_DAYS = 30;
    // Problems shown on the report page; all of them are in the downloadable file.
    const SHOWN_PROBLEMS = 100;
    // Rows between saves of an import's progress.
    const CHECKPOINT = 25;

    const PROBLEM_SUPPRESSED = 'suppressed';
    const PROBLEM_INVALID = 'invalid';
    const PROBLEM_REPEATED = 'repeated';
    const PROBLEM_NOTE = 'note';

    public function __construct()
    {
        add_action(self::CRON_HOOK, [__CLASS__, 'run_from_cron']);
        add_action('mo_hourly_recurring_job', [__CLASS__, 'watchdog']);
        add_action('mo_daily_recurring_job', [__CLASS__, 'delete_old']);
    }

    /* ---------------------------------------------------------------------------------------------
     * Jobs
     * ------------------------------------------------------------------------------------------ */

    public static function get($id)
    {
        if ( ! is_string($id) || ! preg_match('/^[a-z0-9]{12}$/', $id)) return null;

        $job = get_option(self::OPTION_PREFIX . $id);

        return is_array($job) ? $job : null;
    }

    protected static function save($job)
    {
        update_option(self::OPTION_PREFIX . $job['id'], $job, false);
    }

    /**
     * @return array jobs, newest first
     */
    public static function all()
    {
        $jobs = array_filter(array_map([__CLASS__, 'get'], (array)get_option(self::INDEX_OPTION, [])));

        usort($jobs, function ($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });

        return $jobs;
    }

    public static function directory()
    {
        $dir = trailingslashit(wp_upload_dir()['basedir']) . 'mailoptin-imports';

        if ( ! is_dir($dir)) wp_mkdir_p($dir);

        // The files hold personal data: never serve them. On servers that ignore these files (nginx),
        // file names are long random secrets that appear nowhere else.
        $guards = [
            'index.php'  => "<?php\n// Silence is golden.\n",
            '.htaccess'  => "Require all denied\nDeny from all\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];

        foreach ($guards as $file => $content) {
            if ( ! is_file($dir . '/' . $file)) @file_put_contents($dir . '/' . $file, $content);
        }

        return $dir;
    }

    /**
     * @return string a path in the imports directory with a name nobody can guess
     */
    public static function secret_path($suffix = '')
    {
        return self::directory() . '/' . wp_generate_password(40, false) . $suffix . '.csv';
    }

    /**
     * Start an import from an uploaded file.
     *
     * @param array $file an entry of $_FILES
     *
     * @return array|\WP_Error the job
     */
    public static function create_from_upload($file)
    {
        if ( ! is_array($file) || ! empty($file['error']) || empty($file['tmp_name']) || ! is_uploaded_file($file['tmp_name'])) {
            return new \WP_Error('mo_import_upload', __('The file could not be uploaded. Try again, or check the file isn\'t larger than your server allows.', 'mailoptin'));
        }

        $name = sanitize_file_name(wp_unslash($file['name'] ?? 'import.csv'));

        if ( ! preg_match('/\.(csv|txt)$/i', $name)) {
            return new \WP_Error('mo_import_type', __('Choose a .csv file. In Excel or Numbers, use File › Save As (or Export) › CSV.', 'mailoptin'));
        }

        $path = self::secret_path();

        if ( ! @move_uploaded_file($file['tmp_name'], $path)) {
            return new \WP_Error('mo_import_upload', __('The file could not be saved. Check that the uploads folder is writable.', 'mailoptin'));
        }

        return self::create_from_file($path, $name);
    }

    /**
     * Rewrite the file as UTF-8 with \n line endings. Excel saves "CSV" as Windows-1252, "Unicode
     * Text" as UTF-16, and older Mac versions end lines with \r only.
     *
     * @return bool false if it couldn't be read or written
     */
    public static function normalize_file($path)
    {
        $content = file_get_contents($path);

        if (false === $content) return false;

        $original = $content;

        if (0 === strpos($content, "\xFF\xFE") || 0 === strpos($content, "\xFE\xFF")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 0 === strpos($content, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        } else {
            if (0 === strpos($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
            if ( ! mb_check_encoding($content, 'UTF-8')) $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        if (false === strpos($content, "\n") && false !== strpos($content, "\r")) $content = str_replace("\r", "\n", $content);

        return $content === $original || false !== file_put_contents($path, $content);
    }

    /**
     * @param string $path a file in the imports directory, which the job then owns
     * @param string $name the file name to show
     *
     * @return array|\WP_Error the job
     */
    public static function create_from_file($path, $name)
    {
        $id = self::new_id();

        if (filesize($path) > self::MAX_BYTES) {
            @unlink($path);

            return new \WP_Error('mo_import_size', sprintf(__('The file is larger than %s. Split it into smaller files and import them one after another.', 'mailoptin'), size_format(self::MAX_BYTES)));
        }

        $handle = self::normalize_file($path) ? fopen($path, 'r') : false;

        if ( ! $handle) return new \WP_Error('mo_import_read', __('The file could not be read.', 'mailoptin'));

        $lines = [];
        while (count($lines) < 5 && false !== ($line = fgets($handle))) $lines[] = $line;
        $delimiter = self::detect_delimiter(implode('', $lines));
        rewind($handle);

        $headers = fgetcsv($handle, 0, $delimiter, '"', '');
        $offset  = ftell($handle);
        $sample  = fgetcsv($handle, 0, $delimiter, '"', '');

        $total = $sample ? 1 : 0;
        while (false !== fgetcsv($handle, 0, $delimiter, '"', '')) {
            $total++;
        }
        fclose($handle);

        $headers = array_map([__CLASS__, 'clean_cell'], (array)$headers);

        if (empty(array_filter($headers, 'strlen')) || 0 === $total) {
            @unlink($path);

            return new \WP_Error('mo_import_empty', __('The file has no rows to import. The first row should be column names, like Email and First name.', 'mailoptin'));
        }

        $job = [
            'id'            => $id,
            'user_id'       => get_current_user_id(),
            'name'          => $name,
            'file'          => $path,
            'problems_file' => '',
            'delimiter'     => $delimiter,
            'headers'       => $headers,
            'sample'        => array_map([__CLASS__, 'clean_cell'], (array)$sample),
            'total'         => $total,
            'mapping'       => self::guess_mapping($headers),
            'lists'         => [],
            'tag_all'       => '',
            'permission'    => '',
            'existing'      => self::EXISTING_FILL,
            'status'        => self::STATUS_MAPPING,
            'offset'        => $offset,
            'row'           => 0,
            'counts'        => array_fill_keys(['added', 'updated', self::PROBLEM_SUPPRESSED, self::PROBLEM_INVALID, self::PROBLEM_REPEATED, self::PROBLEM_NOTE], 0),
            'problems'      => [],
            'log_id'        => 0,
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'started_at'    => null,
            'finished_at'   => null,
        ];

        self::save($job);

        $index   = (array)get_option(self::INDEX_OPTION, []);
        $index[] = $id;
        update_option(self::INDEX_OPTION, array_values(array_unique($index)), false);

        return $job;
    }

    protected static function new_id()
    {
        return strtolower(wp_generate_password(12, false));
    }

    /**
     * The delimiter that splits the first lines into the same, largest number of columns. Quoted
     * cells like "Name, full" don't count.
     */
    public static function detect_delimiter($sample)
    {
        $lines = array_slice(array_filter(preg_split('/\r\n|\n|\r/', (string)$sample), 'strlen'), 0, 5);
        $best  = ',';
        $score = 0;

        foreach ([',', ';', "\t", '|'] as $delimiter) {
            $counts = array_map(function ($line) use ($delimiter) {
                return count(str_getcsv($line, $delimiter, '"', ''));
            }, $lines);

            if (empty($counts) || min($counts) < 2) continue;

            // Consistent rows beat a single wide one.
            $this_score = min($counts) * (count(array_unique($counts)) === 1 ? 2 : 1);

            if ($this_score > $score) {
                $best  = $delimiter;
                $score = $this_score;
            }
        }

        return $best;
    }

    /* ---------------------------------------------------------------------------------------------
     * Column mapping
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return array target => label: email, first_name, last_name, full_name, tags and field:<id>
     */
    public static function targets()
    {
        $targets = [
            'email'      => __('Email', 'mailoptin'),
            'first_name' => __('First name', 'mailoptin'),
            'last_name'  => __('Last name', 'mailoptin'),
            'full_name'  => __('Full name (split into first and last)', 'mailoptin'),
            'tags'       => __('Tags (comma separated)', 'mailoptin'),
        ];

        if (self::can_import_fields()) {
            foreach (Fields::all() as $field) {
                $targets['field:' . $field['id']] = sprintf(__('%s (custom field)', 'mailoptin'), $field['label']);
            }
        }

        return $targets;
    }

    /**
     * Importing into custom fields is premium, like saving form fields into them.
     */
    public static function can_import_fields()
    {
        return Connect::has_custom_form_fields() && Tags::is_ready();
    }

    protected static function header_key($header)
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(remove_accents((string)$header)));
    }

    /**
     * @return array column index => target, from the column names
     */
    public static function guess_mapping($headers)
    {
        $aliases = [
            'email'      => ['email', 'emailaddress', 'mail', 'emailid', 'subscriberemail', 'useremail'],
            'first_name' => ['firstname', 'fname', 'givenname', 'first', 'forename'],
            'last_name'  => ['lastname', 'lname', 'surname', 'familyname', 'last'],
            'full_name'  => ['name', 'fullname', 'displayname', 'subscribername'],
            'tags'       => ['tags', 'tag', 'labels'],
        ];

        if (self::can_import_fields()) {
            foreach (Fields::all() as $field) {
                $aliases['field:' . $field['id']] = array_unique([self::header_key($field['label']), self::header_key($field['merge_key'])]);
            }
        }

        $mapping = [];
        $used    = [];

        foreach ($headers as $index => $header) {
            $key = self::header_key($header);

            foreach ($aliases as $target => $names) {
                if ( ! isset($used[$target]) && in_array($key, $names, true)) {
                    $mapping[$index] = $target;
                    $used[$target]   = true;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Save the choices from the matching step and start the import.
     *
     * @param array $choices mapping (column index => target), lists, tag_all, permission, existing
     *
     * @return true|\WP_Error
     */
    public static function start($id, $choices)
    {
        $job = self::get($id);

        if ( ! $job || self::STATUS_MAPPING !== $job['status']) return new \WP_Error('mo_import_gone', __('This import can no longer be changed.', 'mailoptin'));

        $targets = self::targets();
        $mapping = [];

        foreach ((array)($choices['mapping'] ?? []) as $index => $target) {
            $target = is_scalar($target) ? (string)$target : '';
            $index  = absint($index);

            // Each target once; the first column that claims it wins.
            if (isset($targets[$target]) && isset($job['headers'][$index]) && ! in_array($target, $mapping, true)) {
                $mapping[$index] = $target;
            }
        }

        $job['mapping'] = $mapping;
        $job['lists']   = array_values(array_filter(array_map('absint', array_filter((array)($choices['lists'] ?? []), 'is_scalar')), function ($list_id) {
            return (bool)ListsRepository::get($list_id);
        }));
        $tag_all           = $choices['tag_all'] ?? '';
        $job['tag_all']    = Tags::sanitize_name(is_scalar($tag_all) ? (string)$tag_all : '');
        $job['permission'] = in_array($choices['permission'] ?? '', [self::PERMISSION_SUBSCRIBED, self::PERMISSION_CONFIRM], true) ? $choices['permission'] : '';
        $job['existing']   = self::EXISTING_REPLACE === ($choices['existing'] ?? '') ? self::EXISTING_REPLACE : self::EXISTING_FILL;

        $error = null;

        if ( ! in_array('email', $mapping, true)) {
            $error = new \WP_Error('mo_import_no_email', __('Choose which column holds the email address.', 'mailoptin'));
        } elseif (empty($job['lists'])) {
            $error = new \WP_Error('mo_import_no_list', __('Choose at least one list to add people to.', 'mailoptin'));
        } elseif ('' === $job['permission']) {
            $error = new \WP_Error('mo_import_no_permission', __('Choose whether these people already agreed to hear from you, or should get a confirmation email.', 'mailoptin'));
        }

        if ($error) {
            // Keep what was chosen so the form shows it again.
            self::save($job);

            return $error;
        }

        if (self::PERMISSION_CONFIRM === $job['permission']) {
            $job['log_id'] = ImportConfirmations::create_log($job['name']);
        }

        $job['status']     = self::STATUS_RUNNING;
        $job['started_at'] = gmdate('Y-m-d H:i:s');
        self::save($job);

        self::schedule($id);

        return true;
    }

    public static function cancel($id)
    {
        $job = self::get($id);

        if ( ! $job || ! in_array($job['status'], [self::STATUS_MAPPING, self::STATUS_RUNNING], true)) return false;

        $job['status']      = self::STATUS_CANCELLED;
        $job['finished_at'] = gmdate('Y-m-d H:i:s');
        self::finish_files($job);
        self::save($job);

        return true;
    }

    public static function delete($id)
    {
        $job = self::get($id);

        if ( ! $job) return false;

        self::finish_files($job);
        if (self::problems_path($job)) @unlink(self::problems_path($job));
        delete_option(self::OPTION_PREFIX . $id);
        update_option(self::INDEX_OPTION, array_values(array_diff((array)get_option(self::INDEX_OPTION, []), [$id])), false);
        wp_clear_scheduled_hook(self::CRON_HOOK, [$id]);

        return true;
    }

    /**
     * Delete reports older than REPORT_DAYS, and imports abandoned at the matching step for a day.
     */
    public static function delete_old()
    {
        $report_before  = gmdate('Y-m-d H:i:s', time() - self::REPORT_DAYS * DAY_IN_SECONDS);
        $abandon_before = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        foreach (self::all() as $job) {
            $old = self::STATUS_MAPPING === $job['status'] ? $job['created_at'] < $abandon_before : $job['created_at'] < $report_before;
            if ($old) self::delete($job['id']);
        }
    }

    protected static function finish_files($job)
    {
        if ( ! empty($job['file']) && is_file($job['file'])) @unlink($job['file']);
    }

    public static function problems_path($job)
    {
        return ! empty($job['problems_file']) ? $job['problems_file'] : '';
    }

    /**
     * The job as stored now, not as cached earlier in this request.
     */
    protected static function fresh($id)
    {
        wp_cache_delete(self::OPTION_PREFIX . $id, 'options');
        wp_cache_delete('notoptions', 'options');

        return self::get($id);
    }

    /* ---------------------------------------------------------------------------------------------
     * Running
     * ------------------------------------------------------------------------------------------ */

    protected static function schedule($id, $delay = 0)
    {
        $next = wp_next_scheduled(self::CRON_HOOK, [$id]);

        if ($next && $next <= time() + $delay) return;
        if ($next) wp_unschedule_event($next, self::CRON_HOOK, [$id]);

        wp_schedule_single_event(time() + $delay, self::CRON_HOOK, [$id]);
    }

    public static function run_from_cron($id)
    {
        // Scheduled first, so the import carries on even if this request dies.
        self::schedule($id, MINUTE_IN_SECONDS);

        $job = self::run_batch($id);

        if ($job && self::STATUS_RUNNING === $job['status']) {
            self::schedule($id, 5);
        } else {
            wp_clear_scheduled_hook(self::CRON_HOOK, [$id]);
        }
    }

    /**
     * Restart imports whose next batch was never scheduled, for example after a lost cron event.
     */
    public static function watchdog()
    {
        foreach (self::all() as $job) {
            if (self::STATUS_RUNNING === $job['status'] && ! wp_next_scheduled(self::CRON_HOOK, [$job['id']])) self::schedule($job['id']);
        }
    }

    /**
     * Only one request imports a job's rows at a time. INSERT IGNORE succeeds for exactly one
     * caller; a lock left by a request that died is taken over by exactly one caller once it expires.
     *
     * @return string|false a token for unlock(), or false if another request holds the lock
     */
    protected static function lock($id, $lease = 120)
    {
        global $wpdb;

        $name  = self::OPTION_PREFIX . $id . '_lock';
        $token = wp_generate_password(20, false);
        $value = (time() + $lease) . '|' . $token;

        $got = 1 === (int)$wpdb->query($wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value));

        if ( ! $got) {
            $got = 1 === (int)$wpdb->query(
                    $wpdb->prepare(
                        "UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d",
                        $value,
                        $name,
                        time()
                    )
                );
        }

        wp_cache_delete($name, 'options');

        return $got ? $token : false;
    }

    protected static function unlock($id, $token)
    {
        global $wpdb;

        $name = self::OPTION_PREFIX . $id . '_lock';

        // Only our own lock: if it expired and another request took it, it's theirs.
        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name = %s AND option_value LIKE %s", $name, '%|' . $wpdb->esc_like($token)));
        wp_cache_delete($name, 'options');
    }

    /**
     * Import the next rows. The progress page and cron both call this.
     *
     * Progress is saved every CHECKPOINT rows. If a request dies in between, the next batch starts
     * again from the last checkpoint: rows it had already imported are recognised by their
     * "imported" event and counted once, and the problems file is cut back to the checkpoint.
     *
     * @return array|null the job afterwards
     */
    public static function run_batch($id, $max_rows = 500, $time_budget = 10)
    {
        $job = self::fresh($id);

        if ( ! $job || self::STATUS_RUNNING !== $job['status']) return $job;

        $token = self::lock($id);

        if ( ! $token) return $job;

        try {
            $job = self::fresh($id);

            if ( ! $job || self::STATUS_RUNNING !== $job['status']) return $job;

            $handle = is_file($job['file']) ? fopen($job['file'], 'r') : false;

            if ( ! $handle) {
                $job['status']      = self::STATUS_CANCELLED;
                $job['finished_at'] = gmdate('Y-m-d H:i:s');
                $job['error']       = __('The uploaded file is gone, so the import stopped.', 'mailoptin');
                self::save($job);

                return $job;
            }

            // Problem rows written after the last checkpoint are written again.
            $problems = self::problems_path($job);
            if ($problems) clearstatcache(true, $problems);
            if ($problems && is_file($problems) && filesize($problems) > (int)($job['problems_size'] ?? 0)) {
                $cut = fopen($problems, 'r+');
                if ($cut) {
                    ftruncate($cut, (int)($job['problems_size'] ?? 0));
                    fclose($cut);
                }
                clearstatcache(true, $problems);
            }

            fseek($handle, $job['offset']);

            $deadline      = time() + $time_budget;
            $rows          = 0;
            $confirmations = [];
            $at_end        = false;

            while ($rows < $max_rows && time() < $deadline) {
                $cells = fgetcsv($handle, 0, $job['delimiter'], '"', '');

                if (false === $cells) {
                    $at_end = true;
                    break;
                }

                $job['row']++;
                $rows++;

                // Not blank lines.
                if ([null] !== $cells && '' !== trim(implode('', $cells))) {
                    self::check_quotes($job, $cells);

                    $result = self::import_row($job, $cells);

                    if ( ! empty($result['confirm'])) $confirmations[] = $result['confirm'];
                }

                $job['offset'] = ftell($handle);

                if (0 === $rows % self::CHECKPOINT) {
                    if ( ! self::checkpoint($job, $confirmations)) {
                        fclose($handle);

                        return self::fresh($id);
                    }
                    $confirmations = [];
                }
            }

            if ( ! $at_end) {
                $at_end = false === fgetcsv($handle, 0, $job['delimiter'], '"', '');
            }

            fclose($handle);

            if ($at_end) {
                $job['status']      = self::STATUS_DONE;
                $job['finished_at'] = gmdate('Y-m-d H:i:s');
            }

            if ( ! self::checkpoint($job, $confirmations)) return self::fresh($id);

            if ($at_end) self::finish_files($job);

            return $job;
        } finally {
            self::unlock($id, $token);
        }
    }

    /**
     * Save progress, unless the import was stopped or deleted meanwhile: then that wins.
     *
     * @return bool false if the import should stop
     */
    protected static function checkpoint(&$job, $confirmations)
    {
        $stored = self::fresh($job['id']);

        if ( ! $stored || self::STATUS_RUNNING !== $stored['status']) {
            if ( ! $stored) self::finish_files($job);

            return false;
        }

        if ($job['log_id'] && ! empty($confirmations)) ImportConfirmations::enqueue($job['log_id'], $confirmations);

        $problems = self::problems_path($job);
        if ($problems) clearstatcache(true, $problems);
        $job['problems_size'] = $problems && is_file($problems) ? filesize($problems) : 0;

        self::save($job);

        return true;
    }

    protected static function cell($job, $cells, $target)
    {
        $index = array_search($target, $job['mapping'], true);

        return false === $index ? '' : self::clean_cell($cells[$index] ?? '');
    }

    /**
     * A cell as text, without the apostrophe exports put before a value a spreadsheet would run as
     * a formula, so an exported file imports unchanged.
     */
    public static function clean_cell($value)
    {
        $value = trim(wp_check_invalid_utf8((string)$value, true));

        return strlen($value) > 1 && "'" === $value[0] && false !== strpos("=+-@\t\r", $value[1]) ? substr($value, 1) : $value;
    }

    /**
     * @param array $job updated in place: counts and problems
     *
     * @return array ['confirm' => subscriber row to send a confirmation to] or []
     */
    protected static function import_row(&$job, $cells)
    {
        $email = Subscribers::normalize_email(self::cell($job, $cells, 'email'));

        if ( ! is_email($email)) {
            self::problem($job, $cells, $email, self::PROBLEM_INVALID, '' === $email ? __('No email address.', 'mailoptin') : __('Not an email address.', 'mailoptin'));

            return [];
        }

        $subscriber = Subscribers::get_by_email($email);
        $suppressed = SuppressionRepository::get_reason($email);

        if ($suppressed || ($subscriber && in_array($subscriber['status'], [Subscribers::STATUS_UNSUBSCRIBED, Subscribers::STATUS_BOUNCED, Subscribers::STATUS_COMPLAINED], true))) {
            $why = [
                SuppressionRepository::REASON_BOUNCED    => __('Their address bounced, so it\'s never emailed.', 'mailoptin'),
                SuppressionRepository::REASON_COMPLAINED => __('They marked an email as spam, so they\'re never emailed.', 'mailoptin'),
            ];
            $reason = $suppressed ?: $subscriber['status'];
            self::problem($job, $cells, $email, self::PROBLEM_SUPPRESSED, $why[$reason] ?? __('They unsubscribed. They can only sign up again themselves.', 'mailoptin'));

            return [];
        }

        $earlier = $subscriber ? self::imported_event($subscriber['id'], $job['id']) : null;

        if ($earlier) {
            // This very row, imported just before a request died: count it, don't report it.
            if ((int)($earlier['row'] ?? 0) === (int)$job['row']) {
                $job['counts'][ ! empty($earlier['new']) ? 'added' : 'updated']++;

                return self::needs_confirmation($job, (int)$subscriber['id']);
            }

            self::problem($job, $cells, $email, self::PROBLEM_REPEATED, __('The same email is on an earlier row.', 'mailoptin'));

            return [];
        }

        $names = ['first_name' => self::cell($job, $cells, 'first_name'), 'last_name' => self::cell($job, $cells, 'last_name')];

        if ('' !== ($full = self::cell($job, $cells, 'full_name'))) {
            $split = AbstractConnect::get_first_last_names($full);
            if ('' === $names['first_name']) $names['first_name'] = (string)($split[0] ?? '');
            if ('' === $names['last_name']) $names['last_name'] = (string)($split[1] ?? '');
        }

        $names = array_map(function ($name) {
            return mb_substr(sanitize_text_field($name), 0, 100);
        }, $names);

        $subscribed = self::PERMISSION_SUBSCRIBED === $job['permission'];

        if ( ! $subscriber) {
            $subscriber_id = Subscribers::create(array_merge(array_filter($names, 'strlen'), [
                'email'          => $email,
                'status'         => $subscribed ? Subscribers::STATUS_SUBSCRIBED : Subscribers::STATUS_PENDING,
                'source'         => 'import',
                'consent_method' => $subscribed ? Consent::METHOD_IMPORT : Consent::METHOD_IMPORT_CONFIRM,
                'consent_text'   => self::consent_text($job),
                'consent_at'     => Subscribers::now(),
            ]));

            if ( ! $subscriber_id) {
                self::problem($job, $cells, $email, self::PROBLEM_INVALID, __('The subscriber could not be saved.', 'mailoptin'));

                return [];
            }

            $is_new = true;
        } else {
            $subscriber_id = (int)$subscriber['id'];
            $is_new        = false;

            $update = array_filter($names, function ($value, $field) use ($subscriber, $job) {
                return '' !== $value && (self::EXISTING_REPLACE === $job['existing'] || '' === trim((string)$subscriber[$field]));
            }, ARRAY_FILTER_USE_BOTH);

            if ( ! empty($update)) Subscribers::update($subscriber_id, $update);
        }

        // Lists. Someone who left a list only rejoins it themselves.
        $status = $subscribed ? Subscribers::STATUS_SUBSCRIBED : Subscribers::STATUS_PENDING;
        $joined = [];

        foreach ($job['lists'] as $list_id) {
            $membership = Subscribers::get_membership($subscriber_id, $list_id);

            if ($membership && in_array($membership['status'], [Subscribers::STATUS_UNSUBSCRIBED, Subscribers::STATUS_SUBSCRIBED], true)) continue;

            // Already confirmed elsewhere: no need to confirm again for another list.
            $list_status = ! $subscribed && $subscriber && ! empty($subscriber['confirmed_at']) && Subscribers::STATUS_SUBSCRIBED === $subscriber['status'] ? Subscribers::STATUS_SUBSCRIBED : $status;

            Subscribers::set_membership($subscriber_id, $list_id, $list_status);
            $joined[] = $list_id;
        }

        Subscribers::sync_status($subscriber_id);

        // Tags from the file and for everyone in it.
        $tag_names = array_filter(array_map('trim', explode(',', self::cell($job, $cells, 'tags'))), 'strlen');
        if ('' !== $job['tag_all']) $tag_names[] = $job['tag_all'];

        $tag_ids = Tags::is_ready() && ! empty($tag_names) ? Tags::ensure(array_values($tag_names)) : [];
        if ( ! empty($tag_ids)) Tags::add_to_subscribers($tag_ids, [$subscriber_id]);

        // Custom fields.
        $values = [];
        if (self::can_import_fields()) {
            foreach ($job['mapping'] as $index => $target) {
                if (0 === strpos($target, 'field:')) {
                    $value = self::clean_cell($cells[$index] ?? '');
                    if ('' !== $value) $values[(int)substr($target, 6)] = $value;
                }
            }
        }

        if ( ! empty($values)) {
            $result = Fields::set_values($subscriber_id, $values, self::EXISTING_FILL === $job['existing'] && ! $is_new);

            if ( ! empty($result['errors'])) {
                self::problem($job, $cells, $email, self::PROBLEM_NOTE, sprintf(__('Imported, but not every value: %s', 'mailoptin'), implode(' ', $result['errors'])));
            }
        }

        Subscribers::log_event($subscriber_id, Subscribers::EVENT_IMPORTED, array_filter([
            'import'   => $job['id'],
            'row'      => (int)$job['row'],
            'new'      => $is_new,
            'file'     => $job['name'],
            'by_admin' => (int)$job['user_id'],
            'list_ids' => $joined,
            'tags'     => empty($tag_ids) ? [] : array_values(array_intersect_key(Tags::names(), array_flip($tag_ids))),
        ]));

        $job['counts'][$is_new ? 'added' : 'updated']++;

        return empty($joined) ? [] : self::needs_confirmation($job, $subscriber_id);
    }

    /**
     * A cell holding line breaks and an email address or delimiter usually means a quote that was
     * never closed, which swallows the rows after it.
     */
    protected static function check_quotes(&$job, $cells)
    {
        foreach ($cells as $cell) {
            $cell = (string)$cell;

            if (false !== strpos($cell, "\n") && (false !== strpos($cell, '@') || false !== strpos($cell, $job['delimiter']))) {
                self::problem($job, $cells, '', self::PROBLEM_NOTE, __('This row may run into the rows after it: check for a quote mark that isn\'t closed.', 'mailoptin'));

                return;
            }
        }
    }

    /**
     * @return array ['confirm' => subscriber] when the import asks for confirmation and a list is pending
     */
    protected static function needs_confirmation($job, $subscriber_id)
    {
        if (self::PERMISSION_CONFIRM !== $job['permission']) return [];

        $subscriber = Subscribers::get($subscriber_id);

        return $subscriber && in_array(Subscribers::STATUS_PENDING, wp_list_pluck(Subscribers::get_memberships($subscriber_id), 'status'), true) ? ['confirm' => $subscriber] : [];
    }

    protected static function consent_text($job)
    {
        $user = get_userdata($job['user_id']);

        return sprintf(__('Imported from %1$s by %2$s', 'mailoptin'), $job['name'], $user ? $user->display_name : __('an admin', 'mailoptin'));
    }

    /**
     * @return array|null meta of the subscriber's "imported" event for this import, if any
     */
    protected static function imported_event($subscriber_id, $import_id)
    {
        global $wpdb;

        $events = Subscribers::events_table();

        $meta = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta FROM $events WHERE subscriber_id = %d AND type = %s AND meta LIKE %s ORDER BY id DESC LIMIT 1",
                $subscriber_id,
                Subscribers::EVENT_IMPORTED,
                '%' . $wpdb->esc_like('"import":"' . $import_id . '"') . '%'
            )
        );

        return null === $meta ? null : (array)json_decode($meta, true);
    }

    /**
     * Note a row that was skipped, or imported with a problem. All go in a CSV to download; the
     * first ones are also kept on the job to show on the report.
     */
    protected static function problem(&$job, $cells, $email, $type, $message)
    {
        $job['counts'][$type]++;

        $row = $job['row'] + 1; // Row 1 is the column names.

        if (count($job['problems']) < self::SHOWN_PROBLEMS) {
            $job['problems'][] = ['row' => $row, 'email' => $email, 'type' => $type, 'message' => $message];
        }

        if (empty($job['problems_file'])) $job['problems_file'] = self::secret_path('-problems');

        $path   = $job['problems_file'];
        $new    = ! is_file($path) || 0 === filesize($path);
        $handle = fopen($path, 'a');

        if ( ! $handle) return;

        if ($new) fputcsv($handle, array_map([SubscriberExport::class, 'safe_cell'], array_merge([__('Row', 'mailoptin'), __('Problem', 'mailoptin')], $job['headers'])), ',', '"', '');

        fputcsv($handle, array_map([SubscriberExport::class, 'safe_cell'], array_merge([$row, $message], (array)$cells)), ',', '"', '');
        fclose($handle);
    }

    public static function skipped_count($job)
    {
        return $job['counts'][self::PROBLEM_SUPPRESSED] + $job['counts'][self::PROBLEM_INVALID] + $job['counts'][self::PROBLEM_REPEATED];
    }

    /**
     * @return SubscriberImport
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}
