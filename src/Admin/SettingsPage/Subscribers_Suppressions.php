<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Admin\UI;
use MailOptin\Core\Audience\DeliveryEvents;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository as Suppressions;

/**
 * Subscribers > Suppression list: who MailOptin won't email, checking an address, adding addresses
 * by hand and lifting a block. On the free plan.
 */
class Subscribers_Suppressions
{
    const TAB = 'suppressions';

    /** Lines read from one "Add addresses" submission. */
    const MAX_LINES = 5000;

    /** Invalid lines listed back after adding. */
    const MAX_INVALID_SHOWN = 50;

    public static function url($args = [])
    {
        return add_query_arg($args, Subscribers_Page::page_url(self::TAB));
    }

    public static function lift_url($hash)
    {
        return self::url(['lift' => $hash]);
    }

    public static function reason_labels()
    {
        return [
            Suppressions::REASON_UNSUBSCRIBED => __('Unsubscribed', 'mailoptin'),
            Suppressions::REASON_BOUNCED      => __('Bounced', 'mailoptin'),
            Suppressions::REASON_COMPLAINED   => __('Marked as spam', 'mailoptin'),
            Suppressions::REASON_MANUAL       => __('Added by hand', 'mailoptin'),
            Suppressions::REASON_ERASED       => __('Erased', 'mailoptin'),
        ];
    }

    public static function reason_badge($reason)
    {
        $tones = [
            Suppressions::REASON_BOUNCED    => 'bad',
            Suppressions::REASON_COMPLAINED => 'bad',
            Suppressions::REASON_MANUAL     => 'warn',
        ];

        return UI::badge(self::reason_labels()[$reason] ?? $reason, $tones[$reason] ?? '');
    }

    public static function source_label($source)
    {
        $labels = [
            'unsubscribe_link'        => __('Unsubscribe link or preference centre', 'mailoptin'),
            'resubscribe'             => __('An earlier unsubscribe', 'mailoptin'),
            'subscriber_status'       => __('A bounce or spam report on the subscriber', 'mailoptin'),
            'mo_leadbank_unsubscribe' => __('MailOptin Leads unsubscribe (older version)', 'mailoptin'),
            'mo_wp_user_unsubscribe'  => __('WordPress users unsubscribe (older version)', 'mailoptin'),
            Suppressions::SOURCE_ADMIN => __('An admin', 'mailoptin'),
        ];

        if (isset($labels[$source])) return $labels[$source];

        return '' === (string)$source ? '—' : Subscribers_Page::provider_name($source);
    }

    public static function local_date($utc)
    {
        return date_i18n(get_option('date_format'), strtotime(get_date_from_gmt($utc)));
    }

    /**
     * An unsubscribe is the person's own choice: only they lift it, by signing up again and confirming.
     * An erasure marker keeps an erased person from coming back through an old import.
     */
    public static function can_lift($reason)
    {
        return ! in_array($reason, [Suppressions::REASON_UNSUBSCRIBED, Suppressions::REASON_ERASED], true);
    }

    /** Lifting these warns first and needs the "I've checked" box: sending again has a real cost. */
    protected static function needs_check($reason)
    {
        return in_array($reason, [Suppressions::REASON_BOUNCED, Suppressions::REASON_COMPLAINED], true);
    }

    /* ---------------------------------------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return array|false [notice, url] when handled
     */
    public static function handle_action($action)
    {
        switch ($action) {
            case 'suppress_add':
                check_admin_referer('mo_suppress_add');
                $result = self::add_by_hand(wp_unslash((string)($_POST['addresses'] ?? '')));
                set_transient(self::result_key(), $result, 10 * MINUTE_IN_SECONDS);

                // The result shows as a note on the screen, invalid lines included.
                return ['', self::url(['added' => 1])];

            case 'suppression_lift':
                $hash = self::valid_hash($_POST['hash'] ?? '');
                check_admin_referer('mo_suppression_lift_' . $hash);
                $notice = self::lift($hash, ! empty($_POST['checked']));

                if ('lift_unchecked' === $notice) return [$notice, self::lift_url($hash)];

                return [$notice, self::url()];
        }

        return false;
    }

    protected static function result_key()
    {
        return 'mo_suppress_add_' . get_current_user_id();
    }

    public static function valid_hash($hash)
    {
        $hash = strtolower((string)$hash);

        return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
    }

