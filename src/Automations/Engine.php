<?php

namespace MailOptin\Core\Automations;

use MailOptin\Core\Audience\CampaignRenderer;
use MailOptin\Core\Audience\PreferenceCentre;
use MailOptin\Core\Audience\Tokens;
use MailOptin\Core\Audience\Tracking;
use MailOptin\Core\Core;
use MailOptin\Core\Repositories\AbstractCampaignLogMeta;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;
use MailOptin\Core\Repositories\CampaignActivityRepository as Activity;
use MailOptin\Core\Repositories\CampaignSettingsRepository;
use MailOptin\Core\Repositories\CustomFieldsRepository;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\SegmentsRepository;
use MailOptin\Core\Repositories\SendQueueRepository as Queue;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\Repositories\TagsRepository;
use MailOptin\Core\SendQueue\QueueRunner;

/**
 * Runs automations: starts a run when a trigger fires, then walks each run through its steps on a
 * one-minute tick. Emails go through the send queue, so the hourly limit, the sending service,
 * retries and the suppression list apply as for any campaign.
 *
 * Rules that keep it from emailing the wrong person or twice:
 * - one run per person per starting event (a unique key), and each email step once per run;
 * - due runs are claimed before they're worked on, so two ticks can't take the same run;
 * - just before each email the run is checked again (suppressed, trigger's own stop reasons);
 * - at most one automation email per address per 24 hours, and only inside the sending hours.
 */
class Engine
{
    const TICK_HOOK = 'mo_automations_tick';
    const SCHEDULE = 'mo_every_minute';

    const RECIPIENT_TYPE = 'automation';

    /** A run whose processing keeps failing is stopped after this many attempts. */
    const MAX_ATTEMPTS = 5;

    const LEASE_SECONDS = 300;

    /** On an automation email's campaign log: the email campaign it was rendered from. */
    const LOG_META_KEY = 'mo_automation_email_campaign_id';
    const LOG_RENDERED_KEY = 'mo_automation_rendered_at';

    protected $worker_id;

    public function __construct()
    {
        $this->worker_id = substr(md5(uniqid('', true)), 0, 32);

        // Created after "init" (a late include, or tests): load now rather than never.
        if (did_action('init')) {
            Triggers::get_instance()->load();
        } else {
            add_action('init', [Triggers::get_instance(), 'load'], 20);
        }
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action(self::TICK_HOOK, [$this, 'tick']);
        add_action('mo_hourly_recurring_job', [$this, 'scan']);

        add_filter('mo_send_queue_email_content', [$this, 'email_content'], 10, 3);
        add_filter('mo_send_queue_email_headers', [$this, 'email_headers'], 10, 3);
        add_filter('mo_send_queue_skip_reason', [$this, 'skip_reason'], 10, 3);
        add_filter('mo_send_queue_row_subscriber_id', [$this, 'row_subscriber_id'], 10, 2);
        add_action('mo_send_queue_email_sent', [$this, 'email_sent'], 10, 2);
        add_action('mo_send_queue_email_failed', [$this, 'email_failed'], 10, 3);

        add_action('mo_subscriber_unsubscribed', [$this, 'subscriber_unsubscribed'], 10, 2);
        add_action('init', [$this, 'unsubscribe_request']);

        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter']);
    }

    /**
     * Automations are a Premium feature.
     */
    public static function is_available()
    {
        return (bool)apply_filters('mailoptin_automations_available', defined('MAILOPTIN_DETACH_LIBSODIUM')) && Repo::is_ready();
    }

    /* ---------------------------------------------------------------------------------------------
     * Starting runs
     * ------------------------------------------------------------------------------------------ */

