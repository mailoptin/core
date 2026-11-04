<?php

namespace MailOptin\Core\EmailCampaigns;

use Carbon\CarbonImmutable;
use MailOptin\Core\Admin\Customizer\EmailCampaign\NewPublishPostTemplatePreview;
use MailOptin\Core\Admin\Customizer\EmailCampaign\PostsEmailDigestTemplatePreview;
use MailOptin\Core\Core;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\Core\EmailCampaigns\NewPublishPost\NewPublishPost;
use MailOptin\Core\EmailCampaigns\PostsEmailDigest\PostsEmailDigest;
use MailOptin\Core\PluginSettings\Settings;
use MailOptin\Core\Repositories\ConnectionsRepository;
use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\SegmentsRepository;

/**
 * Post notifications and email digests as their settings page and list see them: what they send,
 * when and to whom, the checks before they can go out, and test emails.
 *
 * Their settings live with the email customizer's, so the page and the customizer stay in step.
 */
class Automations
{
    // When the last test went out, and when the settings last changed from the page.
    const TEST_META = 'mo_automation_test_sent_at';
    const SAVED_META = 'mo_automation_saved_at';

    // Taxonomies that only describe how a post is stored or shown, never what it is about.
    const IGNORED_TAXONOMIES = ['post_format', 'product_type', 'product_visibility', 'product_shipping_class'];

    public static function get($email_campaign_id, $key)
    {
        return ER::get_merged_customizer_value($email_campaign_id, $key);
    }

    /**
     * @param array $values setting name => value
     */
    public static function save($email_campaign_id, $values)
    {
        if (empty($values)) return;

        ER::update_settings_by_id($email_campaign_id, $values);
        EmailCampaignMeta::update_meta_data($email_campaign_id, self::SAVED_META, gmdate('Y-m-d H:i:s'));
    }

    public static function is_automation($email_campaign_id)
    {
        return in_array(ER::get_email_campaign_type($email_campaign_id), [ER::NEW_PUBLISH_POST, ER::POSTS_EMAIL_DIGEST], true);
    }

    public static function is_digest($email_campaign_id)
    {
        return ER::POSTS_EMAIL_DIGEST === ER::get_email_campaign_type($email_campaign_id);
    }

    public static function subject($email_campaign_id)
    {
        return (string)self::get($email_campaign_id, 'email_campaign_subject');
    }

    public static function is_active($email_campaign_id)
    {
        return ER::is_campaign_active($email_campaign_id);
    }

    /* ---------------------------------------------------------------------------------------------
     * Which posts
     * ------------------------------------------------------------------------------------------ */

    /**
     * Custom post types, categories, tags and authors need Pro.
     */
    public static function post_filters_available()
    {
        return (bool)apply_filters('mailoptin_enable_email_automation_cpt_support', false);
    }

    public static function digest_available()
    {
        return (bool)apply_filters('mailoptin_enable_post_email_digest', false);
    }

    public static function post_type($email_campaign_id)
    {
        $post_type = (string)self::get($email_campaign_id, 'custom_post_type');

        return '' === $post_type ? 'post' : $post_type;
    }

    /**
     * The taxonomies a post type's posts can be filtered by.
     *
     * @return array taxonomy name => label
     */
    public static function filter_taxonomies($post_type)
    {
        $taxonomies = [];

        foreach (get_object_taxonomies($post_type, 'objects') as $name => $taxonomy) {
            // WooCommerce product attributes are pa_*.
            if (in_array($name, self::IGNORED_TAXONOMIES, true) || 0 === strpos($name, 'pa_') || empty($taxonomy->show_ui)) continue;

            $taxonomies[$name] = $taxonomy->label;
        }

        return apply_filters('mailoptin_automation_filter_taxonomies', $taxonomies, $post_type);
    }

    /**
     * The taxonomies the page offers as filters: the post type's own, and any it has a filter saved
     * for that isn't normally offered (a product attribute chosen in the customizer, say), so it can
     * be seen and changed, and saving the page keeps it.
     *
     * @return array taxonomy name => label
     */
    public static function page_taxonomies($email_campaign_id, $post_type)
    {
        $taxonomies = self::filter_taxonomies($post_type);

        foreach (array_keys(self::term_filters($email_campaign_id)) as $taxonomy) {
            if (isset($taxonomies[$taxonomy])) continue;

            $object                = get_taxonomy($taxonomy);
            $taxonomies[$taxonomy] = $object ? $object->label : $taxonomy;
        }

        return $taxonomies;
    }

