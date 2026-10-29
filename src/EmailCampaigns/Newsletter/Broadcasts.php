<?php

namespace MailOptin\Core\EmailCampaigns\Newsletter;

use MailOptin\Core\Admin\Customizer\EmailCampaign\NewsletterTemplatePreview;
use MailOptin\Core\Audience\CampaignRenderer;
use MailOptin\Core\Core;
use MailOptin\Core\EmailCampaigns\Misc;
use MailOptin\Core\PluginSettings\Settings;
use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\SegmentsRepository;
use MailOptin\Core\Repositories\SubscribersRepository;
use MailOptin\Core\SendQueue\QueueRunner;

/**
 * Broadcasts (newsletter email campaigns) as the broadcast page sees them: their status, a
 * scheduled send, test emails and the checks before sending.
 */
class Broadcasts
{
    const SCHEDULE_META = 'mo_broadcast_scheduled_at';
    const TEST_META = 'mo_broadcast_test_sent_at';
    const ERROR_META = 'mo_broadcast_error';
    const CRON_HOOK = 'mo_broadcast_send';

    const STATUS_DRAFT = 'draft';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_SENDING = 'sending';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';

    public function __construct()
    {
        add_action(self::CRON_HOOK, [$this, 'run_scheduled']);
    }

    /* ---------------------------------------------------------------------------------------------
     * Settings the broadcast page edits. They live with the customizer's, so both stay in step.
     * ------------------------------------------------------------------------------------------ */

    public static function get($email_campaign_id, $key)
    {
        return ER::get_merged_customizer_value($email_campaign_id, $key);
    }

    /**
     * @param array $values setting name => value
     */
    public static function save($email_campaign_id, $values)
    {
        $settings = ER::get_settings();

        foreach ($values as $key => $value) {
            $settings[$email_campaign_id][$key] = $value;
        }

        ER::updateSettings($settings);
    }

    public static function subject($email_campaign_id)
    {
        return (string)self::get($email_campaign_id, 'email_campaign_title');
    }

    /* ---------------------------------------------------------------------------------------------
     * Status
     * ------------------------------------------------------------------------------------------ */

    /**
     * The newest campaign log of a broadcast: its last send.
     *
     * @return array|null
     */
    public static function last_log($email_campaign_id)
    {
        global $wpdb;

        $table = $wpdb->prefix . Core::campaign_log_table_name;

        return $wpdb->get_row($wpdb->prepare("SELECT id, title, status, status_time FROM $table WHERE email_campaign_id = %d ORDER BY id DESC LIMIT 1", $email_campaign_id), 'ARRAY_A');
    }

    /**
     * @return array status (one of the STATUS_ constants), log (the last send, if any), scheduled_at (UTC, if scheduled)
     */
    public static function status($email_campaign_id)
    {
        $scheduled = self::scheduled_at($email_campaign_id);
        $log       = self::last_log($email_campaign_id);

        if ($scheduled) return ['status' => self::STATUS_SCHEDULED, 'log' => $log, 'scheduled_at' => $scheduled];

        if ( ! $log) return ['status' => self::STATUS_DRAFT, 'log' => null, 'scheduled_at' => null];

        $map = [
            QueueRunner::LOG_STATUS_SENDING => self::STATUS_SENDING,
            QueueRunner::LOG_STATUS_PAUSED  => self::STATUS_SENDING,
            'failed'                        => self::STATUS_FAILED,
        ];

        return ['status' => $map[$log['status']] ?? self::STATUS_SENT, 'log' => $log, 'scheduled_at' => null];
    }

    /* ---------------------------------------------------------------------------------------------
     * Scheduling
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return string|null UTC datetime
     */
    public static function scheduled_at($email_campaign_id)
    {
        $value = EmailCampaignMeta::get_meta_data($email_campaign_id, self::SCHEDULE_META);

        return $value ? (string)$value : null;
    }

    /**
     * @param int $timestamp when to send, as a Unix timestamp
     *
     * @return true|\WP_Error
     */
    public static function schedule($email_campaign_id, $timestamp)
    {
        if ($timestamp < time() + MINUTE_IN_SECONDS) {
            return new \WP_Error('mo_broadcast_past', __('Choose a time in the future.', 'mailoptin'));
        }

        self::cancel($email_campaign_id);

        wp_schedule_single_event($timestamp, self::CRON_HOOK, [absint($email_campaign_id)]);
        EmailCampaignMeta::update_meta_data($email_campaign_id, self::SCHEDULE_META, gmdate('Y-m-d H:i:s', $timestamp));
        EmailCampaignMeta::delete_meta_data($email_campaign_id, self::ERROR_META);

        return true;
    }

    public static function cancel($email_campaign_id)
    {
        wp_clear_scheduled_hook(self::CRON_HOOK, [absint($email_campaign_id)]);
        EmailCampaignMeta::delete_meta_data($email_campaign_id, self::SCHEDULE_META);
    }

