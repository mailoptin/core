<?php

namespace MailOptin\Core\EmailCampaigns\PostsEmailDigest;

use MailOptin\Core\SendQueue\QueueRunner;
use Carbon\CarbonImmutable;
use MailOptin\Core\Connections\ConnectionFactory;
use MailOptin\Core\EmailCampaigns\AbstractTriggers;
use MailOptin\Core\EmailCampaigns\Misc;
use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use WP_Post;

class PostsEmailDigest extends AbstractTriggers
{
    public function __construct()
    {
        parent::__construct();

        add_action('mo_hourly_recurring_job', [$this, 'run_job']);
        add_action('admin_init', function () {
            if (isset($_GET['mo_ped_debug'])) {
                $this->run_job();
            }
        });
    }

    public function last_processed_at($email_campaign_id)
    {
        return EmailCampaignMeta::get_meta_data($email_campaign_id, 'last_processed_at');
    }

    public function timezone()
    {
        return wp_timezone_string();
    }

    /**
     * Now in site time, "Y-m-d H:i:s". Same as current_time('mysql'), from the clock the schedule uses.
     */
    public function now_mysql()
    {
        return CarbonImmutable::now($this->timezone())->format('Y-m-d H:i:s');
    }

    /** Schedule choice for a digest that only goes out when someone sends it. */
    const SCHEDULE_MANUAL = 'manual';

    /**
     * When the schedule last sent this digest. Sending by hand moves "last_processed_at" (the start of the
     * next digest's posts) but not this, so a hand-sent digest doesn't by itself stop the scheduled one.
     */
    const LAST_SCHEDULED_META = 'last_scheduled_at';

    /** Scheduled sends up to and including this time (site time, Y-m-d H:i:s) are skipped. */
    const SKIP_UNTIL_META = 'skip_scheduled_until';

    /** Lets a digest that has never been sent by its schedule say so, once it has been sent by hand. */
    const NEVER = 'never';

    /** How long a send may hold the lock before it's treated as abandoned. */
    const LOCK_TTL = 10 * MINUTE_IN_SECONDS;