    /**
     * One address per line; commas, semicolons and tabs also separate, so a pasted spreadsheet
     * column or a comma list works. Quotes and angle brackets around an address are dropped.
     *
     * @return array added, already, unsubscribed (subscribers taken off their lists), invalid (lines)
     */
    public static function add_by_hand($text)
    {
        $result = ['added' => 0, 'already' => 0, 'unsubscribed' => 0, 'invalid' => [], 'invalid_count' => 0];
        // Cut the text first so a huge paste isn't split in full before the line limit applies.
        $lines  = array_slice(preg_split('/[\r\n,;\t]+/', substr($text, 0, self::MAX_LINES * 100)), 0, self::MAX_LINES);
        $seen   = [];

        foreach ($lines as $line) {
            $line = trim($line, " \t\"'<>");
            if ('' === $line) continue;

            $email = Subscribers::normalize_email($line);

            if ( ! is_email($email)) {
                $result['invalid_count']++;
                if (count($result['invalid']) < self::MAX_INVALID_SHOWN) $result['invalid'][] = $line;
                continue;
            }

            if (isset($seen[$email])) continue;
            $seen[$email] = true;

            $existing = Suppressions::get_reason($email);

            if (Suppressions::REASON_UNSUBSCRIBED === $existing) {
                // An unsubscribe is lifted when they confirm a new signup; "never email" must not be.
                Suppressions::make_manual($email);
                $result['added']++;
            } elseif ($existing) {
                // Bounced, complained, already added by hand, or erased: each already blocks every email.
                $result['already']++;
            } else {
                Suppressions::add($email, Suppressions::REASON_MANUAL, Suppressions::SOURCE_ADMIN);
                $result['added']++;
            }

            // A subscriber with this address is taken off every list, as the screen says.
            $subscriber = Subscribers::get_by_email($email);

            if ($subscriber && self::has_active_membership($subscriber['id'])) {
                Subscribers::log_event($subscriber['id'], Subscribers::EVENT_SUPPRESSED, ['by_admin' => get_current_user_id()]);
                Subscription::unsubscribe($subscriber['id']);
                $result['unsubscribed']++;
            }
        }

        return $result;
    }

    protected static function has_active_membership($subscriber_id)
    {
        foreach (Subscribers::get_memberships($subscriber_id) as $membership) {
            if (Subscribers::STATUS_UNSUBSCRIBED !== $membership['status']) return true;
        }

        return false;
    }

    /**
     * Let an address be emailed again. It isn't put back on any list.
     *
     * @return string notice key
     */
    public static function lift($hash, $checked)
    {
        $row = '' === $hash ? null : Suppressions::get_by_hash($hash);

        if ( ! $row) return 'lift_missing';

        if (Suppressions::REASON_UNSUBSCRIBED === $row['reason']) return 'lift_unsubscribed';
        if (Suppressions::REASON_ERASED === $row['reason']) return 'lift_erased';

        if (self::needs_check($row['reason']) && ! $checked) return 'lift_unchecked';

        $subscribers = Suppressions::subscribers_by_hash([$hash]);
        $subscriber  = isset($subscribers[$hash]) ? Subscribers::get($subscribers[$hash]['id']) : null;

        // A subscriber marked bounced or complained: the same path as "Mark as subscribed again" on
        // their profile, which also keeps an unsubscribe they made before the bounce.
        if ($subscriber && in_array($subscriber['status'], [Subscribers::STATUS_BOUNCED, Subscribers::STATUS_COMPLAINED], true) && self::needs_check($row['reason'])) {
            DeliveryEvents::resubscribe($subscriber['id']);

            return Suppressions::get_by_hash($hash) ? 'lift_kept_unsubscribe' : 'lifted';
        }

        Suppressions::remove_hash($hash);

        if ($subscriber) {
            Subscribers::log_event($subscriber['id'], Subscribers::EVENT_SUPPRESSION_LIFTED, ['reason' => $row['reason'], 'by_admin' => get_current_user_id()]);

            // Lifting a hand block doesn't lift a bounce or complaint that came after it: put that back,
            // so it shows here and can be lifted on its own, with its warning.
            if (in_array($subscriber['status'], [Subscribers::STATUS_BOUNCED, Subscribers::STATUS_COMPLAINED], true)) {
                Suppressions::add($subscriber['email'], $subscriber['status'], 'subscriber_status');

                return 'lift_kept_bounce';
            }
        }

        return 'lifted';
    }

