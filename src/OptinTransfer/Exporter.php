<?php

namespace MailOptin\Core\OptinTransfer;

use MailOptin\Core\Repositories\CampaignSettingsRepository;
use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;
use MailOptin\Core\Repositories\TagsRepository;

/**
 * Writes optin campaigns to an export file.
 *
 * Only settings the site owner saved go in: template defaults aren't stored, so they aren't exported either.
 * IDs that only mean something on this site (posts, terms, MailOptin tags, custom fields) stay in the settings
 * as they are, and the file carries a description of each so the importer can find it again.
 * Stats, subscribers and connection credentials are never exported.
 */
class Exporter
{
    const FORMAT  = 'mailoptin-optin-campaigns';
    const VERSION = 1;

    /**
     * @param int[] $campaign_ids
     *
     * @return array the export, ready for wp_json_encode()
     */
    public static function build(array $campaign_ids)
    {
        $ids = [];
        foreach (array_map('absint', $campaign_ids) as $id) {
            if ( ! $id || ! OCR::get_optin_campaign_by_id($id)) continue;
            // An A/B test travels whole: its parent first, then every variant.
            $parent = OCR::get_split_parent_id($id) ?: $id;
            $ids[]  = (int)$parent;
            foreach ((array)OCR::get_split_test_variant_ids($parent) as $variant) $ids[] = (int)$variant;
        }
        $ids = array_values(array_unique($ids));

        $export = [
            'format'    => self::FORMAT,
            'version'   => self::VERSION,
            'mailoptin' => defined('MAILOPTIN_VERSION_NUMBER') ? MAILOPTIN_VERSION_NUMBER : '',
            'site'      => wp_parse_url(home_url(), PHP_URL_HOST),
            'exported'  => gmdate('c'),
            'campaigns' => [],
            'posts'     => [],
            'terms'     => [],
            'tags'      => [],
            'fields'    => [],
        ];

        $tag_names = TagsRepository::is_ready() ? TagsRepository::names() : [];

        foreach ($ids as $id) {
            $row      = OCR::get_optin_campaign_by_id($id);
            $settings = CampaignSettingsRepository::get_saved(CampaignSettingsRepository::TYPE_OPTIN, $id);
            $settings = is_array($settings) ? $settings : [];
            unset($settings['activate_optin']);

            $parent = OCR::get_split_parent_id($id);

            $export['campaigns'][] = [
                'id'         => (int)$id,
                'name'       => (string)$row['name'],
                'type'       => (string)$row['optin_type'],
                'template'   => (string)$row['optin_class'],
                'uuid'       => (string)$row['uuid'],
                'variant_of' => $parent ? (int)$parent : null,
                'settings'   => $settings,
                'images'     => References::images($settings),
            ];

            foreach (References::post_settings() as $key) {
                foreach (References::ids($settings[$key] ?? null) as $post_id) {
                    if ( ! isset($export['posts'][$post_id]) && ($ref = References::describe_post($post_id))) $export['posts'][$post_id] = $ref;
                }
            }

            foreach (References::term_settings() as $key => $taxonomy) {
                foreach (References::ids($settings[$key] ?? null) as $term_id) {
                    $k = $taxonomy . ':' . $term_id;
                    if ( ! isset($export['terms'][$k]) && ($ref = References::describe_term($term_id, $taxonomy))) $export['terms'][$k] = $ref;
                }
            }

            foreach (References::integrations($settings) as $integration) {
                foreach (References::ids($integration['MailOptinConnect_lead_tags'] ?? null) as $tag_id) {
                    if (isset($tag_names[$tag_id])) $export['tags'][$tag_id] = $tag_names[$tag_id];
                }
                foreach (array_keys(References::field_mappings($integration)) as $merge_key) {
                    if ( ! isset($export['fields'][$merge_key]) && ($ref = References::describe_field($merge_key))) $export['fields'][$merge_key] = $ref;
                }
            }
        }

        return apply_filters('mailoptin_optin_campaigns_export', $export, $ids);
    }

    /** A file name such as optin-campaigns-acme-garden-supply-2026-11-19.json */
    public static function filename()
    {
        $site = sanitize_title(get_bloginfo('name')) ?: 'site';

        return 'optin-campaigns-' . $site . '-' . gmdate('Y-m-d') . '.json';
    }

    /** Send the export as a download and stop. */
    public static function download(array $campaign_ids)
    {
        $json = wp_json_encode(self::build($campaign_ids), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::filename() . '"');
        header('X-Content-Type-Options: nosniff');
        echo $json;
        exit;
    }
}
