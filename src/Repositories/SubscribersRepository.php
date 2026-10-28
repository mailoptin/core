<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;

/**
 * Subscribers of built-in lists, their list memberships and activity events. All datetimes are UTC.
 */
class SubscribersRepository extends AbstractRepository
{
    const STATUS_PENDING = 'pending';
    const STATUS_SUBSCRIBED = 'subscribed';
    const STATUS_UNSUBSCRIBED = 'unsubscribed';
    const STATUS_BOUNCED = 'bounced';
    const STATUS_COMPLAINED = 'complained';

    const EVENT_SIGNUP = 'signup';
    const EVENT_CONFIRMATION_SENT = 'confirmation_sent';
    const EVENT_CONFIRMED = 'confirmed';
    const EVENT_UNSUBSCRIBED = 'unsubscribed';
    const EVENT_SENT = 'sent';

    public static function table()
    {
        return self::wpdb()->prefix . Core::subscribers_table_name;
    }

    public static function memberships_table()
    {
        return self::wpdb()->prefix . Core::list_subscribers_table_name;
    }

    public static function events_table()
    {
        return self::wpdb()->prefix . Core::subscriber_events_table_name;
    }

    public static function normalize_email($email)
    {
        return strtolower(trim($email));
    }

    public static function now()
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * @return array|null
     */
    public static function get($subscriber_id)
    {
        $table = self::table();

        return self::wpdb()->get_row(self::wpdb()->prepare("SELECT * FROM $table WHERE id = %d", $subscriber_id), 'ARRAY_A');
    }

    /**
     * @return array|null
     */
    public static function get_by_email($email)
    {
        $table = self::table();

        return self::wpdb()->get_row(
            self::wpdb()->prepare("SELECT * FROM $table WHERE email = %s", self::normalize_email($email)),
            'ARRAY_A'
        );
    }

    /**
     * @param array $data columns of the subscribers table; email is required.
     *
     * @return int|false new subscriber ID
     */
    public static function create($data)
    {
        $now = self::now();

        $data = array_merge([
            'status'     => self::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ], $data);

        $data['email'] = self::normalize_email($data['email']);

        if ( ! is_email($data['email'])) return false;

        $inserted = self::wpdb()->insert(self::table(), $data);

        return $inserted ? (int)self::wpdb()->insert_id : false;
    }

    public static function update($subscriber_id, $data)
    {
        $data['updated_at'] = self::now();

        if (isset($data['email'])) {
            $data['email'] = self::normalize_email($data['email']);
        }

        return false !== self::wpdb()->update(self::table(), $data, ['id' => $subscriber_id]);
    }

    /**
     * @return array|null membership row for one list
     */
    public static function get_membership($subscriber_id, $list_id)
    {
        $table = self::memberships_table();

        return self::wpdb()->get_row(
            self::wpdb()->prepare("SELECT * FROM $table WHERE subscriber_id = %d AND list_id = %d", $subscriber_id, $list_id),
            'ARRAY_A'
        );
    }

    /**
     * @return array membership rows of a subscriber
     */
    public static function get_memberships($subscriber_id)
    {
        $table = self::memberships_table();

        return self::wpdb()->get_results(
            self::wpdb()->prepare("SELECT * FROM $table WHERE subscriber_id = %d ORDER BY list_id", $subscriber_id),
            'ARRAY_A'
        );
    }

    public static function set_membership($subscriber_id, $list_id, $status)
    {
        $table = self::memberships_table();
        $now   = self::now();

        return false !== self::wpdb()->query(
                self::wpdb()->prepare(
                    "INSERT INTO $table (list_id, subscriber_id, status, subscribed_at, unsubscribed_at) VALUES (%d, %d, %s, %s, NULL)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), subscribed_at = IF(VALUES(status) = %s, subscribed_at, VALUES(subscribed_at)),
                    unsubscribed_at = IF(VALUES(status) = %s, %s, NULL)",
                    $list_id,
                    $subscriber_id,
                    $status,
                    $now,
                    self::STATUS_UNSUBSCRIBED,
                    self::STATUS_UNSUBSCRIBED,
                    $now
                )
            );
    }

    /**
     * Mark the subscriber confirmed and turn every pending membership into a subscription.
     */
    public static function confirm($subscriber_id)
    {
        $table = self::memberships_table();

        self::wpdb()->update(
            $table,
            ['status' => self::STATUS_SUBSCRIBED],
            ['subscriber_id' => $subscriber_id, 'status' => self::STATUS_PENDING]
        );

        self::update($subscriber_id, ['confirmed_at' => self::now()]);

        return self::sync_status($subscriber_id);
    }

    /**
     * Derive the subscriber's overall status from their memberships. Bounced and complained stay put.
     *
     * @return string the new status
     */
    public static function sync_status($subscriber_id)
    {
        $subscriber = self::get($subscriber_id);

        if ( ! $subscriber) return '';

        if (in_array($subscriber['status'], [self::STATUS_BOUNCED, self::STATUS_COMPLAINED], true)) {
            return $subscriber['status'];
        }

        $statuses = wp_list_pluck(self::get_memberships($subscriber_id), 'status');

        if (in_array(self::STATUS_SUBSCRIBED, $statuses, true)) {
            $status = self::STATUS_SUBSCRIBED;
        } elseif (in_array(self::STATUS_PENDING, $statuses, true)) {
            $status = self::STATUS_PENDING;
        } else {
            $status = self::STATUS_UNSUBSCRIBED;
        }

        if ($status !== $subscriber['status']) {
            self::update($subscriber_id, ['status' => $status]);
        }

        return $status;
    }

    /**
     * Subscribed members of a list, in ID order, for queueing a campaign in batches.
     *
     * @return array rows with id and email
     */
    public static function get_list_recipients($list_id, $after_id = 0, $limit = 1000)
    {
        $table             = self::table();
        $memberships_table = self::memberships_table();

        return self::wpdb()->get_results(
            self::wpdb()->prepare(
                "SELECT s.id, s.email FROM $table s
                INNER JOIN $memberships_table m ON m.subscriber_id = s.id
                WHERE m.list_id = %d AND m.status = %s AND s.status = %s AND s.id > %d
                ORDER BY s.id LIMIT %d",
                $list_id,
                self::STATUS_SUBSCRIBED,
                self::STATUS_SUBSCRIBED,
                $after_id,
                $limit
            ),
            'ARRAY_A'
        );
    }

    public static function log_event($subscriber_id, $type, $meta = [], $campaign_log_id = 0)
    {
        return self::wpdb()->insert(self::events_table(), [
            'subscriber_id'   => $subscriber_id,
            'type'            => $type,
            'campaign_log_id' => $campaign_log_id,
            'meta'            => empty($meta) ? null : wp_json_encode($meta),
            'created_at'      => self::now(),
        ]);
    }

    /**
     * @return string|null UTC datetime of the subscriber's most recent event of this type
     */
    public static function last_event_at($subscriber_id, $type)
    {
        $table = self::events_table();

        return self::wpdb()->get_var(
            self::wpdb()->prepare("SELECT MAX(created_at) FROM $table WHERE subscriber_id = %d AND type = %s", $subscriber_id, $type)
        );
    }

    /**
     * @return array newest first
     */
    public static function get_events($subscriber_id, $limit = 50)
    {
        $table = self::events_table();

        return self::wpdb()->get_results(
            self::wpdb()->prepare("SELECT * FROM $table WHERE subscriber_id = %d ORDER BY id DESC LIMIT %d", $subscriber_id, $limit),
            'ARRAY_A'
        );
    }
}
