<?php

namespace MailOptin\Core\OptinForms\Wheel;

/**
 * Every spin of a prize wheel: which campaign, whose email (as a hash), which slice came up and its code,
 * and, for WooCommerce, the order it was used in. Used for "one spin per email", the counts in the
 * Customizer and on the Optin Campaigns list, and Premium's Statistics page.
 *
 * No email address is stored: only a keyed hash of it, enough to tell whether that address has spun.
 */
class Spins
{
    const DB_OPTION = 'mo_wheel_spins_db';
    const DB_VERSION = 2;
    const KEY_OPTION = 'mo_wheel_hash_key';

    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . 'mo_wheel_spins';
    }

    /**
     * Creates (or brings up to date) the table, and marks it done only once it's really there, so a failed
     * install is tried again.
     */
    public static function maybe_install()
    {
        if ((int)get_option(self::DB_OPTION, 0) >= self::DB_VERSION) return;

        global $wpdb;

        $table   = self::table();
        $collate = $wpdb->has_cap('collation') ? $wpdb->get_charset_collate() : '';

        // once_key is the address's hash while "one spin per address" is on, else NULL: the unique key then
        // lets only one spin per address through, even when two arrive at once (NULLs don't collide).
        $wpdb->query("CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            optin_id bigint(20) unsigned NOT NULL,
            email_hash char(64) CHARACTER SET ascii NOT NULL,
            once_key char(64) CHARACTER SET ascii NULL DEFAULT NULL,
            slice_id varchar(20) NOT NULL DEFAULT '',
            slice_label varchar(191) NOT NULL DEFAULT '',
            prize_type varchar(10) NOT NULL DEFAULT '',
            code varchar(100) NOT NULL DEFAULT '',
            token char(32) CHARACTER SET ascii NOT NULL DEFAULT '',
            applied tinyint(1) NOT NULL DEFAULT 0,
            emailed_at datetime NULL DEFAULT NULL,
            used_order bigint(20) unsigned NOT NULL DEFAULT 0,
            used_total decimal(14,4) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY once (optin_id, once_key),
            KEY optin_email (optin_id, email_hash),
            KEY email_code (email_hash, code(32)),
            KEY token (token)
        ) $collate");

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return;

        // A table from the first version: the column and keys it didn't have.
        $columns = (array)$wpdb->get_col("SHOW COLUMNS FROM $table", 0);
        if ( ! in_array('once_key', $columns, true)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN once_key char(64) CHARACTER SET ascii NULL DEFAULT NULL AFTER email_hash, ADD UNIQUE KEY once (optin_id, once_key), ADD KEY email_code (email_hash, code(32))");
        }

        update_option(self::DB_OPTION, self::DB_VERSION, true);
    }

    /**
     * A keyed hash of the address: the same address always gives the same hash on this site. The key is
     * the site's own, made once, so changing WordPress's salts doesn't reset "one spin per address".
     */
    public static function email_hash($email)
    {
        $key = (string)get_option(self::KEY_OPTION, '');
        if ('' === $key) {
            $key = wp_generate_password(64, false, false);
            add_option(self::KEY_OPTION, $key, '', 'no');
            $key = (string)get_option(self::KEY_OPTION, $key);
        }

        return hash_hmac('sha256', strtolower(trim((string)$email)), $key);
    }

    /** @return object|null The address's spin on this campaign, if it has one. */
    public static function find($optin_id, $email)
    {
        global $wpdb;
        self::maybe_install();

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE optin_id = %d AND email_hash = %s AND prize_type <> 'pending' ORDER BY id DESC LIMIT 1",
            $optin_id,
            self::email_hash($email)
        ));
    }

    public static function by_token($token)
    {
        global $wpdb;
        self::maybe_install();

        $token = preg_replace('/[^a-f0-9]/', '', (string)$token);
        if (32 !== strlen($token)) return null;

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE token = %s LIMIT 1', $token));
    }

    /**
     * Reserves this address's spin before the draw: a "pending" row, which the unique key refuses when
     * "one spin per address" is on and the address already has one (two requests at once included).
     *
     * @return int The reserved row's id; 0 when the address has had its spin, or the row couldn't be saved.
     */
    public static function reserve($optin_id, $email, $once)
    {
        global $wpdb;
        self::maybe_install();

        $hash = self::email_hash($email);

        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::table() . " (optin_id, email_hash, once_key, prize_type, created_at) VALUES (%d, %s, " . ($once ? '%s' : 'NULL') . ", 'pending', %s)",
            ...($once ? [absint($optin_id), $hash, $hash, current_time('mysql', true)] : [absint($optin_id), $hash, current_time('mysql', true)])
        ));

        return (int)$wpdb->rows_affected > 0 ? (int)$wpdb->insert_id : 0;
    }

    /** Fills in a reserved spin once the prize is drawn. */
    public static function complete($id, $slice, $code, $token)
    {
        return self::update($id, [
            'slice_id'    => mb_substr((string)$slice['id'], 0, 20),
            'slice_label' => mb_substr((string)$slice['label'], 0, 191),
            'prize_type'  => (string)$slice['type'],
            'code'        => mb_substr((string)$code, 0, 100),
            'token'       => $token,
        ]);
    }

    /** No prize could be drawn: the reserved spin is recorded as one with no prize. */
    public static function complete_empty($id)
    {
        return self::update($id, ['prize_type' => 'none']);
    }

    public static function update($id, $data)
    {
        global $wpdb;

        return $wpdb->update(self::table(), $data, ['id' => absint($id)]);
    }

    /** ['spins' => n, 'won' => n, 'slices' => [slice_id => n]] for a campaign, optionally since a time (GMT). */
    public static function counts($optin_id, $since = '')
    {
        global $wpdb;
        self::maybe_install();

        $where = $wpdb->prepare('optin_id = %d', $optin_id) . " AND prize_type <> 'pending'" . ('' !== $since ? $wpdb->prepare(' AND created_at >= %s', $since) : '');
        $rows  = (array)$wpdb->get_results('SELECT slice_id, prize_type, COUNT(*) AS n FROM ' . self::table() . " WHERE $where GROUP BY slice_id, prize_type", ARRAY_A);

        $out = ['spins' => 0, 'won' => 0, 'slices' => []];
        foreach ($rows as $r) {
            $n                            = (int)$r['n'];
            $out['spins']                 += $n;
            $out['slices'][$r['slice_id']] = ($out['slices'][$r['slice_id']] ?? 0) + $n;
            if ('none' !== $r['prize_type']) $out['won'] += $n;
        }

        return $out;
    }

    /** Spins and wins of many campaigns at once, for the Optin Campaigns list. */
    public static function totals($optin_ids)
    {
        global $wpdb;
        self::maybe_install();

        $ids = array_filter(array_map('absint', (array)$optin_ids));
        if ( ! $ids) return [];

        $rows = (array)$wpdb->get_results(
            'SELECT optin_id, COUNT(*) AS spins, SUM(prize_type <> \'none\') AS won FROM ' . self::table() . ' WHERE optin_id IN (' . implode(',', $ids) . ") AND prize_type <> 'pending' GROUP BY optin_id",
            ARRAY_A
        );

        $out = [];
        foreach ($rows as $r) $out[(int)$r['optin_id']] = ['spins' => (int)$r['spins'], 'won' => (int)$r['won']];

        return $out;
    }

    public static function delete_campaign($optin_id)
    {
        global $wpdb;

        // Quietly: on a site where no wheel was ever made the table isn't there.
        $quiet = $wpdb->suppress_errors(true);
        $wpdb->delete(self::table(), ['optin_id' => absint($optin_id)]);
        $wpdb->suppress_errors($quiet);
    }
}
