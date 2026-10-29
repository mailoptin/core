<?php

namespace MailOptin\Core\Submissions;

use MailOptin\Core\Repositories\ConversionSyncRepository as Syncs;

/**
 * Tells admins when submissions didn't reach an integration: a count on the Submissions menu item
 * and a banner on the Submissions screen. Shown on free sites too, where reviewing and resending
 * them is a premium feature.
 */
class SubmissionAlert
{
    const DAYS = 7;

    /** @var int|null cached for the request: the menu and the banner both need it */
    protected static $count = null;

    public static function count()
    {
        if (null === self::$count) {
            self::$count = Syncs::count_unsent_submissions(Syncs::now(-self::DAYS * DAY_IN_SECONDS));
        }

        return self::$count;
    }

    public static function reset()
    {
        self::$count = null;
    }

    public static function menu_title($title)
    {
        $count = self::count();

        if ( ! $count) return $title;

        return sprintf(
            '%s <span class="awaiting-mod count-%2$d"><span class="pending-count" aria-hidden="true">%2$d</span><span class="screen-reader-text">%3$s</span></span>',
            $title,
            $count,
            esc_html(sprintf(_n('%d submission not sent', '%d submissions not sent', $count, 'mailoptin'), $count))
        );
    }

    /**
     * @param bool $can_resend premium is active, so the review and retry buttons work
     *
     * @return string
     */
    public static function banner_html($can_resend)
    {
        $count = self::count();

        if ( ! $count) return '';

        $latest = Syncs::latest_failure();
        $error  = $latest ? $latest['last_error'] : '';

        $html = '<div class="notice notice-error inline mo-submissions-alert" style="margin:12px 0"><p>';

        $html .= '<strong>' . esc_html(sprintf(
                _n(
                    '%d submission didn\'t reach your email service in the last %d days.',
                    '%d submissions didn\'t reach your email service in the last %d days.',
                    $count,
                    'mailoptin'
                ),
                $count,
                self::DAYS
            )) . '</strong> ';

        if ($latest && '' !== $error) {
            $html .= sprintf(
                    esc_html__('The latest error was from %1$s: %2$s.', 'mailoptin'),
                    esc_html(self::connection_label($latest['connection_service'])),
                    '<em>' . esc_html(wp_trim_words($error, 30)) . '</em>'
                ) . ' ';
        }

        $html .= sprintf(
            esc_html__('Fix the connection under %1$sIntegrations%2$s; failed sends are retried automatically for a day.', 'mailoptin'),
            '<a href="' . esc_url(MAILOPTIN_CONNECTIONS_SETTINGS_PAGE) . '">',
            '</a>'
        );

        $html .= '</p><p>';

        if ($can_resend) {
            $html .= sprintf(
                '<a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a>',
                esc_url(add_query_arg('status', 'unsent', MAILOPTIN_LEAD_BANK_SETTINGS_PAGE)),
                esc_html__('Review failed', 'mailoptin'),
                esc_url(wp_nonce_url(add_query_arg('mo_submissions_action', 'retry_all', MAILOPTIN_LEAD_BANK_SETTINGS_PAGE), 'mo_submissions_retry_all')),
                esc_html__('Retry all failed', 'mailoptin')
            );
        } else {
            $html .= sprintf(
                '<a class="button button-primary" target="_blank" href="%s">%s</a>',
                'https://mailoptin.io/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=submissions_failed',
                esc_html__('Upgrade to review and resend them', 'mailoptin')
            );
        }

        return $html . '</p></div>';
    }

    public static function connection_label($connection_service)
    {
        $connections = apply_filters('mailoptin_registered_connections', []);

        return $connections[$connection_service] ?? preg_replace('/Connect$/', '', (string)$connection_service);
    }
}