    /* ---------------------------------------------------------------------------------------------
     * Screen
     * ------------------------------------------------------------------------------------------ */

    public static function notices()
    {
        return [
            'lifted'                => [__('MailOptin can email this address again. It wasn\'t added back to any list.', 'mailoptin'), 'success'],
            'lift_kept_unsubscribe' => [__('The bounce or spam report was lifted. They had unsubscribed before it, so they stay unsubscribed.', 'mailoptin'), 'warning'],
            'lift_unchecked'        => [__('Tick "I\'ve checked this address works" to allow emails again.', 'mailoptin'), 'error'],
            'lift_kept_bounce'      => [__('The hand block was lifted, but this address has bounced or marked an email as spam since, so it stays suppressed for that reason.', 'mailoptin'), 'warning'],
            'lift_unsubscribed'     => [__('Only they can undo an unsubscribe: send them a link to your signup form. When they confirm, MailOptin lifts it.', 'mailoptin'), 'error'],
            'lift_erased'           => [__('An erased person\'s address stays suppressed, so an old import can\'t bring them back.', 'mailoptin'), 'error'],
            'lift_missing'          => [__('That address isn\'t on the suppression list any more.', 'mailoptin'), 'warning'],
        ];
    }

    public static function html()
    {
        $html = '';

        if (isset($_GET['added'])) $html .= self::added_result_html();

        $hash = self::valid_hash($_GET['lift'] ?? '');
        if ('' !== $hash) $html .= self::lift_html($hash);

        $html .= self::views_html();
        $html .= '<div class="mo-supp-top">' . self::check_html() . self::add_form_html() . '</div>';

        $table = new Suppression_List();
        $table->prepare_items();

        ob_start();
        $table->display();
        $html .= '<form method="get"><input type="hidden" name="page" value="' . esc_attr(Subscribers_Page::SLUG) . '"><input type="hidden" name="view" value="' . esc_attr(self::TAB) . '">'
                 . ('' !== Suppression_List::current_reason() ? '<input type="hidden" name="reason" value="' . esc_attr(Suppression_List::current_reason()) . '">' : '')
                 . ob_get_clean() . '</form>';

        $html .= '<p class="description mo-supp-privacy">' . esc_html__('For privacy, MailOptin keeps only a fingerprint (a hash) of suppressed addresses. The table shows the address when that person is still a subscriber; otherwise "Address not stored". You can always check any address you type.', 'mailoptin') . '</p>';

        return $html;
    }

    protected static function views_html()
    {
        $counts  = Suppressions::counts();
        $current = Suppression_List::current_reason();
        $views   = ['' => __('All', 'mailoptin')] + self::reason_labels();
        $html    = '<nav class="mo-ui-views" aria-label="' . esc_attr__('Filter by reason', 'mailoptin') . '">';

        foreach ($views as $reason => $label) {
            $count = '' === $reason ? $counts['all'] : ($counts[$reason] ?? 0);

            // Nothing adds "erased" any more; show it only if old rows have it.
            if (Suppressions::REASON_ERASED === $reason && 0 === $count) continue;

            $html .= sprintf(
                '<a href="%s"%s>%s<span class="mo-ui-count">%s</span></a>',
                esc_url('' === $reason ? self::url() : self::url(['reason' => $reason])),
                $current === $reason ? ' class="mo-ui-on" aria-current="page"' : '',
                esc_html($label),
                esc_html(number_format_i18n($count))
            );
        }

        return $html . '</nav>';
    }

    protected static function check_html()
    {
        $email  = '';
        $result = '';

        if (isset($_POST['mo_suppression_check']) && check_admin_referer('mo_suppression_check')) {
            $email = sanitize_text_field(wp_unslash($_POST['check_email'] ?? ''));
            $result = self::check_result_html($email);
        }

        return '<div class="mo-ui-card mo-ui-card-pad mo-supp-check"><h3>' . esc_html__('Check an address', 'mailoptin') . '</h3>'
               . '<form method="post" action="' . esc_url(self::url()) . '">'
               . wp_nonce_field('mo_suppression_check', '_wpnonce', true, false)
               . '<input type="hidden" name="mo_suppression_check" value="1">'
               . '<label class="screen-reader-text" for="mo-check-email">' . esc_html__('Email address', 'mailoptin') . '</label>'
               . '<input type="email" id="mo-check-email" name="check_email" class="regular-text" required value="' . esc_attr($email) . '" placeholder="name@example.com"> '
               . '<button type="submit" class="button">' . esc_html__('Check', 'mailoptin') . '</button>'
               . '</form>' . $result . '</div>';
    }