    /**
     * Take the digest's send lock, so the schedule and a "Send now" can't send the same digest at once.
     *
     * @return bool false when another send holds it
     */
    public function lock($email_campaign_id)
    {
        global $wpdb;

        $name = self::lock_name($email_campaign_id);
        $now  = time();

        // INSERT IGNORE succeeds for exactly one caller: option_name is a unique key. (add_option() won't do:
        // it upserts, and it trusts the options cache.)
        $taken = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $now));

        if (1 === (int)$taken) return $this->lock_taken($name);

        $since = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));

        if (null === $since || $now - (int)$since < self::LOCK_TTL) return false;

        // Abandoned by a send that died mid-way: take it over, unless someone else just did.
        $taken = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $now, $name, $since));

        return 1 === (int)$taken ? $this->lock_taken($name) : false;
    }

    protected static function lock_name($email_campaign_id)
    {
        return 'mo_digest_send_lock_' . absint($email_campaign_id);
    }

    protected function lock_taken($name)
    {
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');

        return true;
    }

    public function unlock($email_campaign_id)
    {
        global $wpdb;

        $name = self::lock_name($email_campaign_id);

        $wpdb->delete($wpdb->options, ['option_name' => $name]);
        wp_cache_delete($name, 'options');
    }

    /**
     * When the schedule last sent the digest, for working out whether this period's digest went out.
     *
     * @return string|null site time "Y-m-d H:i:s", or null when it never has
     */
    public function last_scheduled_at($email_campaign_id)
    {
        $value = EmailCampaignMeta::get_meta_data($email_campaign_id, self::LAST_SCHEDULED_META);

        if (self::NEVER === $value) return null;

        // Digests sent before this existed only have last_processed_at, and only the schedule set it then.
        if (empty($value)) $value = $this->last_processed_at($email_campaign_id);

        return empty($value) ? null : $value;
    }

    /**
     * The digest's schedule.
     *
     * @return array interval, hour, day (0 = Sunday), month_date
     */
    public function schedule($email_campaign_id)
    {
        return [
            'interval'   => (string)ER::get_merged_customizer_value($email_campaign_id, 'schedule_interval'),
            'hour'       => min(23, absint(ER::get_merged_customizer_value($email_campaign_id, 'schedule_time'))),
            'day'        => min(6, absint(ER::get_merged_customizer_value($email_campaign_id, 'schedule_day'))),
            'month_date' => min(28, max(1, absint(ER::get_merged_customizer_value($email_campaign_id, 'schedule_month_date')))),
        ];
    }

    public function is_manual_only($email_campaign_id)
    {
        return self::SCHEDULE_MANUAL === $this->schedule($email_campaign_id)['interval'];
    }

    /**
     * The next time the schedule would send the digest, counting a slot that passed under two hours ago and
     * hasn't gone out yet (the hourly job still catches those). Ignores whether there will be posts to send.
     *
     * @param int $email_campaign_id
     * @param CarbonImmutable|null $now
     *
     * @return CarbonImmutable|null null for a digest that's only sent by hand
     */
    public function next_scheduled_at($email_campaign_id, $now = null)
    {
        $schedule = $this->schedule($email_campaign_id);
        $now      = $now ?: CarbonImmutable::now($this->timezone());

        // should_send() still sends a slot while it's under two hours late (diffInRealHours() <= 1).
        $from = $now->subHours(2)->addSecond();

        switch ($schedule['interval']) {
            case 'every_day':
                $slot = $from->startOfDay()->hour($schedule['hour']);
                if ($slot->lessThan($from)) $slot = $slot->addDay();
                break;
            case 'every_week':
                $slot = $from->startOfDay()->hour($schedule['hour']);
                for ($i = 0; $i < 8 && ($slot->dayOfWeek !== $schedule['day'] || $slot->lessThan($from)); $i++) {
                    $slot = $slot->addDay();
                }
                break;
            case 'every_month':
                $slot = $from->startOfMonth()->day($schedule['month_date'])->hour($schedule['hour']);
                if ($slot->lessThan($from)) $slot = $slot->addMonthNoOverflow();
                break;
            default:
                return null;
        }

        // A slot already sent this period doesn't count: move on to the next one.
        if ($slot->lessThanOrEqualTo($now) && ! $this->should_send($email_campaign_id, $slot, $schedule['interval'])) {
            return $this->next_scheduled_at($email_campaign_id, $slot->addHours(2));
        }

        return $slot;
    }

    /**
     * The next scheduled send that will really go out: {@see next_scheduled_at()} past any slot a send by hand
     * already took the place of.
     *
     * @return CarbonImmutable|null
     */
    public function next_unskipped_at($email_campaign_id, $now = null)
    {
        $next = $this->next_scheduled_at($email_campaign_id, $now);

        for ($i = 0; $next && $i < 5 && $this->is_skipped($email_campaign_id, $next); $i++) {
            $next = $this->next_scheduled_at($email_campaign_id, $next->addHours(2));
        }

        return $next;
    }

    /**
     * Whether the scheduled send at $slot was skipped by a send by hand.
     */
    public function is_skipped($email_campaign_id, CarbonImmutable $slot)
    {
        $until = EmailCampaignMeta::get_meta_data($email_campaign_id, self::SKIP_UNTIL_META);

        if (empty($until)) return false;

        $until = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $until, $this->timezone());

        if ( ! $until) return false;

        // Long past: tidy it away.
        if ($until->addHours(2)->lessThan(CarbonImmutable::now($this->timezone()))) {
            EmailCampaignMeta::delete_meta_data($email_campaign_id, self::SKIP_UNTIL_META);
        }

        return $slot->lessThanOrEqualTo($until);
    }

    public function post_collect_query($email_campaign_id)
    {
        $item_count = ER::get_merged_customizer_value($email_campaign_id, 'item_number');

        $parameters = [
            'posts_per_page' => $item_count,
            'post_status'    => apply_filters('mailoptin_post_digest_post_status', 'publish', $email_campaign_id),
            'order'          => 'DESC',
            'orderby'        => 'post_date'
        ];

        $post_authors = ER::get_merged_customizer_value($email_campaign_id, 'post_authors');

        if ( ! empty($post_authors)) {
            $parameters['author'] = implode(',', $post_authors);
        }

        $custom_post_type = ER::get_merged_customizer_value($email_campaign_id, 'custom_post_type');

        $parameters['post_type'] = $custom_post_type;

        $custom_post_type_settings = ER::get_merged_customizer_value($email_campaign_id, 'custom_post_type_settings');

        if ( ! empty($custom_post_type_settings)) {

            $custom_post_type_settings = json_decode($custom_post_type_settings, true);

            if (is_array($custom_post_type_settings) && ! empty($custom_post_type_settings)) {

                $parameters['tax_query'] = [];

                foreach ($custom_post_type_settings as $taxonomy => $digest_terms) {
                    if ( ! empty($digest_terms) && taxonomy_exists($taxonomy)) {
                        $parameters['tax_query'][] = [
                            'taxonomy' => $taxonomy,
                            'field'    => 'term_id',
                            'terms'    => array_map('absint', $digest_terms)
                        ];
                    }
                }
            }
        }

        $categories = ER::get_merged_customizer_value($email_campaign_id, 'post_categories');
        $tags       = ER::get_merged_customizer_value($email_campaign_id, 'post_tags');

        if ( ! empty($categories)) {
            $parameters['category'] = implode(',', array_map('trim', $categories));
        }

        if ( ! empty($tags)) {
            $parameters['tag_id'] = implode(',', array_map('trim', $tags));
        }

        return $parameters;
    }

    /**
     * @param $email_campaign_id
     *
     * @return WP_Post[]
     */
    public function post_collection($email_campaign_id)
    {
        $newer_than_timestamp = $this->posts_since($email_campaign_id);

        $parameters = $this->post_collect_query($email_campaign_id);

        $parameters['date_query'] = [
            [
                'column' => 'post_date',
                'after'  => $newer_than_timestamp
            ]
        ];

        do_action('mo_post_digest_post_collection', $email_campaign_id, $parameters);

        $parameters = apply_filters('mo_post_digest_get_posts_args', $parameters, $email_campaign_id);

        return apply_filters(
            'mo_post_email_digest_post_collection',
            get_posts($parameters),
            $email_campaign_id,
            $this,
            $parameters
        );
    }

    /**
     * The latest matching posts, whenever they were published: for a digest sent by hand with nothing new.
     *
     * @return WP_Post[]
     */
    public function latest_posts($email_campaign_id)
    {
        $parameters = $this->post_collect_query($email_campaign_id);

        $parameters = apply_filters('mo_post_digest_get_posts_args', $parameters, $email_campaign_id, 'latest');

        return apply_filters('mo_post_email_digest_post_collection', get_posts($parameters), $email_campaign_id, $this, $parameters);
    }

    /**
     * Site time after which posts go into the next digest.
     *
     * @return string
     */
    public function posts_since($email_campaign_id)
    {
        $newer_than_timestamp = EmailCampaignMeta::get_meta_data($email_campaign_id, 'created_at');
        // backward compat for bug we've fixed where meta was being saved as array.
        if (is_array($newer_than_timestamp)) {
            $newer_than_timestamp = $newer_than_timestamp[0];
        }

        $last_processed_at = $this->last_processed_at($email_campaign_id);

        if ( ! empty($last_processed_at)) {
            $newer_than_timestamp = $last_processed_at;
        }

        return (string)$newer_than_timestamp;
    }

    /**
     * Check if two dates fall within the same custom week.
     *
     * @param CarbonImmutable $date
     * @param CarbonImmutable $now
     *
     * @return bool
     */
    public function is_same_week(CarbonImmutable $date, CarbonImmutable $now)
    {
        $start_of_week = absint(get_option('start_of_week', 1));

        $end_of_week = $start_of_week == 0 ? 6 : $start_of_week - 1;

        $week_start = $now->startOfWeek($start_of_week);
        $week_end   = $now->endOfWeek($end_of_week);

        return $date->between($week_start, $week_end);
    }

    /**
     * @param $email_campaign_id
     * @param CarbonImmutable $schedule_hour
     * @param $digest_type
     *
     * @return bool
     */
    public function should_send($email_campaign_id, $schedule_hour, $digest_type)
    {
        $timezone   = $this->timezone();
        $carbon_now = CarbonImmutable::now($timezone);

        $last_processed_at = $this->last_scheduled_at($email_campaign_id);

        if ( ! empty($last_processed_at)) {

            $last_processed_at_carbon_instance = CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $last_processed_at,
                $timezone
            );

            if ($digest_type == 'every_day') {
                if ($last_processed_at_carbon_instance->isToday()) {
                    return false;
                }
            }

            if ($digest_type == 'every_week') {
                if ($this->is_same_week($last_processed_at_carbon_instance, $carbon_now)) {
                    return false;
                }
            }

            if ($digest_type == 'every_month') {
                if ($last_processed_at_carbon_instance->isCurrentMonth()) {
                    return false;
                }
            }
        }

        // add an hour grace so missed schedule can still run.
        if ($schedule_hour->diffInRealHours($carbon_now) <= 1) {
            return true;
        }

        return false;
    }

    /**
     * @return void|null
     */
    public function run_job()
    {
        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) return;

        $postDigests = ER::get_by_email_campaign_type(ER::POSTS_EMAIL_DIGEST);

        if (empty($postDigests)) return;

        foreach ($postDigests as $postDigest) {

            $email_campaign_id = absint($postDigest['id']);

            if (ER::is_campaign_active($email_campaign_id) === false) continue;

            $schedule_interval   = ER::get_merged_customizer_value($email_campaign_id, 'schedule_interval');
            $schedule_time       = absint(ER::get_merged_customizer_value($email_campaign_id, 'schedule_time'));
            $schedule_day        = absint(ER::get_merged_customizer_value($email_campaign_id, 'schedule_day'));
            $schedule_month_date = absint(ER::get_merged_customizer_value($email_campaign_id, 'schedule_month_date'));

            $timezone = $this->timezone();

            $carbon_now   = CarbonImmutable::now($timezone);
            $carbon_today = CarbonImmutable::today($timezone);

            $schedule_hour = $carbon_today->hour($schedule_time);

            if (isset($_GET['mo_ped_debug'])) {
                $this->create_and_send_campaign($email_campaign_id);
                return;
            }

            // A send by hand took this slot's place.
            if (self::SCHEDULE_MANUAL !== $schedule_interval && $this->is_skipped($email_campaign_id, $schedule_hour)) continue;

            switch ($schedule_interval) {
                case 'every_day':
                    if ($schedule_hour->lessThanOrEqualTo($carbon_now) &&
                        $this->should_send($email_campaign_id, $schedule_hour, 'every_day')) {
                        $this->create_and_send_campaign($email_campaign_id, true);
                    }
                    break;
                case 'every_week':
                    if ($carbon_today->isDayOfWeek($schedule_day) &&
                        $schedule_hour->lessThanOrEqualTo($carbon_now) &&
                        $this->should_send($email_campaign_id, $schedule_hour, 'every_week')) {
                        $this->create_and_send_campaign($email_campaign_id, true);
                    }
                    break;
                case 'every_month':
                    if ($carbon_now->day == $schedule_month_date &&
                        $schedule_hour->lessThanOrEqualTo($carbon_now) &&
                        $this->should_send($email_campaign_id, $schedule_hour, 'every_month')) {
                        $this->create_and_send_campaign($email_campaign_id, true);
                    }
                    break;
            }
        }
    }

    /**
     * @param int $email_campaign_id
     * @param bool $scheduled whether the schedule is sending it
     */
    public function create_and_send_campaign($email_campaign_id, $scheduled = false)
    {
        if ( ! $this->lock($email_campaign_id)) return;

        try {
            $campaign_id = $this->create_campaign($email_campaign_id);

            if ($campaign_id) {
                if ($scheduled) {
                    EmailCampaignMeta::update_meta_data($email_campaign_id, self::LAST_SCHEDULED_META, $this->now_mysql());
                }

                $this->send_campaign($email_campaign_id, $campaign_id);
            }
        } finally {
            $this->unlock($email_campaign_id);
        }
    }

    /**
     * Send the digest now, outside its schedule.
     *
     * @param int $email_campaign_id
     * @param array $args post_ids: the posts to include (from those the digest would send now, or the latest
     *                    posts with include_latest); skip_next: whether this replaces the next scheduled send.
     *
     * @return int|\WP_Error campaign log ID
     */
    public function send_now($email_campaign_id, $args = [])
    {
        $args = wp_parse_args($args, ['post_ids' => [], 'skip_next' => true, 'include_latest' => false]);

        $email_campaign_id = absint($email_campaign_id);

        if (ER::POSTS_EMAIL_DIGEST !== ER::get_email_campaign_type($email_campaign_id)) {
            return new \WP_Error('mo_digest_not_found', __('That digest no longer exists.', 'mailoptin'));
        }

        if ( ! ConnectionFactory::make($this->connection_service($email_campaign_id))) {
            return new \WP_Error('mo_digest_no_connection', __('Choose who the digest goes to in its settings first.', 'mailoptin'));
        }

        // Before choosing posts: a scheduled send in progress may be about to move the start of the next digest.
        if ( ! $this->lock($email_campaign_id)) {
            return new \WP_Error('mo_digest_busy', __('This digest is being sent right now. Check the campaign log in a minute.', 'mailoptin'));
        }

        try {
            $available = $args['include_latest'] ? $this->latest_posts($email_campaign_id) : $this->post_collection($email_campaign_id);
            $wanted    = array_map('absint', (array)$args['post_ids']);

            // Only posts the digest would send anyway: the form can't add others.
            $posts = array_values(array_filter($available, function ($post) use ($wanted) {
                return in_array((int)$post->ID, $wanted, true);
            }));

            if (empty($posts)) {
                return new \WP_Error('mo_digest_no_posts', __('Choose at least one post to send.', 'mailoptin'));
            }

            $next           = $this->next_unskipped_at($email_campaign_id);
            $last_processed = (string)$this->last_processed_at($email_campaign_id);

            $campaign_log_id = $this->create_campaign($email_campaign_id, $posts);

            if ( ! $campaign_log_id) {
                return new \WP_Error('mo_digest_not_created', __('The digest couldn\'t be created. Try again.', 'mailoptin'));
            }

            // From now on the schedule's own record decides whether this period's digest went out.
            if ('' === (string)EmailCampaignMeta::get_meta_data($email_campaign_id, self::LAST_SCHEDULED_META)) {
                EmailCampaignMeta::update_meta_data($email_campaign_id, self::LAST_SCHEDULED_META, $last_processed ?: self::NEVER);
            }

            if ( ! $this->send_campaign($email_campaign_id, $campaign_log_id)) {
                // Nothing went out: the posts stay for the next digest and the schedule is untouched.
                if ('' === $last_processed) {
                    EmailCampaignMeta::delete_meta_data($email_campaign_id, 'last_processed_at');
                } else {
                    EmailCampaignMeta::update_meta_data($email_campaign_id, 'last_processed_at', $last_processed);
                }

                return new \WP_Error('mo_digest_send_failed', __('The digest couldn\'t be sent. The campaign log says why.', 'mailoptin'));
            }

            // "Still send it" leaves any earlier skip alone: it's about the slot after it.
            if ($args['skip_next'] && $next) {
                EmailCampaignMeta::update_meta_data($email_campaign_id, self::SKIP_UNTIL_META, $next->format('Y-m-d H:i:s'));
            }

            do_action('mailoptin_digest_sent_by_hand', $email_campaign_id, $campaign_log_id, $posts);

            return $campaign_log_id;

        } finally {
            $this->unlock($email_campaign_id);
        }
    }

    /**
     * @param $email_campaign_id
     *
     * @return bool|int
     */
    public function create_campaign($email_campaign_id, $post_collection = null)
    {
        $email_subject = Misc::parse_email_subject(ER::get_merged_customizer_value($email_campaign_id, 'email_campaign_subject'));

        if (null === $post_collection) $post_collection = $this->post_collection($email_campaign_id);

        if (empty($post_collection)) return false;

        $email_subject = apply_filters('mailoptin_digest_email_campaign_subject', $email_subject, $email_campaign_id, $post_collection);

        $content_html = (new Templatify($email_campaign_id, $post_collection))->forge();

        return $this->save_campaign_log(
            $email_campaign_id,
            $email_subject,
            $content_html
        );
    }

    /**
     * Does the actual campaign sending.
     *
     * @param int $email_campaign_id
     * @param int $campaign_log_id
     *
     * @return bool whether it was sent (or queued)
     */
    public function send_campaign($email_campaign_id, $campaign_log_id)
    {
        do_action('mo_posts_email_digest_before_send_campaign', $email_campaign_id, $campaign_log_id);

        $campaign           = $this->CampaignLogRepository->getById($campaign_log_id);
        $connection_service = $this->connection_service($email_campaign_id);

        $connection_instance = ConnectionFactory::make($connection_service);

        if ( ! $connection_instance) return false;

        EmailCampaignMeta::update_meta_data($email_campaign_id, 'last_processed_at', $this->now_mysql());

        $response = $connection_instance->send_newsletter(
            $email_campaign_id,
            $campaign_log_id,
            $campaign->title,
            $connection_instance->replace_placeholder_tags($campaign->content_html, 'html'),
            $connection_instance->replace_placeholder_tags($campaign->content_text, 'text')
        );

        if (isset($response['success']) && (true === $response['success'])) {
            // The send queue marks the campaign processed itself once every email has gone out.
            if (($response['status'] ?? '') !== QueueRunner::LOG_STATUS_SENDING) {
                $this->update_campaign_status($campaign_log_id, 'processed');
            }

            return true;
        }

        $this->update_campaign_status($campaign_log_id, 'failed');

        return false;
    }

    /**
     * Singleton.
     *
     * @return PostsEmailDigest
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