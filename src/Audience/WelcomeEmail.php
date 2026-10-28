<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\Core;
use MailOptin\Core\Logging\CampaignLogRepository;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SendQueueRepository as Queue;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\SendQueue\QueueRunner;
use MailOptin\Core\SendQueue\WpMailSender;

/**
 * A list's welcome email, sent once to each person who joins the list through a form: when they
 * confirm, or straight away on lists without double opt-in. People added from the admin screen
 * don't get it.
 *
 * Each list's welcome email has one campaign log. Its send queue rows are keyed by email, so
 * nobody is welcomed to the same list twice, and the log shows how many were sent.
 */
class WelcomeEmail
{
    public function __construct()
    {
        add_action('mo_subscriber_subscribed', [$this, 'on_subscribed'], 10, 2);
        add_action('mo_subscriber_confirmed', [$this, 'on_confirmed'], 10, 2);
        // Stored welcome emails embed the branding and postal address, so rebuild them when settings change.
        add_action('update_option_' . MAILOPTIN_SETTINGS_DB_OPTION_NAME, [__CLASS__, 'refresh_all']);
    }

    public function on_subscribed($subscriber_id, $list_id)
    {
        self::send($subscriber_id, $list_id);
    }

    public function on_confirmed($subscriber_id, $list_ids = [])
    {
        foreach ((array)$list_ids as $list_id) {
            self::send($subscriber_id, $list_id);
        }
    }

    public static function default_subject($list)
    {
        return sprintf(__('Welcome to %s', 'mailoptin'), $list['name']);
    }

    public static function default_body()
    {
        return __("Hi {{subscriber.first_name|there}},\n\nThanks for joining {{list.name}}. We're glad to have you.\n\nTalk soon,\nThe team", 'mailoptin');
    }

    public static function subject($list)
    {
        return '' !== trim((string)$list['welcome_subject']) ? $list['welcome_subject'] : self::default_subject($list);
    }

    public static function body($list)
    {
        return '' !== trim((string)$list['welcome_body']) ? $list['welcome_body'] : self::default_body();
    }

    /**
     * The message in the branded email frame, with the postal address and an unsubscribe link.
     * Developers can replace the frame with the mo_welcome_email_html filter.
     */
    public static function html($list, $body)
    {
        $address = Subscription::postal_footer();

        $footer = sprintf(
            '%s%s <a href="{{unsubscribe}}">%s</a>',
            '' !== $address ? esc_html($address) . '<br>' : '',
            esc_html(sprintf(__("You're receiving this because you subscribed to %s.", 'mailoptin'), $list['name'])),
            esc_html__('Unsubscribe', 'mailoptin')
        );

        $html = EmailBranding::frame(wpautop(wp_kses_post($body)), $footer);

        return apply_filters('mo_welcome_email_html', $html, $list, $body);
    }

    public static function text($html)
    {
        return \MailOptin\Core\html_to_text($html);
    }

    /**
     * Save the welcome email and refresh its campaign log, so emails still queued use the new content.
     */
    public static function save($list_id, $enabled, $subject, $body)
    {
        ListsRepository::update($list_id, [
            'welcome_email'   => $enabled ? 1 : 0,
            'welcome_subject' => sanitize_text_field($subject),
            'welcome_body'    => wp_kses_post($body),
        ]);

        $list = ListsRepository::get($list_id);

        if ($list && self::existing_log_id($list)) {
            self::write_log_content(self::existing_log_id($list), $list);
        }
    }

    /**
     * Rebuild every list's stored welcome email, after branding or the postal address changes.
     */
    public static function refresh_all()
    {
        foreach (ListsRepository::get_all() as $list) {
            $log_id = self::existing_log_id($list);

            if ($log_id) self::write_log_content($log_id, $list);
        }
    }

    protected static function existing_log_id($list)
    {
        $log_id = absint($list['welcome_campaign_log_id']);

        return $log_id && CampaignLogRepository::instance()->retrieveTitle($log_id) !== null ? $log_id : 0;
    }

