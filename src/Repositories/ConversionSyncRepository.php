<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;

/**
 * Whether each submission (conversion) reached the integrations on its form. All datetimes are UTC.
 *
 * Status: sent; retrying (failed, another automatic attempt is scheduled); failed (automatic
 * attempts used up, or a manual retry failed).
 */
class ConversionSyncRepository extends AbstractRepository
{
    const STATUS_SENT = 'sent';
    const STATUS_RETRYING = 'retrying';
    const STATUS_FAILED = 'failed';

    // Submissions saved before this (site local time, like mo_conversions.date_added) have no sync history.
    const TRACKING_SINCE_OPTION = 'mo_submission_sync_tracking_since';

    public static function table()
    {
        return self::wpdb()->prefix . Core::conversion_syncs_table_name;
    }

    public static function now($offset_seconds = 0)
    {
        return gmdate('Y-m-d H:i:s', time() + $offset_seconds);
    }

    /**
     * @param int $conversion_id
     * @param string $connection_service
     * @param string $connection_email_list
     * @param bool $sent
     * @param string $error
     * @param array|null $retry_data what's needed to send again; only kept while it hasn't been sent
     * @param string|null $next_attempt_at UTC; null when there's no automatic retry
     *
     * @return int sync ID
     */
    public static function add($conversion_id, $connection_service, $connection_email_list, $sent, $error = '', $retry_data = null, $next_attempt_at = null)
    {
        $now = self::now();

        self::wpdb()->insert(self::table(), [
            'conversion_id'         => absint($conversion_id),
            'connection_service'    => (string)$connection_service,
            'connection_email_list' => is_scalar($connection_email_list) ? (string)$connection_email_list : '',
            'status'                => $sent ? self::STATUS_SENT : ($next_attempt_at ? self::STATUS_RETRYING : self::STATUS_FAILED),
            'attempts'              => 1,
            'last_error'            => $sent ? null : (string)$error,
            'next_attempt_at'       => $sent ? null : $next_attempt_at,
            'retry_data'            => $sent || null === $retry_data ? null : wp_json_encode($retry_data),
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);

        return (int)self::wpdb()->insert_id;
    }

    public static function get($id)
    {
        $table = self::table();

        return self::wpdb()->get_row(self::wpdb()->prepare("SELECT * FROM $table WHERE id = %d", $id), 'ARRAY_A');
    }

    /**
     * Record the result of another attempt.
     *
     * @param int $id
     * @param bool $sent
     * @param string $error
     * @param string|null $next_attempt_at UTC; null means no further automatic attempt
     */
    public static function record_attempt($id, $sent, $error = '', $next_attempt_at = null)
    {
        $table = self::table();

        $next = ! $sent && $next_attempt_at ? self::wpdb()->prepare('%s', $next_attempt_at) : 'NULL';

        self::wpdb()->query(
            self::wpdb()->prepare(
                "UPDATE $table SET attempts = attempts + 1, status = %s, last_error = %s, next_attempt_at = $next, updated_at = %s" .
                ($sent ? ', retry_data = NULL' : '') . " WHERE id = %d",
                $sent ? self::STATUS_SENT : ($next_attempt_at ? self::STATUS_RETRYING : self::STATUS_FAILED),
                $sent ? '' : (string)$error,
                self::now(),
                $id
            )
        );
    }

    /**
     * Reserve a row before retrying it, so two cron runs don't send the same submission twice.
     * Pushes its next attempt out; the retry's result then replaces it.
     *
     * @return bool true if this caller got the row
     */
    public static function claim($id, $manual = false)
    {
        $table = self::table();
        $sql   = "UPDATE $table SET next_attempt_at = %s, updated_at = %s WHERE id = %d AND retry_data IS NOT NULL AND ";
        $sql  .= $manual ? '(status = %s OR status = %s)' : 'status = %s AND next_attempt_at <= %s';

        return 1 === (int)self::wpdb()->query(
                self::wpdb()->prepare(
                    $sql,
                    self::now(10 * MINUTE_IN_SECONDS),
                    self::now(),
                    $id,
                    $manual ? self::STATUS_FAILED : self::STATUS_RETRYING,
                    $manual ? self::STATUS_RETRYING : self::now()
                )
            );
    }

    /**
     * Hand an unsent row to the automatic retry job straight away.
     */
    public static function schedule_now($id)
    {
        $table = self::table();

        return (bool)self::wpdb()->query(
            self::wpdb()->prepare(
                "UPDATE $table SET status = %s, next_attempt_at = %s, updated_at = %s WHERE id = %d AND status <> %s AND retry_data IS NOT NULL",
                self::STATUS_RETRYING,
                self::now(),
                self::now(),
                $id,
                self::STATUS_SENT
            )
        );
    }

    /**
     * @param int[] $conversion_ids
     *
     * @return array conversion_id => list of sync rows, oldest first
     */
    public static function for_conversions($conversion_ids)
    {
        $conversion_ids = array_filter(array_map('absint', (array)$conversion_ids));

        if (empty($conversion_ids)) return [];

        $table = self::table();
        $in    = implode(',', $conversion_ids);
        $rows  = self::wpdb()->get_results("SELECT * FROM $table WHERE conversion_id IN ($in) ORDER BY id", 'ARRAY_A');

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int)$row['conversion_id']][] = $row;
        }

        return $grouped;
    }

    public static function for_conversion($conversion_id)
    {
        return self::for_conversions([$conversion_id])[(int)$conversion_id] ?? [];
    }

    /**
     * Retrying rows whose next attempt is due.
     *
     * @return array
     */
    public static function due($limit = 20)
    {
        $table = self::table();

        return self::wpdb()->get_results(
            self::wpdb()->prepare(
                "SELECT * FROM $table WHERE status = %s AND next_attempt_at <= %s ORDER BY next_attempt_at LIMIT %d",
                self::STATUS_RETRYING,
                self::now(),
                $limit
            ),
            'ARRAY_A'
        );
    }

    /**
     * Rows that haven't been sent yet and can be tried again by hand.
     *
     * @param int[]|null $conversion_ids limit to these submissions; null for all
     *
     * @return array
     */
    public static function unsent($conversion_ids = null, $limit = 500)
    {
        $table = self::table();
        $where = self::wpdb()->prepare('status IN (%s, %s) AND retry_data IS NOT NULL', self::STATUS_FAILED, self::STATUS_RETRYING);

        if (is_array($conversion_ids)) {
            $conversion_ids = array_filter(array_map('absint', $conversion_ids));
            if (empty($conversion_ids)) return [];
            $where .= ' AND conversion_id IN (' . implode(',', $conversion_ids) . ')';
        }

        return self::wpdb()->get_results(
            self::wpdb()->prepare("SELECT * FROM $table WHERE $where ORDER BY id LIMIT %d", $limit),
            'ARRAY_A'
        );
    }

    /**
     * @param string $since UTC; only submissions first recorded since then
     *
     * @return int submissions with at least one integration not sent yet
     */
    public static function count_unsent_submissions($since = null)
    {
        $table = self::table();
        $sql   = self::wpdb()->prepare("SELECT COUNT(DISTINCT conversion_id) FROM $table WHERE status IN (%s, %s)", self::STATUS_FAILED, self::STATUS_RETRYING);

        if ($since) $sql .= self::wpdb()->prepare(' AND created_at >= %s', $since);

        return (int)self::wpdb()->get_var($sql);
    }

    /**
     * @return array|null the most recently updated row that failed
     */
    public static function latest_failure()
    {
        $table = self::table();

        return self::wpdb()->get_row(
            self::wpdb()->prepare("SELECT * FROM $table WHERE status IN (%s, %s) ORDER BY updated_at DESC, id DESC LIMIT 1", self::STATUS_FAILED, self::STATUS_RETRYING),
            'ARRAY_A'
        );
    }

    public static function delete_for_conversions($conversion_ids)
    {
        $conversion_ids = array_filter(array_map('absint', (array)$conversion_ids));

        if (empty($conversion_ids)) return 0;

        $table = self::table();

        return (int)self::wpdb()->query("DELETE FROM $table WHERE conversion_id IN (" . implode(',', $conversion_ids) . ")");
    }

    /**
     * Remove rows whose submission no longer exists.
     */
    public static function delete_orphans()
    {
        $table       = self::table();
        $conversions = self::wpdb()->prefix . Core::conversions_table_name;

        return (int)self::wpdb()->query("DELETE s FROM $table s LEFT JOIN $conversions c ON c.id = s.conversion_id WHERE c.id IS NULL");
    }

    /**
     * Drop stored form data, for example after a personal data erasure. The rows stay so the
     * history is kept, but they can no longer be retried.
     */
    public static function forget_retry_data($conversion_ids)
    {
        $conversion_ids = array_filter(array_map('absint', (array)$conversion_ids));

        if (empty($conversion_ids)) return 0;

        $table = self::table();

        return (int)self::wpdb()->query(
            self::wpdb()->prepare(
                "UPDATE $table SET retry_data = NULL, status = IF(status = %s, %s, status), next_attempt_at = NULL WHERE conversion_id IN (" . implode(',', $conversion_ids) . ")",
                self::STATUS_RETRYING,
                self::STATUS_FAILED
            )
        );
    }
}
