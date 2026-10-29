<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Limits on double opt-in emails. A bot that fills the form convincingly and rotates IPs could
 * otherwise make the site email thousands of strangers, which damages the sender's domain.
 */
class ConfirmationThrottle
{
    const REASON_COOLDOWN = 'cooldown';
    const REASON_ADDRESS_DAILY = 'address_daily_limit';
    const REASON_IP_HOURLY = 'ip_hourly_limit';
    const REASON_SITE_HOURLY = 'site_hourly_limit';

    const SITE_LIMIT_REACHED_OPTION = 'mo_confirmation_site_limit_reached';

    /**
     * @param int $subscriber_id
     * @param string $ip_address IP of the signup, empty when not known
     *
     * @return true|string true when an email may be sent, otherwise one of the REASON_* constants
     */
    public static function check($subscriber_id, $ip_address = '')
    {
        $cooldown  = (int)apply_filters('mo_confirmation_email_cooldown', 10 * MINUTE_IN_SECONDS);
        $last_sent = Subscribers::last_event_at($subscriber_id, Subscribers::EVENT_CONFIRMATION_SENT);

        if ($last_sent && strtotime($last_sent . ' UTC') > time() - $cooldown) {
            return self::REASON_COOLDOWN;
        }

        $address_limit = (int)apply_filters('mo_confirmation_email_daily_limit_per_address', 3);

        if ($address_limit > 0 && Subscribers::count_events_since(Subscribers::EVENT_CONFIRMATION_SENT, self::ago(DAY_IN_SECONDS), $subscriber_id) >= $address_limit) {
            return self::REASON_ADDRESS_DAILY;
        }

        $ip_limit = (int)apply_filters('mo_confirmation_email_hourly_limit_per_ip', 5);

        if ($ip_limit > 0 && filter_var($ip_address, FILTER_VALIDATE_IP) && self::ip_count($ip_address) >= $ip_limit) {
            return self::REASON_IP_HOURLY;
        }

        $site_limit = (int)apply_filters('mo_confirmation_email_hourly_limit', 200);

        // Confirmations from an import go through the send queue at its own hourly limit, so they
        // don't use up this one, which is there to stop a bot flooding the form.
        if ($site_limit > 0 && Subscribers::count_events_since(Subscribers::EVENT_CONFIRMATION_SENT, self::ago(HOUR_IN_SECONDS), 0, true) >= $site_limit) {
            update_option(self::SITE_LIMIT_REACHED_OPTION, time(), false);

            return self::REASON_SITE_HOURLY;
        }

        return true;
    }

    /**
     * Count a sent confirmation against the IP's hourly limit.
     */
    public static function record_ip($ip_address)
    {
        if ( ! filter_var($ip_address, FILTER_VALIDATE_IP)) return;

        $key      = self::ip_key($ip_address);
        $attempts = self::ip_attempts($ip_address);

        $attempts[] = time();

        set_transient($key, $attempts, HOUR_IN_SECONDS);
    }

    protected static function ip_count($ip_address)
    {
        return count(self::ip_attempts($ip_address));
    }

    protected static function ip_attempts($ip_address)
    {
        $attempts = get_transient(self::ip_key($ip_address));
        $cutoff   = time() - HOUR_IN_SECONDS;

        return array_values(array_filter(is_array($attempts) ? $attempts : [], function ($time) use ($cutoff) {
            return is_numeric($time) && (int)$time > $cutoff;
        }));
    }

    protected static function ip_key($ip_address)
    {
        return 'mo_confirmation_ip_' . md5($ip_address);
    }

    protected static function ago($seconds)
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }
}