    /**
     * Start every live automation of this trigger that the event and person pass.
     *
     * @param int $automation_id only this automation (for scans); 0 for every live one
     *
     * @return int[] run IDs started
     */
    public static function start(AbstractTrigger $trigger, $email, $trigger_ref, $context, $automation_id = 0)
    {
        $email = strtolower(trim((string)$email));

        if ( ! self::is_available() || ! $trigger->is_available() || ! is_email($email)) return [];

        // Nothing will ever be sent to a suppressed address; don't start a run for it.
        if (SuppressionRepository::is_suppressed($email)) return [];

        $subscriber = Subscribers::get_by_email($email);
        $started    = [];

        foreach (Repo::live_for_trigger($trigger->id()) as $automation) {
            // A scan looks for people for one automation; it mustn't start the others on the same trigger.
            if ($automation_id && (int)$automation['id'] !== (int)$automation_id) continue;

            $settings = $automation['trigger_settings'];
            $runs     = $settings['runs'] ?? $trigger->default_runs();

            if ( ! $trigger->matches($context, $settings, $automation['audience'])) continue;

            if ( ! self::audience_allows($automation['audience'], $subscriber)) continue;

            // Unless re-entry is allowed, someone part-way through doesn't start again.
            if (empty($settings['reenter']) && Repo::has_active_run($automation['id'], $email)) continue;

            // "Once" means one run for this person ever, whatever event starts it.
            $ref = 'once' === $runs ? '' : (string)$trigger_ref;

            $run_id = Repo::start_run($automation['id'], $email, $ref, $context, $subscriber['id'] ?? 0);

            if ($run_id) $started[] = $run_id;
        }

        if ( ! empty($started)) self::ensure_tick();

        return $started;
    }