    protected static function check_result_html($email)
    {
        $email = Subscribers::normalize_email($email);

        if ( ! is_email($email)) return UI::note(esc_html__('That isn\'t an email address.', 'mailoptin'), 'warn');

        $row = Suppressions::get_by_hash(Suppressions::hash($email));

        if ( ! $row) {
            return UI::note(sprintf(
            /* translators: %s: email address */
                esc_html__('%s is not suppressed. MailOptin can email it.', 'mailoptin'),
                '<strong>' . esc_html($email) . '</strong>'
            ), 'ok');
        }

        $what = [
            Suppressions::REASON_UNSUBSCRIBED => __('Only they can undo this. If they want your emails again, send them a link to your signup form; when they confirm, MailOptin lifts it.', 'mailoptin'),
            Suppressions::REASON_BOUNCED      => __('Emails to this address bounced. MailOptin won\'t email it, not even to confirm a signup.', 'mailoptin'),
            Suppressions::REASON_COMPLAINED   => __('This address marked an email as spam. MailOptin won\'t email it, not even to confirm a signup.', 'mailoptin'),
            Suppressions::REASON_MANUAL       => __('An admin added this address. MailOptin won\'t email it, not even to confirm a signup.', 'mailoptin'),
        ];

        $html = sprintf(
        /* translators: 1: email address, 2: reason badge, 3: date, 4: where the block came from */
            __('%1$s is suppressed: %2$s %3$s, from %4$s.', 'mailoptin'),
            '<strong>' . esc_html($email) . '</strong>',
            self::reason_badge($row['reason']),
            esc_html(self::local_date($row['created_at'])),
            esc_html(self::source_label($row['source']))
        );

        if (isset($what[$row['reason']])) $html .= ' ' . esc_html($what[$row['reason']]);

        if (self::can_lift($row['reason'])) {
            $html .= ' <a href="' . esc_url(self::lift_url($row['email_hash'])) . '">' . esc_html__('Allow emails again…', 'mailoptin') . '</a>';
        }

        return UI::note($html, 'warn');
    }

    protected static function add_form_html()
    {
        return '<details class="mo-ui-card mo-ui-card-pad mo-supp-add"' . (isset($_GET['add']) ? ' open' : '') . '><summary>' . esc_html__('Add addresses', 'mailoptin') . '</summary>'
               . '<form method="post" action="' . esc_url(self::url()) . '">'
               . wp_nonce_field('mo_suppress_add', '_wpnonce', true, false)
               . '<input type="hidden" name="mo_subscriber_action" value="suppress_add">'
               . '<p><label for="mo-supp-addresses">' . esc_html__('Addresses MailOptin should never email', 'mailoptin') . '</label></p>'
               . '<textarea id="mo-supp-addresses" name="addresses" rows="6" class="large-text code" required placeholder="old-customer@example.org"></textarea>'
               . '<p class="description">' . esc_html__('One per line, or paste a column from a spreadsheet. They\'re added as "Added by hand", and any subscriber with one of these addresses is unsubscribed from every list.', 'mailoptin') . '</p>'
               . '<p><button type="submit" class="button button-primary">' . esc_html__('Suppress these addresses', 'mailoptin') . '</button></p>'
               . '</form></details>';
    }

    protected static function added_result_html()
    {
        $result = get_transient(self::result_key());

        if ( ! is_array($result)) return '';

        delete_transient(self::result_key());

        $parts = [];
        /* translators: %s: number of addresses */
        $parts[] = sprintf(_n('%s address added.', '%s addresses added.', $result['added'], 'mailoptin'), number_format_i18n($result['added']));
        /* translators: %s: number of addresses */
        if ($result['already']) $parts[] = sprintf(_n('%s was already suppressed.', '%s were already suppressed.', $result['already'], 'mailoptin'), number_format_i18n($result['already']));
        /* translators: %s: number of subscribers */
        if ($result['unsubscribed']) $parts[] = sprintf(_n('%s subscriber was unsubscribed from every list.', '%s subscribers were unsubscribed from every list.', $result['unsubscribed'], 'mailoptin'), number_format_i18n($result['unsubscribed']));

        $html = esc_html(implode(' ', $parts));

        if ($result['invalid_count']) {
            /* translators: %s: number of lines */
            $html .= '<br>' . esc_html(sprintf(_n('%s line isn\'t an email address and was skipped:', '%s lines aren\'t email addresses and were skipped:', $result['invalid_count'], 'mailoptin'), number_format_i18n($result['invalid_count'])));
            $html .= '<br><code>' . implode('</code><br><code>', array_map('esc_html', $result['invalid'])) . '</code>';
            if ($result['invalid_count'] > count($result['invalid'])) $html .= '<br>' . esc_html__('…and more.', 'mailoptin');
        }

        return UI::note($html, $result['invalid_count'] ? 'warn' : 'ok');
    }

