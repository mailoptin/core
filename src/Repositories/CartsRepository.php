<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;

/**
 * WooCommerce carts kept for the "A cart is abandoned" automations. All datetimes are UTC.
 *
 * A cart is "active" while the shopper is still shopping, "abandoned" once an automation's wait
 * passed with no order, "recovered" when they order within the recovery window after it was
 * abandoned. A cart that turns into an order before it was abandoned is simply deleted.
 */
class CartsRepository extends AbstractRepository
{
    const STATUS_ACTIVE = 'active';
    const STATUS_ABANDONED = 'abandoned';
    const STATUS_RECOVERED = 'recovered';

    /** An order this long after a cart was abandoned counts as recovering it. */
    const RECOVERY_DAYS = 30;

    public static function table()
    {
        return self::wpdb()->prefix . Core::abandoned_carts_table_name;
    }

    /** Created with the automation tables (DB update 24). */
    public static function is_ready()
    {
        return AutomationsRepository::is_ready();
    }

    public static function now($offset_seconds = 0)
    {
        return gmdate('Y-m-d H:i:s', time() + $offset_seconds);
    }

    protected static function inflate($row)
    {
        if ( ! $row) return null;

        $row['id']              = (int)$row['id'];
        $row['user_id']         = (int)$row['user_id'];
        $row['order_id']        = (int)$row['order_id'];
        $row['total']           = (float)$row['total'];
        $row['recovered_total'] = (float)$row['recovered_total'];
        $items                  = json_decode((string)$row['items'], true);
        $row['items']           = is_array($items) ? $items : [];

        return $row;
    }

