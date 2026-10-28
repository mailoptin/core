<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;

/**
 * Built-in email lists.
 */
class ListsRepository extends AbstractRepository
{
    public static function table()
    {
        return self::wpdb()->prefix . Core::lists_table_name;
    }

    /**
     * @param array $data name (required), description, double_optin, welcome_email
     *
     * @return int|false new list ID
     */
    public static function create($data)
    {
        if (empty($data['name'])) return false;

        $data = array_merge(['double_optin' => 1, 'welcome_email' => 0], $data, ['created_at' => gmdate('Y-m-d H:i:s')]);

        $inserted = self::wpdb()->insert(self::table(), $data);

        return $inserted ? (int)self::wpdb()->insert_id : false;
    }

    public static function update($list_id, $data)
    {
        return false !== self::wpdb()->update(self::table(), $data, ['id' => $list_id]);
    }

    /**
     * @return array|null
     */
    public static function get($list_id)
    {
        $table = self::table();

        return self::wpdb()->get_row(self::wpdb()->prepare("SELECT * FROM $table WHERE id = %d", $list_id), 'ARRAY_A');
    }

    /**
     * @return array
     */
    public static function get_all()
    {
        $table = self::table();

        return self::wpdb()->get_results("SELECT * FROM $table ORDER BY name", 'ARRAY_A');
    }

    /**
     * Deletes the list and its memberships. Subscribers themselves are kept.
     */
    public static function delete($list_id)
    {
        self::wpdb()->delete(SubscribersRepository::memberships_table(), ['list_id' => $list_id], ['%d']);

        return (bool)self::wpdb()->delete(self::table(), ['id' => $list_id], ['%d']);
    }

    /**
     * @return array list_id => ['subscribed' => int, 'pending' => int]
     */
    public static function member_counts()
    {
        $memberships = SubscribersRepository::memberships_table();
        $subscribers = SubscribersRepository::table();
        $counts      = [];

        $rows = self::wpdb()->get_results(
            "SELECT m.list_id, m.status, COUNT(*) AS total FROM $memberships m
            INNER JOIN $subscribers s ON s.id = m.subscriber_id
            WHERE s.status NOT IN ('bounced', 'complained')
            GROUP BY m.list_id, m.status",
            'ARRAY_A'
        );

        foreach ($rows as $row) {
            $counts[$row['list_id']][$row['status']] = (int)$row['total'];
        }

        return array_map(function ($statuses) {
            return [
                'subscribed' => $statuses[SubscribersRepository::STATUS_SUBSCRIBED] ?? 0,
                'pending'    => $statuses[SubscribersRepository::STATUS_PENDING] ?? 0,
            ];
        }, $counts);
    }

    public static function is_double_optin($list_id)
    {
        $list = self::get($list_id);

        return $list ? (bool)$list['double_optin'] : true;
    }
}
