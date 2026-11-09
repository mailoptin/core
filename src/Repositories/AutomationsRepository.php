<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;

/**
 * Automations, their steps, their runs (one per person per starting event) and the steps each run
 * has done. Settings and context are stored as JSON. All datetimes are UTC.
 */
class AutomationsRepository extends AbstractRepository
{
    const STATUS_DRAFT = 'draft';
    const STATUS_LIVE = 'live';

    const STEP_WAIT = 'wait';
    const STEP_EMAIL = 'email';
    const STEP_CHECK = 'check';

    const RUN_ACTIVE = 'active';
    const RUN_COMPLETED = 'completed';
    const RUN_STOPPED = 'stopped';

    const RUN_STEP_QUEUED = 'queued';
    const RUN_STEP_SENT = 'sent';
    const RUN_STEP_SKIPPED = 'skipped';
    const RUN_STEP_FAILED = 'failed';

    /** The email campaign type of an automation's emails, edited like any other email. */
    const EMAIL_CAMPAIGN_TYPE = 'automation_email';

    public static function table()
    {
        return self::wpdb()->prefix . Core::automations_table_name;
    }

    public static function steps_table()
    {
        return self::wpdb()->prefix . Core::automation_steps_table_name;
    }

    public static function runs_table()
    {
        return self::wpdb()->prefix . Core::automation_runs_table_name;
    }

    public static function run_steps_table()
    {
        return self::wpdb()->prefix . Core::automation_run_steps_table_name;
    }

    public static function now($offset_seconds = 0)
    {
        return gmdate('Y-m-d H:i:s', time() + $offset_seconds);
    }

    public static function is_ready()
    {
        static $ready = null;

        if (null === $ready || ! $ready) {
            $ready = (bool)self::wpdb()->get_var(self::wpdb()->prepare('SHOW TABLES LIKE %s', self::runs_table()));
        }

        return $ready;
    }

    protected static function decode($json)
    {
        $value = json_decode((string)$json, true);

        return is_array($value) ? $value : [];
    }

    protected static function inflate($row)
    {
        if ( ! $row) return null;

        foreach (['trigger_settings', 'audience', 'settings', 'context'] as $key) {
            if (array_key_exists($key, $row)) $row[$key] = self::decode($row[$key]);
        }

        foreach (['id', 'automation_id', 'subscriber_id', 'current_step_id', 'waiting_step_id', 'attempts', 'position', 'run_id', 'step_id', 'queue_id', 'deleted'] as $key) {
            if (array_key_exists($key, $row)) $row[$key] = (int)$row[$key];
        }

        return $row;
    }

    /* ---------------------------------------------------------------------------------------------
     * Automations
     * ------------------------------------------------------------------------------------------ */

