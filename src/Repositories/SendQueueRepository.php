<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;

/**
 * One row per recipient per campaign log. All datetimes are UTC.
 *
 * Row lifecycle: pending -> claimed -> sending -> sent | failed | skipped.
 * A failed send that can be retried goes back to pending with a later next_attempt_at.
 */
class SendQueueRepository extends AbstractRepository
{
    const STATUS_PENDING = 'pending';
    const STATUS_CLAIMED = 'claimed';
    const STATUS_SENDING = 'sending';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
    const STATUS_SKIPPED = 'skipped';

    const INSERT_BATCH_SIZE = 500;

    // Marks a row whose send was interrupted part way. It may have gone out, so it is never retried.
    const ATTEMPTS_INTERRUPTED = 9999;

    public static function table()
    {
        return self::wpdb()->prefix . Core::send_queue_table_name;
    }

    public static function now($offset_seconds = 0)
    {
        return gmdate('Y-m-d H:i:s', time() + $offset_seconds);
    }

    /**
     * Add recipients to a campaign's queue. An email already queued for the campaign is ignored.
     *
     * @param int $campaign_log_id
     * @param int $email_campaign_id
     * @param array $recipients each ['email' => string, 'recipient_type' => string, 'recipient_id' => int]
     *
     * @return int number of rows added
     */
    public static function enqueue($campaign_log_id, $email_campaign_id, $recipients)
    {
        $table = self::table();
        $now   = self::now();
        $added = 0;

        $rows = [];
        foreach ($recipients as $recipient) {
            $email = strtolower(trim($recipient['email'] ?? ''));
            if ( ! is_email($email)) continue;

            $rows[] = self::wpdb()->prepare(
                '(%d, %d, %s, %s, %d, %s, %s)',
                $campaign_log_id,
                $email_campaign_id,
                $email,
                $recipient['recipient_type'] ?? 'subscriber',
                absint($recipient['recipient_id'] ?? 0),
                self::STATUS_PENDING,
                $now
            );
        }

        foreach (array_chunk($rows, self::INSERT_BATCH_SIZE) as $chunk) {
            $added += (int)self::wpdb()->query(
                "INSERT IGNORE INTO $table (campaign_log_id, email_campaign_id, email, recipient_type, recipient_id, status, next_attempt_at) VALUES " . implode(',', $chunk)
            );
        }

        return $added;
    }

    /**
     * Atomically reserve up to $limit ready rows for one worker, then return them.
     *
     * @param int $campaign_log_id
     * @param string $worker_id
     * @param int $limit
     * @param int $lease_seconds how long the claim is held before another worker may recover it
     *
     * @return array
     */
    public static function claim($campaign_log_id, $worker_id, $limit, $lease_seconds)
    {
        $table = self::table();

        self::release_expired($campaign_log_id);

        self::wpdb()->query(
            self::wpdb()->prepare(
                "UPDATE $table SET status = %s, claimed_by = %s, claimed_until = %s
                WHERE campaign_log_id = %d AND status = %s AND next_attempt_at <= %s
                ORDER BY id LIMIT %d",
                self::STATUS_CLAIMED,
                $worker_id,
                self::now($lease_seconds),
                $campaign_log_id,
                self::STATUS_PENDING,
                self::now(),
                $limit
            )
        );

        return self::wpdb()->get_results(
            self::wpdb()->prepare(
                "SELECT * FROM $table WHERE campaign_log_id = %d AND status = %s AND claimed_by = %s ORDER BY id",
                $campaign_log_id,
                self::STATUS_CLAIMED,
                $worker_id
            ),
            'ARRAY_A'
        );
    }

    /**
     * Recover rows whose worker died. Rows that were never started go back to pending.
     * Rows that were mid-send are failed, not retried, because the email may already have gone out.
     *
     * @param int $campaign_log_id
     */
    public static function release_expired($campaign_log_id)
    {
        $table = self::table();
        $now   = self::now();

        self::wpdb()->query(
            self::wpdb()->prepare(
                "UPDATE $table SET status = %s, claimed_by = '', claimed_until = NULL
                WHERE campaign_log_id = %d AND status = %s AND claimed_until < %s",
                self::STATUS_PENDING,
                $campaign_log_id,
                self::STATUS_CLAIMED,
                $now
            )
        );

        self::wpdb()->query(
            self::wpdb()->prepare(
                "UPDATE $table SET status = %s, claimed_by = '', claimed_until = NULL, attempts = %d, error = %s
                WHERE campaign_log_id = %d AND status = %s AND claimed_until < %s",
                self::STATUS_FAILED,
                self::ATTEMPTS_INTERRUPTED,
                __('Sending stopped before it finished. Not retried, to avoid sending this email twice.', 'mailoptin'),
                $campaign_log_id,
                self::STATUS_SENDING,
                $now
            )
        );
    }

    /**
     * @param int $id
     * @param string $worker_id
     *
     * @return bool false when the row is no longer claimed by this worker.
     */
    public static function mark_sending($id, $worker_id)
    {
        $table = self::table();

        return 1 === (int)self::wpdb()->query(
                self::wpdb()->prepare(
                    "UPDATE $table SET status = %s, attempts = attempts + 1 WHERE id = %d AND status = %s AND claimed_by = %s",
                    self::STATUS_SENDING,
                    $id,
                    self::STATUS_CLAIMED,
                    $worker_id
                )
            );
    }

