<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\Core;
use MailOptin\Core\Repositories\SendQueueRepository as Queue;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\SendQueue\QueueRunner;

/**
 * Double opt-in emails for imported subscribers go through the send queue, so a large import is
 * sent at the hourly limit and retried on failure, instead of thousands of emails at once.
 * Each import has its own campaign log, which shows how many were sent.
 */
class ImportConfirmations
{
    const RECIPIENT_TYPE = 'import_confirmation';

    public function __construct()
    {
        add_filter('mo_send_queue_email_content', [$this, 'content'], 10, 3);
        add_filter('mo_send_queue_skip_reason', [$this, 'skip_reason'], 10, 3);
        add_action('mo_send_queue_email_sent', [$this, 'log_sent'], 10, 2);
    }

    /**
     * @return int campaign log ID for an import's confirmation emails
     */
    public static function create_log($file_name)
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . Core::campaign_log_table_name, [
            'email_campaign_id' => 0,
            'title'             => sprintf(__('Confirmation emails for %s', 'mailoptin'), $file_name),
            'content_html'      => '',
            'content_text'      => '',
            'status'            => QueueRunner::LOG_STATUS_SENDING,
            'status_time'       => current_time('mysql'),
        ]);

        return (int)$wpdb->insert_id;
    }

    /**
     * @param int $campaign_log_id
     * @param array $subscribers rows with id and email
     */
    public static function enqueue($campaign_log_id, $subscribers)
    {
        if (empty($subscribers)) return 0;

        $added = Queue::enqueue($campaign_log_id, 0, array_map(function ($subscriber) {
            return ['email' => $subscriber['email'], 'recipient_type' => self::RECIPIENT_TYPE, 'recipient_id' => $subscriber['id']];
        }, $subscribers));

        if ($added) QueueRunner::get_instance()->start($campaign_log_id, 0, []);

        return $added;
    }

    public function content($content, $row, $campaign_log_id)
    {
        if (self::RECIPIENT_TYPE !== ($row['recipient_type'] ?? '')) return $content;

        $subscriber = Subscribers::get($row['recipient_id']);
        $email      = $subscriber ? Subscription::confirmation_email($subscriber) : null;

        return $email ? ['subject' => $email['subject'], 'html' => $email['html'], 'text' => $email['text']] : $content;
    }

    /**
     * Things change while an import's emails wait in the queue.
     */
    public function skip_reason($reason, $row, $campaign_log_id)
    {
        if ('' !== (string)$reason || self::RECIPIENT_TYPE !== ($row['recipient_type'] ?? '')) return $reason;

        $subscriber = Subscribers::get($row['recipient_id']);

        if ( ! $subscriber) return __('The subscriber was deleted.', 'mailoptin');

        if (SuppressionRepository::is_suppressed($subscriber['email'])) return __('The address unsubscribed or bounced.', 'mailoptin');

        if ( ! Subscription::confirmation_email($subscriber)) return __('Nothing is waiting for confirmation any more.', 'mailoptin');

        return $reason;
    }

    public function log_sent($row, $campaign_log_id)
    {
        if (self::RECIPIENT_TYPE !== ($row['recipient_type'] ?? '')) return;

        $subscriber = Subscribers::get($row['recipient_id']);
        $email      = $subscriber ? Subscription::confirmation_email($subscriber) : null;

        Subscribers::log_event($row['recipient_id'], Subscribers::EVENT_CONFIRMATION_SENT, ['lists' => $email['lists'] ?? []], $campaign_log_id);

        // Unconfirmed signups are deleted a while after their last change; for imports, count from the email.
        if ($subscriber) Subscribers::update($subscriber['id'], []);
    }

    /**
     * @return ImportConfirmations
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}