    /**
     * The audience every automation has: a segment, and tags to leave out. Someone who isn't a
     * subscriber has no tags, and is never in a segment.
     *
     * @param array $audience segment_id, exclude_tag_ids
     * @param array|null $subscriber
     */
    public static function audience_allows($audience, $subscriber)
    {
        $segment_id  = absint($audience['segment_id'] ?? 0);
        $exclude_ids = array_values(array_filter(array_map('absint', (array)($audience['exclude_tag_ids'] ?? []))));

        if ( ! $segment_id && empty($exclude_ids)) return true;

        if ( ! $subscriber) return ! $segment_id;

        $filters = SegmentsRepository::filters_for($segment_id, $exclude_ids);

        // A deleted segment or tag can't be honoured: leave the person out rather than guess.
        if (is_wp_error($filters)) return false;

        $where  = ['s.id = %d'];
        $params = [absint($subscriber['id'])];

        Subscribers::filter_where($filters, $where, $params);

        global $wpdb;

        return (bool)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Subscribers::table() . ' s WHERE ' . implode(' AND ', $where), $params));
    }

    /* ---------------------------------------------------------------------------------------------
     * The tick
     * ------------------------------------------------------------------------------------------ */

    public function cron_schedules($schedules)
    {
        $schedules[self::SCHEDULE] = ['interval' => MINUTE_IN_SECONDS, 'display' => __('Every minute (MailOptin automations)', 'mailoptin')];

        return $schedules;
    }

    public static function ensure_tick()
    {
        if ( ! wp_next_scheduled(self::TICK_HOOK)) {
            wp_schedule_event(time(), self::SCHEDULE, self::TICK_HOOK);
        }
    }

    public function tick()
    {
        if ( ! self::is_available()) {
            wp_clear_scheduled_hook(self::TICK_HOOK);

            return;
        }

        $this->run();
    }

    /**
     * Work through due runs until the time budget runs out.
     *
     * @return int runs handled
     */
    public function run($deadline = null)
    {
        $deadline = $deadline ?: time() + (int)apply_filters('mailoptin_automations_time_budget', 20);
        $handled  = 0;
        $seen     = [];

        while (time() < $deadline) {
            // A run that threw stays claimed by this worker until its lease ends; don't take it again now.
            $runs = array_filter(Repo::claim_due($this->worker_id, 25, self::LEASE_SECONDS), function ($run) use ($seen) {
                return ! isset($seen[$run['id']]);
            });

            if (empty($runs)) break;

            foreach ($runs as $run) {
                $seen[$run['id']] = true;

                try {
                    $this->process($run);
                } catch (\Throwable $e) {
                    // Left claimed: the lease runs out and it's tried again, until MAX_ATTEMPTS.
                    error_log('MailOptin automation run ' . $run['id'] . ': ' . $e->getMessage());
                }

                $handled++;
            }
        }

        return $handled;
    }

    /**
     * Start runs for triggers that look for people on a schedule. Hourly.
     */
    public function scan()
    {
        if ( ! self::is_available()) return;

        // The tick is cleared while automations are unavailable; bring it back for runs still waiting.
        if (Repo::has_active_runs()) self::ensure_tick();

        foreach (Triggers::get_instance()->all() as $trigger) {
            foreach (Repo::live_for_trigger($trigger->id()) as $automation) {
                $trigger->scan($automation);
            }
        }
    }

    /**
     * Move one claimed run as far as it can go now.
     */
    public function process($run)
    {
        $automation = Repo::get($run['automation_id']);
        $trigger    = $automation ? Triggers::get_instance()->get($automation['trigger_id']) : null;

        if ( ! $trigger || ! $trigger->is_available()) {
            Repo::finish_run($run['id'], Repo::RUN_STOPPED, 'trigger_unavailable');

            return;
        }

        if ($run['attempts'] > self::MAX_ATTEMPTS) {
            Repo::finish_run($run['id'], Repo::RUN_STOPPED, 'error');

            return;
        }

        $reason = self::stop_reason($run, $trigger);

        if ('' !== $reason) {
            Repo::finish_run($run['id'], Repo::RUN_STOPPED, $reason);

            return;
        }

        $current  = $run['current_step_id'] ? Repo::get_step($run['current_step_id']) : null;
        $position = $current ? $current['position'] : 0;
        $now      = time();

        // Enough for any real automation; stops a loop if steps are ever misconfigured.
        for ($i = 0; $i < 100; $i++) {
            $step = Repo::next_step($run['automation_id'], $position);

            if ( ! $step) {
                Repo::finish_run($run['id'], Repo::RUN_COMPLETED);

                return;
            }

            if (Repo::STEP_WAIT === $step['type']) {
                if ($run['waiting_step_id'] !== $step['id']) {
                    Repo::release_run($run['id'], [
                        'current_step_id' => $current['id'] ?? 0,
                        'waiting_step_id' => $step['id'],
                        'next_run_at'     => gmdate('Y-m-d H:i:s', self::wait_until($step['settings'], $now)),
                    ]);

                    return;
                }

                $run['waiting_step_id'] = 0;
            }

            if (Repo::STEP_CHECK === $step['type'] && ! self::check_passes($step, $run, $trigger)) {
                Repo::finish_run($run['id'], Repo::RUN_STOPPED, 'check:' . sanitize_key($step['settings']['check'] ?? ''));

                return;
            }

            if (Repo::STEP_EMAIL === $step['type']) {
                $done = Repo::find_run_step($run['id'], $step['id']);

                // Already queued for this run (a step moved, or a second worker): never sent again.
                // One recorded but not queued (the request died in between) is finished off below.
                if ( ! $done || ! $done['queue_id']) {
                    $send_at = $done ? $now : self::send_time($automation, $run['email'], $now);

                    if ($send_at > $now) {
                        Repo::release_run($run['id'], [
                            'current_step_id' => $current['id'] ?? 0,
                            'waiting_step_id' => 0,
                            'next_run_at'     => gmdate('Y-m-d H:i:s', $send_at),
                        ]);

                        return;
                    }

                    $run_step_id = $done ? $done['id'] : Repo::add_run_step($run['id'], $step['id'], $run['email']);

                    if ($run_step_id && ! self::queue_email($run, $step, $run_step_id)) {
                        Repo::finish_run($run['id'], Repo::RUN_STOPPED, 'email_missing');

                        return;
                    }
                }
            }

            $current  = $step;
            $position = $step['position'];
        }

        Repo::release_run($run['id'], ['current_step_id' => $current['id'] ?? 0, 'waiting_step_id' => 0, 'next_run_at' => Repo::now()]);
    }

    /**
     * Why a run should stop now, or '' to carry on. Asked before each step and again just before
     * each email is sent.
     */
    public static function stop_reason($run, AbstractTrigger $trigger)
    {
        if (SuppressionRepository::is_suppressed($run['email'])) return 'suppressed';

        return (string)$trigger->stop_reason($run);
    }

    /* ---------------------------------------------------------------------------------------------
     * Timing
     * ------------------------------------------------------------------------------------------ */

    /**
     * When a Wait step ends: the amount from now, then, optionally, the next given time of day
     * (and weekday) in the site's time zone.
     *
     * @param array $settings amount, unit (minutes, hours, days, weeks), until ("09:00" or ""), weekdays_only
     *
     * @return int unix time
     */
    public static function wait_until($settings, $now)
    {
        $amount = max(0, (int)($settings['amount'] ?? 0));
        $unit   = in_array($settings['unit'] ?? 'days', ['minutes', 'hours', 'days', 'weeks'], true) ? ($settings['unit'] ?? 'days') : 'days';
        $until  = self::parse_time($settings['until'] ?? '');
        $tz     = wp_timezone();
        $start  = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);

        if (in_array($unit, ['days', 'weeks'], true)) {
            // Calendar days in the site's time zone, so a clock change doesn't move the hour.
            $date = $start->modify(sprintf('+%d %s', 'weeks' === $unit ? $amount * 7 : $amount, 'days'));

            // "Wait 1 day, then until 09:00": 09:00 on that day; if that's already past, the next.
            if (null !== $until) {
                $date = $date->setTime($until[0], $until[1]);
                if ($date->getTimestamp() <= $now) $date = $date->modify('+1 day')->setTime($until[0], $until[1]);
            }
        } else {
            $due  = $now + $amount * ('hours' === $unit ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS);
            $date = (new \DateTimeImmutable('@' . $due))->setTimezone($tz);

            // "Wait 2 hours, then until 09:00": the first 09:00 after those two hours.
            if (null !== $until) {
                $at = $date->setTime($until[0], $until[1]);
                if ($at->getTimestamp() < $due) $at = $at->modify('+1 day')->setTime($until[0], $until[1]);
                $date = $at;
            }
        }

        if ( ! empty($settings['weekdays_only'])) {
            while ((int)$date->format('N') >= 6) {
                $date = $date->modify('+1 day');
                if (null !== $until) $date = $date->setTime($until[0], $until[1]);
            }
        }

        return $date->getTimestamp();
    }

    /**
     * The earliest time an email can go now: inside the automation's sending hours and at least
     * 24 hours after the last automation email to the same address.
     *
     * @return int unix time; $now when it can go straight away
     */
    public static function send_time($automation, $email, $now)
    {
        $at = $now;

        if (apply_filters('mailoptin_automation_daily_cap', true, $automation)) {
            $last = Repo::last_email_at($email);

            if ($last) {
                $next = strtotime($last . ' UTC') + DAY_IN_SECONDS;
                if ($next > $at) $at = $next;
            }
        }

        return self::next_in_window($automation['settings'], $at);
    }

    /**
     * The first moment at or after $time inside the sending hours. Hours can cross midnight
     * (22:00 to 06:00). Without both hours set, any time.
     */
    public static function next_in_window($settings, $time)
    {
        $from = self::parse_time($settings['send_from'] ?? '');
        $to   = self::parse_time($settings['send_to'] ?? '');

        if (null === $from || null === $to || $from === $to) return $time;

        $date    = (new \DateTimeImmutable('@' . $time))->setTimezone(wp_timezone());
        $minutes = (int)$date->format('G') * 60 + (int)$date->format('i');
        $start   = $from[0] * 60 + $from[1];
        $end     = $to[0] * 60 + $to[1];

        $inside = $start < $end ? ($minutes >= $start && $minutes < $end) : ($minutes >= $start || $minutes < $end);

        if ($inside) return $time;

        $open = $date->setTime($from[0], $from[1]);

        // Before today's opening, it's today; after closing, tomorrow.
        if ($open->getTimestamp() <= $time) $open = $open->modify('+1 day')->setTime($from[0], $from[1]);

        return $open->getTimestamp();
    }

    /**
     * @return int[]|null [hour, minute] from "HH:MM", or null
     */
    protected static function parse_time($value)
    {
        if ( ! is_string($value) || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) return null;

        $hour   = (int)$m[1];
        $minute = (int)$m[2];

        return $hour < 24 && $minute < 60 ? [$hour, $minute] : null;
    }

    /* ---------------------------------------------------------------------------------------------
     * Checks
     * ------------------------------------------------------------------------------------------ */

    /**
     * "Continue only if" choices every trigger has, plus the trigger's own.
     *
     * @return array id => ['label' => string, 'callback' => callable($run): bool]
     */
    public static function checks(AbstractTrigger $trigger)
    {
        $engagement = function ($type, $want) {
            return function ($run) use ($type, $want) {
                return self::engaged_with_last_email($run, $type) === $want;
            };
        };

        return $trigger->checks() + [
                'opened_last'      => ['label' => __('they opened the last email', 'mailoptin'), 'callback' => $engagement(Activity::TYPE_OPEN, true)],
                'not_opened_last'  => ['label' => __("they didn't open the last email", 'mailoptin'), 'callback' => $engagement(Activity::TYPE_OPEN, false)],
                'clicked_last'     => ['label' => __('they clicked the last email', 'mailoptin'), 'callback' => $engagement(Activity::TYPE_CLICK, true)],
                'not_clicked_last' => ['label' => __("they didn't click the last email", 'mailoptin'), 'callback' => $engagement(Activity::TYPE_CLICK, false)],
            ];
    }

    protected static function check_passes($step, $run, AbstractTrigger $trigger)
    {
        $checks = self::checks($trigger);
        $id     = (string)($step['settings']['check'] ?? '');

        // A check that no longer exists (its plugin was removed) stops the run: guessing could send the wrong email.
        if ( ! isset($checks[$id]) || ! is_callable($checks[$id]['callback'])) return false;

        return (bool)call_user_func($checks[$id]['callback'], $run);
    }

    /**
     * Whether the person opened (or clicked) this run's last email since it was sent. Only
     * subscribers are tracked; for anyone else it's false.
     */
    public static function engaged_with_last_email($run, $type)
    {
        global $wpdb;

        if ( ! $run['subscriber_id'] || ! Activity::is_ready()) return false;

        $run_steps = Repo::run_steps_table();
        $last      = $wpdb->get_row($wpdb->prepare("SELECT * FROM $run_steps WHERE run_id = %d AND status = %s ORDER BY id DESC LIMIT 1", $run['id'], Repo::RUN_STEP_SENT), 'ARRAY_A');

        if ( ! $last || ! $last['queue_id']) return false;

        $row = Queue::get($last['queue_id']);

        if ( ! $row) return false;

        return (bool)$wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . Activity::table() . ' WHERE campaign_log_id = %d AND subscriber_id = %d AND type = %s AND last_at >= %s LIMIT 1',
            $row['campaign_log_id'], $run['subscriber_id'], $type, $last['created_at']
        ));
    }

    /* ---------------------------------------------------------------------------------------------
     * Emails
     * ------------------------------------------------------------------------------------------ */

    /**
     * Queue an email step for a run.
     *
     * @return bool false when the step's email doesn't exist any more
     */
    protected static function queue_email($run, $step, $run_step_id)
    {
        $email_campaign_id = absint($step['settings']['email_campaign_id'] ?? 0);
        $log_id            = self::campaign_log_id($email_campaign_id);

        if ( ! $log_id) {
            Repo::update_run_step($run_step_id, ['status' => Repo::RUN_STEP_FAILED]);

            return false;
        }

        Queue::enqueue($log_id, $email_campaign_id, [[
            'email'          => $run['email'],
            'recipient_type' => self::RECIPIENT_TYPE,
            'recipient_id'   => $run_step_id,
        ]]);

        global $wpdb;

        $queue_id = (int)$wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . Queue::table() . ' WHERE campaign_log_id = %d AND email = %s AND recipient_id = %d',
            $log_id, $run['email'], $run_step_id
        ));

        Repo::update_run_step($run_step_id, ['queue_id' => $queue_id]);

        QueueRunner::get_instance()->start($log_id, $email_campaign_id, []);

        return true;
    }

    /**
     * The campaign log an automation email is sent under: one per email, holding its content,
     * created the first time it's needed and re-rendered after the email is changed.
     *
     * @return int 0 when the email doesn't exist
     */
    public static function campaign_log_id($email_campaign_id)
    {
        global $wpdb;

        if ( ! $email_campaign_id || Repo::EMAIL_CAMPAIGN_TYPE !== ER::get_email_campaign_type($email_campaign_id)) return 0;

        $log_id = (int)$wpdb->get_var($wpdb->prepare(
            'SELECT m.campaign_log_id FROM ' . $wpdb->prefix . Core::campaign_log_meta_table_name . ' m INNER JOIN ' . $wpdb->prefix . Core::campaign_log_table_name . ' l ON l.id = m.campaign_log_id
            WHERE m.meta_key = %s AND m.meta_value = %s ORDER BY m.campaign_log_id DESC LIMIT 1',
            self::LOG_META_KEY, (string)$email_campaign_id
        ));

        if ( ! $log_id) {
            $wpdb->insert($wpdb->prefix . Core::campaign_log_table_name, [
                'email_campaign_id' => $email_campaign_id,
                'title'             => '',
                'content_html'      => '',
                'content_text'      => '',
                'status'            => QueueRunner::LOG_STATUS_PROCESSED,
                'status_time'       => current_time('mysql'),
            ]);

            $log_id = (int)$wpdb->insert_id;

            AbstractCampaignLogMeta::update_campaignlog_meta($log_id, self::LOG_META_KEY, (string)$email_campaign_id);
            AbstractCampaignLogMeta::update_campaignlog_meta($log_id, QueueRunner::STATE_META_KEY, QueueRunner::STATE_SENDING);

            self::render_log($email_campaign_id, $log_id);
        } elseif (self::changed_since_render($email_campaign_id, $log_id)) {
            self::render_log($email_campaign_id, $log_id);
        }

        return $log_id;
    }

    protected static function changed_since_render($email_campaign_id, $log_id)
    {
        global $wpdb;

        $updated  = $wpdb->get_var($wpdb->prepare(
            'SELECT updated_at FROM ' . CampaignSettingsRepository::table() . ' WHERE campaign_type = %s AND campaign_id = %d',
            CampaignSettingsRepository::TYPE_EMAIL, $email_campaign_id
        ));
        $rendered = AbstractCampaignLogMeta::get_campaignlog_meta($log_id, self::LOG_RENDERED_KEY);

        return $updated && ( ! $rendered || $updated > $rendered);
    }

    /**
     * Render an automation email into its campaign log. Its placeholders stay as they are, to be
     * filled in for each person when it's sent.
     */
    public static function render_log($email_campaign_id, $log_id)
    {
        global $wpdb;

        $content = self::render_email($email_campaign_id);

        $wpdb->update($wpdb->prefix . Core::campaign_log_table_name, [
            'title'        => $content['subject'],
            'content_html' => $content['html'],
            'content_text' => $content['text'],
        ], ['id' => $log_id]);

        AbstractCampaignLogMeta::update_campaignlog_meta($log_id, self::LOG_RENDERED_KEY, gmdate('Y-m-d H:i:s'));
    }

    /**
     * @return array subject, html, text
     */
    public static function render_email($email_campaign_id)
    {
        $html = (new \MailOptin\Core\EmailCampaigns\Newsletter\Templatify($email_campaign_id))->forge();

        $content = [
            'subject' => (string)ER::get_merged_customizer_value($email_campaign_id, 'email_campaign_title'),
            'html'    => $html,
            'text'    => \MailOptin\Core\html_to_text($html),
        ];

        return apply_filters('mailoptin_automation_email_content', $content, $email_campaign_id);
    }

    /**
     * Who and what a queued automation email is for.
     *
     * @return array|null run_step, run, automation, trigger
     */
    protected static function row_context($row)
    {
        static $cache = [];

        if (self::RECIPIENT_TYPE !== ($row['recipient_type'] ?? '')) return null;

        $id = absint($row['recipient_id']);

        if (isset($cache[$id])) return $cache[$id];

        $run_step   = Repo::get_run_step($id);
        $run        = $run_step ? Repo::get_run($run_step['run_id']) : null;
        $automation = $run ? Repo::get($run['automation_id']) : null;
        $trigger    = $automation ? Triggers::get_instance()->get($automation['trigger_id']) : null;

        $context = $trigger ? compact('run_step', 'run', 'automation', 'trigger') : null;

        // Small: an email's request handles a few rows, and the run can change between them.
        if (count($cache) > 50) $cache = [];

        return $cache[$id] = $context;
    }

    public function skip_reason($reason, $row, $campaign_log_id)
    {
        if ('' !== (string)$reason) return $reason;

        if (self::RECIPIENT_TYPE !== ($row['recipient_type'] ?? '')) return $reason;

        $context = self::row_context($row);

        if ( ! $context) return __('The automation was deleted.', 'mailoptin');

        $run = Repo::get_run($context['run']['id']);

        if ( ! $run || Repo::RUN_STOPPED === $run['status']) {
            Repo::update_run_step($context['run_step']['id'], ['status' => Repo::RUN_STEP_SKIPPED]);

            return __('The automation stopped for this person.', 'mailoptin');
        }

        $stop = self::stop_reason($run, $context['trigger']);

        if ('' !== $stop) {
            Repo::finish_run($run['id'], Repo::RUN_STOPPED, $stop, true);
            Repo::update_run_step($context['run_step']['id'], ['status' => Repo::RUN_STEP_SKIPPED]);

            return sprintf(__('The automation stopped for this person (%s).', 'mailoptin'), $stop);
        }

        return $reason;
    }

    public function row_subscriber_id($subscriber_id, $row)
    {
        $context = self::row_context($row);

        return $context ? (int)$context['run']['subscriber_id'] : $subscriber_id;
    }

    /**
     * Fill in an automation email for one person: the trigger's placeholders, the subscriber's
     * merge tags, the unsubscribe and web version links, and click and open tracking.
     */
    public function email_content($content, $row, $campaign_log_id)
    {
        $context = self::row_context($row);

        if ( ! $context) return $content;

        $run        = $context['run'];
        $subscriber = $run['subscriber_id'] ? Subscribers::get($run['subscriber_id']) : null;
        $person     = $subscriber ?: ['id' => 0, 'email' => $run['email'], 'first_name' => '', 'last_name' => ''];
        $fields     = $subscriber && TagsRepository::is_ready() ? CustomFieldsRepository::merge_values($subscriber['id']) : [];

        $links = [
            '{{unsubscribe}}' => self::unsubscribe_url($run, $subscriber, $campaign_log_id),
            // The web version is one page per email, not per person, so it can't show their order
            // or name. Automation emails are personal; like welcome emails they have no web version.
            '{{webversion}}'  => '#',
            '{{preferences}}' => $subscriber ? PreferenceCentre::url($subscriber) : '',
        ];

        $fill = function ($text, $escape) use ($context, $person, $fields, $links) {
            $text = self::replace_placeholders($text, $context['trigger'], $context['run'], $escape);
            $text = CampaignRenderer::replace_fallback_tags($text, $person, $escape, $fields);

            return CampaignRenderer::replace_tags($text, $links, $escape);
        };

        $html = $content['html'];

        if ($subscriber) {
            $html = Tracking::prepare_html(PreferenceCentre::add_link($html, true), $row, $campaign_log_id);
        }

        return [
            'subject' => $fill($content['subject'], false),
            'html'    => $fill($html, true),
            'text'    => $fill($content['text'], false),
        ];
    }

    /**
     * Placeholders every automation has, and the trigger's own.
     *
     * @return array key => ['label', 'value' => callable($context, $run), 'sample']
     */
    public static function placeholders(AbstractTrigger $trigger)
    {
        return $trigger->placeholders() + [
                'store.name' => ['label' => __('Site name', 'mailoptin'), 'value' => function () { return get_bloginfo('name'); }, 'sample' => get_bloginfo('name')],
                'store.url'  => ['label' => __('Site address', 'mailoptin'), 'value' => function () { return home_url('/'); }, 'sample' => home_url('/')],
            ];
    }

    /**
     * Replace {{namespace.key}} and {{namespace.key|fallback}} with the trigger's values, escaped
     * for HTML when $escape. Subscriber tags ({{subscriber.…}}) and unknown tags are left alone.
     */
    public static function replace_placeholders($text, AbstractTrigger $trigger, $run, $escape, $sample = false)
    {
        $text = (string)$text;

        if (false === strpos($text, '{{')) return $text;

        $placeholders = self::placeholders($trigger);

        return preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]*\.[a-z0-9_.]+)\s*(?:\|([^}]*))?\}\}/', function ($match) use ($placeholders, $run, $escape, $sample) {
            $key = $match[1];

            if (0 === strpos($key, 'subscriber.') || ! isset($placeholders[$key])) return $match[0];

            if ($sample) {
                $value = (string)($placeholders[$key]['sample'] ?? '');
            } else {
                try {
                    $value = (string)call_user_func($placeholders[$key]['value'], $run['context'] ?? [], $run);
                } catch (\Throwable $e) {
                    $value = '';
                }
            }

            if ('' === trim($value)) $value = $match[2] ?? '';

            return $escape ? esc_html($value) : $value;
        }, $text);
    }

    public function email_headers($headers, $row, $campaign_log_id)
    {
        $context = self::row_context($row);

        if ( ! $context) return $headers;

        $run        = $context['run'];
        $subscriber = $run['subscriber_id'] ? Subscribers::get($run['subscriber_id']) : null;

        $headers[] = sprintf('List-Unsubscribe: <%s>', self::unsubscribe_url($run, $subscriber, $campaign_log_id));
        $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';

        return $headers;
    }

    public function email_sent($row, $campaign_log_id)
    {
        $context = self::row_context($row);

        if ( ! $context) return;

        Repo::update_run_step($context['run_step']['id'], ['status' => Repo::RUN_STEP_SENT]);

        if ($context['run']['subscriber_id']) {
            Subscribers::log_event($context['run']['subscriber_id'], Subscribers::EVENT_SENT, ['automation_id' => (int)$context['automation']['id']], $campaign_log_id);
        }

        if (Activity::is_ready()) Activity::record_sent($campaign_log_id);
    }

    public function email_failed($row, $campaign_log_id, $message)
    {
        $context = self::row_context($row);

        if ( ! $context) return;

        $fresh = Queue::get($row['id']);

        // Only once the queue has given up; a retry is still on its way.
        if ($fresh && Queue::STATUS_FAILED === $fresh['status']) {
            Repo::update_run_step($context['run_step']['id'], ['status' => Repo::RUN_STEP_FAILED]);
        }
    }

    /* ---------------------------------------------------------------------------------------------
     * Unsubscribing
     * ------------------------------------------------------------------------------------------ */

    /**
     * Subscribers unsubscribe from everything, as from any campaign. Someone who isn't a subscriber
     * (a store customer) gets their own link, which adds them to the suppression list.
     */
    public static function unsubscribe_url($run, $subscriber, $campaign_log_id)
    {
        if ($subscriber) return Tokens::unsubscribe_url($subscriber, 0, $campaign_log_id);

        return add_query_arg([
            'mo_auto_unsub' => $run['id'],
            'mo_token'      => Tokens::make('automation_unsubscribe', [$run['id'], $run['email']]),
        ], home_url('/'));
    }

    public function unsubscribe_request()
    {
        if ( ! isset($_GET['mo_auto_unsub'], $_GET['mo_token'])) return;

        $run   = Repo::get_run(absint($_GET['mo_auto_unsub']));
        $token = sanitize_text_field(wp_unslash($_GET['mo_token']));

        if ( ! $run || ! Tokens::check('automation_unsubscribe', [$run['id'], $run['email']], $token)) {
            wp_die(esc_html__('This link is not valid. It may have been copied incompletely.', 'mailoptin'), esc_html__('Invalid link', 'mailoptin'), ['response' => 404]);
        }

        $masked = PreferenceCentre::masked_email($run['email']);

        if ('POST' === strtoupper($_SERVER['REQUEST_METHOD'] ?? '')) {
            SuppressionRepository::add($run['email'], SuppressionRepository::REASON_UNSUBSCRIBED, 'automation');
            Repo::stop_runs(['email' => $run['email']], 'unsubscribed');

            wp_die(
                sprintf(esc_html__('%s will no longer receive these emails.', 'mailoptin'), '<strong>' . esc_html($masked) . '</strong>'),
                esc_html__("You've been unsubscribed", 'mailoptin'),
                ['response' => 200]
            );
        }

        wp_die(
            sprintf(esc_html__('Click the button below to stop emails to %s.', 'mailoptin'), '<strong>' . esc_html($masked) . '</strong>')
            . sprintf('<form method="post" action="%s" style="margin-top:16px"><button type="submit" class="button">%s</button></form>', esc_url(add_query_arg([])), esc_html__('Unsubscribe', 'mailoptin')),
            esc_html__('Unsubscribe', 'mailoptin'),
            ['response' => 200]
        );
    }

    /**
     * Unsubscribing from every list stops the person's automations too.
     */
    public function subscriber_unsubscribed($subscriber_id, $list_id)
    {
        $subscriber = Subscribers::get($subscriber_id);

        if ( ! $subscriber || ($list_id && Subscribers::STATUS_UNSUBSCRIBED !== $subscriber['status'])) return;

        Repo::stop_runs(['email' => $subscriber['email']], 'unsubscribed');
    }

    /* ---------------------------------------------------------------------------------------------
     * Privacy
     * ------------------------------------------------------------------------------------------ */

    public function register_eraser($erasers)
    {
        $erasers['mailoptin-automations'] = [
            'eraser_friendly_name' => __('MailOptin automations', 'mailoptin'),
            'callback'             => function ($email) {
                $removed = Repo::is_ready() ? Repo::erase_email($email) : 0;

                return ['items_removed' => $removed > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
            },
        ];

        return $erasers;
    }

    public function register_exporter($exporters)
    {
        $exporters['mailoptin-automations'] = [
            'exporter_friendly_name' => __('MailOptin automations', 'mailoptin'),
            'callback'               => function ($email) {
                $data = [];

                foreach (Repo::is_ready() ? Repo::runs_for_email($email, 500) : [] as $run) {
                    $data[] = [
                        'group_id'    => 'mailoptin-automations',
                        'group_label' => __('MailOptin automations', 'mailoptin'),
                        'item_id'     => 'mailoptin-automation-run-' . $run['id'],
                        'data'        => [
                            ['name' => __('Automation', 'mailoptin'), 'value' => (string)$run['automation_name']],
                            ['name' => __('Status', 'mailoptin'), 'value' => $run['status'] . ($run['stop_reason'] ? ' (' . $run['stop_reason'] . ')' : '')],
                            ['name' => __('Started', 'mailoptin'), 'value' => $run['started_at'] . ' UTC'],
                        ],
                    ];
                }

                return ['data' => $data, 'done' => true];
            },
        ];

        return $exporters;
    }

    /**
     * @return Engine
     */
    public static function get_instance()
    {
        static $instance = null;

        if (null === $instance) $instance = new self();

        return $instance;
    }
}
