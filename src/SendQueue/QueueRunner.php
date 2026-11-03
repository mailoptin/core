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

    const BLOCKED_OPTION = 'mo_send_queue_blocked';

    const BLOCK_SECONDS = 900;

    /** @var float start of the current second, for the sending service's per-second limit */
    protected $window_start = 0.0;

    /** @var int emails sent in it */
    protected $window_count = 0;

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
        // While sending is stopped, the cron tick picks it back up; dispatching would only spin.
        if ( ! self::blocked() && $this->is_sending($campaign_log_id) && Queue::has_ready($campaign_log_id) && $this->hourly_allowance() > 0) {
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
        if ( ! $this->is_sending($campaign_log_id) || self::blocked()) return 0;

        $deadline   = $deadline ?: $this->deadline();
        $chunk_size = max(1, (int)apply_filters('mo_send_queue_chunk_size', 50));
        $handled    = 0;

        while (time() < $deadline && $this->is_sending($campaign_log_id)) {

            $allowance = $this->hourly_allowance();
            if ($allowance <= 0) break;

            $rows = Queue::claim($campaign_log_id, $this->worker_id, min($chunk_size, $allowance), $this->lease_seconds());
            if (empty($rows)) break;

            $suppressed = SuppressionRepository::filter_suppressed(wp_list_pluck($rows, 'email'));
            $to_send    = [];

            foreach ($rows as $row) {

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

                $to_send[] = $row;
            }

            // Each recipient type can have its own sender.
            $by_type = [];
            foreach ($to_send as $row) $by_type[$row['recipient_type']][] = $row;

            foreach ($by_type as $type_rows) {
                $handled += $this->send_rows($type_rows, $campaign_log_id, $deadline);
            }

            if (self::blocked()) break;
        }

        $this->maybe_complete($campaign_log_id);

        return $handled;
    }

    /**
     * The sender for a campaign: wp_mail() unless a sending service takes over.
     *
     * @return SenderInterface
     */
    protected function sender($recipient_type, $campaign_log_id)
    {
        return apply_filters('mo_send_queue_sender', new WpMailSender(), $recipient_type, $campaign_log_id);
    }

    /**
     * Send claimed rows, in batches when the sender can, at no more than its emails-per-second limit.
     * Rows left when the deadline comes, or when sending stops, go back to the pending pool.
     *
     * @return int rows sent or failed
     */
    protected function send_rows(array $rows, $campaign_log_id, $deadline)
    {
        if (empty($rows)) return 0;

        $sender  = $this->sender($rows[0]['recipient_type'], $campaign_log_id);
        $batch   = $sender instanceof BatchSenderInterface ? max(1, (int)$sender->batch_size()) : 1;
        $per_sec = $sender instanceof BatchSenderInterface ? (int)$sender->max_per_second() : 0;
        if ($per_sec > 0) $batch = min($batch, $per_sec);

        $handled = 0;

        while ( ! empty($rows)) {

            if (time() >= $deadline || self::blocked()) {
                // Hand back unstarted rows now rather than waiting for the lease to expire.
                foreach ($rows as $row) Queue::release($row['id'], $this->worker_id);
                break;
            }

            // Keep to the service's per-second limit: wait out the rest of the second when it's used up.
            // The window spans every chunk this worker sends, not just this call.
            if ($per_sec > 0 && $this->window_count >= $per_sec) {
                $elapsed = microtime(true) - $this->window_start;
                if ($elapsed < 1) usleep((int)((1 - $elapsed) * 1000000));
                $this->window_start = microtime(true);
                $this->window_count = 0;
            } elseif (microtime(true) - $this->window_start >= 1) {
                $this->window_start = microtime(true);
                $this->window_count = 0;
            }

            $chunk = [];
            foreach (array_splice($rows, 0, $batch) as $row) {
                if (Queue::mark_sending($row['id'], $this->worker_id)) $chunk[$row['id']] = $row;
            }

            if (empty($chunk)) continue;

            $started = time();

            if ($sender instanceof BatchSenderInterface) {
                $results = $sender->send_batch($chunk, $campaign_log_id);
            } else {
                $row     = reset($chunk);
                $results = [$row['id'] => $sender->send($row, $campaign_log_id)];
            }

            foreach ($chunk as $id => $row) {
                $this->handle_result($row, $results[$id] ?? new \WP_Error('mo_no_result', __('The sender returned no result.', 'mailoptin'), ['retryable' => true]), $campaign_log_id, $started);
                $handled++;
            }

            $this->window_count += count($chunk);
        }

        return $handled;
    }

    /**
     * @param int $started when this email's batch was sent: a stop set after that isn't cleared by it
     */
    protected function handle_result($row, $result, $campaign_log_id, $started = 0)
    {
        if (true === $result) {
            Queue::mark_sent($row['id']);

            // An email got through, so a stop from before this batch is over. One set while it was
            // being sent (by another worker) stays.
            $notice = self::block_notice();
            if (null !== $notice && (int)($notice['since'] ?? 0) < $started) self::unblock();

            do_action('mo_send_queue_email_sent', $row, $campaign_log_id);

            return;
        }

        $message   = $result instanceof \WP_Error ? $result->get_error_message() : __('Unknown sending error.', 'mailoptin');
        $data      = $result instanceof \WP_Error ? $result->get_error_data() : [];
        $retryable = is_array($data) && ! empty($data['retryable']);
        $attempts  = (int)$row['attempts'] + 1;

        // The credentials or the account are the problem, not this email: put it back without using
        // up an attempt, and stop sending until it's fixed.
        if (is_array($data) && ! empty($data['stop'])) {
            self::block($message);
            Queue::requeue($row['id'], $message, Queue::now(self::BLOCK_SECONDS));

            return;
        }

        // A rate limit isn't the email's fault either: wait, without using up an attempt.
        if (is_array($data) && ! empty($data['throttled'])) {
            Queue::requeue($row['id'], $message, Queue::now(max(MINUTE_IN_SECONDS, (int)($data['retry_after'] ?? 0))));

            return;
        }

        if ($retryable && $attempts < $this->max_attempts()) {
            $delay = is_array($data) && ! empty($data['retry_after']) ? max((int)$data['retry_after'], $this->retry_delay($attempts)) : $this->retry_delay($attempts);
            Queue::mark_failed($row['id'], $message, Queue::now($delay));

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

    /**
     * Stop all sending for a while, because the sending service refused the credentials or the account.
     * Sending tries again after BLOCK_SECONDS, or as soon as the settings are saved.
     */
    public static function block($error)
    {
        update_option(self::BLOCKED_OPTION, ['error' => (string)$error, 'until' => time() + self::BLOCK_SECONDS, 'since' => time()], false);
    }

    public static function unblock()
    {
        delete_option(self::BLOCKED_OPTION);
    }

    /**
     * @return array|null error, until and since, while sending is stopped
     */
    public static function blocked()
    {
        $blocked = get_option(self::BLOCKED_OPTION);

        if ( ! is_array($blocked) || empty($blocked['until'])) return null;

        return (int)$blocked['until'] > time() ? $blocked : null;
    }

    /**
     * The last time sending was stopped, while it hasn't been fixed: shown until the settings are saved
     * or an email goes out.
     *
     * @return array|null
     */
    public static function block_notice()
    {
        $blocked = get_option(self::BLOCKED_OPTION);

        return is_array($blocked) && ! empty($blocked['error']) ? $blocked : null;
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
