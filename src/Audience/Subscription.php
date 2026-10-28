<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\PluginSettings\Settings;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\SendQueue\WpMailSender;

/**
 * Subscribe, confirm and unsubscribe for built-in lists.
 */
class Subscription
{
    const RESULT_SUBSCRIBED = 'subscribed';
    const RESULT_PENDING = 'pending';
    const RESULT_ALREADY_SUBSCRIBED = 'already_subscribed';

    /**
     * @param string $email
     * @param int $list_id
     * @param array $context first_name, last_name, source, source_optin_id, ip_address, consent_text, meta
     *
     * @return array|\WP_Error ['subscriber_id' => int, 'result' => one of the RESULT_* constants]
     */
    public static function subscribe($email, $list_id, $context = [])
    {
        $list = ListsRepository::get($list_id);

        if ( ! $list) {
            return new \WP_Error('mo_list_not_found', sprintf('List %d does not exist.', $list_id));
        }

        $email = Subscribers::normalize_email($email);

        if ( ! is_email($email)) {
            return new \WP_Error('mo_invalid_email', 'Email address is not valid.');
        }

        $consent = array_filter([
            'source'          => $context['source'] ?? '',
            'source_optin_id' => absint($context['source_optin_id'] ?? 0),
            'ip_address'      => $context['ip_address'] ?? '',
            'consent_text'    => $context['consent_text'] ?? null,
            'consent_at'      => Subscribers::now(),
        ]);

        $names = array_filter([
            'first_name' => sanitize_text_field($context['first_name'] ?? ''),
            'last_name'  => sanitize_text_field($context['last_name'] ?? ''),
        ]);

        $subscriber = Subscribers::get_by_email($email);

        if ( ! $subscriber) {
            $subscriber_id = Subscribers::create(array_merge(['email' => $email], $names, $consent));

            if ( ! $subscriber_id) {
                return new \WP_Error('mo_subscriber_not_saved', 'Subscriber could not be saved.');
            }

            $subscriber = Subscribers::get($subscriber_id);
        } else {
            Subscribers::update($subscriber['id'], array_merge($names, $consent));
        }

        $subscriber_id = (int)$subscriber['id'];

        Subscribers::log_event($subscriber_id, Subscribers::EVENT_SIGNUP, array_merge(['list_id' => (int)$list_id], (array)($context['meta'] ?? [])));

        $membership = Subscribers::get_membership($subscriber_id, $list_id);

        if ($membership && Subscribers::STATUS_SUBSCRIBED === $membership['status'] && Subscribers::STATUS_SUBSCRIBED === $subscriber['status']) {
            return ['subscriber_id' => $subscriber_id, 'result' => self::RESULT_ALREADY_SUBSCRIBED];
        }

        // An address that already confirmed and is receiving email doesn't need to confirm again for another list.
        $verified = ! empty($subscriber['confirmed_at']) && Subscribers::STATUS_SUBSCRIBED === $subscriber['status'];

        if ( ! $list['double_optin'] || $verified) {

            if (SuppressionRepository::REASON_UNSUBSCRIBED === SuppressionRepository::get_reason($email)) {
                SuppressionRepository::remove($email);
            }

            Subscribers::set_membership($subscriber_id, $list_id, Subscribers::STATUS_SUBSCRIBED);
            Subscribers::sync_status($subscriber_id);

            do_action('mo_subscriber_subscribed', $subscriber_id, (int)$list_id);

            return ['subscriber_id' => $subscriber_id, 'result' => self::RESULT_SUBSCRIBED];
        }

        // Signing up again is fresh consent; they confirm by clicking the link.
        if (Subscribers::STATUS_SUBSCRIBED !== $subscriber['status']) {
            Subscribers::update($subscriber_id, ['status' => Subscribers::STATUS_PENDING]);
        }

        Subscribers::set_membership($subscriber_id, $list_id, Subscribers::STATUS_PENDING);
        Subscribers::sync_status($subscriber_id);

        self::send_confirmation($subscriber_id, $context['ip_address'] ?? '');

        return ['subscriber_id' => $subscriber_id, 'result' => self::RESULT_PENDING];
    }

    const ADMIN_ADDED = 'added';
    const ADMIN_CONFIRMATION_SENT = 'confirmation_sent';
    const ADMIN_REFUSED_UNSUBSCRIBED = 'refused_unsubscribed';

