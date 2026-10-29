<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Saved segments: named tag, custom field and engagement filters. A campaign sending to a built-in list can be
 * limited to one, and the filters are applied when it sends, so new matches are included.
 *
 * Segments are a premium feature. Free sites keep any saved ones, but campaigns using them don't send.
 */
class SegmentsRepository extends AbstractRepository
{
    // DB update that created the segments table.
    const DB_VERSION = 21;

    const MAX_LENGTH = 100;

    // Email campaign customizer settings of campaigns sending to a built-in list.
    const SEGMENT_SETTING = 'MailOptinConnect_segment';
    const EXCLUDE_TAGS_SETTING = 'MailOptinConnect_exclude_tags';

    public static function is_ready()
    {
        return (int)get_option('mo_db_ver', 0) >= self::DB_VERSION;
    }

    public static function is_available()
    {
        return defined('MAILOPTIN_DETACH_LIBSODIUM');
    }

    public static function table()
    {
        return self::wpdb()->prefix . Core::segments_table_name;
    }

    public static function sanitize_name($name)
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', sanitize_text_field((string)$name))), 0, self::MAX_LENGTH);
    }

    /**
     * Keep only the parts of a filter set a segment stores.
     *
     * @param array $filters as Subscriber_Filters::from_request() returns them
     */
    public static function clean_filters($filters)
    {
        $match = $filters['tag_match'] ?? 'any';

        return [
            'tag_ids'          => array_values(array_unique(array_filter(array_map('absint', (array)($filters['tag_ids'] ?? []))))),
            'tag_match'        => in_array($match, ['any', 'all', 'none'], true) ? $match : 'any',
            'field_conditions' => array_values(array_filter((array)($filters['field_conditions'] ?? []), 'is_array')),
            'engagement'       => array_values(array_filter(array_map([CampaignActivityRepository::class, 'clean_condition'], (array)($filters['engagement'] ?? [])))),
        ];
    }

    protected static function prepare($row)
    {
        if ( ! $row) return null;

        $row['id']      = (int)$row['id'];
        $row['filters'] = self::clean_filters((array)json_decode($row['filters'], true));

        return $row;
    }

    public static function get($segment_id)
    {
        $table = self::table();

        return self::prepare(self::wpdb()->get_row(self::wpdb()->prepare("SELECT * FROM $table WHERE id = %d", $segment_id), 'ARRAY_A'));
    }

    public static function get_by_name($name)
    {
        $table = self::table();

        return self::prepare(self::wpdb()->get_row(self::wpdb()->prepare("SELECT * FROM $table WHERE name = %s", self::sanitize_name($name)), 'ARRAY_A'));
    }

    /**
     * @return array segments by name
     */
    public static function all()
    {
        $table = self::table();

        return array_map([__CLASS__, 'prepare'], self::wpdb()->get_results("SELECT * FROM $table ORDER BY name", 'ARRAY_A'));
    }

    /**
     * @return array id => name, by name
     */
    public static function names()
    {
        return wp_list_pluck(self::all(), 'name', 'id');
    }

    protected static function validate($name, $filters, $segment_id = 0)
    {
        if ('' === $name) return new \WP_Error('mo_segment_name', __('Give the segment a name.', 'mailoptin'));

        $existing = self::get_by_name($name);

        if ($existing && $existing['id'] !== (int)$segment_id) {
            return new \WP_Error('mo_segment_exists', sprintf(__('There is already a segment called "%s".', 'mailoptin'), $name));
        }

        if (null !== $filters && empty($filters['tag_ids']) && empty($filters['field_conditions']) && empty($filters['engagement'])) {
            return new \WP_Error('mo_segment_empty', __('Add at least one filter before saving a segment.', 'mailoptin'));
        }

        return true;
    }

    /**
     * @return int|\WP_Error
     */
    public static function create($name, $filters)
    {
        $name    = self::sanitize_name($name);
        $filters = self::clean_filters($filters);
        $valid   = self::validate($name, $filters);

        if (is_wp_error($valid)) return $valid;

        $inserted = self::wpdb()->insert(self::table(), [
            'name'       => $name,
            'filters'    => wp_json_encode($filters),
            'created_at' => Subscribers::now(),
            'updated_at' => Subscribers::now(),
        ]);

        if ( ! $inserted) return new \WP_Error('mo_segment_not_saved', __('The segment could not be saved.', 'mailoptin'));

        return (int)self::wpdb()->insert_id;
    }

    /**
     * @param array|null $filters null keeps the current filters
     *
     * @return true|\WP_Error
     */
    public static function update($segment_id, $name, $filters = null)
    {
        $segment = self::get($segment_id);

        if ( ! $segment) return new \WP_Error('mo_segment_missing', __('That segment no longer exists.', 'mailoptin'));

        $name    = null === $name ? $segment['name'] : self::sanitize_name($name);
        $filters = null === $filters ? null : self::clean_filters($filters);
        $valid   = self::validate($name, $filters, $segment['id']);

        if (is_wp_error($valid)) return $valid;

        $data = ['name' => $name, 'updated_at' => Subscribers::now()];

        if (null !== $filters) $data['filters'] = wp_json_encode($filters);

        self::wpdb()->update(self::table(), $data, ['id' => $segment['id']]);

        return true;
    }

    public static function delete($segment_id)
    {
        return (bool)self::wpdb()->delete(self::table(), ['id' => absint($segment_id)], ['%d']);
    }

    /**
     * Why a segment can't be used as it is. A filter on a deleted tag or field, or on dropdown
     * options that were removed, would otherwise be skipped and the segment could match more people.
     *
     * @return string[] empty when the segment is fine
     */
    public static function problems($segment)
    {
        $problems = [];
        $filters  = $segment['filters'];

        // Saved segments always have a filter, so none means the stored filters couldn't be read.
        if (empty($filters['tag_ids']) && empty($filters['field_conditions']) && empty($filters['engagement'])) {
            return [__('Its filters couldn\'t be read.', 'mailoptin')];
        }

        if ( ! empty($filters['engagement']) && ! CampaignActivityRepository::is_ready()) {
            $problems[] = __('It filters by opens and clicks, which aren\'t set up yet.', 'mailoptin');
        }

        if ( ! empty($filters['tag_ids'])) {
            $missing = array_diff($filters['tag_ids'], array_map('intval', array_keys(TagsRepository::names())));

            if ( ! empty($missing)) $problems[] = __('It filters by a tag that was deleted.', 'mailoptin');
        }

        foreach ($filters['field_conditions'] as $condition) {
            $field = Fields::get($condition['field_id'] ?? 0);

            // Values that are no longer options are skipped by the filter, which widens "is none of".
            $removed_options = $field && Fields::has_options($field['type']) && is_array($condition['value'] ?? null)
                && array_diff(array_map('strval', $condition['value']), $field['options']);

            if ($removed_options || null === Fields::condition_sql($condition)) {
                $problems[] = __('It filters by a custom field that was deleted or changed.', 'mailoptin');
                break;
            }
        }

        return $problems;
    }

    /**
     * Subscribers in any list who match a segment and are subscribed.
     */
    public static function count_matching($segment)
    {
        $where  = ['s.status = %s'];
        $params = [Subscribers::STATUS_SUBSCRIBED];

        Subscribers::filter_where($segment['filters'], $where, $params);

        return (int)self::wpdb()->get_var(self::wpdb()->prepare('SELECT COUNT(*) FROM ' . Subscribers::table() . ' s WHERE ' . implode(' AND ', $where), $params));
    }

    /**
     * Email campaigns limited to a segment.
     *
     * @return array campaign id => name
     */
    public static function used_by($segment_id)
    {
        $used = [];

        foreach (EmailCampaignRepository::get_settings() as $campaign_id => $settings) {
            if ( ! is_array($settings) || (int)($settings[self::SEGMENT_SETTING] ?? 0) !== (int)$segment_id) continue;

            $name = EmailCampaignRepository::get_email_campaign_name($campaign_id);

            if (null !== $name) $used[(int)$campaign_id] = $name;
        }

        return $used;
    }

    /**
     * Point segments at the tag another was merged into.
     */
    public static function replace_tag($from_id, $into_id)
    {
        foreach (self::all() as $segment) {
            if ( ! in_array((int)$from_id, $segment['filters']['tag_ids'], true)) continue;

            $filters            = $segment['filters'];
            $filters['tag_ids'] = array_values(array_unique(array_map(function ($tag_id) use ($from_id, $into_id) {
                return $tag_id === (int)$from_id ? (int)$into_id : $tag_id;
            }, $filters['tag_ids'])));

            self::wpdb()->update(self::table(), ['filters' => wp_json_encode($filters)], ['id' => $segment['id']]);
        }
    }

    /**
     * Keep campaigns' excluded tags pointing at tags that exist after a merge or delete.
     *
     * @param int $into_id the tag it was merged into; 0 when it was deleted
     */
    public static function replace_excluded_tag($tag_id, $into_id = 0)
    {
        $settings = EmailCampaignRepository::get_settings();
        $changed  = false;

        foreach ((array)$settings as $campaign_id => $campaign_settings) {
            $excluded = $campaign_settings[self::EXCLUDE_TAGS_SETTING] ?? '';
            $excluded = wp_parse_id_list(is_array($excluded) ? $excluded : (string)$excluded);

            if ( ! in_array((int)$tag_id, $excluded, true)) continue;

            $excluded = array_diff($excluded, [(int)$tag_id]);
            if ($into_id) $excluded[] = (int)$into_id;

            $settings[$campaign_id][self::EXCLUDE_TAGS_SETTING] = implode(',', array_unique($excluded));
            $changed = true;
        }

        if ($changed) update_option(MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME, $settings);
    }

    /**
     * The customizer saves the excluded tags as a comma separated list of IDs.
     *
     * @return int[]|null null when the list can't be read, which must stop a send rather than be ignored
     */
    public static function parse_tag_ids($value)
    {
        if (is_array($value)) $value = implode(',', array_filter($value, 'is_scalar'));

        $value = trim((string)$value);

        if ('' === $value) return [];

        $ids = [];

        foreach (explode(',', $value) as $token) {
            $token = trim($token);

            if ('' === $token) continue;
            if ( ! ctype_digit($token) || ! (int)$token) return null;

            $ids[] = (int)$token;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return int[]|null see parse_tag_ids()
     */
    public static function excluded_tag_ids($email_campaign_id)
    {
        return self::parse_tag_ids(EmailCampaignRepository::get_customizer_value($email_campaign_id, self::EXCLUDE_TAGS_SETTING));
    }

    /**
     * Email campaigns that leave out a tag.
     *
     * @return array campaign id => name
     */
    public static function excluded_by($tag_id)
    {
        $campaigns = [];

        foreach (EmailCampaignRepository::get_settings() as $campaign_id => $settings) {
            if ( ! is_array($settings) || ! in_array((int)$tag_id, (array)self::parse_tag_ids($settings[self::EXCLUDE_TAGS_SETTING] ?? ''), true)) continue;

            $name = EmailCampaignRepository::get_email_campaign_name($campaign_id);

            if (null !== $name) $campaigns[(int)$campaign_id] = $name;
        }

        return $campaigns;
    }

    /**
     * The extra filters a campaign sends with: its segment and the tags it leaves out.
     *
     * @return array|\WP_Error filters for SubscribersRepository::get_list_recipients(), or why the campaign can't send
     */
    public static function campaign_filters($email_campaign_id)
    {
        $segment_id  = absint(EmailCampaignRepository::get_customizer_value($email_campaign_id, self::SEGMENT_SETTING));

        return self::filters_for($segment_id, self::excluded_tag_ids($email_campaign_id));
    }

    /**
     * @param int[]|null $exclude_tag_ids null when the stored list couldn't be read
     *
     * @return array|\WP_Error
     */
    public static function filters_for($segment_id, $exclude_tag_ids)
    {
        if ( ! $segment_id && [] === $exclude_tag_ids) return [];

        if (null === $exclude_tag_ids) {
            return new \WP_Error('mo_segment_exclude_invalid', __('The tags this campaign leaves out couldn\'t be read. Choose them again in the campaign settings.', 'mailoptin'));
        }

        // Never fall back to the whole list: that would email people the campaign was meant to skip.
        if ( ! self::is_available() || ! self::is_ready()) {
            return new \WP_Error('mo_segment_premium', __('This campaign is limited to a segment or leaves out tags, which needs MailOptin Premium. Remove them from the campaign settings to send it to the whole list.', 'mailoptin'));
        }

        // Leaving out a deleted tag would leave out nobody, so the campaign would reach the people it was meant to skip.
        if (array_diff($exclude_tag_ids, array_map('intval', array_keys(TagsRepository::names())))) {
            return new \WP_Error('mo_segment_exclude_missing', __('This campaign leaves out people with a tag that was deleted. Check the tags to leave out in the campaign settings, then send again.', 'mailoptin'));
        }

        $filters = ['exclude_tag_ids' => $exclude_tag_ids];

        if ($segment_id) {
            $segment = self::get($segment_id);

            if ( ! $segment) {
                return new \WP_Error('mo_segment_missing', __('The segment this campaign sends to was deleted. Choose another segment in the campaign settings.', 'mailoptin'));
            }

            $problems = self::problems($segment);

            if ( ! empty($problems)) {
                return new \WP_Error('mo_segment_broken', sprintf(__('The segment "%1$s" can\'t be used: %2$s Edit the segment, then send again.', 'mailoptin'), $segment['name'], implode(' ', $problems)));
            }

            $filters = array_merge($segment['filters'], $filters);
        }

        return $filters;
    }
}