    /**
     * The terms a post must have, by taxonomy, counting only the taxonomies of the chosen post type.
     *
     * Posts keep their categories and tags in post_categories and post_tags; every other filter is in
     * custom_post_type_settings. Filters left over from another post type are ignored:
     * no post of this type can have those terms, so they would stop every send.
     *
     * @return array taxonomy => int[] term IDs; a post needs one of the terms of each taxonomy
     */
    public static function term_filters($email_campaign_id)
    {
        $post_type  = self::post_type($email_campaign_id);
        $taxonomies = get_object_taxonomies($post_type);
        $filters    = [];

        // Every post type's other taxonomies, a "series" on posts say, are in custom_post_type_settings.
        $sources = json_decode((string)self::get($email_campaign_id, 'custom_post_type_settings'), true);
        $sources = is_array($sources) ? $sources : [];

        // Posts keep their categories and tags in their own settings.
        if ('post' === $post_type) {
            $sources['category'] = self::get($email_campaign_id, 'post_categories');
            $sources['post_tag'] = self::get($email_campaign_id, 'post_tags');
        }

        foreach ($sources as $taxonomy => $terms) {
            if ( ! in_array($taxonomy, $taxonomies, true) || ! is_array($terms)) continue;

            $terms = array_values(array_filter(array_map('absint', $terms)));

            if ( ! empty($terms)) $filters[$taxonomy] = $terms;
        }

        return $filters;
    }

    /**
     * @return int[]
     */
    public static function author_filter($email_campaign_id)
    {
        $authors = self::get($email_campaign_id, 'post_authors');

        return is_array($authors) ? array_values(array_filter(array_map('absint', $authors))) : [];
    }

    /**
     * Whether a published post is one the automation sends.
     *
     * @param \WP_Post $post
     */
    public static function post_matches($email_campaign_id, $post)
    {
        $authors = self::author_filter($email_campaign_id);

        if ( ! empty($authors) && ! in_array((int)$post->post_author, $authors, true)) return false;

        foreach (self::term_filters($email_campaign_id) as $taxonomy => $terms) {
            $post_terms = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'ids']);

