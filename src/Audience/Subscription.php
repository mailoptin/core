<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\PluginSettings\Settings;
use MailOptin\Core\Repositories\CustomFieldsRepository;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\Repositories\TagsRepository;
use MailOptin\Core\SendQueue\WpMailSender;

/**
 * Subscribe, confirm and unsubscribe for built-in lists.
 */
class Subscription
{
    const RESULT_SUBSCRIBED = 'subscribed';
    const RESULT_PENDING = 'pending';
    const RESULT_ALREADY_SUBSCRIBED = 'already_subscribed';
    // Bounced or complained: nothing is sent, but the form still thanks them.
    const RESULT_REFUSED = 'refused';

    /**
     * @param string $email
     * @param int $list_id
     * @param array $context first_name, last_name, source, source_optin_id, ip_address, consent_text, meta,
     *                       fields (custom field merge key => value), tags (tag IDs or names)
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

        // The wording and how it was agreed to describe one signup, so they're always replaced together.
        if (isset($context['consent_method'])) {
            $consent['consent_text']   = '' === (string)($context['consent_text'] ?? '') ? null : $context['consent_text'];
            $consent['consent_method'] = $context['consent_method'];
        }

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
            // Anyone can submit a form with someone else's address, so a signup only fills in what's
            // missing. The full details of every signup are kept on its timeline event.
            $missing = array_filter($names, function ($value, $field) use ($subscriber) {
                return '' === trim((string)$subscriber[$field]);
            }, ARRAY_FILTER_USE_BOTH);

            if (empty($subscriber['consent_at'])) $missing = array_merge($missing, $consent);

            if ( ! empty($missing)) Subscribers::update($subscriber['id'], $missing);
        }

        $subscriber_id = (int)$subscriber['id'];

        $extra = self::apply_fields_and_tags($subscriber_id, (array)($context['fields'] ?? []), (array)($context['tags'] ?? []));

        Subscribers::log_event($subscriber_id, Subscribers::EVENT_SIGNUP, array_merge(['list_id' => (int)$list_id], $names, $consent, $extra, (array)($context['meta'] ?? [])));

        $membership = Subscribers::get_membership($subscriber_id, $list_id);

        if ($membership && Subscribers::STATUS_SUBSCRIBED === $membership['status'] && Subscribers::STATUS_SUBSCRIBED === $subscriber['status']) {
            return ['subscriber_id' => $subscriber_id, 'result' => self::RESULT_ALREADY_SUBSCRIBED];
        }

        $suppression = SuppressionRepository::get_reason($email);

        // Bounced and complained addresses are never emailed, not even to confirm.
        if (
            in_array($suppression, [SuppressionRepository::REASON_BOUNCED, SuppressionRepository::REASON_COMPLAINED], true) ||
            in_array($subscriber['status'], [Subscribers::STATUS_BOUNCED, Subscribers::STATUS_COMPLAINED], true)
        ) {
            return ['subscriber_id' => $subscriber_id, 'result' => self::RESULT_REFUSED];
        }

        // An address that already confirmed and is receiving email doesn't need to confirm again for another list.
        $verified = ! empty($subscriber['confirmed_at']) && Subscribers::STATUS_SUBSCRIBED === $subscriber['status'];

        // Someone who unsubscribed must confirm by email to come back, even on a single opt-in list,
        // otherwise anyone could resubscribe them by submitting a form with their address.
        $opted_out = $suppression || ($membership && Subscribers::STATUS_UNSUBSCRIBED === $membership['status']);

        if (( ! $list['double_optin'] || $verified) && ! $opted_out) {

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

        self::send_confirmation($subscriber_id, self::request_ip());

        return ['subscriber_id' => $subscriber_id, 'result' => self::RESULT_PENDING];
    }

    /**
     * Custom field values and tags from a signup. Like names, field values only fill in what's
     * missing, since anyone can submit a form with someone else's address.
     *
     * @return array for the signup event: 'fields' => [merge key => value as submitted], 'tags' => names added
     */
    protected static function apply_fields_and_tags($subscriber_id, $fields, $tags)
    {
        if ( ! TagsRepository::is_ready() || (empty($fields) && empty($tags))) return [];

        $extra = [];

        if ( ! empty($fields)) {
            $result = CustomFieldsRepository::set_values($subscriber_id, $fields, true);
            $extra['fields'] = array_filter($fields, function ($value) {
                return '' !== $value && [] !== $value;
            });
            if ( ! empty($result['errors'])) $extra['field_errors'] = array_values($result['errors']);
        }

        if ( ! empty($tags)) {
            $tag_ids = TagsRepository::ensure($tags);
            TagsRepository::add_to_subscribers($tag_ids, [$subscriber_id]);
            if ( ! empty($tag_ids)) $extra['tags'] = array_values(array_intersect_key(TagsRepository::names(), array_flip($tag_ids)));
        }

        return array_filter($extra);
    }

