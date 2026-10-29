<?php

namespace MailOptin\Core\SendQueue;

use MailOptin\Core\AsyncHandler\AsyncHandler;
use MailOptin\Core\Connections\AbstractConnect;
use MailOptin\Core\Logging\CampaignLogRepository;
use MailOptin\Core\PluginSettings\Settings;
use MailOptin\Core\Repositories\AbstractCampaignLogMeta;
use MailOptin\Core\Repositories\SendQueueRepository as Queue;
use MailOptin\Core\Repositories\SuppressionRepository;

/**
 * Sends queued campaign emails in small chunks.
 *
 * Chunks run through the existing AsyncHandler. A one-minute WP-Cron tick, a secret cron URL
 * and `wp mailoptin queue run` pick up anything the async request missed.
 */
class QueueRunner
{
    const ASYNC_ACTION = 'mo_send_queue_chunk';
    const CRON_HOOK = 'mo_send_queue_tick';
    const RUN_KEY_OPTION = 'mo_send_queue_run_key';
    const STATE_META_KEY = 'mo_send_queue_state';

    const STATE_SENDING = 'sending';
    const STATE_PAUSED = 'paused';

    const LOG_STATUS_SENDING = 'sending';
    const LOG_STATUS_PAUSED = 'paused';
    const LOG_STATUS_PROCESSED = 'processed';

    protected $worker_id;