            if ( ! is_array($post_terms) || ! array_intersect(array_map('absint', $post_terms), $terms)) return false;
        }

        return true;
    }

    /**
     * get_posts() arguments for the automation's posts, newest first.
     */
    public static function posts_query($email_campaign_id, $limit)
    {
        $args = [
            'posts_per_page' => $limit,
            'post_type'      => self::post_type($email_campaign_id),
            'post_status'    => 'publish',
            'order'          => 'DESC',
            'orderby'        => 'post_date',
        ];

        $authors = self::author_filter($email_campaign_id);

        if ( ! empty($authors)) $args['author__in'] = $authors;

        $tax_query = [];

        foreach (self::term_filters($email_campaign_id) as $taxonomy => $terms) {
            $tax_query[] = ['taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $terms];
        }

        if ( ! empty($tax_query)) $args['tax_query'] = $tax_query;

        return $args;
    }

    /**
     * The newest published post the automation would send: what a test email shows.
     *
     * @return \WP_Post|null
     */
    public static function latest_matching_post($email_campaign_id)
    {
        $posts = get_posts(self::posts_query($email_campaign_id, 1));

        return $posts[0] ?? null;
    }

    /**
     * Names of the terms and authors the posts are filtered by.
     *
     * @return string[]
     */
    public static function filter_names($email_campaign_id)
    {
        $names = [];

        foreach (self::term_filters($email_campaign_id) as $taxonomy => $terms) {
            foreach ($terms as $term_id) {
                $term = get_term($term_id, $taxonomy);
                if ($term && ! is_wp_error($term)) $names[] = $term->name;
            }
        }

        foreach (self::author_filter($email_campaign_id) as $author_id) {
            $user = get_userdata($author_id);
            if ($user) $names[] = sprintf(__('by %s', 'mailoptin'), $user->display_name);
        }

        return $names;
    }

    public static function post_type_label($post_type)
    {
        $object = get_post_type_object($post_type);

        return $object ? $object->labels->name : $post_type;
    }

    /* ---------------------------------------------------------------------------------------------
     * When
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return int|null minutes after publishing, or null when it sends straight away
     */
    public static function delay_minutes($email_campaign_id)
    {
        if (self::get($email_campaign_id, 'send_immediately')) return null;

        $digit = absint(self::get($email_campaign_id, 'schedule_digit'));
        $unit  = (string)self::get($email_campaign_id, 'schedule_type');

        // An empty delay never sends, as NewPublishPost::schedule_time() has it.
        if ( ! $digit) return 0;

        $per = ['minutes' => 1, 'hours' => 60, 'days' => 1440];

        return $digit * ($per[$unit] ?? 60);
    }

    public static function delay_label($email_campaign_id)
    {
        $delay = self::delay_minutes($email_campaign_id);

        if (null === $delay) return __('straight away', 'mailoptin');

        $digit = absint(self::get($email_campaign_id, 'schedule_digit'));
        $unit  = (string)self::get($email_campaign_id, 'schedule_type');

        if ( ! $digit) return __('never: the delay is empty', 'mailoptin');

        $labels = [
            'minutes' => _n('%s minute after publishing', '%s minutes after publishing', $digit, 'mailoptin'),
            'hours'   => _n('%s hour after publishing', '%s hours after publishing', $digit, 'mailoptin'),
            'days'    => _n('%s day after publishing', '%s days after publishing', $digit, 'mailoptin'),
        ];

        return sprintf($labels[$unit] ?? $labels['hours'], number_format_i18n($digit));
    }

    public static function day_names()
    {
        global $wp_locale;

        return array_map(function ($day) use ($wp_locale) {
            return $wp_locale->get_weekday($day);
        }, range(0, 6));
    }

    public static function hour_label($hour)
    {
        return date_i18n(get_option('time_format'), gmmktime(absint($hour), 0, 0, 1, 1, 2020), true);
    }

    public static function ordinal($number)
    {
        $number = absint($number);

        if (in_array($number % 100, [11, 12, 13], true)) return sprintf(__('%sth', 'mailoptin'), $number);

        switch ($number % 10) {
            case 1:
                return sprintf(__('%sst', 'mailoptin'), $number);
            case 2:
                return sprintf(__('%snd', 'mailoptin'), $number);
            case 3:
                return sprintf(__('%srd', 'mailoptin'), $number);
        }

        return sprintf(__('%sth', 'mailoptin'), $number);
    }

    public static function digest_schedule_label($email_campaign_id)
    {
        $hour = self::hour_label(self::get($email_campaign_id, 'schedule_time'));

        switch ((string)self::get($email_campaign_id, 'schedule_interval')) {
            case 'every_week':
                $days = self::day_names();

                return sprintf(__('Every %1$s at %2$s', 'mailoptin'), $days[absint(self::get($email_campaign_id, 'schedule_day')) % 7], $hour);
            case 'every_month':
                return sprintf(__('Monthly on the %1$s at %2$s', 'mailoptin'), self::ordinal(self::get($email_campaign_id, 'schedule_month_date')), $hour);
            default:
                return sprintf(__('Every day at %s', 'mailoptin'), $hour);
        }
    }

    /**
     * When the next digest is due, in site time. It goes out at the first hourly run after that, and
     * only if there are new posts.
     *
     * @param CarbonImmutable|null $now
     *
     * @return CarbonImmutable|null
     */
    public static function next_digest_at($email_campaign_id, $now = null)
    {
        $timezone = wp_timezone_string();
        $now      = $now ?: CarbonImmutable::now($timezone);
        $interval = (string)self::get($email_campaign_id, 'schedule_interval');
        // run_job() sends nothing for an interval it doesn't know.
        if ( ! in_array($interval, ['every_day', 'every_week', 'every_month'], true)) return null;
        $hour     = absint(self::get($email_campaign_id, 'schedule_time')) % 24;
        $weekday  = absint(self::get($email_campaign_id, 'schedule_day')) % 7;
        $monthday = max(1, min(28, absint(self::get($email_campaign_id, 'schedule_month_date'))));
        $last     = (string)EmailCampaignMeta::get_meta_data($email_campaign_id, 'last_processed_at');
        $last     = '' !== $last ? CarbonImmutable::createFromFormat('Y-m-d H:i:s', $last, $timezone) : null;
        $digest   = PostsEmailDigest::get_instance();

        for ($i = 0; $i <= 62; $i++) {
            $day = $now->startOfDay()->addDays($i);

            if ('every_week' === $interval && $day->dayOfWeek !== $weekday) continue;
            if ('every_month' === $interval && $day->day !== $monthday) continue;

            $at = $day->setTime($hour, 0);

            // The hourly run sends while less than two whole hours have passed (should_send counts
            // whole hours), and not again once it has sent for that period.
            if ($at->lessThanOrEqualTo($now) && $at->diffInRealHours($now) > 1) continue;

            if ($last) {
                if ('every_day' === $interval && $last->isSameDay($at)) continue;
                if ('every_week' === $interval && $digest->is_same_week($last, $at)) continue;
                if ('every_month' === $interval && $last->isSameMonth($at)) continue;
            }

            return $at;
        }

        return null;
    }

    /**
     * What the next digest would hold if it went out now.
     *
     * @return \WP_Post[]
     */
    public static function digest_posts_so_far($email_campaign_id)
    {
        return (array)PostsEmailDigest::get_instance()->post_collection($email_campaign_id);
    }

    /* ---------------------------------------------------------------------------------------------
     * Audience
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return array [list or connection name, detail]
     */
    public static function audience_labels($email_campaign_id)
    {
        $connection = (string)self::get($email_campaign_id, 'connection_service');

        if ('' === $connection) return ['', ''];

        $connections = ConnectionsRepository::get_connections('email_campaign');
        $name        = $connections[$connection] ?? $connection;
        $list        = (string)self::get($email_campaign_id, 'connection_email_list');

        if ('' === $list || ! Broadcasts::connection_has_lists($connection)) return [$name, ''];

        $lists = ConnectionsRepository::connection_email_list($connection);
        $label = $lists[$list] ?? $list;

        if ('MailOptinConnect' !== $connection) return [$label, sprintf(__('via %s', 'mailoptin'), $name)];

        $segment = absint(self::get($email_campaign_id, SegmentsRepository::SEGMENT_SETTING));
        $segment = $segment ? (SegmentsRepository::get($segment)['name'] ?? '') : '';

        return [$label, '' !== $segment ? sprintf(__('%s segment', 'mailoptin'), $segment) : __('Everyone in the list', 'mailoptin')];
    }

    /* ---------------------------------------------------------------------------------------------
     * Checks and tests
     * ------------------------------------------------------------------------------------------ */

    public static function preview_html($email_campaign_id)
    {
        if (self::is_digest($email_campaign_id)) {
            return (string)(new PostsEmailDigestTemplatePreview($email_campaign_id))->forge();
        }

        $post = self::latest_matching_post($email_campaign_id);

        return (string)(new NewPublishPostTemplatePreview($email_campaign_id, $post ? $post->ID : false))->forge();
    }

    public static function test_is_current($email_campaign_id)
    {
        $tested = (string)EmailCampaignMeta::get_meta_data($email_campaign_id, self::TEST_META);
        $saved  = (string)EmailCampaignMeta::get_meta_data($email_campaign_id, self::SAVED_META);

        return '' !== $tested && $tested >= $saved;
    }

    /**
     * @return array checks, each label, ok (bool) and blocking (whether it stops the automation sending)
     */
    public static function checks($email_campaign_id, $html = null)
    {
        $html      = null === $html ? self::preview_html($email_campaign_id) : $html;
        $count     = Broadcasts::recipients_count($email_campaign_id);
        $post_type = self::post_type($email_campaign_id);

        $checks = [
            ['label' => __('Subject', 'mailoptin'), 'ok' => '' !== trim(self::subject($email_campaign_id)), 'blocking' => true],
            ['label' => __('Sender address', 'mailoptin'), 'ok' => is_email(Settings::instance()->from_email()), 'blocking' => true],
            ['label' => __('Who it goes to', 'mailoptin'), 'ok' => Broadcasts::has_recipients_set($email_campaign_id), 'blocking' => true],
        ];

        if ( ! post_type_exists($post_type)) {
            $checks[] = ['label' => sprintf(__('The "%s" post type isn\'t registered. Is its plugin active?', 'mailoptin'), $post_type), 'ok' => false, 'blocking' => true];
        }

        if (self::is_digest($email_campaign_id) && ! self::digest_available()) {
            $checks[] = ['label' => __('Digests need MailOptin Pro', 'mailoptin'), 'ok' => false, 'blocking' => true];
        }

        if ( ! self::is_digest($email_campaign_id) && 0 === self::delay_minutes($email_campaign_id)) {
            $checks[] = ['label' => __('A delay to send after', 'mailoptin'), 'ok' => false, 'blocking' => true];
        }

        if (null !== $count) {
            $checks[] = [
                'label'    => is_wp_error($count) ? $count->get_error_message() : sprintf(_n('Audience: %s person', 'Audience: %s people', $count, 'mailoptin'), number_format_i18n($count)),
                'ok'       => ! is_wp_error($count) && $count > 0,
                'blocking' => is_wp_error($count),
            ];
        }

        $checks[] = ['label' => __('Unsubscribe link in the design', 'mailoptin'), 'ok' => false !== strpos($html, '{{unsubscribe}}'), 'blocking' => false];
        $checks[] = ['label' => __('Test sent since the last change', 'mailoptin'), 'ok' => self::test_is_current($email_campaign_id), 'blocking' => false];

        return $checks;
    }

    public static function can_send($email_campaign_id, $checks = null)
    {
        foreach ($checks ?: self::checks($email_campaign_id) as $check) {
            if ($check['blocking'] && ! $check['ok']) return false;
        }

        return true;
    }

    /**
     * A test with the newest matching post, or the posts the next digest would hold.
     *
     * @return bool
     */
    public static function send_test($email_campaign_id, $email)
    {
        if ( ! is_email($email)) return false;

        $subject = Misc::parse_email_subject(self::subject($email_campaign_id));

        if (self::is_digest($email_campaign_id)) {
            $preview = new PostsEmailDigestTemplatePreview($email_campaign_id);
        } else {
            $post    = self::latest_matching_post($email_campaign_id);
            $preview = new NewPublishPostTemplatePreview($email_campaign_id, $post ? $post->ID : false);
            $subject = NewPublishPost::format_campaign_subject($subject, $post ?: (object)['post_title' => __('Your latest post', 'mailoptin')]);
        }

        $sent = Broadcasts::deliver_test($email, $subject, (string)$preview->forge());

        if ($sent) EmailCampaignMeta::update_meta_data($email_campaign_id, self::TEST_META, gmdate('Y-m-d H:i:s'));

        return $sent;
    }

    /* ---------------------------------------------------------------------------------------------
     * Sends
     * ------------------------------------------------------------------------------------------ */

    /**
     * The automation's newest campaign logs: one per post, or per digest.
     *
     * @return array[] id, title, status, status_time
     */
    public static function recent_logs($email_campaign_id, $limit = 3)
    {
        global $wpdb;

        $table = $wpdb->prefix . Core::campaign_log_table_name;

        return (array)$wpdb->get_results($wpdb->prepare("SELECT id, title, status, status_time FROM $table WHERE email_campaign_id = %d ORDER BY id DESC LIMIT %d", $email_campaign_id, $limit), 'ARRAY_A');
    }

    /**
     * The newest campaign log of each of these automations.
     *
     * @return array email campaign ID => log row
     */
    public static function last_logs($email_campaign_ids)
    {
        global $wpdb;

        $ids = array_filter(array_map('absint', (array)$email_campaign_ids));

        if (empty($ids)) return [];

        $table = $wpdb->prefix . Core::campaign_log_table_name;
        $in    = implode(',', $ids);
        $rows  = $wpdb->get_results("SELECT l.id, l.email_campaign_id, l.title, l.status, l.status_time FROM $table l JOIN (SELECT MAX(id) id FROM $table WHERE email_campaign_id IN ($in) GROUP BY email_campaign_id) m ON m.id = l.id", 'ARRAY_A');

        $logs = [];

        foreach ((array)$rows as $row) {
            $logs[absint($row['email_campaign_id'])] = $row;
        }

        return $logs;
    }
}