    protected static function write_log_content($campaign_log_id, $list)
    {
        global $wpdb;

        $html = self::html($list, self::body($list));
        $text = self::text($html);

        $wpdb->update($wpdb->prefix . Core::campaign_log_table_name, [
            'title'        => self::subject($list),
            'content_html' => $html,
            'content_text' => $text,
        ], ['id' => $campaign_log_id]);

        CampaignRenderer::save_campaign_content($campaign_log_id, $list['id'], $html, $text);
    }

    /**
     * The list's welcome email campaign log, created on first use or if it was deleted from the Campaign Log.
     */
    protected static function campaign_log_id($list)
    {
        global $wpdb;

        $log_id = self::existing_log_id($list);

        if ($log_id) return $log_id;

        $wpdb->insert($wpdb->prefix . Core::campaign_log_table_name, [
            'email_campaign_id' => 0,
            'title'             => self::subject($list),
            'content_html'      => '',
            'content_text'      => '',
            'status'            => QueueRunner::LOG_STATUS_PROCESSED,
            'status_time'       => current_time('mysql'),
        ]);

        $log_id = (int)$wpdb->insert_id;

        ListsRepository::update($list['id'], ['welcome_campaign_log_id' => $log_id]);
        \MailOptin\Core\Repositories\AbstractCampaignLogMeta::update_campaignlog_meta($log_id, 'mo_welcome_list_id', (int)$list['id']);

        self::write_log_content($log_id, $list);

        return $log_id;
    }

    /**
     * @return bool true if the welcome email was queued
     */
    public static function send($subscriber_id, $list_id)
    {
        $list       = ListsRepository::get($list_id);
        $subscriber = Subscribers::get($subscriber_id);

        if ( ! $list || empty($list['welcome_email']) || ! $subscriber) return false;

        if (Subscribers::STATUS_SUBSCRIBED !== $subscriber['status']) return false;

        $log_id = self::campaign_log_id($list);

        $added = Queue::enqueue($log_id, 0, [[
            'email'          => $subscriber['email'],
            'recipient_type' => CampaignRenderer::RECIPIENT_TYPE,
            'recipient_id'   => $subscriber['id'],
        ]]);

        // Already welcomed to this list, for example after unsubscribing and joining again.
        if (0 === $added) return false;

        QueueRunner::get_instance()->start($log_id, 0, []);

        return true;
    }

    /**
     * @return int number sent so far
     */
    public static function sent_count($list)
    {
        $log_id = self::existing_log_id($list);

        return $log_id ? Queue::counts($log_id)[Queue::STATUS_SENT] : 0;
    }

    /**
     * Send the welcome email to an address right away, with sample merge tag values.
     */
    public static function send_test($list_id, $to)
    {
        $list = ListsRepository::get($list_id);

        if ( ! $list || ! is_email($to)) return false;

        $user   = wp_get_current_user();
        $sample = ['email' => $to, 'first_name' => $user->first_name ?: $user->display_name, 'last_name' => $user->last_name];

        $tags = [
            '{{list.name}}'             => $list['name'],
            '{{unsubscribe}}'           => '#',
            '{{webversion}}'            => '#',
            '{{subscriber.email}}'      => $sample['email'],
            '{{subscriber.first_name}}' => $sample['first_name'],
            '{{subscriber.last_name}}'  => $sample['last_name'],
        ];

        $html    = CampaignRenderer::replace_tags(CampaignRenderer::replace_fallback_tags(self::html($list, self::body($list)), $sample, true), $tags, true);
        $subject = CampaignRenderer::replace_tags(CampaignRenderer::replace_fallback_tags(self::subject($list), $sample, false), $tags, false);
        $text    = self::text($html);

        $add_plain_text = function ($phpmailer) use ($text) {
            $phpmailer->AltBody = $text;
        };

        add_action('phpmailer_init', $add_plain_text);
        $sent = wp_mail($to, sprintf(__('[Test] %s', 'mailoptin'), $subject), $html, WpMailSender::default_headers());
        remove_action('phpmailer_init', $add_plain_text);

        return $sent;
    }

    /**
     * @return WelcomeEmail
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