    /**
     * Send a scheduled broadcast. The schedule is cleared first, so a cron run that overlaps or
     * repeats can't send it twice.
     */
    public function run_scheduled($email_campaign_id)
    {
        $email_campaign_id = absint($email_campaign_id);

        if ( ! self::scheduled_at($email_campaign_id) || ! ER::get_email_campaign_by_id($email_campaign_id)) return;

        EmailCampaignMeta::delete_meta_data($email_campaign_id, self::SCHEDULE_META);

        $result = Newsletter::get_instance()->dispatch($email_campaign_id);

        if ( ! $result['success']) EmailCampaignMeta::update_meta_data($email_campaign_id, self::ERROR_META, $result['error']);
    }

    /* ---------------------------------------------------------------------------------------------
     * Before sending
     * ------------------------------------------------------------------------------------------ */

    /**
     * How many people a broadcast to a built-in list goes to now.
     *
     * @return int|\WP_Error|null null for other connections, which count their own lists
     */
    public static function recipients_count($email_campaign_id)
    {
        if ('MailOptinConnect' !== self::get($email_campaign_id, 'connection_service')) return null;

        $list_id = absint(self::get($email_campaign_id, 'connection_email_list'));

        if ( ! $list_id) return 0;

        $filters = SegmentsRepository::campaign_filters($email_campaign_id);

        if (is_wp_error($filters)) return $filters;

        return SubscribersRepository::count_list_recipients($list_id, $filters);
    }

    /**
     * @return array checks, each label, ok (bool) and blocking (whether it stops sending)
     */
    public static function checks($email_campaign_id)
    {
        $count = self::recipients_count($email_campaign_id);
        $html  = (string)(new NewsletterTemplatePreview($email_campaign_id))->forge();

        $checks = [
            ['label' => __('Subject', 'mailoptin'), 'ok' => '' !== trim(self::subject($email_campaign_id)), 'blocking' => true],
            ['label' => __('Sender address', 'mailoptin'), 'ok' => is_email(Settings::instance()->from_email()), 'blocking' => true],
            ['label' => __('Who it goes to', 'mailoptin'), 'ok' => self::has_recipients_set($email_campaign_id), 'blocking' => true],
        ];

        if (null !== $count) {
            $checks[] = [
                'label'    => is_wp_error($count) ? $count->get_error_message() : sprintf(_n('Audience: %s person', 'Audience: %s people', $count, 'mailoptin'), number_format_i18n($count)),
                'ok'       => ! is_wp_error($count) && $count > 0,
                'blocking' => true,
            ];
        }

        $checks[] = ['label' => __('Unsubscribe link in the design', 'mailoptin'), 'ok' => false !== strpos($html, '{{unsubscribe}}'), 'blocking' => false];
        $checks[] = ['label' => __('Test email sent', 'mailoptin'), 'ok' => (bool)EmailCampaignMeta::get_meta_data($email_campaign_id, self::TEST_META), 'blocking' => false];

        return $checks;
    }

    /**
     * A connection, and a list when the connection has lists (WordPress users, for one, doesn't).
     */
    protected static function has_recipients_set($email_campaign_id)
    {
        $connection = (string)self::get($email_campaign_id, 'connection_service');

        if ('' === $connection) return false;

        return '' !== (string)self::get($email_campaign_id, 'connection_email_list') || empty(\MailOptin\Core\Repositories\ConnectionsRepository::connection_email_list($connection));
    }

    public static function can_send($email_campaign_id)
    {
        foreach (self::checks($email_campaign_id) as $check) {
            if ($check['blocking'] && ! $check['ok']) return false;
        }

        return true;
    }

    /**
     * Send a test of the broadcast. Merge tags use the subscriber with that address, if there is
     * one, or their fallbacks; links that only work for real recipients point nowhere.
     *
     * @return bool
     */
    public static function send_test($email_campaign_id, $email)
    {
        if ( ! is_email($email)) return false;

        $subscriber = SubscribersRepository::get_by_email($email) ?: [];
        $subject    = Misc::parse_email_subject(self::subject($email_campaign_id));
        $html       = (string)(new NewsletterTemplatePreview($email_campaign_id))->forge();
        $blank      = ['{{unsubscribe}}' => '#', '{{webversion}}' => '#', '{{preferences}}' => '#', '{{list.name}}' => ''];

        $html    = CampaignRenderer::replace_tags(CampaignRenderer::replace_fallback_tags($html, $subscriber, true), $blank + self::subscriber_tags($subscriber), true);
        $subject = CampaignRenderer::replace_tags(CampaignRenderer::replace_fallback_tags($subject, $subscriber, false), self::subscriber_tags($subscriber), false);

        $settings = Settings::instance();
        $sent     = wp_mail($email, sprintf(__('[Test] %s', 'mailoptin'), $subject), $html, ['Content-Type: text/html', sprintf('From: %s <%s>', $settings->from_name(), $settings->from_email())]);

        if ($sent) EmailCampaignMeta::update_meta_data($email_campaign_id, self::TEST_META, gmdate('Y-m-d H:i:s'));

        return (bool)$sent;
    }

    protected static function subscriber_tags($subscriber)
    {
        return [
            '{{subscriber.email}}'      => $subscriber['email'] ?? '',
            '{{subscriber.first_name}}' => $subscriber['first_name'] ?? '',
            '{{subscriber.last_name}}'  => $subscriber['last_name'] ?? '',
        ];
    }

    /**
     * @return Broadcasts
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
