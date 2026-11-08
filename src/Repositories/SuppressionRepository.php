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
    /** Added by an admin on the Suppression list screen: never emailed, not even to confirm a signup. */
    const REASON_MANUAL = 'manual';

    /** Reasons that stop every email, confirmations included. Only an admin can lift these. */
    const HARD_REASONS = [self::REASON_BOUNCED, self::REASON_COMPLAINED, self::REASON_MANUAL];

    const SOURCE_ADMIN = 'admin';

    public static function table()
    {
        return self::wpdb()->prefix . Core::suppressions_table_name;
    }

    public static function hash($email)
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /**
     * An address already suppressed keeps its reason, except that a bounce or complaint replaces an
     * unsubscribe: those are stickier, since confirming again only lifts an unsubscribe.
     */
    public static function add($email, $reason, $source = '')
    {
        $table = self::table();

        return (bool)self::wpdb()->query(
            self::wpdb()->prepare(
                "INSERT INTO $table (email_hash, reason, source, created_at) VALUES (%s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE reason = IF(reason = 'unsubscribed' AND VALUES(reason) IN ('bounced', 'complained'), VALUES(reason), reason)",
                self::hash($email),
                $reason,
                $source,
                gmdate('Y-m-d H:i:s')
            )
        );
    }

    /**
     * Add many addresses at once. Addresses already suppressed keep their original reason.
     *
     * @param string[] $emails
     * @param string $reason
     * @param string $source
     *
     * @return int number of addresses added
     */
    public static function add_many($emails, $reason, $source = '')
    {
        $table = self::table();
        $now   = gmdate('Y-m-d H:i:s');
        $added = 0;

        $rows = [];
        foreach (array_unique(array_map('strtolower', array_map('trim', (array)$emails))) as $email) {
            if ( ! is_email($email)) continue;

            $rows[] = self::wpdb()->prepare('(%s, %s, %s, %s)', self::hash($email), $reason, $source, $now);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $added += (int)self::wpdb()->query("INSERT IGNORE INTO $table (email_hash, reason, source, created_at) VALUES " . implode(',', $chunk));
        }

        return $added;
    }

    /**
     * @return string|null why the address is suppressed, or null if it isn't
     */
    public static function get_reason($email)
    {
        $table = self::table();

        return self::wpdb()->get_var(self::wpdb()->prepare("SELECT reason FROM $table WHERE email_hash = %s", self::hash($email)));
    }

    /**
     * Lift an unsubscribe recorded by one of these sources only. A bounce or complaint is never lifted.
     *
     * @param string[] $sources
     */
    public static function remove_unsubscribe_from($email, $sources)
    {
        $table = self::table();
        $in    = implode(',', array_fill(0, count($sources), '%s'));

        if (empty($sources)) return false;

        return (bool)self::wpdb()->query(
            self::wpdb()->prepare("DELETE FROM $table WHERE email_hash = %s AND reason = %s AND source IN ($in)", array_merge([self::hash($email), self::REASON_UNSUBSCRIBED], array_values($sources)))
        );
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

    /**
     * The row for an address, by its hash.
     *
     * @return array|null email_hash, reason, source, created_at
     */
    public static function get_by_hash($hash)
    {
        $table = self::table();

        return self::wpdb()->get_row(self::wpdb()->prepare("SELECT email_hash, reason, source, created_at FROM $table WHERE email_hash = %s", (string)$hash), 'ARRAY_A');
    }

    public static function remove_hash($hash)
    {
        return (bool)self::wpdb()->delete(self::table(), ['email_hash' => (string)$hash], ['%s']);
    }

    /**
     * @return int[] reason => number of addresses, plus 'all'
     */
    public static function counts()
    {
        $table  = self::table();
        $counts = ['all' => 0];

        foreach ((array)self::wpdb()->get_results("SELECT reason, COUNT(*) AS n FROM $table GROUP BY reason", 'ARRAY_A') as $row) {
            $counts[$row['reason']] = (int)$row['n'];
            $counts['all']          += (int)$row['n'];
        }

        return $counts;
    }

    /**
     * A page of the list, newest first. Each row carries the subscriber whose address has that hash,
     * if there is one (subscriber_id and email), since the list itself keeps no addresses.
     *
     * @param string $reason '' for every reason
     *
     * @return array[]
     */
    public static function page($reason, $per_page, $offset)
    {
        $table = self::table();
        $where = '' === $reason ? '1=1' : self::wpdb()->prepare('reason = %s', $reason);

        $rows = (array)self::wpdb()->get_results(
            self::wpdb()->prepare("SELECT email_hash, reason, source, created_at FROM $table WHERE $where ORDER BY created_at DESC, email_hash ASC LIMIT %d OFFSET %d", absint($per_page), absint($offset)),
            'ARRAY_A'
        );

        if (empty($rows)) return [];

        $by_hash = self::subscribers_by_hash(array_column($rows, 'email_hash'));

        foreach ($rows as &$row) {
            $row['subscriber_id'] = isset($by_hash[$row['email_hash']]) ? (int)$by_hash[$row['email_hash']]['id'] : 0;
            $row['email']         = isset($by_hash[$row['email_hash']]) ? $by_hash[$row['email_hash']]['email'] : '';
        }

        return $rows;
    }

    /**
     * Subscribers whose address has one of these hashes. Subscriber emails are stored lowercased and
     * trimmed, as hash() expects.
     *
     * @return array hash => ['id' => int, 'email' => string]
     */
    public static function subscribers_by_hash($hashes)
    {
        $hashes = array_values(array_unique(array_filter((array)$hashes)));

        if (empty($hashes)) return [];

        $table = SubscribersRepository::table();
        $in    = implode(',', array_fill(0, count($hashes), '%s'));

        $found = [];
        foreach ((array)self::wpdb()->get_results(self::wpdb()->prepare("SELECT id, email, SHA2(email, 256) AS h FROM $table WHERE SHA2(email, 256) IN ($in)", $hashes), 'ARRAY_A') as $row) {
            $found[$row['h']] = ['id' => (int)$row['id'], 'email' => $row['email']];
        }

        return $found;
    }
}