    /**
     * @param array $data name, trigger_id, trigger_settings, audience, settings, status
     *
     * @return int automation ID
     */
    public static function create($data)
    {
        $now = self::now();

        self::wpdb()->insert(self::table(), [
            'name'             => sanitize_text_field($data['name'] ?? ''),
            'trigger_id'       => sanitize_key($data['trigger_id'] ?? ''),
            'trigger_settings' => wp_json_encode((array)($data['trigger_settings'] ?? [])),
            'audience'         => wp_json_encode((array)($data['audience'] ?? [])),
            'settings'         => wp_json_encode((array)($data['settings'] ?? [])),
            'status'           => self::STATUS_LIVE === ($data['status'] ?? '') ? self::STATUS_LIVE : self::STATUS_DRAFT,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $id = (int)self::wpdb()->insert_id;

        self::changed();

        return $id;
    }

    public static function update($automation_id, $data)
    {
        $fields = [];

        if (isset($data['name'])) $fields['name'] = sanitize_text_field($data['name']);
        if (isset($data['trigger_id'])) $fields['trigger_id'] = sanitize_key($data['trigger_id']);
        if (isset($data['status'])) $fields['status'] = self::STATUS_LIVE === $data['status'] ? self::STATUS_LIVE : self::STATUS_DRAFT;

        foreach (['trigger_settings', 'audience', 'settings'] as $key) {
            if (isset($data[$key])) $fields[$key] = wp_json_encode((array)$data[$key]);
        }

        if (empty($fields)) return false;

        $fields['updated_at'] = self::now();

        $updated = false !== self::wpdb()->update(self::table(), $fields, ['id' => absint($automation_id)]);

        self::changed();

        return $updated;
    }

    public static function get($automation_id)
    {
        $table = self::table();

        return self::inflate(self::wpdb()->get_row(self::wpdb()->prepare("SELECT * FROM $table WHERE id = %d", $automation_id), 'ARRAY_A'));
    }

    /**
     * @return array[] every automation, newest first
     */
    public static function all()
    {
        $table = self::table();

        return array_map([__CLASS__, 'inflate'], (array)self::wpdb()->get_results("SELECT * FROM $table ORDER BY id DESC", 'ARRAY_A'));
    }

    public static function has_live()
    {
        return (bool)self::wpdb()->get_var(self::wpdb()->prepare('SELECT 1 FROM ' . self::table() . ' WHERE status = %s LIMIT 1', self::STATUS_LIVE));
    }

    /** @var array trigger ID => live automations, for this request */
    protected static $live_cache = [];

    /**
     * @return array[] live automations started by this trigger
     */
    public static function live_for_trigger($trigger_id)
    {
        // Events can fire thousands of times in one request (bulk tagging, imports): look once.
        if ( ! isset(self::$live_cache[$trigger_id])) {
            $table = self::table();

            self::$live_cache[$trigger_id] = array_map([__CLASS__, 'inflate'], (array)self::wpdb()->get_results(
                self::wpdb()->prepare("SELECT * FROM $table WHERE trigger_id = %s AND status = %s ORDER BY id", $trigger_id, self::STATUS_LIVE),
                'ARRAY_A'
            ));
        }

        return self::$live_cache[$trigger_id];
    }

    /**
     * For the automations list: people in progress, and emails sent in the last 30 days.
     *
     * @return array automation ID => ['active' => int, 'sent_30' => int]
     */
    public static function list_stats()
    {
        $wpdb  = self::wpdb();
        $runs  = self::runs_table();
        $stats = [];

        foreach ((array)$wpdb->get_results($wpdb->prepare("SELECT automation_id, COUNT(*) n FROM $runs WHERE status = %s GROUP BY automation_id", self::RUN_ACTIVE), 'ARRAY_A') as $row) {
            $stats[(int)$row['automation_id']]['active'] = (int)$row['n'];
        }

        foreach ((array)$wpdb->get_results($wpdb->prepare(
            'SELECT r.automation_id, COUNT(*) n FROM ' . self::run_steps_table() . " rs INNER JOIN $runs r ON r.id = rs.run_id
            WHERE rs.status = %s AND rs.created_at >= %s GROUP BY r.automation_id",
            self::RUN_STEP_SENT, self::now(-30 * DAY_IN_SECONDS)
        ), 'ARRAY_A') as $row) {
            $stats[(int)$row['automation_id']]['sent_30'] = (int)$row['n'];
        }

        return $stats;
    }

    /**
     * People in progress in one automation, and its emails sent in the last 30 days.
     *
     * @return array ['active' => int, 'sent_30' => int]
     */
    public static function automation_stats($automation_id)
    {
        $wpdb   = self::wpdb();
        $active = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::runs_table() . ' WHERE status = %s AND automation_id = %d', self::RUN_ACTIVE, $automation_id));
        $steps  = array_map('absint', wp_list_pluck(self::steps($automation_id, true), 'id'));
        $sent   = $steps ? (int)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::run_steps_table() . ' WHERE step_id IN (' . implode(',', $steps) . ') AND status = %s AND created_at >= %s',
            self::RUN_STEP_SENT, self::now(-30 * DAY_IN_SECONDS)
        )) : 0;

        return ['active' => $active, 'sent_30' => $sent];
    }

    /**
     * The automation and step that send an email campaign.
     *
     * @return array|null [automation, step]
     */
    public static function email_owner($email_campaign_id)
    {
        $email_campaign_id = absint($email_campaign_id);

        if ( ! $email_campaign_id) return null;

        // Email steps store {"email_campaign_id":N,...}; checked exactly on the decoded value.
        $rows = self::wpdb()->get_results(self::wpdb()->prepare(
            'SELECT * FROM ' . self::steps_table() . ' WHERE type = %s AND deleted = 0 AND settings LIKE %s',
            self::STEP_EMAIL, '%' . self::wpdb()->esc_like('"email_campaign_id":' . $email_campaign_id) . '%'
        ), 'ARRAY_A');

        foreach ((array)$rows as $row) {
            $step = self::get_step($row['id']);

            if ($step && absint($step['settings']['email_campaign_id'] ?? 0) === $email_campaign_id) {
                $automation = self::get($step['automation_id']);

                return $automation ? [$automation, $step] : null;
            }
        }

        return null;
    }

    /**
     * Emails sent by each step of an automation, ever.
     *
     * @return array step ID => int
     */
    public static function step_sent_counts($automation_id)
    {
        $steps = wp_list_pluck(self::steps($automation_id, true), 'id');

        if (empty($steps)) return [];

        $rows = self::wpdb()->get_results(self::wpdb()->prepare(
            'SELECT step_id, COUNT(*) n FROM ' . self::run_steps_table() . ' WHERE step_id IN (' . implode(',', array_map('absint', $steps)) . ') AND status = %s GROUP BY step_id',
            self::RUN_STEP_SENT
        ), 'ARRAY_A');

        return array_map('intval', wp_list_pluck((array)$rows, 'n', 'step_id'));
    }

    /**
     * Emails sent in each of some runs.
     *
     * @return array run ID => int
     */
    public static function sent_counts_for_runs($run_ids)
    {
        $ids = array_filter(array_map('absint', (array)$run_ids));

        if (empty($ids)) return [];

        $rows = self::wpdb()->get_results(self::wpdb()->prepare(
            'SELECT run_id, COUNT(*) n FROM ' . self::run_steps_table() . ' WHERE run_id IN (' . implode(',', $ids) . ') AND status = %s GROUP BY run_id',
            self::RUN_STEP_SENT
        ), 'ARRAY_A');

        return array_map('intval', wp_list_pluck((array)$rows, 'n', 'run_id'));
    }

    /**
     * The most recent run of an automation: a real person to fill a test email with.
     */
    public static function latest_run($automation_id)
    {
        $row = self::wpdb()->get_row(self::wpdb()->prepare('SELECT * FROM ' . self::runs_table() . ' WHERE automation_id = %d ORDER BY id DESC LIMIT 1', $automation_id), 'ARRAY_A');

        return $row ? self::inflate($row) : null;
    }

    /**
     * Why runs of an automation stopped early, with how many, for the report.
     *
     * @return array reason => int
     */
    public static function stop_reasons($automation_id)
    {
        $rows = self::wpdb()->get_results(self::wpdb()->prepare(
            'SELECT stop_reason, COUNT(*) n FROM ' . self::runs_table() . ' WHERE automation_id = %d AND status = %s GROUP BY stop_reason ORDER BY n DESC',
            $automation_id, self::RUN_STOPPED
        ), 'ARRAY_A');

        return array_map('intval', wp_list_pluck((array)$rows, 'n', 'stop_reason'));
    }

    /**
     * Where a scanning trigger got to for an automation (e.g. a unix time). Kept in an option, not autoloaded.
     */
    public static function get_cursor($automation_id)
    {
        return get_option('mo_automation_cursor_' . absint($automation_id), '');
    }

    public static function set_cursor($automation_id, $value)
    {
        $name = 'mo_automation_cursor_' . absint($automation_id);

        if (false === get_option($name)) {
            add_option($name, $value, '', 'no');
        } else {
            update_option($name, $value, false);
        }
    }

    /**
     * After an automation is created, changed or deleted: forget what this request read, and tell
     * anyone keeping their own summary (such as whether a cart automation is live).
     */
    protected static function changed()
    {
        self::flush_cache();

        do_action('mailoptin_automations_changed');
    }

    public static function flush_cache()
    {
        self::$live_cache = [];
    }

    /**
     * Delete an automation with its steps, runs and their emails waiting in the send queue.
     */
    public static function delete($automation_id)
    {
        $automation_id = absint($automation_id);

        self::flush_cache();
        delete_option('mo_automation_cursor_' . $automation_id);

        foreach (self::steps($automation_id, true) as $step) {
            self::cancel_queued_for_step($step['id'], __('The automation was deleted.', 'mailoptin'));
        }

        $runs      = self::runs_table();
        $run_steps = self::run_steps_table();

        self::wpdb()->query(self::wpdb()->prepare("DELETE rs FROM $run_steps rs INNER JOIN $runs r ON r.id = rs.run_id WHERE r.automation_id = %d", $automation_id));
        self::wpdb()->delete($runs, ['automation_id' => $automation_id]);
        self::wpdb()->delete(self::steps_table(), ['automation_id' => $automation_id]);

        $deleted = (bool)self::wpdb()->delete(self::table(), ['id' => $automation_id]);

        self::changed();

        return $deleted;
    }

    /* ---------------------------------------------------------------------------------------------
     * Steps
     * ------------------------------------------------------------------------------------------ */

    /**
     * @param bool $with_deleted include steps removed while runs may still point at them
     *
     * @return array[] in order
     */
    public static function steps($automation_id, $with_deleted = false)
    {
        $table = self::steps_table();
        $where = $with_deleted ? '' : ' AND deleted = 0';

        return array_map([__CLASS__, 'inflate'], (array)self::wpdb()->get_results(
            self::wpdb()->prepare("SELECT * FROM $table WHERE automation_id = %d$where ORDER BY position, id", $automation_id),
            'ARRAY_A'
        ));
    }

    public static function get_step($step_id)
    {
        $table = self::steps_table();

        return self::inflate(self::wpdb()->get_row(self::wpdb()->prepare("SELECT * FROM $table WHERE id = %d", $step_id), 'ARRAY_A'));
    }

    /**
     * Save an automation's steps in order. A step with an "id" keeps it, so people part-way through
     * stay at the same place; steps left out are marked deleted (their waiting emails are cancelled)
     * rather than removed, because runs may still point at them.
     *
     * @param array[] $steps each type, settings and optionally id
     *
     * @return int[] the step IDs, in order
     */
    public static function save_steps($automation_id, $steps)
    {
        $automation_id = absint($automation_id);
        $existing      = [];

        foreach (self::steps($automation_id, true) as $step) $existing[$step['id']] = $step;

        $kept = [];
        $ids  = [];

        foreach (array_values((array)$steps) as $position => $step) {
            $type = in_array($step['type'] ?? '', [self::STEP_WAIT, self::STEP_EMAIL, self::STEP_CHECK], true) ? $step['type'] : '';

            if ('' === $type) continue;

            $row = [
                'automation_id' => $automation_id,
                'position'      => ($position + 1) * 10,
                'type'          => $type,
                'settings'      => wp_json_encode((array)($step['settings'] ?? [])),
                'deleted'       => 0,
            ];

            $id = absint($step['id'] ?? 0);

            // A step can't change type or move to another automation; that's a new step.
            if ($id && isset($existing[$id]) && $existing[$id]['type'] === $type) {
                self::wpdb()->update(self::steps_table(), $row, ['id' => $id]);
            } else {
                self::wpdb()->insert(self::steps_table(), $row);
                $id = (int)self::wpdb()->insert_id;
            }

            $kept[$id] = true;
            $ids[]     = $id;
        }

        foreach ($existing as $id => $step) {
            if (isset($kept[$id]) || $step['deleted']) continue;

            self::wpdb()->update(self::steps_table(), ['deleted' => 1], ['id' => $id]);

            if (self::STEP_EMAIL === $step['type']) {
                self::cancel_queued_for_step($id, __('The email was removed from the automation.', 'mailoptin'));
            }
        }

        return $ids;
    }

    /**
     * The first step after this position that is still in the automation.
     *
     * @param int $position 0 for the start
     */
    public static function next_step($automation_id, $position)
    {
        $table = self::steps_table();

        return self::inflate(self::wpdb()->get_row(
            self::wpdb()->prepare("SELECT * FROM $table WHERE automation_id = %d AND deleted = 0 AND position > %d ORDER BY position, id LIMIT 1", $automation_id, $position),
            'ARRAY_A'
        ));
    }

    /* ---------------------------------------------------------------------------------------------
     * Runs
     * ------------------------------------------------------------------------------------------ */

    /**
     * Start a run. Nothing happens if this person already has one for this event.
     *
     * @return int run ID, or 0 when it already existed
     */
    public static function start_run($automation_id, $email, $trigger_ref, $context, $subscriber_id = 0, $next_run_at = null)
    {
        $now = self::now();

        $inserted = self::wpdb()->query(self::wpdb()->prepare(
            'INSERT IGNORE INTO ' . self::runs_table() . ' (automation_id, email, subscriber_id, trigger_ref, context, status, next_run_at, started_at, updated_at) VALUES (%d, %s, %d, %s, %s, %s, %s, %s, %s)',
            $automation_id,
            strtolower(trim($email)),
            absint($subscriber_id),
            substr((string)$trigger_ref, 0, 100),
            wp_json_encode((array)$context),
            self::RUN_ACTIVE,
            $next_run_at ?: $now,
            $now,
            $now
        ));

        return $inserted ? (int)self::wpdb()->insert_id : 0;
    }

    public static function get_run($run_id)
    {
        $table = self::runs_table();

        return self::inflate(self::wpdb()->get_row(self::wpdb()->prepare("SELECT * FROM $table WHERE id = %d", $run_id), 'ARRAY_A'));
    }

    public static function has_active_run($automation_id, $email)
    {
        $table = self::runs_table();

        return (bool)self::wpdb()->get_var(self::wpdb()->prepare(
            "SELECT id FROM $table WHERE automation_id = %d AND email = %s AND status = %s LIMIT 1",
            $automation_id, strtolower(trim($email)), self::RUN_ACTIVE
        ));
    }

    /**
     * Reserve up to $limit due runs of live automations for one worker. The reservation lasts
     * $lease_seconds, after which another worker may take a run whose worker died.
     *
     * @return array[]
     */
    public static function claim_due($worker_id, $limit, $lease_seconds, $trigger_ids = null)
    {
        $runs        = self::runs_table();
        $automations = self::table();
        $now         = self::now();
        $wpdb        = self::wpdb();

        // Runs whose trigger isn't available (its plugin is off) wait where they are, untouched.
        $trigger_sql = '';
        if (is_array($trigger_ids)) {
            if (empty($trigger_ids)) return [];
            $trigger_sql = ' AND a.trigger_id IN (' . implode(',', array_fill(0, count($trigger_ids), '%s')) . ')';
        }

        // Three small steps rather than one UPDATE ... IN (subquery): MySQL runs that as a scan of the
        // whole runs table, locking every row it reads. These are an index range read and key lookups.
        $ids = array_map('intval', (array)$wpdb->get_col($wpdb->prepare(
            "SELECT r.id FROM $runs r INNER JOIN $automations a ON a.id = r.automation_id
            WHERE r.status = %s AND r.next_run_at <= %s AND (r.claimed_until IS NULL OR r.claimed_until < %s) AND a.status = %s$trigger_sql
            ORDER BY r.next_run_at, r.id LIMIT %d",
            array_merge([self::RUN_ACTIVE, $now, $now, self::STATUS_LIVE], is_array($trigger_ids) ? array_values($trigger_ids) : [], [absint($limit)])
        )));

        if (empty($ids)) return [];

        $in = implode(',', $ids);

        // Re-checks the lease, so two workers can't both take a run.
        $wpdb->query($wpdb->prepare(
            "UPDATE $runs SET claimed_by = %s, claimed_until = %s, attempts = attempts + 1
            WHERE id IN ($in) AND status = %s AND (claimed_until IS NULL OR claimed_until < %s)",
            $worker_id, self::now($lease_seconds), self::RUN_ACTIVE, $now
        ));

        return array_map([__CLASS__, 'inflate'], (array)$wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $runs WHERE id IN ($in) AND claimed_by = %s AND status = %s ORDER BY next_run_at, id", $worker_id, self::RUN_ACTIVE),
            'ARRAY_A'
        ));
    }

    /**
     * When the next active run of a live automation is due, or null when none is waiting.
     *
     * @param string[]|null $trigger_ids only runs of these triggers
     *
     * @return int|null unix time
     */
    public static function next_due_at($trigger_ids = null)
    {
        $runs        = self::runs_table();
        $automations = self::table();
        $wpdb        = self::wpdb();

        $trigger_sql = '';
        if (is_array($trigger_ids)) {
            if (empty($trigger_ids)) return null;
            $trigger_sql = ' AND a.trigger_id IN (' . implode(',', array_fill(0, count($trigger_ids), '%s')) . ')';
        }

        // ORDER BY ... LIMIT 1 walks the "due" index and stops at the first live match; MIN() over the
        // join would read every active run.
        $next = $wpdb->get_var($wpdb->prepare(
            "SELECT r.next_run_at FROM $runs r INNER JOIN $automations a ON a.id = r.automation_id
            WHERE r.status = %s AND a.status = %s$trigger_sql ORDER BY r.next_run_at LIMIT 1",
            array_merge([self::RUN_ACTIVE, self::STATUS_LIVE], is_array($trigger_ids) ? array_values($trigger_ids) : [])
        ));

        return $next ? (int)strtotime($next . ' UTC') : null;
    }

    /**
     * Delete finished runs, and their run steps, older than $days. Runs of "once" automations
     * (empty trigger_ref) are kept: they are what stops the person starting again.
     *
     * @return int runs deleted
     */
    public static function prune($days, $limit = 1000)
    {
        $runs      = self::runs_table();
        $run_steps = self::run_steps_table();
        $wpdb      = self::wpdb();

        $ids = array_map('intval', (array)$wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $runs WHERE status IN (%s, %s) AND trigger_ref <> '' AND finished_at < %s ORDER BY id LIMIT %d",
            self::RUN_COMPLETED, self::RUN_STOPPED, self::now(-absint($days) * DAY_IN_SECONDS), absint($limit)
        )));

        if (empty($ids)) return 0;

        $in = implode(',', $ids);
        $wpdb->query("DELETE FROM $run_steps WHERE run_id IN ($in)");
        $wpdb->query("DELETE FROM $runs WHERE id IN ($in)");

        return count($ids);
    }

    /**
     * Release a claimed run, saving where it got to. Progress resets its attempt count.
     *
     * @param array $fields any of current_step_id, waiting_step_id, next_run_at
     */
    public static function release_run($run_id, $fields = [])
    {
        $allowed = array_intersect_key((array)$fields, array_flip(['current_step_id', 'waiting_step_id', 'next_run_at']));

        return false !== self::wpdb()->update(
            self::runs_table(),
            $allowed + ['claimed_by' => '', 'claimed_until' => null, 'attempts' => 0, 'updated_at' => self::now()],
            ['id' => absint($run_id)]
        );
    }

    /**
     * Finish a run: completed, or stopped with the reason.
     *
     * @param bool $after_completion also stop a run that already completed: its last email was
     *                               still in the queue when the reason to stop came up
     */
    public static function finish_run($run_id, $status, $reason = '', $after_completion = false)
    {
        $run_id = absint($run_id);

        if ($after_completion && self::RUN_STOPPED === $status) {
            self::wpdb()->update(self::runs_table(), ['status' => self::RUN_ACTIVE], ['id' => $run_id, 'status' => self::RUN_COMPLETED]);
        }

        self::wpdb()->update(self::runs_table(), [
            'status'        => self::RUN_STOPPED === $status ? self::RUN_STOPPED : self::RUN_COMPLETED,
            'stop_reason'   => substr((string)$reason, 0, 100),
            'claimed_by'    => '',
            'claimed_until' => null,
            'finished_at'   => self::now(),
            'updated_at'    => self::now(),
        ], ['id' => $run_id, 'status' => self::RUN_ACTIVE]);

        if (self::RUN_STOPPED === $status) {
            foreach (self::queued_run_steps('rs.run_id = %d', [$run_id]) as $run_step) {
                self::cancel_queued($run_step, __('The automation stopped for this person.', 'mailoptin') . ' (' . $reason . ')');
            }
        }
    }

    /**
     * Stop active runs. Runs are matched by email, and optionally by automation and starting event.
     *
     * @param array $where email, automation_id, trigger_ref, trigger_id (any combination)
     *
     * @return int runs stopped
     */
    public static function stop_runs($where, $reason)
    {
        $runs        = self::runs_table();
        $automations = self::table();
        $sql         = "SELECT r.id FROM $runs r INNER JOIN $automations a ON a.id = r.automation_id WHERE r.status = %s";
        $params      = [self::RUN_ACTIVE];

        if (isset($where['email'])) {
            $sql      .= ' AND r.email = %s';
            $params[] = strtolower(trim($where['email']));
        }

        if (isset($where['subscriber_id'])) {
            $sql      .= ' AND r.subscriber_id = %d';
            $params[] = absint($where['subscriber_id']);
        }

        if (isset($where['automation_id'])) {
            $sql      .= ' AND r.automation_id = %d';
            $params[] = absint($where['automation_id']);
        }

        if (isset($where['trigger_ref'])) {
            $sql      .= ' AND r.trigger_ref = %s';
            $params[] = (string)$where['trigger_ref'];
        }

        if (isset($where['trigger_id'])) {
            $sql      .= ' AND a.trigger_id = %s';
            $params[] = (string)$where['trigger_id'];
        }

        // Never everything: at least one way of picking the person or the event.
        if (count($params) < 2) return 0;

        $ids = array_map('intval', (array)self::wpdb()->get_col(self::wpdb()->prepare($sql, $params)));

        foreach ($ids as $run_id) self::finish_run($run_id, self::RUN_STOPPED, $reason);

        return count($ids);
    }

    /**
     * @return array[] runs for an email, newest first, with the automation's name
     */
    public static function runs_for_email($email, $limit = 50)
    {
        $runs        = self::runs_table();
        $automations = self::table();

        return array_map([__CLASS__, 'inflate'], (array)self::wpdb()->get_results(self::wpdb()->prepare(
            "SELECT r.*, a.name AS automation_name FROM $runs r LEFT JOIN $automations a ON a.id = r.automation_id WHERE r.email = %s ORDER BY r.id DESC LIMIT %d",
            strtolower(trim($email)), $limit
        ), 'ARRAY_A'));
    }

    /**
     * @return int[] status => number of runs
     */
    public static function run_counts($automation_id)
    {
        $table  = self::runs_table();
        $counts = [self::RUN_ACTIVE => 0, self::RUN_COMPLETED => 0, self::RUN_STOPPED => 0];

        foreach ((array)self::wpdb()->get_results(self::wpdb()->prepare("SELECT status, COUNT(*) AS n FROM $table WHERE automation_id = %d GROUP BY status", $automation_id), 'ARRAY_A') as $row) {
            $counts[$row['status']] = (int)$row['n'];
        }

        return $counts;
    }

    /* ---------------------------------------------------------------------------------------------
     * Run steps
     * ------------------------------------------------------------------------------------------ */

    /**
     * Record that a run reached an email step.
     *
     * @return int run step ID, or 0 when this run already had it
     */
    public static function add_run_step($run_id, $step_id, $email)
    {
        $inserted = self::wpdb()->query(self::wpdb()->prepare(
            'INSERT IGNORE INTO ' . self::run_steps_table() . ' (run_id, step_id, email, status, created_at) VALUES (%d, %d, %s, %s, %s)',
            $run_id, $step_id, strtolower(trim($email)), self::RUN_STEP_QUEUED, self::now()
        ));

        return $inserted ? (int)self::wpdb()->insert_id : 0;
    }

    public static function find_run_step($run_id, $step_id)
    {
        $table = self::run_steps_table();

        return self::inflate(self::wpdb()->get_row(self::wpdb()->prepare("SELECT * FROM $table WHERE run_id = %d AND step_id = %d", $run_id, $step_id), 'ARRAY_A'));
    }

    public static function get_run_step($run_step_id)
    {
        $table = self::run_steps_table();

        return self::inflate(self::wpdb()->get_row(self::wpdb()->prepare("SELECT * FROM $table WHERE id = %d", $run_step_id), 'ARRAY_A'));
    }

    public static function update_run_step($run_step_id, $fields)
    {
        $allowed = array_intersect_key((array)$fields, array_flip(['status', 'queue_id']));

        return false !== self::wpdb()->update(self::run_steps_table(), $allowed, ['id' => absint($run_step_id)]);
    }

    /**
     * When this address was last sent an automation email, or had one waiting in the send queue.
     * Emails the queue skipped or gave up on don't count.
     *
     * @return string|null UTC datetime
     */
    public static function last_email_at($email, $except_run_step_id = 0)
    {
        $table = self::run_steps_table();
        $queue = SendQueueRepository::table();

        return self::wpdb()->get_var(self::wpdb()->prepare(
            "SELECT MAX(rs.created_at) FROM $table rs INNER JOIN $queue q ON q.id = rs.queue_id
            WHERE rs.email = %s AND rs.id <> %d AND q.status IN (%s, %s, %s, %s)",
            strtolower(trim($email)), $except_run_step_id,
            SendQueueRepository::STATUS_PENDING, SendQueueRepository::STATUS_CLAIMED, SendQueueRepository::STATUS_SENDING, SendQueueRepository::STATUS_SENT
        ));
    }

    /**
     * @return array[] run steps of an email step, with their run, for the latest sends
     */
    public static function run_steps_for_step($step_id)
    {
        $table = self::run_steps_table();

        return array_map([__CLASS__, 'inflate'], (array)self::wpdb()->get_results(self::wpdb()->prepare("SELECT * FROM $table WHERE step_id = %d ORDER BY id DESC LIMIT 500", $step_id), 'ARRAY_A'));
    }

    protected static function queued_run_steps($where, $params)
    {
        $table = self::run_steps_table();

        return array_map([__CLASS__, 'inflate'], (array)self::wpdb()->get_results(
            self::wpdb()->prepare("SELECT rs.* FROM $table rs WHERE $where AND rs.status = %s", array_merge($params, [self::RUN_STEP_QUEUED])),
            'ARRAY_A'
        ));
    }

    protected static function cancel_queued_for_step($step_id, $reason)
    {
        foreach (self::queued_run_steps('rs.step_id = %d', [$step_id]) as $run_step) {
            self::cancel_queued($run_step, $reason);
        }
    }

    /**
     * Skip a run step's email if it hasn't gone out yet. One that is already being sent goes.
     */
    protected static function cancel_queued($run_step, $reason)
    {
        $queue = SendQueueRepository::table();

        if ($run_step['queue_id']) {
            self::wpdb()->query(self::wpdb()->prepare(
                "UPDATE $queue SET status = %s, error = %s WHERE id = %d AND status = %s",
                SendQueueRepository::STATUS_SKIPPED, $reason, $run_step['queue_id'], SendQueueRepository::STATUS_PENDING
            ));
        }

        self::update_run_step($run_step['id'], ['status' => self::RUN_STEP_SKIPPED]);
    }

    /* ---------------------------------------------------------------------------------------------
     * Privacy
     * ------------------------------------------------------------------------------------------ */

    /**
     * Remove everything kept about an address: its runs and the steps they did.
     *
     * @return int runs removed
     */
    public static function erase_email($email)
    {
        $email     = strtolower(trim($email));
        $runs      = self::runs_table();
        $run_steps = self::run_steps_table();

        self::wpdb()->query(self::wpdb()->prepare("DELETE rs FROM $run_steps rs INNER JOIN $runs r ON r.id = rs.run_id WHERE r.email = %s", $email));
        self::wpdb()->delete($run_steps, ['email' => $email]);

        return (int)self::wpdb()->delete($runs, ['email' => $email]);
    }
}
