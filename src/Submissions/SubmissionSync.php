<?php

namespace MailOptin\Core\Submissions;

use MailOptin\Core\AjaxHandler;
use MailOptin\Core\Connections\AbstractConnect;
use MailOptin\Core\OptinForms\ConversionDataBuilder;
use MailOptin\Core\Repositories\ConversionSyncRepository as Syncs;

/**
 * Sends a submission to an integration and records whether it got there, so the Submissions
 * screen can show failures and they can be sent again: automatically a few times, then by hand.
 */
class SubmissionSync
{
    const CRON_HOOK = 'mo_submission_sync_retry';

    /** @var string[] errors integrations logged during the current send */
    protected static $captured = [];
    protected static $capturing = false;

    public function __construct()
    {
        add_action('mo_optin_error_logged', [__CLASS__, 'capture_error']);
        add_action(self::CRON_HOOK, [__CLASS__, 'run_due']);
        add_action('mo_hourly_recurring_job', [__CLASS__, 'run_due']);
    }

    /**
     * Integrations log the real reason (an API error) and return a generic message to the visitor,
     * so keep what they log while a send is in progress.
     */
    public static function capture_error($message)
    {
        if (self::$capturing && is_string($message) && '' !== trim($message)) {
            self::$captured[] = wp_strip_all_tags($message);
        }
    }

    /**
     * Delays before each automatic retry: about 30 minutes, 4 hours and 20 hours, so three
     * retries over roughly a day.
     *
     * @return int[] seconds
     */
    public static function retry_delays()
    {
        return array_map('absint', (array)apply_filters('mo_submission_sync_retry_delays', [30 * MINUTE_IN_SECONDS, 4 * HOUR_IN_SECONDS, 20 * HOUR_IN_SECONDS]));
    }

    /**
     * @param int $attempts attempts made so far
     *
     * @return string|null UTC time of the next automatic attempt, or null when there are none left
     */
    public static function next_attempt_at($attempts)
    {
        $delays = self::retry_delays();

        return isset($delays[$attempts - 1]) ? Syncs::now($delays[$attempts - 1]) : null;
    }

    /**
     * Send to one integration and record the result.
     *
     * @param int $conversion_id 0 when the submission wasn't saved; the send then isn't tracked
     * @param string $connection_service
     * @param string $connection_email_list
     * @param ConversionDataBuilder $conversion_data
     *
     * @return array the integration's ajax response
     */
    public static function send($conversion_id, $connection_service, $connection_email_list, $conversion_data)
    {
        if ( ! $conversion_id) {
            return AjaxHandler::add_lead_to_connection($connection_service, $connection_email_list, $conversion_data);
        }

        $retry_data = self::retry_data($conversion_data);

        list($response, $error) = self::attempt($connection_service, $connection_email_list, $conversion_data);

        $sent = AbstractConnect::is_ajax_success($response);
        $next = $sent ? null : self::next_attempt_at(1);

        Syncs::add($conversion_id, $connection_service, $connection_email_list, $sent, $error, $retry_data, $next);

        if ($next) self::schedule($next);

        return $response;
    }

    /**
     * Send a recorded submission again.
     *
     * @param int $sync_id
     * @param bool $manual an admin asked for it; otherwise it's an automatic retry that must be due
     *
     * @return true|\WP_Error
     */
    public static function retry($sync_id, $manual = false)
    {
        $sync = Syncs::get($sync_id);

        if ( ! $sync || empty($sync['retry_data']) || Syncs::STATUS_SENT === $sync['status']) {
            return new \WP_Error('mo_sync_not_retryable', __('This submission can no longer be sent again.', 'mailoptin'));
        }

        if ( ! Syncs::claim($sync_id, $manual)) {
            return new \WP_Error('mo_sync_busy', __('This submission is already being sent.', 'mailoptin'));
        }

        $conversion_data = self::builder(json_decode($sync['retry_data'], true));

        list($response, $error) = self::attempt($sync['connection_service'], $sync['connection_email_list'], $conversion_data);

        $sent     = AbstractConnect::is_ajax_success($response);
        $attempts = (int)$sync['attempts'] + 1;
        $next     = $sent ? null : self::next_attempt_at($attempts);

        Syncs::record_attempt($sync_id, $sent, $error, $next);

        if ($next) self::schedule($next);

        do_action('mo_submission_sync_retried', (int)$sync_id, $sent, $manual);

        return $sent ? true : new \WP_Error('mo_sync_failed', $error);
    }