    /**
     * The address the request came from, for rate limits. Unlike the IP stored with the consent record,
     * it ignores forwarding headers, which a client can set to anything.
     */
    public static function request_ip()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

        return (string)apply_filters('mo_confirmation_rate_limit_ip', filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '');
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
            $subscriber_id = Subscribers::create(array_merge(['email' => $email, 'source' => 'admin', 'consent_at' => Subscribers::now(), 'consent_method' => Consent::METHOD_ADMIN], $names));

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
     * The double opt-in email for a subscriber's pending lists.
     *
     * @return array|null subject, html, text and lists (names); null when no list is pending
     */
    public static function confirmation_email($subscriber)
    {
        $list_names = [];
        foreach (Subscribers::get_memberships($subscriber['id']) as $membership) {
            if (Subscribers::STATUS_PENDING !== $membership['status']) continue;

            $list = ListsRepository::get($membership['list_id']);
            if ($list) $list_names[] = $list['name'];
        }

        if (empty($list_names)) return null;

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

        $html = EmailBranding::frame(
            sprintf('<p>%s</p><p>%s</p>', esc_html($greeting), esc_html($intro)) .
            EmailBranding::button($confirm_url, $button) .
            sprintf('<p style="color:#646970;font-size:13px">%s</p>', esc_html($ignore)),
            esc_html($footer)
        );

        $text = implode("\n\n", array_filter([$greeting, $intro, $button . ': ' . $confirm_url, $ignore, $footer]));

        $html = apply_filters('mo_confirmation_email_html', $html, $subscriber, $confirm_url);
        $text = apply_filters('mo_confirmation_email_text', $text, $subscriber, $confirm_url);

        return ['subject' => $subject, 'html' => $html, 'text' => $text, 'lists' => $list_names];
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

        $email = self::confirmation_email($subscriber);

        if ( ! $email) return false;

        list($subject, $html, $text, $list_names) = [$email['subject'], $email['html'], $email['text'], $email['lists']];

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

        $pending_list_ids = [];
        foreach (Subscribers::get_memberships($subscriber_id) as $membership) {
            if (Subscribers::STATUS_PENDING === $membership['status']) $pending_list_ids[] = (int)$membership['list_id'];
        }

        if (empty($pending_list_ids)) return true;

        // Clicking the link proves the inbox works and they want email again.
        if (Subscribers::STATUS_SUBSCRIBED !== $subscriber['status']) {
            Subscribers::update($subscriber_id, ['status' => Subscribers::STATUS_PENDING]);
        }

        // Clicking proves they want email again, but not that an address that bounced or complained is safe.
        if (SuppressionRepository::REASON_UNSUBSCRIBED === SuppressionRepository::get_reason($subscriber['email'])) {
            SuppressionRepository::remove($subscriber['email']);
        }

        Subscribers::confirm($subscriber_id);
        Subscribers::log_event($subscriber_id, Subscribers::EVENT_CONFIRMED);

        do_action('mo_subscriber_confirmed', (int)$subscriber_id, $pending_list_ids);

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

        // A link for a list that has since been deleted can't stop that list, so it stops everything.
        if ($list_id && ! ListsRepository::get($list_id)) $list_id = 0;

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
        // Fresh, not Settings::instance(), so an address saved earlier in the same request is used.
        $settings = new Settings();

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