    /**
     * Hand an unstarted claimed row back to the pending pool.
     */
    public static function release($id, $worker_id)
    {
        $table = self::table();

        return (bool)self::wpdb()->query(
            self::wpdb()->prepare(
                "UPDATE $table SET status = %s, claimed_by = '', claimed_until = NULL WHERE id = %d AND status = %s AND claimed_by = %s",
                self::STATUS_PENDING,
                $id,
                self::STATUS_CLAIMED,
                $worker_id
            )
        );
    }

    public static function mark_sent($id)
    {
        return self::finish($id, self::STATUS_SENT, null, self::now());
    }

    public static function mark_skipped($id, $reason)
    {
        return self::finish($id, self::STATUS_SKIPPED, $reason);
    }

    /**
     * @param int $id
     * @param string $error
     * @param string|null $retry_at UTC datetime to retry at, or null to fail permanently.
     *
     * @return bool
     */
    public static function mark_failed($id, $error, $retry_at = null)
    {
        if (is_null($retry_at)) {
            return self::finish($id, self::STATUS_FAILED, $error);
        }

        return false !== self::wpdb()->update(
                self::table(),
                [
                    'status'          => self::STATUS_PENDING,
                    'claimed_by'      => '',
                    'claimed_until'   => null,
                    'next_attempt_at' => $retry_at,
                    'error'           => $error,
                ],
                ['id' => $id]
            );
    }

    protected static function finish($id, $status, $error = null, $sent_at = null)
    {
        return false !== self::wpdb()->update(
                self::table(),
                [
                    'status'        => $status,
                    'claimed_by'    => '',
                    'claimed_until' => null,
                    'sent_at'       => $sent_at,
                    'error'         => $error,
                ],
                ['id' => $id]
            );
    }

    /**
     * Put a campaign's failed rows back in the queue, for the Campaign Log "Resend" action.
     * Rows already sent, and rows whose send was interrupted part way, are left alone, so nobody gets the email twice.
     *
     * @return int rows requeued
     */
    public static function retry_failed($campaign_log_id)
    {
        $table = self::table();

        return (int)self::wpdb()->query(
            self::wpdb()->prepare(
                "UPDATE $table SET status = %s, attempts = 0, next_attempt_at = %s, error = NULL WHERE campaign_log_id = %d AND status = %s AND attempts <> %d",
                self::STATUS_PENDING,
                self::now(),
                $campaign_log_id,
                self::STATUS_FAILED,
                self::ATTEMPTS_INTERRUPTED
            )
        );
    }

    /**
     * Cancel a recipient's unsent email in one campaign, for example after they unsubscribe mid-send.
     * A row another worker is sending right now is left alone.
     */
    public static function skip_unsent($campaign_log_id, $email, $reason)
    {
        $table = self::table();

        return (bool)self::wpdb()->query(
            self::wpdb()->prepare(
                "UPDATE $table SET status = %s, claimed_by = '', claimed_until = NULL, error = %s
                WHERE campaign_log_id = %d AND email = %s AND status IN (%s, %s)",
                self::STATUS_SKIPPED,
                $reason,
                $campaign_log_id,
                strtolower(trim($email)),
                self::STATUS_PENDING,
                self::STATUS_CLAIMED
            )
        );
    }

    /**
     * @param int $campaign_log_id
     *
     * @return array status => count, with every status present.
     */
    public static function counts($campaign_log_id)
    {
        $table = self::table();

        $counts = array_fill_keys([
            self::STATUS_PENDING,
            self::STATUS_CLAIMED,
            self::STATUS_SENDING,
            self::STATUS_SENT,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED
        ], 0);

        $rows = self::wpdb()->get_results(
            self::wpdb()->prepare("SELECT status, COUNT(*) AS total FROM $table WHERE campaign_log_id = %d GROUP BY status", $campaign_log_id),
            'ARRAY_A'
        );

        foreach ($rows as $row) {
            $counts[$row['status']] = (int)$row['total'];
        }

        return $counts;
    }

    public static function has_unfinished($campaign_log_id)
    {
        $table = self::table();

        return (bool)self::wpdb()->get_var(
            self::wpdb()->prepare(
                "SELECT 1 FROM $table WHERE campaign_log_id = %d AND status IN (%s, %s, %s) LIMIT 1",
                $campaign_log_id,
                self::STATUS_PENDING,
                self::STATUS_CLAIMED,
                self::STATUS_SENDING
            )
        );
    }

    public static function has_ready($campaign_log_id)
    {
        $table = self::table();

        return (bool)self::wpdb()->get_var(
            self::wpdb()->prepare(
                "SELECT 1 FROM $table WHERE campaign_log_id = %d AND status = %s AND next_attempt_at <= %s LIMIT 1",
                $campaign_log_id,
                self::STATUS_PENDING,
                self::now()
            )
        );
    }

    /**
     * Emails sent across all campaigns since a UTC datetime. Used for the hourly sending limit.
     */
    public static function sent_since($datetime)
    {
        $table = self::table();

        return (int)self::wpdb()->get_var(
            self::wpdb()->prepare("SELECT COUNT(*) FROM $table WHERE status = %s AND sent_at >= %s", self::STATUS_SENT, $datetime)
        );
    }

    /**
     * @return int[] campaign log IDs that still have rows to send.
     */
    public static function campaigns_with_work()
    {
        $table = self::table();

        return array_map('absint', self::wpdb()->get_col(
            self::wpdb()->prepare(
                "SELECT DISTINCT campaign_log_id FROM $table WHERE status IN (%s, %s, %s)",
                self::STATUS_PENDING,
                self::STATUS_CLAIMED,
                self::STATUS_SENDING
            )
        ));
    }

    public static function delete_campaign($campaign_log_id)
    {
        return self::wpdb()->delete(self::table(), ['campaign_log_id' => $campaign_log_id], ['%d']);
    }
}
