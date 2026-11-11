<?php

namespace MailOptin\Core\Audience\Sources;

use MailOptin\Core\Repositories\AudienceConsentsRepository as Consents;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;

/**
 * Customers, users and members as broadcast audiences, alongside lists. Nothing is copied: each
 * source is asked who its people are when an email is counted or sent, then narrowed to those
 * who may get marketing.
 *
 * Who may: everyone the source lists ("every customer"), or only those who agreed ("only people
 * who agreed"): they ticked this source's consent box, or are already subscribed to a list.
 * Either way, never anyone who withdrew, unsubscribed, bounced, complained or is suppressed.
 */
class Sources
{
    /** Settings › Customers and members, per source. */
    const OPTION = 'mo_audience_sources';

    /** A broadcast's chosen sources: JSON [{"source": "woo", "option": ""}, ...]. */
    const CAMPAIGN_SETTING = 'MailOptinConnect_audience_sources';

    /** Short enough that "source:<id>" fits the send queue's recipient_type column. */
    const ID_PATTERN = '/^[a-z0-9_-]{1,23}$/';

    /** The suppression list's source for unsubscribes from these emails. */
    const SUPPRESSION_SOURCE = 'customers';

    /** @var AbstractSource[]|null */
    protected static $sources = null;

    public function __construct()
    {
        add_action('init', [$this, 'register_checkboxes'], 20);
    }

    /**
     * Every registered source, active or not, by id. Plugins add theirs with the
     * mailoptin_audience_sources filter or mailoptin_register_audience_source().
     *
     * @return AbstractSource[]
     */
    public static function all()
    {
        if (null === self::$sources) {
            $sources = apply_filters('mailoptin_audience_sources', [
                new WooCustomers(),
                new EddCustomers(),
                new RegisteredUsers(),
                new PmproMembers(),
                new LifterMembers(),
            ]);

            self::$sources = [];

            foreach ((array)$sources as $source) {
                if ($source instanceof AbstractSource && preg_match(self::ID_PATTERN, $source->id())) {
                    self::$sources[$source->id()] = $source;
                }
            }
        }

        return self::$sources;
    }

    /** For mailoptin_register_audience_source() and tests. */
    public static function add(AbstractSource $source)
    {
        self::all();

        if (preg_match(self::ID_PATTERN, $source->id())) self::$sources[$source->id()] = $source;
    }

    public static function reset()
    {
        self::$sources = null;
    }

    /**
     * @return AbstractSource[] the sources whose plugin is active
     */
    public static function active()
    {
        return array_filter(self::all(), function (AbstractSource $source) {
            return $source->is_active();
        });
    }

    /**
     * @return AbstractSource|null
     */
    public static function get($id)
    {
        $all = self::all();

        return $all[$id] ?? null;
    }

    /* ---------------------------------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return array mode, checkbox_text, and the source's own settings
     */
    public static function settings($id)
    {
        $source = self::get($id);

        if ( ! $source) return [];

        $saved = (array)(get_option(self::OPTION, [])[$id] ?? []);

        $settings = array_merge(
            ['mode' => $source->default_mode(), 'checkbox_text' => $source->default_checkbox_text()],
            $source->setting_defaults(),
            array_intersect_key($saved, array_flip(array_merge(['mode', 'checkbox_text'], array_keys($source->setting_defaults()))))
        );

        if ( ! in_array($settings['mode'], [AbstractSource::MODE_AGREED, AbstractSource::MODE_EVERYONE], true)) {
            $settings['mode'] = $source->default_mode();
        }

        if ('' === trim((string)$settings['checkbox_text'])) $settings['checkbox_text'] = $source->default_checkbox_text();

        return $settings;
    }

    /**
     * @param array $posted source id => posted fields
     */
    public static function save_settings($posted)
    {
        $saved = (array)get_option(self::OPTION, []);

        foreach (self::all() as $id => $source) {
            if ( ! isset($posted[$id]) || ! is_array($posted[$id])) continue;

            $fields = $posted[$id];

            $saved[$id] = array_merge($source->sanitize_settings($fields), [
                'mode'          => AbstractSource::MODE_EVERYONE === ($fields['mode'] ?? '') ? AbstractSource::MODE_EVERYONE : AbstractSource::MODE_AGREED,
                'checkbox_text' => substr(sanitize_text_field(wp_unslash((string)($fields['checkbox_text'] ?? ''))), 0, 500),
            ]);
        }

        update_option(self::OPTION, $saved, false);
    }

    /**
     * Sources set to "only people who agreed" show their consent box.
     */
    public function register_checkboxes()
    {
        foreach (self::active() as $id => $source) {
            $settings = self::settings($id);

            if (AbstractSource::MODE_AGREED === $settings['mode'] && '' !== $source->checkbox_place()) {
                $source->register_checkbox($settings['checkbox_text']);
            }
        }
    }

    /* ---------------------------------------------------------------------------------------------
     * Consent
     * ------------------------------------------------------------------------------------------ */