    /**
     * Automatic retries that are due. Runs from a single cron event set for the next due time,
     * with the hourly job as a fallback.
     *
     * @return int retries attempted
     */
    public static function run_due()
    {
        $count    = 0;
        $deadline = time() + (int)apply_filters('mo_submission_sync_time_budget', 20);

        foreach (Syncs::due(20) as $sync) {
            if (time() >= $deadline) break;

            self::retry($sync['id']);
            $count++;
        }

        if ( ! empty(Syncs::due(1))) self::schedule(Syncs::now(MINUTE_IN_SECONDS));

        return $count;
    }

    public static function schedule_soon()
    {
        self::schedule(Syncs::now(MINUTE_IN_SECONDS));
        spawn_cron();
    }

    protected static function schedule($utc_datetime)
    {
        $timestamp = strtotime($utc_datetime . ' UTC');
        $next      = wp_next_scheduled(self::CRON_HOOK);

        if ( ! $next || $next > $timestamp) {
            if ($next) wp_unschedule_event($next, self::CRON_HOOK);
            wp_schedule_single_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * @return array [response, error message]
     */
    protected static function attempt($connection_service, $connection_email_list, $conversion_data)
    {
        self::$captured  = [];
        self::$capturing = true;

        try {
            $response = AjaxHandler::add_lead_to_connection($connection_service, $connection_email_list, $conversion_data);
        } catch (\Throwable $e) {
            $response = AbstractConnect::ajax_failure($e->getMessage());
        } finally {
            self::$capturing = false;
        }

        $error = '';

        if ( ! AbstractConnect::is_ajax_success($response)) {
            // The integration's own message beats the generic one shown to the visitor.
            $error = ! empty(self::$captured) ? end(self::$captured) : wp_strip_all_tags((string)($response['message'] ?? ''));
            $error = '' !== trim($error) ? $error : __('The integration did not accept the submission.', 'mailoptin');
        }

        return [$response, $error];
    }

    /**
     * What's needed to rebuild the submission for another attempt.
     */
    public static function retry_data(ConversionDataBuilder $conversion_data)
    {
        return [
            'payload'                    => $conversion_data->payload,
            'optin_uuid'                 => $conversion_data->optin_uuid,
            'optin_campaign_id'          => $conversion_data->optin_campaign_id,
            'optin_campaign_type'        => $conversion_data->optin_campaign_type,
            'email'                      => $conversion_data->email,
            'name'                       => $conversion_data->name,
            'user_agent'                 => $conversion_data->user_agent,
            'conversion_page'            => $conversion_data->conversion_page,
            'referrer'                   => $conversion_data->referrer,
            'is_double_optin'            => $conversion_data->is_double_optin,
            'form_custom_field_mappings' => $conversion_data->form_custom_field_mappings,
            'form_tags'                  => $conversion_data->form_tags,
            'ip_address'                 => $conversion_data->ip_address ?: \MailOptin\Core\get_ip_address(),
        ];
    }

    public static function builder($data)
    {
        $builder = new ConversionDataBuilder();

        foreach ((array)$data as $key => $value) {
            if (property_exists($builder, $key)) $builder->$key = $value;
        }

        // Already checked when it was submitted.
        $builder->is_timestamp_check_active = false;

        return $builder;
    }

    /**
     * @return SubmissionSync
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
