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
    const DB_VERSION = 1;

    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . 'mo_wheel_spins';
    }

    public static function maybe_install()
    {
        if ((int)get_option(self::DB_OPTION, 0) >= self::DB_VERSION) return;

        global $wpdb;

        $table   = self::table();
        $collate = $wpdb->has_cap('collation') ? $wpdb->get_charset_collate() : '';

        $wpdb->query("CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            optin_id bigint(20) unsigned NOT NULL,
            email_hash char(64) CHARACTER SET ascii NOT NULL,
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
            KEY optin_email (optin_id, email_hash),
            KEY code (code(32)),
            KEY token (token)
        ) $collate");

        update_option(self::DB_OPTION, self::DB_VERSION, true);
    }

    /** A keyed hash of the address: the same address always gives the same hash on this site. */
    public static function email_hash($email)
    {
        return hash_hmac('sha256', strtolower(trim((string)$email)), wp_salt('auth'));
    }

    /** @return object|null The address's spin on this campaign, if it has one. */
    public static function find($optin_id, $email)
    {
        global $wpdb;
        self::maybe_install();

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE optin_id = %d AND email_hash = %s ORDER BY id DESC LIMIT 1',
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

    /** @return int The new spin's id, 0 when it couldn't be saved. */
    public static function add($optin_id, $email, $slice, $code, $token)
    {
        global $wpdb;
        self::maybe_install();

        $ok = $wpdb->insert(self::table(), [
            'optin_id'    => absint($optin_id),
            'email_hash'  => self::email_hash($email),
            'slice_id'    => mb_substr((string)$slice['id'], 0, 20),
            'slice_label' => mb_substr((string)$slice['label'], 0, 191),
            'prize_type'  => (string)$slice['type'],
            'code'        => mb_substr((string)$code, 0, 100),
            'token'       => $token,
            'created_at'  => current_time('mysql', true),
        ]);

        return $ok ? (int)$wpdb->insert_id : 0;
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

        $where = $wpdb->prepare('optin_id = %d', $optin_id) . ('' !== $since ? $wpdb->prepare(' AND created_at >= %s', $since) : '');
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
            'SELECT optin_id, COUNT(*) AS spins, SUM(prize_type <> \'none\') AS won FROM ' . self::table() . ' WHERE optin_id IN (' . implode(',', $ids) . ') GROUP BY optin_id',
            ARRAY_A
        );

        $out = [];
        foreach ($rows as $r) $out[(int)$r['optin_id']] = ['spins' => (int)$r['spins'], 'won' => (int)$r['won']];

        return $out;
    }

    public static function delete_campaign($optin_id)
    {
        global $wpdb;

        if ((int)get_option(self::DB_OPTION, 0) < 1) return;

        $wpdb->delete(self::table(), ['optin_id' => absint($optin_id)]);
    }
}