    protected static function lift_html($hash)
    {
        $row = Suppressions::get_by_hash($hash);

        if ( ! $row) return UI::note(esc_html__('That address isn\'t on the suppression list any more.', 'mailoptin'), 'warn');

        if (Suppressions::REASON_UNSUBSCRIBED === $row['reason']) return UI::note(esc_html(self::notices()['lift_unsubscribed'][0]), 'warn');
        if (Suppressions::REASON_ERASED === $row['reason']) return UI::note(esc_html(self::notices()['lift_erased'][0]), 'warn');

        $subscribers = Suppressions::subscribers_by_hash([$hash]);
        $who         = isset($subscribers[$hash]) ? '<strong>' . esc_html($subscribers[$hash]['email']) . '</strong>' : esc_html__('this address', 'mailoptin');
        $check       = self::needs_check($row['reason']);

        $html = '<div class="mo-ui-card mo-ui-card-pad mo-supp-lift' . ($check ? ' mo-supp-lift-warn' : '') . '">';
        /* translators: %s: email address or "this address" */
        $html .= '<h3>' . sprintf(esc_html__('Allow emails to %s again?', 'mailoptin'), $who) . '</h3>';

        if ($check) {
            $html .= '<p>' . (Suppressions::REASON_BOUNCED === $row['reason']
                    ? wp_kses(__('Emails to this address <strong>bounced</strong>.', 'mailoptin'), ['strong' => []])
                    : wp_kses(__('This address <strong>marked an email as spam</strong>.', 'mailoptin'), ['strong' => []]))
                     . ' ' . esc_html__('Sending to addresses that bounce or complain again harms how inbox providers treat all your email. Only do this if you know the address works now and wants your email.', 'mailoptin') . '</p>';
        } else {
            $html .= '<p>' . esc_html__('This doesn\'t add them back to any list: they\'d need to sign up or be added again.', 'mailoptin') . '</p>';
        }

        $html .= '<form method="post" action="' . esc_url(self::url()) . '">'
                 . wp_nonce_field('mo_suppression_lift_' . $hash, '_wpnonce', true, false)
                 . '<input type="hidden" name="mo_subscriber_action" value="suppression_lift">'
                 . '<input type="hidden" name="hash" value="' . esc_attr($hash) . '">';

        if ($check) {
            $html .= '<p><label><input type="checkbox" name="checked" value="1" required> ' . esc_html__('I\'ve checked this address works', 'mailoptin') . '</label></p>';
        }

        $html .= '<p>' . UI::button(__('Cancel', 'mailoptin'), self::url()) . ' <button type="submit" class="button ' . ($check ? 'button-link-delete mo-supp-lift-go' : 'button-primary') . '">' . esc_html__('Allow emails again', 'mailoptin') . '</button></p>';

        return $html . '</form></div>';
    }

    public static function styles()
    {
        return '<style>
.mo-supp-top{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;margin:0 0 16px}
.mo-supp-top h3,.mo-supp-lift h3{margin:0 0 10px;font-size:14px}
.mo-supp-top>*{min-width:0}
.mo-supp-check form{display:flex;gap:6px;flex-wrap:wrap}
.mo-supp-check input[type=email]{flex:1 1 200px;min-width:0;width:auto}
.mo-supp-check .mo-ui-note{margin:12px 0 0}
.mo-supp-add summary{cursor:pointer;font-weight:600}
.mo-supp-lift{margin:0 0 16px;max-width:72ch}
.mo-supp-lift-warn{border-left:4px solid #d63638}
.mo-supp-lift .mo-supp-lift-go{border:1px solid #d63638;padding:0 10px}
.mo-supp-not-stored{color:#646970;font-style:italic}
.mo-supp-privacy{max-width:80ch;margin-top:12px}
@media (max-width:782px){.mo-supp-top{grid-template-columns:1fr}}
</style>';
    }
}