    /**
     * They ticked a source's consent box. It doesn't undo an unsubscribe: a box ticked at one
     * plugin isn't consent for the others, and a guest checkout can be made with anyone's address.
     * Someone who unsubscribed comes back by signing up again through a form.
     */
    public static function agree($source_id, $email, $consent_text, $reference = '')
    {
        if ( ! Consents::agree($source_id, $email, $consent_text, $reference)) return false;

        do_action('mailoptin_audience_consent_agreed', $source_id, strtolower(trim($email)));

        return true;
    }

    /* ---------------------------------------------------------------------------------------------
     * Who gets it
     * ------------------------------------------------------------------------------------------ */

    /**
     * A broadcast's chosen sources, only those still registered and active.
     *
     * @return array each ['source' => id, 'option' => string]
     */
    public static function campaign_selection($raw)
    {
        $selection = is_array($raw) ? $raw : json_decode((string)$raw, true);
        $clean     = [];

        foreach ((array)$selection as $item) {
            if ( ! is_array($item)) continue;

            $source = self::get((string)($item['source'] ?? ''));

            if ( ! $source || ! $source->is_active()) continue;

            $option = (string)($item['option'] ?? '');

            // A role, level or course that's gone leaves the choice out; it never widens to everyone.
            if ( ! $source->is_valid_option($option)) continue;

            $clean[$source->id()] = ['source' => $source->id(), 'option' => $option];
        }

        return array_values($clean);
    }

    /**
     * Chosen sources that can't be used any more: their plugin was deactivated, or the role,
     * level or course chosen is gone.
     *
     * @return string[] labels, for a blocking check before sending
     */
    public static function unusable_choices($raw)
    {
        $selection = is_array($raw) ? $raw : json_decode((string)$raw, true);
        $problems  = [];

        foreach ((array)$selection as $item) {
            if ( ! is_array($item)) continue;

            $source = self::get((string)($item['source'] ?? ''));

            if ( ! $source || ! $source->is_active()) {
                $problems[] = sprintf(__('%s can\'t be used: its plugin isn\'t active.', 'mailoptin'), $source ? $source->label() : (string)($item['source'] ?? ''));
            } elseif ( ! $source->is_valid_option((string)($item['option'] ?? ''))) {
                $problems[] = sprintf(__('The %1$s chosen for %2$s no longer exists.', 'mailoptin'), strtolower($source->option_label()), $source->label());
            }
        }

        return $problems;
    }

    public static function encode_selection($selection)
    {
        return wp_json_encode(array_values(array_map(function ($item) {
            return ['source' => (string)$item['source'], 'option' => (string)$item['option']];
        }, self::campaign_selection($selection))));
    }

    /**
     * The sources a campaign goes to, and how its segment and left-out tags narrow them.
     *
     * @param array $filters SegmentsRepository::campaign_filters()
     *
     * @return array selection (campaign_selection()) and narrow (for recipients())
     */
    public static function campaign_audience($email_campaign_id, $filters)
    {
        $selection = self::campaign_selection(\MailOptin\Core\Repositories\EmailCampaignRepository::get_merged_customizer_value($email_campaign_id, self::CAMPAIGN_SETTING));

        return [
            'selection' => $selection,
            'narrow'    => [
                'filters' => is_array($filters) ? $filters : [],
                'segment' => absint(\MailOptin\Core\Repositories\EmailCampaignRepository::get_merged_customizer_value($email_campaign_id, \MailOptin\Core\Repositories\SegmentsRepository::SEGMENT_SETTING)) > 0,
            ],
        ];
    }

    /**
     * The people of one source who may get marketing, as SQL selecting email, first_name,
     * last_name and subscriber_id (0 when they aren't a subscriber).
     *
     * @param array $narrow 'filters' for SubscribersRepository::filter_where(), and 'segment'
     *                      (bool): a segment was chosen, so only subscribers can match it
     *
     * @return array [sql, params]
     */
    public static function recipients_sql(AbstractSource $source, $option, $narrow = [])
    {
        list($people, $params) = $source->people_sql($option, self::settings($source->id()));

        // A plugin's tables may use another collation than ours, which MySQL won't compare: the
        // source's emails are converted to our email column's, which also keeps its index usable.
        $people = 'SELECT ' . self::as_our_email('p0.email') . ' AS email, p0.first_name, p0.last_name FROM (' . $people . ') p0';

        $subscribers  = Subscribers::table();
        $consents     = Consents::table();
        $suppressions = SuppressionRepository::table();

        $where = [
            "(c.status IS NULL OR c.status <> 'withdrawn')",
            // Someone who unsubscribed from every list, bounced or complained doesn't get marketing.
            "(s.id IS NULL OR s.status IN ('subscribed', 'pending'))",
            "NOT EXISTS (SELECT 1 FROM $suppressions x WHERE x.email_hash = SHA2(p.email, 256))",
        ];

        $params[] = $source->id();

        if (AbstractSource::MODE_AGREED === self::settings($source->id())['mode']) {
            $where[] = "(c.status = 'agreed' OR s.status = 'subscribed')";
        }

        if ( ! empty($narrow['segment'])) $where[] = 's.id IS NOT NULL';

        if ( ! empty($narrow['filters'])) Subscribers::filter_where((array)$narrow['filters'], $where, $params);

        return [
            "SELECT p.email, p.first_name, p.last_name, COALESCE(s.id, 0) AS subscriber_id
            FROM ($people) p
            LEFT JOIN $subscribers s ON s.email = p.email
            LEFT JOIN $consents c ON c.source = %s AND c.email = " . self::as_our_email('p.email', $consents) . "
            WHERE " . implode(' AND ', $where),
            $params,
        ];
    }

