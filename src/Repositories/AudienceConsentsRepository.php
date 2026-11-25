<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;

/**
 * Marketing consent given by customers, users and members who aren't necessarily on a list:
 * ticking the box at checkout or registration, or withdrawing it from their profile.
 */
class AudienceConsentsRepository extends AbstractRepository
{
    const STATUS_AGREED = 'agreed';
    const STATUS_WITHDRAWN = 'withdrawn';

    public static function table()
    {
        return self::wpdb()->prefix . Core::audience_consents_table_name;
    }

    protected static $ready = null;

    public static function is_ready()
    {
        if (null === self::$ready) {
            self::$ready = self::table() === self::wpdb()->get_var(self::wpdb()->prepare('SHOW TABLES LIKE %s', self::wpdb()->esc_like(self::table())));
        }

        return self::$ready;
    }

    /** After the table is created or dropped (tests, upgrades). */
    public static function reset_ready()
    {
        self::$ready = null;
    }

    protected static function now()
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Record that $email agreed to marketing from $source. A later agreement replaces an earlier
     * withdrawal: they ticked the box again.
     *
     * @param string $reference where they agreed, like "Order #1042"
     */
    public static function agree($source, $email, $consent_text, $reference = '')
    {
        return self::save($source, $email, self::STATUS_AGREED, $consent_text, $reference);
    }

    public static function withdraw($source, $email, $reference = '')
    {
        return self::save($source, $email, self::STATUS_WITHDRAWN, null, $reference);
    }

    protected static function save($source, $email, $status, $consent_text, $reference)
    {
        $email = strtolower(trim((string)$email));

        if ( ! is_email($email) || '' === (string)$source || ! self::is_ready()) return false;

        $table = self::table();
        $now   = self::now();

        // Withdrawing keeps the text they agreed to, as a record of what was withdrawn.
        return false !== self::wpdb()->query(self::wpdb()->prepare(
                "INSERT INTO $table (source, email, status, consent_text, reference, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE status = VALUES(status), consent_text = IF(VALUES(status) = 'agreed', VALUES(consent_text), consent_text),
                reference = IF(VALUES(reference) = '', reference, VALUES(reference)), updated_at = VALUES(updated_at)",
                substr((string)$source, 0, 40),
                $email,
                $status,
                (string)$consent_text,
                substr((string)$reference, 0, 100),
                $now,
                $now
            ));
    }

    public static function withdraw_by_id($id)
    {
        if ( ! self::is_ready()) return false;

        return false !== self::wpdb()->update(self::table(), ['status' => self::STATUS_WITHDRAWN, 'updated_at' => self::now()], ['id' => absint($id)], ['%s', '%s'], ['%d']);
    }

    /**
     * @return array|null the row for this source and address
     */
    public static function get($source, $email)
    {
        if ( ! self::is_ready()) return null;

        $table = self::table();

        return self::wpdb()->get_row(
            self::wpdb()->prepare("SELECT * FROM $table WHERE source = %s AND email = %s", $source, strtolower(trim((string)$email))),
            'ARRAY_A'
        ) ?: null;
    }

    /**
     * @return array every source's row for this address
     */
    public static function for_email($email)
    {
        if ( ! self::is_ready()) return [];

        $table = self::table();

        return self::wpdb()->get_results(
            self::wpdb()->prepare("SELECT * FROM $table WHERE email = %s ORDER BY source", strtolower(trim((string)$email))),
            'ARRAY_A'
        ) ?: [];
    }

    /** Erasing someone's personal data. */
    public static function delete_email($email)
    {
        if ( ! self::is_ready()) return 0;

        return (int)self::wpdb()->delete(self::table(), ['email' => strtolower(trim((string)$email))], ['%s']);
    }
}
