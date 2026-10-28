<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;

/**
 * Addresses that must never be emailed. Stores a hash, not the address, so the record
 * survives a GDPR erasure without keeping the email itself.
 */
class SuppressionRepository extends AbstractRepository
{
    const REASON_UNSUBSCRIBED = 'unsubscribed';
    const REASON_BOUNCED = 'bounced';
    const REASON_COMPLAINED = 'complained';
    const REASON_ERASED = 'erased';

    public static function table()
    {
        return self::wpdb()->prefix . Core::suppressions_table_name;
    }

    public static function hash($email)
    {
        return hash('sha256', strtolower(trim($email)));
    }

    public static function add($email, $reason, $source = '')
    {
        $table = self::table();

        return (bool)self::wpdb()->query(
            self::wpdb()->prepare(
                "INSERT IGNORE INTO $table (email_hash, reason, source, created_at) VALUES (%s, %s, %s, %s)",
                self::hash($email),
                $reason,
                $source,
                gmdate('Y-m-d H:i:s')
            )
        );
    }

    /**
     * @return string|null why the address is suppressed, or null if it isn't
     */
    public static function get_reason($email)
    {
        $table = self::table();

        return self::wpdb()->get_var(self::wpdb()->prepare("SELECT reason FROM $table WHERE email_hash = %s", self::hash($email)));
    }

    public static function remove($email)
    {
        return (bool)self::wpdb()->delete(self::table(), ['email_hash' => self::hash($email)], ['%s']);
    }

    public static function is_suppressed($email)
    {
        return in_array(strtolower(trim($email)), self::filter_suppressed([$email]), true);
    }

    /**
     * @param string[] $emails
     *
     * @return string[] the lowercased emails from $emails that are suppressed.
     */
    public static function filter_suppressed($emails)
    {
        if (empty($emails)) return [];

        $hash_to_email = [];
        foreach ($emails as $email) {
            $hash_to_email[self::hash($email)] = strtolower(trim($email));
        }

        $table        = self::table();
        $placeholders = implode(',', array_fill(0, count($hash_to_email), '%s'));

        $found = self::wpdb()->get_col(
            self::wpdb()->prepare("SELECT email_hash FROM $table WHERE email_hash IN ($placeholders)", array_keys($hash_to_email))
        );

        return array_values(array_intersect_key($hash_to_email, array_flip($found)));
    }
}