    /**
     * Add someone from the admin screen.
     *
     * @param string $email
     * @param int[] $list_ids
     * @param array $names first_name, last_name
     * @param bool $has_consent true when the admin confirms they have permission; otherwise a confirmation email is sent
     *
     * @return string|\WP_Error one of the ADMIN_* constants
     */
    public static function add_by_admin($email, $list_ids, $names = [], $has_consent = false)
    {
        $email    = Subscribers::normalize_email($email);
        $list_ids = array_filter(array_map('absint', (array)$list_ids));

        if ( ! is_email($email)) return new \WP_Error('mo_invalid_email', __('Enter a valid email address.', 'mailoptin'));

        if (empty($list_ids)) return new \WP_Error('mo_no_list', __('Choose at least one list.', 'mailoptin'));

        // Someone who unsubscribed has to sign up again themselves.
        if (SuppressionRepository::is_suppressed($email)) return self::ADMIN_REFUSED_UNSUBSCRIBED;

        $names = array_filter([
            'first_name' => sanitize_text_field($names['first_name'] ?? ''),
            'last_name'  => sanitize_text_field($names['last_name'] ?? ''),
        ]);

        $subscriber = Subscribers::get_by_email($email);

        if ( ! $subscriber) {
            $subscriber_id = Subscribers::create(array_merge(['email' => $email, 'source' => 'admin', 'consent_at' => Subscribers::now()], $names));

            if ( ! $subscriber_id) return new \WP_Error('mo_subscriber_not_saved', __('The subscriber could not be saved.', 'mailoptin'));
        } else {
            $subscriber_id = (int)$subscriber['id'];
            if ( ! empty($names)) Subscribers::update($subscriber_id, $names);
        }

        $status = $has_consent ? Subscribers::STATUS_SUBSCRIBED : Subscribers::STATUS_PENDING;

        foreach ($list_ids as $list_id) {
            if ( ! ListsRepository::get($list_id)) continue;

            $membership = Subscribers::get_membership($subscriber_id, $list_id);
            if ($membership && Subscribers::STATUS_SUBSCRIBED === $membership['status']) continue;

            Subscribers::set_membership($subscriber_id, $list_id, $status);
        }

        Subscribers::sync_status($subscriber_id);
        Subscribers::log_event($subscriber_id, Subscribers::EVENT_SIGNUP, ['added_by_admin' => get_current_user_id(), 'list_ids' => array_values($list_ids)]);

        if ($has_consent) return self::ADMIN_ADDED;

        self::send_confirmation($subscriber_id, '', true);

        return self::ADMIN_CONFIRMATION_SENT;
    }

    /**
     * Send the double opt-in email, within the limits in ConfirmationThrottle.
     *
     * @param int $subscriber_id
     * @param string $ip_address IP of the signup; empty for an admin resending from the subscriber's profile
     * @param bool $by_admin an admin resend skips the limits
     *
     * @return bool true if an email was sent
     */
    public static function send_confirmation($subscriber_id, $ip_address = '', $by_admin = false)
    {
        $subscriber = Subscribers::get($subscriber_id);

        if ( ! $subscriber) return false;

        if ( ! $by_admin) {
            $allowed = ConfirmationThrottle::check($subscriber_id, $ip_address);

            if (true !== $allowed) {
                // The cooldown is routine (a double click); the other limits are worth a note on the timeline.
                if (ConfirmationThrottle::REASON_COOLDOWN !== $allowed) {
                    Subscribers::log_event($subscriber_id, Subscribers::EVENT_CONFIRMATION_THROTTLED, ['reason' => $allowed]);
                }

                return false;
            }
        }

        $list_names = [];
        foreach (Subscribers::get_memberships($subscriber_id) as $membership) {
            if (Subscribers::STATUS_PENDING !== $membership['status']) continue;

            $list = ListsRepository::get($membership['list_id']);
            if ($list) $list_names[] = $list['name'];
        }

        if (empty($list_names)) return false;

        $site_name   = Settings::instance()->company_name() ?: get_bloginfo('name');
        $confirm_url = Tokens::confirm_url($subscriber);
        $lists       = implode(', ', $list_names);

        $subject = apply_filters('mo_confirmation_email_subject', __('Please confirm your subscription', 'mailoptin'), $subscriber);

        $greeting = empty($subscriber['first_name'])
            ? __('Hi,', 'mailoptin')
            : sprintf(__('Hi %s,', 'mailoptin'), $subscriber['first_name']);

        $intro  = sprintf(__('Click below to confirm you want to receive %1$s from %2$s.', 'mailoptin'), $lists, $site_name);
        $button = __('Yes, subscribe me', 'mailoptin');
        $ignore = __("If you didn't sign up, ignore this email and you won't hear from us.", 'mailoptin');
        $footer = self::postal_footer();

        $html = sprintf(
            '<p>%s</p><p>%s</p><p><a href="%s" style="display:inline-block;padding:10px 18px;background:#2271b1;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:600;">%s</a></p><p style="color:#646970;font-size:13px;">%s</p>%s',
            esc_html($greeting),
            esc_html($intro),
            esc_url($confirm_url),
            esc_html($button),
            esc_html($ignore),
            $footer ? '<p style="color:#646970;font-size:12px;">' . esc_html($footer) . '</p>' : ''
        );

        $text = implode("\n\n", array_filter([$greeting, $intro, $button . ': ' . $confirm_url, $ignore, $footer]));

        $html = apply_filters('mo_confirmation_email_html', $html, $subscriber, $confirm_url);
        $text = apply_filters('mo_confirmation_email_text', $text, $subscriber, $confirm_url);

        $add_plain_text = function ($phpmailer) use ($text) {
            $phpmailer->AltBody = $text;
        };

        add_action('phpmailer_init', $add_plain_text);
        $sent = wp_mail($subscriber['email'], $subject, $html, WpMailSender::default_headers());
        remove_action('phpmailer_init', $add_plain_text);

        if ($sent) {
            Subscribers::log_event($subscriber_id, Subscribers::EVENT_CONFIRMATION_SENT, ['lists' => $list_names]);

            if ( ! $by_admin) ConfirmationThrottle::record_ip($ip_address);
        }

        return $sent;
    }