    /**
     * SQL converting an email expression to the character set and collation of our subscribers'
     * email column.
     */
    public static function as_our_email($expression, $table = null)
    {
        global $wpdb;

        static $collations = [];

        $table = $table ?: Subscribers::table();

        if ( ! isset($collations[$table])) {
            $column              = $wpdb->get_row($wpdb->prepare("SHOW FULL COLUMNS FROM $table LIKE %s", 'email'), 'ARRAY_A');
            $collations[$table] = preg_match('/^[a-z0-9_]+$/i', (string)($column['Collation'] ?? '')) ? $column['Collation'] : '';
        }

        $collation = $collations[$table];

        if ('' === $collation) return $expression;

        $charset = strtok($collation, '_');

        return "CONVERT($expression USING $charset) COLLATE $collation";
    }

    /**
     * The next batch of a source's recipients, in email order.
     *
     * @return array rows email, first_name, last_name, subscriber_id
     */
    public static function recipients(AbstractSource $source, $option, $narrow, $after_email, $limit)
    {
        global $wpdb;

        list($sql, $params) = self::recipients_sql($source, $option, $narrow);

        $params[] = (string)$after_email;
        $params[] = absint($limit);

        return $wpdb->get_results($wpdb->prepare("SELECT * FROM ($sql) r WHERE r.email > %s ORDER BY r.email LIMIT %d", $params), 'ARRAY_A') ?: [];
    }

    /**
     * Queue a source's recipients for a campaign in one statement, so a large store is read once
     * rather than in batches that each re-read it. The queue ignores anyone already in it.
     *
     * @return int how many were added
     */
    public static function enqueue($campaign_log_id, $email_campaign_id, AbstractSource $source, $option, $narrow)
    {
        global $wpdb;

        list($sql, $params) = self::recipients_sql($source, $option, $narrow);

        $queue = \MailOptin\Core\Repositories\SendQueueRepository::table();

        array_unshift($params, absint($campaign_log_id), absint($email_campaign_id), ContactRenderer::type($source->id()), 'pending', gmdate('Y-m-d H:i:s'));

        return (int)$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $queue (campaign_log_id, email_campaign_id, email, recipient_type, recipient_id, status, next_attempt_at)
            SELECT %d, %d, r.email, %s, r.subscriber_id, %s, %s FROM ($sql) r WHERE r.email LIKE %s",
            array_merge($params, ['%_@_%'])
        ));
    }

    /**
     * How many different people a campaign's list and sources reach together.
     *
     * @param array $selection campaign_selection()
     * @param array|null $list_part [sql selecting email, params] for the list's recipients, or null
     *
     * @return int
     */
    public static function count_union($selection, $narrow, $list_part = null)
    {
        global $wpdb;

        $parts  = [];
        $params = [];

        if ($list_part) {
            $parts[] = $list_part[0];
            $params  = array_merge($params, $list_part[1]);
        }

        foreach ($selection as $item) {
            $source = self::get($item['source']);

            if ( ! $source) continue;

            list($sql, $source_params) = self::recipients_sql($source, $item['option'], $narrow);

            $parts[] = "SELECT email FROM ($sql) r" . count($parts);
            $params  = array_merge($params, $source_params);
        }

        if (empty($parts)) return 0;

        $sql = 'SELECT COUNT(DISTINCT u.email) FROM (' . implode(' UNION ALL ', $parts) . ') u';
        $sql = empty($params) ? $sql : $wpdb->prepare($sql, $params);

        // Shown on every view of the broadcast page; a large store needn't be counted each time.
        $key   = 'mo_audience_count_' . md5($sql);
        $count = get_transient($key);

        if (false === $count) {
            $count = (int)$wpdb->get_var($sql);
            set_transient($key, $count, MINUTE_IN_SECONDS);
        }

        return (int)$count;
    }

    /**
     * The first name and last name a source has for this address, for emails to people who
     * aren't subscribers.
     *
     * @return array first_name, last_name
     */
    public static function person($source_id, $email)
    {
        global $wpdb;

        $source = self::get($source_id);

        if ( ! $source || ! $source->is_active()) return ['first_name' => '', 'last_name' => ''];

        list($people, $params) = $source->people_sql('', self::settings($source_id), strtolower(trim($email)));

        $row = $wpdb->get_row($wpdb->prepare("SELECT first_name, last_name FROM ($people) p LIMIT 1", $params), 'ARRAY_A');

        return [
            'first_name' => (string)($row['first_name'] ?? ''),
            'last_name'  => (string)($row['last_name'] ?? ''),
        ];
    }

    /**
     * @return Sources
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
