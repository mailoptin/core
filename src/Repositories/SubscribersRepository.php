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
    const EVENT_CONFIRMATION_THROTTLED = 'confirmation_throttled';
    const EVENT_CONFIRMED = 'confirmed';
    const EVENT_UNSUBSCRIBED = 'unsubscribed';
    const EVENT_SENT = 'sent';
    const EVENT_TAGS_ADDED = 'tags_added';
    const EVENT_TAGS_REMOVED = 'tags_removed';
    const EVENT_FIELDS_CHANGED = 'fields_changed';
    const EVENT_IMPORTED = 'imported';

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
     * Subscribers for the admin list table.
     *
     * @param array $args status, list_id, source_optin_id, search, per_page, page, orderby (created_at|email), order,
     *                    tag_ids with tag_match (any|all|none), field_conditions (see CustomFieldsRepository::condition_sql())
     *
     * @return array ['items' => rows, each with 'lists' => [list_id => list name] and 'tags' => [tag_id => name], 'total' => int]
     */
    public static function query($args = [])
    {
        $table             = self::table();
        $memberships_table = self::memberships_table();

        $where  = ['1=1'];
        $params = [];

        if ( ! empty($args['status'])) {
            $where[]  = 's.status = %s';
            $params[] = $args['status'];
        }

        if ( ! empty($args['list_id'])) {
            $where[]  = "EXISTS (SELECT 1 FROM $memberships_table m WHERE m.subscriber_id = s.id AND m.list_id = %d AND m.status != %s)";
            $params[] = absint($args['list_id']);
            $params[] = self::STATUS_UNSUBSCRIBED;
        }

        // Keyset paging for exports: faster than OFFSET on large lists.
        if ( ! empty($args['after_id'])) {
            $where[]  = 's.id > %d';
            $params[] = absint($args['after_id']);
        }

        if ( ! empty($args['source_optin_id'])) {
            $where[]  = 's.source_optin_id = %d';
            $params[] = absint($args['source_optin_id']);
        }

        $tags_ready = TagsRepository::is_ready();

        if ( ! empty($args['search'])) {
            $like     = '%' . self::wpdb()->esc_like($args['search']) . '%';
            $fields   = $tags_ready ? ' OR EXISTS (SELECT 1 FROM ' . CustomFieldsRepository::values_table() . ' fv WHERE fv.subscriber_id = s.id AND fv.value_text LIKE %s)' : '';
            $where[]  = "(s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s$fields)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            if ($tags_ready) $params[] = $like;
        }

        $tag_ids = array_values(array_filter(array_map('absint', (array)($args['tag_ids'] ?? []))));

        if ($tags_ready && ! empty($tag_ids)) {
            $links = TagsRepository::links_table();
            $in    = implode(',', $tag_ids);
            $match = $args['tag_match'] ?? 'any';

            if ('all' === $match) {
                $where[] = "(SELECT COUNT(*) FROM $links t WHERE t.subscriber_id = s.id AND t.tag_id IN ($in)) = " . count($tag_ids);
            } else {
                $where[] = ('none' === $match ? 'NOT ' : '') . "EXISTS (SELECT 1 FROM $links t WHERE t.subscriber_id = s.id AND t.tag_id IN ($in))";
            }
        }

        if ($tags_ready) {
            foreach ((array)($args['field_conditions'] ?? []) as $condition) {
                $sql = is_array($condition) ? CustomFieldsRepository::condition_sql($condition) : null;
                if ($sql) $where[] = $sql;
            }
        }

        $where_sql = implode(' AND ', $where);

        $orderby  = in_array($args['orderby'] ?? '', ['email', 'created_at', 'id'], true) ? $args['orderby'] : 'created_at';
        $order    = 'ASC' === strtoupper($args['order'] ?? '') ? 'ASC' : 'DESC';
        $per_page = max(1, absint($args['per_page'] ?? 20));
        $offset   = (max(1, absint($args['page'] ?? 1)) - 1) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM $table s WHERE $where_sql";
        $total     = ! empty($args['skip_count']) ? 0 : (int)self::wpdb()->get_var(empty($params) ? $count_sql : self::wpdb()->prepare($count_sql, $params));

        $items = self::wpdb()->get_results(
            self::wpdb()->prepare(
                "SELECT s.* FROM $table s WHERE $where_sql ORDER BY s.$orderby $order, s.id $order LIMIT %d OFFSET %d",
                array_merge($params, [$per_page, $offset])
            ),
            'ARRAY_A'
        );

        if ( ! empty($items)) {
            $ids   = implode(',', array_map('absint', wp_list_pluck($items, 'id')));
            $lists = ListsRepository::table();
            $rows  = self::wpdb()->get_results(
                self::wpdb()->prepare(
                    "SELECT m.subscriber_id, l.id, l.name FROM $memberships_table m INNER JOIN $lists l ON l.id = m.list_id
                    WHERE m.subscriber_id IN ($ids) AND m.status != %s ORDER BY l.name",
                    self::STATUS_UNSUBSCRIBED
                ),
                'ARRAY_A'
            );

            $by_subscriber = [];
            foreach ($rows as $row) {
                $by_subscriber[$row['subscriber_id']][$row['id']] = $row['name'];
            }

            $tags = $tags_ready ? TagsRepository::for_subscribers(wp_list_pluck($items, 'id')) : [];

            foreach ($items as &$item) {
                $item['lists'] = $by_subscriber[$item['id']] ?? [];
                $item['tags']  = $tags[(int)$item['id']] ?? [];
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array status => count, with every status present
     */
    public static function count_by_status()
    {
        $table  = self::table();
        $counts = array_fill_keys([self::STATUS_SUBSCRIBED, self::STATUS_PENDING, self::STATUS_UNSUBSCRIBED, self::STATUS_BOUNCED, self::STATUS_COMPLAINED], 0);

        foreach (self::wpdb()->get_results("SELECT status, COUNT(*) AS total FROM $table GROUP BY status", 'ARRAY_A') as $row) {
            $counts[$row['status']] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * Delete a subscriber with their memberships, events, tags and custom field values. This is
     * not an unsubscribe: the address can be added again later.
     */
    public static function delete($subscriber_id)
    {
        self::wpdb()->delete(self::memberships_table(), ['subscriber_id' => $subscriber_id], ['%d']);
        self::wpdb()->delete(self::events_table(), ['subscriber_id' => $subscriber_id], ['%d']);
        TagsRepository::delete_for_subscribers([$subscriber_id]);
        CustomFieldsRepository::delete_values_for_subscribers([$subscriber_id]);

        return (bool)self::wpdb()->delete(self::table(), ['id' => $subscriber_id], ['%d']);
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
     * The same kind of event for many subscribers at once, each with its own meta.
     *
     * @param array $meta_by_subscriber subscriber_id => meta
     */
    public static function log_events($type, $meta_by_subscriber)
    {
        $rows = [];
        $now  = self::now();

        foreach ($meta_by_subscriber as $subscriber_id => $meta) {
            $rows[] = self::wpdb()->prepare('(%d, %s, 0, %s, %s)', $subscriber_id, $type, wp_json_encode($meta), $now);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            self::wpdb()->query('INSERT INTO ' . self::events_table() . ' (subscriber_id, type, campaign_log_id, meta, created_at) VALUES ' . implode(',', $chunk));
        }
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
     * @param string $type
     * @param string $since UTC datetime
     * @param int $subscriber_id 0 for every subscriber
     *
     * @return int
     */
    public static function count_events_since($type, $since, $subscriber_id = 0)
    {
        $table = self::events_table();

        if ($subscriber_id) {
            return (int)self::wpdb()->get_var(
                self::wpdb()->prepare("SELECT COUNT(*) FROM $table WHERE subscriber_id = %d AND type = %s AND created_at >= %s", $subscriber_id, $type, $since)
            );
        }

        return (int)self::wpdb()->get_var(
            self::wpdb()->prepare("SELECT COUNT(*) FROM $table WHERE type = %s AND created_at >= %s", $type, $since)
        );
    }

    /**
     * Delete signups that never confirmed, with their memberships and events.
     *
     * @param string $before UTC datetime; pending subscribers created before it are deleted
     * @param int $limit
     *
     * @return int number deleted
     */
    public static function delete_stale_pending($before, $limit = 500)
    {
        $table = self::table();

        $queue = self::wpdb()->prefix . Core::send_queue_table_name;

        // Not people whose confirmation email from an import is still waiting in the send queue.
        $ids = array_map('absint', self::wpdb()->get_col(
            self::wpdb()->prepare(
                "SELECT id FROM $table s WHERE status = %s AND confirmed_at IS NULL AND updated_at < %s
                AND NOT EXISTS (SELECT 1 FROM $queue q WHERE q.recipient_type = %s AND q.recipient_id = s.id AND q.status IN (%s, %s, %s))
                ORDER BY id LIMIT %d",
                self::STATUS_PENDING,
                $before,
                \MailOptin\Core\Audience\ImportConfirmations::RECIPIENT_TYPE,
                SendQueueRepository::STATUS_PENDING,
                SendQueueRepository::STATUS_CLAIMED,
                SendQueueRepository::STATUS_SENDING,
                $limit
            )
        ));

        if (empty($ids)) return 0;

        $in = implode(',', $ids);

        self::wpdb()->query("DELETE FROM " . self::memberships_table() . " WHERE subscriber_id IN ($in)");
        self::wpdb()->query("DELETE FROM " . self::events_table() . " WHERE subscriber_id IN ($in)");
        TagsRepository::delete_for_subscribers($ids);
        CustomFieldsRepository::delete_values_for_subscribers($ids);
        self::wpdb()->query("DELETE FROM $table WHERE id IN ($in)");

        return count($ids);
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