    public static function get($cart_id)
    {
        return self::inflate(self::wpdb()->get_row(self::wpdb()->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $cart_id), 'ARRAY_A'));
    }

    public static function get_by_session($session_key)
    {
        return self::inflate(self::wpdb()->get_row(self::wpdb()->prepare('SELECT * FROM ' . self::table() . ' WHERE session_key = %s', $session_key), 'ARRAY_A'));
    }

    /**
     * Record a shopper's cart, or update it. A cart that was abandoned stays abandoned when they come
     * back and change it: its emails carry on, showing what is in it now.
     *
     * @param array $data session_key, user_id, email, first_name, items, total, currency
     *
     * @return int cart ID
     */
    public static function save($data)
    {
        $now  = self::now();
        $wpdb = self::wpdb();

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::table() . ' (session_key, user_id, email, first_name, items, total, currency, status, created_at, updated_at)
            VALUES (%s, %d, %s, %s, %s, %f, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), email = IF(VALUES(email) = \'\', email, VALUES(email)), first_name = IF(VALUES(first_name) = \'\', first_name, VALUES(first_name)),
            items = VALUES(items), total = VALUES(total), currency = VALUES(currency), updated_at = VALUES(updated_at)',
            (string)$data['session_key'],
            absint($data['user_id'] ?? 0),
            strtolower(trim((string)($data['email'] ?? ''))),
            mb_substr(sanitize_text_field((string)($data['first_name'] ?? '')), 0, 100),
            wp_json_encode(array_values((array)$data['items'])),
            (float)($data['total'] ?? 0),
            substr(sanitize_text_field((string)($data['currency'] ?? '')), 0, 3),
            self::STATUS_ACTIVE,
            $now,
            $now
        ));

        $cart = self::get_by_session($data['session_key']);

        return $cart ? $cart['id'] : 0;
    }

    /**
     * Forget the cart of a session while it is still being shopped (emptied, or ordered before it
     * was ever abandoned). An abandoned cart is kept: it is the record of the emails it started.
     */
    public static function delete_active($session_key)
    {
        return self::wpdb()->delete(self::table(), ['session_key' => $session_key, 'status' => self::STATUS_ACTIVE]);
    }

    public static function delete($cart_id)
    {
        return self::wpdb()->delete(self::table(), ['id' => absint($cart_id)]);
    }

    public static function update($cart_id, $fields)
    {
        $allowed = array_intersect_key((array)$fields, array_flip(['session_key', 'email', 'status', 'abandoned_at', 'order_id', 'recovered_total', 'recovered_at']));

        return empty($allowed) ? false : false !== self::wpdb()->update(self::table(), $allowed, ['id' => absint($cart_id)]);
    }

    /**
     * Carts with an email whose last change was between $oldest and $newest, not yet ordered, and with
     * no run of the automation yet: the ones a scan may start. Paged by ID.
     *
     * @return array[]
     */
    public static function due_for($automation_id, $newest, $oldest, $after_id, $limit)
    {
        $runs = AutomationsRepository::runs_table();

        return array_map([__CLASS__, 'inflate'], (array)self::wpdb()->get_results(self::wpdb()->prepare(
            'SELECT c.* FROM ' . self::table() . " c
            WHERE c.status IN (%s, %s) AND c.updated_at <= %s AND c.updated_at >= %s AND c.email <> '' AND c.id > %d
            AND NOT EXISTS (SELECT 1 FROM $runs r WHERE r.automation_id = %d AND r.email = c.email AND r.trigger_ref = CONCAT('cart-', c.id))
            ORDER BY c.id LIMIT %d",
            self::STATUS_ACTIVE, self::STATUS_ABANDONED, $newest, $oldest, $after_id, $automation_id, $limit
        ), 'ARRAY_A'));
    }

    /**
     * Carts this order recovers or completes: the one checked out, and other carts of the same
     * address still open within the recovery window.
     *
     * @return array[]
     */
    public static function for_order($order_id, $email)
    {
        $email = strtolower(trim((string)$email));

        return array_map([__CLASS__, 'inflate'], (array)self::wpdb()->get_results(self::wpdb()->prepare(
            'SELECT * FROM ' . self::table() . " WHERE status IN (%s, %s) AND (order_id = %d OR (email <> '' AND email = %s AND updated_at >= %s))",
            self::STATUS_ACTIVE, self::STATUS_ABANDONED, $order_id, $email, self::now(-self::RECOVERY_DAYS * DAY_IN_SECONDS)
        ), 'ARRAY_A'));
    }

    /**
     * @param string $view '', in_progress, recovered, lost
     *
     * @return array [rows, total]
     */
    public static function page($view, $page = 1, $per_page = 50)
    {
        list($where, $params) = self::view_where($view);

        $table  = self::table();
        $offset = max(0, ((int)$page - 1) * $per_page);
        $total  = (int)self::wpdb()->get_var(self::wpdb()->prepare("SELECT COUNT(*) FROM $table c WHERE $where", $params));
        $rows   = self::wpdb()->get_results(self::wpdb()->prepare(
            "SELECT c.* FROM $table c WHERE $where ORDER BY c.abandoned_at DESC, c.id DESC LIMIT %d OFFSET %d",
            array_merge($params, [$per_page, $offset])
        ), 'ARRAY_A');

        return [array_map([__CLASS__, 'inflate'], (array)$rows), $total];
    }

    /**
     * Abandoned carts are "in progress" while a run of theirs is still active, "lost" after that.
     */
    protected static function view_where($view)
    {
        $runs   = AutomationsRepository::runs_table();
        $active = "EXISTS (SELECT 1 FROM $runs r WHERE r.email = c.email AND r.trigger_ref = CONCAT('cart-', c.id) AND r.status = %s)";

        switch ($view) {
            case 'in_progress':
                return ["c.status = %s AND $active", [self::STATUS_ABANDONED, AutomationsRepository::RUN_ACTIVE]];
            case 'lost':
                return ["c.status = %s AND NOT $active", [self::STATUS_ABANDONED, AutomationsRepository::RUN_ACTIVE]];
            case 'recovered':
                return ['c.status = %s', [self::STATUS_RECOVERED]];
            default:
                return ['c.status IN (%s, %s)', [self::STATUS_ABANDONED, self::STATUS_RECOVERED]];
        }
    }

    /**
     * @return array view => count
     */
    public static function view_counts()
    {
        $counts = [];

        foreach (['', 'in_progress', 'recovered', 'lost'] as $view) {
            list($where, $params) = self::view_where($view);
            $counts[$view] = (int)self::wpdb()->get_var(self::wpdb()->prepare('SELECT COUNT(*) FROM ' . self::table() . " c WHERE $where", $params));
        }

        return $counts;
    }

    /**
     * Carts abandoned and recovered since a time, and the revenue recovered.
     *
     * @return array ['abandoned' => int, 'recovered' => int, 'revenue' => float]
     */
    public static function recovery_stats($since_utc)
    {
        $row = self::wpdb()->get_row(self::wpdb()->prepare(
            'SELECT COUNT(*) AS abandoned, SUM(status = %s) AS recovered, SUM(IF(status = %s, recovered_total, 0)) AS revenue
            FROM ' . self::table() . ' WHERE status IN (%s, %s) AND abandoned_at >= %s',
            self::STATUS_RECOVERED, self::STATUS_RECOVERED, self::STATUS_ABANDONED, self::STATUS_RECOVERED, $since_utc
        ), 'ARRAY_A');

        return ['abandoned' => (int)($row['abandoned'] ?? 0), 'recovered' => (int)($row['recovered'] ?? 0), 'revenue' => (float)($row['revenue'] ?? 0)];
    }

    /**
     * Carts abandoned since a time, for "Who's included" counts.
     *
     * @return array[]
     */
    public static function abandoned_since($since_utc, $limit = 500)
    {
        return array_map([__CLASS__, 'inflate'], (array)self::wpdb()->get_results(self::wpdb()->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE status IN (%s, %s) AND abandoned_at >= %s ORDER BY id DESC LIMIT %d',
            self::STATUS_ABANDONED, self::STATUS_RECOVERED, $since_utc, $limit
        ), 'ARRAY_A'));
    }

    /**
     * Delete carts nobody needs: open or lost ones after 30 days, recovered ones after a year.
     */
    public static function prune()
    {
        $table = self::table();

        self::wpdb()->query(self::wpdb()->prepare(
            "DELETE FROM $table WHERE status IN (%s, %s) AND updated_at < %s LIMIT 1000",
            self::STATUS_ACTIVE, self::STATUS_ABANDONED, self::now(-self::RECOVERY_DAYS * DAY_IN_SECONDS)
        ));

        self::wpdb()->query(self::wpdb()->prepare(
            "DELETE FROM $table WHERE status = %s AND recovered_at < %s LIMIT 1000",
            self::STATUS_RECOVERED, self::now(-YEAR_IN_SECONDS)
        ));
    }

    public static function for_email($email)
    {
        return array_map([__CLASS__, 'inflate'], (array)self::wpdb()->get_results(self::wpdb()->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE email = %s ORDER BY id DESC LIMIT 500',
            strtolower(trim((string)$email))
        ), 'ARRAY_A'));
    }

    public static function erase_email($email)
    {
        return (int)self::wpdb()->delete(self::table(), ['email' => strtolower(trim((string)$email))]);
    }
}