    public function __construct()
    {
        $this->worker_id = substr(md5(uniqid('', true)), 0, 32);

        add_action('mailoptin_async_handler_job', [$this, 'async_job'], 10, 2);
        add_action(self::CRON_HOOK, [$this, 'run_all']);
        add_action('init', [$this, 'cron_url_handler']);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('mailoptin queue', QueueCommand::class);
        }
    }

    /**
     * Queue recipients and start sending. Can be called several times for one campaign to add
     * recipients in batches; an address already queued for the campaign is ignored.
     *
     * @param int $campaign_log_id
     * @param int $email_campaign_id
     * @param array $recipients see SendQueueRepository::enqueue()
     *
     * @return int number of recipients added
     */
    public function start($campaign_log_id, $email_campaign_id, $recipients)
    {
        $added = Queue::enqueue($campaign_log_id, $email_campaign_id, $recipients);

        // A paused campaign stays paused; new recipients wait for Resume. For example, a paused
        // welcome email keeps queueing new subscribers without sending to them.
        if (self::STATE_PAUSED === AbstractCampaignLogMeta::get_campaignlog_meta($campaign_log_id, self::STATE_META_KEY)) {
            return $added;
        }

        AbstractCampaignLogMeta::update_campaignlog_meta($campaign_log_id, self::STATE_META_KEY, self::STATE_SENDING);
        $this->update_log_status($campaign_log_id, self::LOG_STATUS_SENDING);

        $this->dispatch($campaign_log_id);

        return $added;
    }

    public function pause($campaign_log_id)
    {
        AbstractCampaignLogMeta::update_campaignlog_meta($campaign_log_id, self::STATE_META_KEY, self::STATE_PAUSED);
        $this->update_log_status($campaign_log_id, self::LOG_STATUS_PAUSED);
    }

    public function resume($campaign_log_id)
    {
        AbstractCampaignLogMeta::update_campaignlog_meta($campaign_log_id, self::STATE_META_KEY, self::STATE_SENDING);
        $this->update_log_status($campaign_log_id, self::LOG_STATUS_SENDING);
        $this->dispatch($campaign_log_id);
    }

    public function is_sending($campaign_log_id)
    {
        return self::STATE_SENDING === AbstractCampaignLogMeta::get_campaignlog_meta($campaign_log_id, self::STATE_META_KEY);
    }

    public function dispatch($campaign_log_id)
    {
        AsyncHandler::push_to_queue(self::ASYNC_ACTION, ['campaign_log_id' => absint($campaign_log_id)]);
        $this->schedule_tick();
    }

    public function async_job($action, $item)
    {
        if (self::ASYNC_ACTION !== $action || empty($item['campaign_log_id'])) return;

        $campaign_log_id = absint($item['campaign_log_id']);

        $this->process($campaign_log_id);

        // Keep going straight away while there is ready work and room under the hourly limit.
        // Otherwise the cron tick picks it up later (retries waiting, or limit reached).
        if ($this->is_sending($campaign_log_id) && Queue::has_ready($campaign_log_id) && $this->hourly_allowance() > 0) {
            $this->dispatch($campaign_log_id);
        }
    }

    /**
     * Process every campaign that has work. Used by the cron tick, the cron URL and WP-CLI.
     *
     * @return int emails handled
     */
    public function run_all()
    {
        $handled  = 0;
        $deadline = $this->deadline();

        foreach (Queue::campaigns_with_work() as $campaign_log_id) {
            if (time() >= $deadline) break;

            if ($this->is_sending($campaign_log_id)) {
                $handled += $this->process($campaign_log_id, $deadline);
            }
        }

        if ( ! empty(Queue::campaigns_with_work())) {
            $this->schedule_tick();
        }

        return $handled;
    }

    /**
     * Send ready rows for one campaign until the time budget, the hourly limit or the ready rows run out.
     *
     * @param int $campaign_log_id
     * @param int|null $deadline unix time to stop by; defaults to now plus the time budget.
     *
     * @return int emails handled (sent, failed or skipped)
     */
    public function process($campaign_log_id, $deadline = null)
    {
        if ( ! $this->is_sending($campaign_log_id)) return 0;

        $deadline   = $deadline ?: $this->deadline();
        $chunk_size = max(1, (int)apply_filters('mo_send_queue_chunk_size', 50));
        $handled    = 0;

        while (time() < $deadline && $this->is_sending($campaign_log_id)) {

            $allowance = $this->hourly_allowance();
            if ($allowance <= 0) break;

            $rows = Queue::claim($campaign_log_id, $this->worker_id, min($chunk_size, $allowance), $this->lease_seconds());
            if (empty($rows)) break;

            $suppressed = SuppressionRepository::filter_suppressed(wp_list_pluck($rows, 'email'));

            foreach ($rows as $row) {

                if (time() >= $deadline) {
                    // Hand back unstarted rows now rather than waiting for the lease to expire.
                    Queue::release($row['id'], $this->worker_id);
                    continue;
                }

                if (in_array($row['email'], $suppressed, true)) {
                    Queue::mark_skipped($row['id'], __('Unsubscribed or suppressed.', 'mailoptin'));
                    $handled++;
                    continue;
                }

                // A connection can return a reason to skip a recipient, such as a deleted user.
                $skip_reason = apply_filters('mo_send_queue_skip_reason', '', $row, $campaign_log_id);

                if ('' !== (string)$skip_reason) {
                    Queue::mark_skipped($row['id'], (string)$skip_reason);
                    $handled++;
                    continue;
                }

                if ( ! Queue::mark_sending($row['id'], $this->worker_id)) continue;

                $this->send_row($row, $campaign_log_id);
                $handled++;
            }
        }

        $this->maybe_complete($campaign_log_id);

        return $handled;
    }

    protected function send_row($row, $campaign_log_id)
    {
        $sender = apply_filters('mo_send_queue_sender', new WpMailSender(), $row['recipient_type'], $campaign_log_id);

        $result = $sender->send($row, $campaign_log_id);

        if (true === $result) {
            Queue::mark_sent($row['id']);
            do_action('mo_send_queue_email_sent', $row, $campaign_log_id);

            return;
        }

        $message   = $result instanceof \WP_Error ? $result->get_error_message() : __('Unknown sending error.', 'mailoptin');
        $data      = $result instanceof \WP_Error ? $result->get_error_data() : [];
        $retryable = is_array($data) && ! empty($data['retryable']);
        $attempts  = (int)$row['attempts'] + 1;

        if ($retryable && $attempts < $this->max_attempts()) {
            Queue::mark_failed($row['id'], $message, Queue::now($this->retry_delay($attempts)));

            return;
        }

        Queue::mark_failed($row['id'], $message);

        AbstractConnect::save_campaign_error_log(
            sprintf('Email address: %s; Note: %s', $row['email'], $message),
            $campaign_log_id,
            $row['email_campaign_id']
        );

        do_action('mo_send_queue_email_failed', $row, $campaign_log_id, $message);
    }

    protected function maybe_complete($campaign_log_id)
    {
        if (Queue::has_unfinished($campaign_log_id)) return;

        AbstractCampaignLogMeta::delete_campaignlog_meta($campaign_log_id, self::STATE_META_KEY);
        $this->update_log_status($campaign_log_id, self::LOG_STATUS_PROCESSED);

        // Recipients queued while this was finishing (a welcome email for a new signup) would otherwise
        // wait for the next start(), so pick the campaign back up.
        if (Queue::has_unfinished($campaign_log_id)) {
            AbstractCampaignLogMeta::update_campaignlog_meta($campaign_log_id, self::STATE_META_KEY, self::STATE_SENDING);
            $this->update_log_status($campaign_log_id, self::LOG_STATUS_SENDING);
            $this->dispatch($campaign_log_id);

            return;
        }

        do_action('mo_send_queue_campaign_completed', $campaign_log_id, Queue::counts($campaign_log_id));
    }

    protected function update_log_status($campaign_log_id, $status)
    {
        CampaignLogRepository::instance()->updateStatus($campaign_log_id, $status);
        CampaignLogRepository::instance()->updateStatusTime($campaign_log_id, current_time('mysql'));
    }

    /**
     * The most emails sent in an hour, across all campaigns.
     */
    public static function hourly_limit()
    {
        $setting = absint(Settings::instance()->send_queue_hourly_limit(1000));

        return (int)apply_filters('mo_send_queue_hourly_limit', $setting > 0 ? $setting : 1000);
    }

    public function hourly_allowance()
    {
        return self::hourly_limit() - Queue::sent_since(Queue::now(-HOUR_IN_SECONDS));
    }

    protected function deadline()
    {
        return time() + (int)apply_filters('mo_send_queue_time_budget', 20);
    }

    protected function lease_seconds()
    {
        return (int)apply_filters('mo_send_queue_lease_seconds', 300);
    }

    protected function max_attempts()
    {
        return (int)apply_filters('mo_send_queue_max_attempts', 3);
    }

    /**
     * 1, 2, 4 ... minutes, capped at one hour.
     */
    protected function retry_delay($attempts)
    {
        return (int)min(HOUR_IN_SECONDS, MINUTE_IN_SECONDS * pow(2, max(0, $attempts - 1)));
    }

    protected function schedule_tick()
    {
        if ( ! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::CRON_HOOK);
        }
    }

    public static function run_key()
    {
        $key = get_option(self::RUN_KEY_OPTION);

        if (empty($key)) {
            $key = wp_generate_password(32, false);
            update_option(self::RUN_KEY_OPTION, $key, false);
        }

        return $key;
    }

    public static function regenerate_run_key()
    {
        delete_option(self::RUN_KEY_OPTION);

        return self::run_key();
    }

    public static function run_url()
    {
        return add_query_arg('mo_queue_run', self::run_key(), home_url('/'));
    }

    /**
     * Lets a real server cron call https://example.com/?mo_queue_run=<key> every minute.
     */
    public function cron_url_handler()
    {
        if (empty($_GET['mo_queue_run'])) return;

        nocache_headers();

        $key = sanitize_text_field(wp_unslash($_GET['mo_queue_run']));

        if ( ! hash_equals(self::run_key(), $key)) {
            wp_send_json_error(['message' => 'Invalid key'], 403);
        }

        wp_send_json_success(['handled' => $this->run_all()]);
    }

    /**
     * @return QueueRunner
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