    /**
     * @param int $subscriber_id
     *
     * @return bool
     */
    public static function confirm($subscriber_id)
    {
        $subscriber = Subscribers::get($subscriber_id);

        if ( ! $subscriber) return false;

        $had_pending = in_array(Subscribers::STATUS_PENDING, wp_list_pluck(Subscribers::get_memberships($subscriber_id), 'status'), true);

        if ( ! $had_pending) return true;

        // Clicking the link proves the inbox works and they want email again.
        if (Subscribers::STATUS_SUBSCRIBED !== $subscriber['status']) {
            Subscribers::update($subscriber_id, ['status' => Subscribers::STATUS_PENDING]);
        }

        SuppressionRepository::remove($subscriber['email']);

        Subscribers::confirm($subscriber_id);
        Subscribers::log_event($subscriber_id, Subscribers::EVENT_CONFIRMED);

        do_action('mo_subscriber_confirmed', (int)$subscriber_id);

        return true;
    }

    /**
     * @param int $subscriber_id
     * @param int $list_id 0 for every list
     * @param int $campaign_log_id campaign whose link was clicked; its pending email to them is cancelled
     *
     * @return bool
     */
    public static function unsubscribe($subscriber_id, $list_id = 0, $campaign_log_id = 0)
    {
        $subscriber = Subscribers::get($subscriber_id);

        if ( ! $subscriber) return false;

        foreach (Subscribers::get_memberships($subscriber_id) as $membership) {
            if ($list_id && (int)$membership['list_id'] !== (int)$list_id) continue;

            Subscribers::set_membership($subscriber_id, $membership['list_id'], Subscribers::STATUS_UNSUBSCRIBED);
        }

        $status = Subscribers::sync_status($subscriber_id);

        if (Subscribers::STATUS_UNSUBSCRIBED === $status) {
            SuppressionRepository::add($subscriber['email'], SuppressionRepository::REASON_UNSUBSCRIBED, 'unsubscribe_link');
        }

        if ($campaign_log_id) {
            SendQueueRepository::skip_unsent($campaign_log_id, $subscriber['email'], __('Unsubscribed while the campaign was sending.', 'mailoptin'));
        }

        Subscribers::log_event($subscriber_id, Subscribers::EVENT_UNSUBSCRIBED, ['list_id' => (int)$list_id], (int)$campaign_log_id);

        do_action('mo_subscriber_unsubscribed', (int)$subscriber_id, (int)$list_id, (int)$campaign_log_id);

        return true;
    }

    /**
     * The sender's postal address, required in marketing email by anti-spam law.
     */
    public static function postal_footer()
    {
        $settings = Settings::instance();

        return implode(', ', array_filter([
            $settings->company_name(),
            $settings->company_address(),
            $settings->company_address_2(),
            $settings->company_city(),
            $settings->company_state(),
            $settings->company_zip(),
        ]));
    }
}
