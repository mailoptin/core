<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;

/**
 * Bounces and spam complaints reported by a sending service. A hard bounce or a complaint stops all
 * email to the address; soft bounces count, and enough of them in a row count as a hard bounce.
 */
class DeliveryEvents
{
    const HARD_BOUNCE = 'hard_bounce';
    const SOFT_BOUNCE = 'soft_bounce';
    const COMPLAINT = 'complaint';

    /** Soft bounces with no open or click in between that make an address count as bounced. */
    const SOFT_BOUNCE_LIMIT = 4;

    /** Metadata key holding the send queue row id, sent with each email so its events find their campaign. */
    const QUEUE_KEY = 'mo_q';

    /**
     * @param array $event type (one of the constants), email, provider, detail, metadata
     *
     * @return string what was done: suppressed, counted, ignored or duplicate
     */
    public static function record(array $event)
    {
        $type  = (string)($event['type'] ?? '');
        $email = Subscribers::normalize_email((string)($event['email'] ?? ''));

        if ( ! in_array($type, [self::HARD_BOUNCE, self::SOFT_BOUNCE, self::COMPLAINT], true) || ! is_email($email)) {
            return 'ignored';
        }

        $provider = sanitize_key((string)($event['provider'] ?? ''));
        $detail   = sanitize_text_field((string)($event['detail'] ?? ''));

        // The queue row it was sent from, when the email came from a campaign and the row is for this address.
        $campaign_log_id = 0;
        $queue_id        = absint($event['metadata'][self::QUEUE_KEY] ?? 0);

        if ($queue_id) {
            $row = SendQueueRepository::get($queue_id);
            if ($row && strtolower($row['email']) === $email) $campaign_log_id = (int)$row['campaign_log_id'];
        }

        $subscriber = Subscribers::get_by_email($email);

        $event_type = [self::HARD_BOUNCE => Subscribers::EVENT_BOUNCED, self::SOFT_BOUNCE => Subscribers::EVENT_SOFT_BOUNCED, self::COMPLAINT => Subscribers::EVENT_COMPLAINED][$type];

        // Providers resend a webhook they think failed; count each email's event once.
        if ($subscriber && self::already_logged($subscriber['id'], $event_type, $campaign_log_id)) return 'duplicate';

        $meta = array_filter(['provider' => $provider, 'detail' => $detail]);

        if (self::SOFT_BOUNCE === $type) {
            if ( ! $subscriber) return 'ignored';

            Subscribers::log_event($subscriber['id'], $event_type, $meta, $campaign_log_id);

            if (self::soft_bounces_in_a_row($subscriber['id']) < self::SOFT_BOUNCE_LIMIT) return 'counted';

            $type = self::HARD_BOUNCE;
            $meta = ['provider' => $provider, 'detail' => sprintf(__('Soft bounced %d times in a row. Last: %s', 'mailoptin'), self::SOFT_BOUNCE_LIMIT, $detail), 'soft' => 1];
        }

        $complaint = self::COMPLAINT === $type;

        SuppressionRepository::add($email, $complaint ? SuppressionRepository::REASON_COMPLAINED : SuppressionRepository::REASON_BOUNCED, $provider);

        if ($subscriber) {
            Subscribers::update($subscriber['id'], ['status' => $complaint ? Subscribers::STATUS_COMPLAINED : Subscribers::STATUS_BOUNCED]);
            Subscribers::log_event($subscriber['id'], $complaint ? Subscribers::EVENT_COMPLAINED : Subscribers::EVENT_BOUNCED, $meta, $campaign_log_id);
        }

        do_action('mo_delivery_event_recorded', $type, $email, $campaign_log_id, $meta);

        return 'suppressed';
    }

    /**
     * The most recent bounce, or complaint, logged for a subscriber.
     *
     * @return array|null event row
     */
    public static function last_event($subscriber_id, $complaint = false)
    {
        global $wpdb;

        $table = Subscribers::events_table();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE subscriber_id = %d AND type = %s ORDER BY id DESC LIMIT 1",
            $subscriber_id, $complaint ? Subscribers::EVENT_COMPLAINED : Subscribers::EVENT_BOUNCED
        ), ARRAY_A) ?: null;
    }

    protected static function already_logged($subscriber_id, $event_type, $campaign_log_id)
    {
        global $wpdb;

        $table = Subscribers::events_table();

        // Without a campaign, a repeat within ten minutes is the same event.
        if ( ! $campaign_log_id) {
            return (bool)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE subscriber_id = %d AND type = %s AND campaign_log_id = 0 AND created_at >= %s LIMIT 1",
                $subscriber_id, $event_type, gmdate('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS)
            ));
        }

        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE subscriber_id = %d AND type = %s AND campaign_log_id = %d LIMIT 1",
            $subscriber_id, $event_type, $campaign_log_id
        ));
    }

    /**
     * Soft bounces since the subscriber last opened, clicked, confirmed or was marked subscribed again.
     */
    public static function soft_bounces_in_a_row($subscriber_id)
    {
        global $wpdb;

        $table = Subscribers::events_table();

        $since = (string)$wpdb->get_var($wpdb->prepare(
            "SELECT MAX(created_at) FROM $table WHERE subscriber_id = %d AND type IN (%s, %s, %s, %s)",
            $subscriber_id, Subscribers::EVENT_OPENED, Subscribers::EVENT_CLICKED, Subscribers::EVENT_CONFIRMED, Subscribers::EVENT_RESUBSCRIBED
        ));

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE subscriber_id = %d AND type = %s AND created_at >= %s",
            $subscriber_id, Subscribers::EVENT_SOFT_BOUNCED, '' === $since ? '1970-01-01 00:00:00' : $since
        ));
    }

    /**
     * Let an address that bounced or complained be emailed again, for when the admin knows it works now.
     */
    public static function resubscribe($subscriber_id)
    {
        $subscriber = Subscribers::get($subscriber_id);

        if ( ! $subscriber || ! in_array($subscriber['status'], [Subscribers::STATUS_BOUNCED, Subscribers::STATUS_COMPLAINED], true)) return false;

        $reason = SuppressionRepository::get_reason($subscriber['email']);

        if (in_array($reason, [SuppressionRepository::REASON_BOUNCED, SuppressionRepository::REASON_COMPLAINED], true)) {
            SuppressionRepository::remove($subscriber['email']);
        }

        // Their list memberships decide the status again.
        Subscribers::update($subscriber_id, ['status' => Subscribers::STATUS_UNSUBSCRIBED]);
        Subscribers::sync_status($subscriber_id);
        Subscribers::log_event($subscriber_id, Subscribers::EVENT_RESUBSCRIBED, ['by_admin' => get_current_user_id()]);

        return true;
    }

    /**
     * Bounce and complaint counts for the settings screen.
     *
     * @return array hard, soft, complaints, sent, rate (hard bounces per email sent, as a percentage)
     */
    public static function summary($days = 30)
    {
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

        $hard = Subscribers::count_events_since(Subscribers::EVENT_BOUNCED, $since);
        $sent = Subscribers::count_events_since(Subscribers::EVENT_SENT, $since);

        return [
            'hard'       => $hard,
            'soft'       => Subscribers::count_events_since(Subscribers::EVENT_SOFT_BOUNCED, $since),
            'complaints' => Subscribers::count_events_since(Subscribers::EVENT_COMPLAINED, $since),
            'sent'       => $sent,
            'rate'       => $sent > 0 ? round(100 * $hard / $sent, 1) : 0.0,
        ];
    }
}
