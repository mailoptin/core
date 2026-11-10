<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;

/**
 * WooCommerce carts kept for the "A cart is abandoned" automations. All datetimes are UTC.
 *
 * A cart is "active" while the shopper is still shopping, "abandoned" once an automation started
 * for it, "recovered" when the order it led to is paid. A cart that turns into an order before it
 * was abandoned is simply deleted.
 *
 * Which automations have looked at a cart, and the run each started (0 when the cart wasn't for it),
 * is kept in a small table of its own: it is what stops a cart being looked at twice, and how the
 * carts screen finds a cart's emails in every "runs for each customer" mode.
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

    public static function runs_table()
    {
        return self::wpdb()->prefix . Core::abandoned_cart_runs_table_name;
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

    public static function inflate($row)
    {
        if ( ! $row) return null;

        $row['id']              = (int)$row['id'];
        $row['user_id']         = (int)$row['user_id'];
        $row['order_id']        = (int)$row['order_id'];
        $row['total']           = (float)$row['total'];
        $row['recovered_total'] = (float)$row['recovered_total'];
        $items                  = is_array($row['items']) ? $row['items'] : json_decode((string)$row['items'], true);
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
     * Record a shopper's cart, or update it. A cart that was abandoned keeps its email (its emails
     * were started for that address) and stays abandoned: its emails show what is in it now.
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
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id),
            email = IF(status = \'active\' AND VALUES(email) <> \'\', VALUES(email), email),
            first_name = IF(VALUES(first_name) = \'\', first_name, VALUES(first_name)),
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
     * Let go of a session's cart: deleted while it is still being shopped; detached from the session
     * once abandoned, so the shopper's next cart is a new one. $empty also clears its products, which
     * stops its emails ("they emptied their cart").
     */
    public static function release_session($session_key, $empty)
    {
        $cart = self::get_by_session($session_key);

        if ( ! $cart) return;

        if (self::STATUS_ACTIVE === $cart['status']) {
            self::delete($cart['id']);

            return;
        }

        $fields = ['session_key' => 'gone-' . $cart['id']];
        if ($empty) $fields['items'] = '[]';

        self::wpdb()->update(self::table(), $fields, ['id' => $cart['id']]);
    }

    public static function delete($cart_id)
    {
        self::wpdb()->delete(self::runs_table(), ['cart_id' => absint($cart_id)]);

        return self::wpdb()->delete(self::table(), ['id' => absint($cart_id)]);
    }

    public static function update($cart_id, $fields)
    {
        $allowed = array_intersect_key((array)$fields, array_flip(['session_key', 'email', 'status', 'abandoned_at', 'order_id', 'recovered_total', 'recovered_at']));

        return empty($allowed) ? false : false !== self::wpdb()->update(self::table(), $allowed, ['id' => absint($cart_id)]);
    }

    /* ---------------------------------------------------------------------------------------------
     * Carts and the automations that looked at them
     * ------------------------------------------------------------------------------------------ */

    /**
     * Note that an automation looked at a cart, and the run it started (0 if none).
     */
    public static function mark_seen($cart_id, $automation_id, $run_id)
    {
        self::wpdb()->query(self::wpdb()->prepare(
            'INSERT IGNORE INTO ' . self::runs_table() . ' (cart_id, automation_id, run_id, created_at) VALUES (%d, %d, %d, %s)',
            $cart_id, $automation_id, $run_id, self::now()
        ));
    }

    /**
     * Runs started for a cart, by any automation.
     *
     * @return int[]
     */
    public static function run_ids($cart_id)
    {
        return array_map('intval', (array)self::wpdb()->get_col(self::wpdb()->prepare(
            'SELECT run_id FROM ' . self::runs_table() . ' WHERE cart_id = %d AND run_id > 0', $cart_id
        )));
    }

    /**
     * Whether any of the cart's runs is still sending.
     */
    public static function has_active_run($cart_id)
    {
        return (bool)self::wpdb()->get_var(self::wpdb()->prepare(
            'SELECT 1 FROM ' . self::runs_table() . ' cr INNER JOIN ' . AutomationsRepository::runs_table() . ' r ON r.id = cr.run_id
            WHERE cr.cart_id = %d AND r.status = %s LIMIT 1',
            $cart_id, AutomationsRepository::RUN_ACTIVE
        ));
    }

    /**
     * Carts with an email whose last change was between $oldest and $newest, not yet looked at by the
     * automation. Paged by (updated_at, id) so it reads only that window of the status_updated index.
     *
     * @param array|null $after [updated_at, id] of the last cart of the previous page
     *
     * @return array[]
     */
    public static function due_for($automation_id, $newest, $oldest, $after, $limit)
    {
        $after = $after ?: [$oldest, 0];

        return array_map([__CLASS__, 'inflate'], (array)self::wpdb()->get_results(self::wpdb()->prepare(
            'SELECT c.* FROM ' . self::table() . ' c
            WHERE c.status IN (%s, %s) AND c.updated_at <= %s AND (c.updated_at > %s OR (c.updated_at = %s AND c.id > %d))
            AND c.email <> \'\'
            AND NOT EXISTS (SELECT 1 FROM ' . self::runs_table() . ' cr WHERE cr.cart_id = c.id AND cr.automation_id = %d)
            ORDER BY c.updated_at, c.id LIMIT %d',
            self::STATUS_ACTIVE, self::STATUS_ABANDONED, $newest, $after[0], $after[0], $after[1], $automation_id, $limit
        ), 'ARRAY_A'));
    }

    /**
     * The cart an order recovers or completes: the one checked out with it, else (for an order placed
     * at checkout) the newest cart of the same address within the recovery window.
     *
     * @return array|null
     */
    public static function for_order($order_id, $email, $from_checkout)
    {
        $table = self::table();
        $cart  = self::wpdb()->get_row(self::wpdb()->prepare(
            "SELECT * FROM $table WHERE order_id = %d AND status IN (%s, %s) ORDER BY id DESC LIMIT 1",
            $order_id, self::STATUS_ACTIVE, self::STATUS_ABANDONED
        ), 'ARRAY_A');

        if ( ! $cart && $from_checkout && '' !== trim((string)$email)) {
            $cart = self::wpdb()->get_row(self::wpdb()->prepare(
                "SELECT * FROM $table WHERE email = %s AND status IN (%s, %s) AND updated_at >= %s ORDER BY id DESC LIMIT 1",
                strtolower(trim((string)$email)), self::STATUS_ACTIVE, self::STATUS_ABANDONED, self::now(-self::RECOVERY_DAYS * DAY_IN_SECONDS)
            ), 'ARRAY_A');
        }

        return self::inflate($cart);
    }

    /* ---------------------------------------------------------------------------------------------
     * The carts screen
     * ------------------------------------------------------------------------------------------ */

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
            "SELECT c.* FROM $table c WHERE $where ORDER BY c.id DESC LIMIT %d OFFSET %d",
            array_merge($params, [$per_page, $offset])
        ), 'ARRAY_A');

        return [array_map([__CLASS__, 'inflate'], (array)$rows), $total];
    }

    /**
     * Abandoned carts are "in progress" while one of their runs is still active, "lost" after that.
     */
    protected static function view_where($view)
    {
        $active = 'EXISTS (SELECT 1 FROM ' . self::runs_table() . ' cr INNER JOIN ' . AutomationsRepository::runs_table() . ' r ON r.id = cr.run_id WHERE cr.cart_id = c.id AND r.status = %s)';

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
        $table = self::table();
        $row   = self::wpdb()->get_row(self::wpdb()->prepare(
            "SELECT SUM(status = %s) AS abandoned, SUM(status = %s) AS recovered FROM $table WHERE status IN (%s, %s)",
            self::STATUS_ABANDONED, self::STATUS_RECOVERED, self::STATUS_ABANDONED, self::STATUS_RECOVERED
        ), 'ARRAY_A');

        list($where, $params) = self::view_where('in_progress');
        $in_progress = (int)self::wpdb()->get_var(self::wpdb()->prepare("SELECT COUNT(*) FROM $table c WHERE $where", $params));
        $abandoned   = (int)($row['abandoned'] ?? 0);
        $recovered   = (int)($row['recovered'] ?? 0);

        return ['' => $abandoned + $recovered, 'in_progress' => $in_progress, 'recovered' => $recovered, 'lost' => max(0, $abandoned - $in_progress)];
    }

    /**
     * Emails sent for some carts.
     *
     * @return array cart ID => int
     */
    public static function sent_counts($cart_ids)
    {
        $ids = array_filter(array_map('absint', (array)$cart_ids));

        if (empty($ids)) return [];

        $rows = self::wpdb()->get_results(self::wpdb()->prepare(
            'SELECT cr.cart_id, COUNT(*) n FROM ' . self::runs_table() . ' cr INNER JOIN ' . AutomationsRepository::run_steps_table() . ' rs ON rs.run_id = cr.run_id
            WHERE cr.cart_id IN (' . implode(',', $ids) . ') AND cr.run_id > 0 AND rs.status = %s GROUP BY cr.cart_id',
            AutomationsRepository::RUN_STEP_SENT
        ), 'ARRAY_A');

        return array_map('intval', wp_list_pluck((array)$rows, 'n', 'cart_id'));
    }

    /**
     * Carts an automation started for since a time, how many were recovered and the revenue.
     *
     * @return array ['abandoned' => int, 'recovered' => int, 'revenue' => float]
     */
    public static function recovery_stats($automation_id, $since_utc)
    {
        $row = self::wpdb()->get_row(self::wpdb()->prepare(
            'SELECT COUNT(*) AS abandoned, SUM(c.status = %s) AS recovered, SUM(IF(c.status = %s, c.recovered_total, 0)) AS revenue
            FROM ' . self::runs_table() . ' cr INNER JOIN ' . self::table() . ' c ON c.id = cr.cart_id
            WHERE cr.automation_id = %d AND cr.run_id > 0 AND cr.created_at >= %s',
            self::STATUS_RECOVERED, self::STATUS_RECOVERED, $automation_id, $since_utc
        ), 'ARRAY_A');

        return ['abandoned' => (int)($row['abandoned'] ?? 0), 'recovered' => (int)($row['recovered'] ?? 0), 'revenue' => (float)($row['revenue'] ?? 0)];
    }

    /**
     * Carts with an email last changed since a time, for "Who's included" counts.
     *
     * @return array [carts (newest first, at most $limit), total]
     */
    public static function recent($since_utc, $limit = 500)
    {
        $table = self::table();
        $total = (int)self::wpdb()->get_var(self::wpdb()->prepare(
            "SELECT COUNT(*) FROM $table WHERE status IN (%s, %s, %s) AND updated_at >= %s AND email <> ''",
            self::STATUS_ACTIVE, self::STATUS_ABANDONED, self::STATUS_RECOVERED, $since_utc
        ));
        $rows  = self::wpdb()->get_results(self::wpdb()->prepare(
            "SELECT * FROM $table WHERE status IN (%s, %s, %s) AND updated_at >= %s AND email <> '' ORDER BY id DESC LIMIT %d",
            self::STATUS_ACTIVE, self::STATUS_ABANDONED, self::STATUS_RECOVERED, $since_utc, $limit
        ), 'ARRAY_A');

        return [array_map([__CLASS__, 'inflate'], (array)$rows), $total];
    }

    /**
     * Delete carts nobody needs: open or lost ones after 30 days, recovered ones after a year.
     */
    public static function prune()
    {
        $table = self::table();
        $ids   = array_map('intval', (array)self::wpdb()->get_col(self::wpdb()->prepare(
            "(SELECT id FROM $table WHERE status IN (%s, %s) AND updated_at < %s LIMIT 1000)
            UNION ALL (SELECT id FROM $table WHERE status = %s AND recovered_at < %s LIMIT 1000)",
            self::STATUS_ACTIVE, self::STATUS_ABANDONED, self::now(-self::RECOVERY_DAYS * DAY_IN_SECONDS),
            self::STATUS_RECOVERED, self::now(-YEAR_IN_SECONDS)
        )));

        if (empty($ids)) return;

        $in = implode(',', $ids);
        self::wpdb()->query('DELETE FROM ' . self::runs_table() . " WHERE cart_id IN ($in)");
        self::wpdb()->query("DELETE FROM $table WHERE id IN ($in)");
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
        $removed = 0;

        foreach (self::for_email($email) as $cart) $removed += (int)self::delete($cart['id']);

        return $removed;
    }
}
