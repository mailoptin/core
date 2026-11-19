<?php

namespace MailOptin\Core\OptinTransfer;

use MailOptin\Core\AjaxHandler;
use MailOptin\Core\Repositories\CustomFieldsRepository;
use MailOptin\Core\Repositories\OptinCampaignMeta;
use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;
use MailOptin\Core\Repositories\OptinThemesRepository;
use MailOptin\Core\Repositories\TagsRepository;

/**
 * Reads an export file, says what will differ on this site, and adds the campaigns as drafts.
 *
 * review() and run() share plan(), so what the review screen says is what the import does.
 */
class Importer
{
    const MAX_BYTES = 5242880; // 5MB; a campaign is usually a few kilobytes.

    const TYPES = ['lightbox', 'inpost', 'sidebar', 'bar', 'slidein'];

    /**
     * Check a file's contents.
     *
     * @return array|\WP_Error the export
     */
    public static function parse($json)
    {
        if ( ! is_string($json) || '' === trim($json)) return new \WP_Error('mo_import_empty', __('The file is empty.', 'mailoptin'));

        if (strlen($json) > self::MAX_BYTES) return new \WP_Error('mo_import_size', __('The file is larger than 5MB, which is too big for an export of optin campaigns.', 'mailoptin'));

        $data = json_decode($json, true, 64);

        if ( ! is_array($data) || ($data['format'] ?? '') !== Exporter::FORMAT || ! isset($data['campaigns']) || ! is_array($data['campaigns'])) {
            return new \WP_Error('mo_import_format', __("This file wasn't exported from MailOptin's Optin Campaigns page.", 'mailoptin'));
        }

        if ((int)($data['version'] ?? 0) > Exporter::VERSION) {
            return new \WP_Error('mo_import_version', sprintf(
                __('This file is from MailOptin %s. Update MailOptin to import it.', 'mailoptin'),
                sanitize_text_field((string)($data['mailoptin'] ?? ''))
            ));
        }

        $campaigns = [];
        foreach ($data['campaigns'] as $c) {
            if ( ! is_array($c) || empty($c['id']) || empty($c['name']) || ! is_array($c['settings'] ?? null)) continue;
            $campaigns[(int)$c['id']] = [
                'id'         => (int)$c['id'],
                'name'       => sanitize_text_field((string)$c['name']),
                'type'       => sanitize_key((string)($c['type'] ?? '')),
                'template'   => preg_replace('/[^A-Za-z0-9_]/', '', (string)($c['template'] ?? '')),
                'uuid'       => preg_replace('/[^A-Za-z0-9]/', '', (string)($c['uuid'] ?? '')),
                'variant_of' => empty($c['variant_of']) ? null : (int)$c['variant_of'],
                'settings'   => self::clean_settings($c['settings']),
                'images'     => array_values(array_filter(array_map('esc_url_raw', array_filter((array)($c['images'] ?? []), 'is_string')))),
            ];
        }

        if (empty($campaigns)) return new \WP_Error('mo_import_none', __("There aren't any campaigns in this file.", 'mailoptin'));

        // A variant whose parent isn't in the file comes in on its own.
        foreach ($campaigns as $id => $c) {
            if ($c['variant_of'] && ! isset($campaigns[$c['variant_of']])) $campaigns[$id]['variant_of'] = null;
        }

        return [
            'site'      => sanitize_text_field((string)($data['site'] ?? '')),
            'exported'  => sanitize_text_field((string)($data['exported'] ?? '')),
            'campaigns' => $campaigns,
            'posts'     => is_array($data['posts'] ?? null) ? $data['posts'] : [],
            'terms'     => is_array($data['terms'] ?? null) ? $data['terms'] : [],
            'tags'      => is_array($data['tags'] ?? null) ? array_map('sanitize_text_field', array_filter($data['tags'], 'is_scalar')) : [],
            'fields'    => is_array($data['fields'] ?? null) ? $data['fields'] : [],
        ];
    }

    /** Setting names as the Customizer writes them; values only scalars and arrays of them. */
    protected static function clean_settings($settings)
    {
        $clean = [];
        foreach ($settings as $key => $value) {
            if ( ! is_string($key) || ! preg_match('/^[A-Za-z0-9_\-]{1,191}$/', $key)) continue;
            if (is_array($value)) {
                $clean[$key] = self::clean_settings_value($value);
            } elseif (is_scalar($value) || null === $value) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    protected static function clean_settings_value(array $value)
    {
        $out = [];
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $out[$k] = self::clean_settings_value($v);
            } elseif (is_scalar($v) || null === $v) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * Is the campaign's template here? 'ok', 'premium' (needs MailOptin Premium) or 'missing'.
     */
    public static function template_status($type, $template)
    {
        if ( ! in_array($type, self::TYPES, true) || '' === $template) return 'missing';

        $class = apply_filters('mailoptin_register_optin_class', '\\MailOptin\\Core\\OptinForms\\' . ucfirst($type) . '\\' . $template, 0, $template, ucfirst($type));
        if (class_exists($class)) return 'ok';

        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM') && in_array($template, wp_list_pluck(OptinThemesRepository::premium_themes(), 'optin_class'), true)) {
            return 'premium';
        }

        return 'missing';
    }

    /**
     * What happens to one campaign: its settings as they'll be saved, and what to tell the site owner.
     *
     * @param array $taken names already used, including ones this import is about to use
     */
    public static function plan(array $c, array $export, array $taken)
    {
        $settings = $c['settings'];
        $issues   = [];
        $creates  = ['tags' => [], 'fields' => []];

        $template = self::template_status($c['type'], $c['template']);
        if ('missing' === $template) {
            return ['status' => 'skip', 'issues' => [__("Its template isn't available in this version of MailOptin, so it can't be imported.", 'mailoptin')], 'settings' => [], 'name' => $c['name'], 'creates' => $creates];
        }
        if ('premium' === $template) {
            $issues[] = __('Uses a Premium template. It comes in now and shows in your list once MailOptin Premium is active.', 'mailoptin');
        }

        // Posts and products.
        $missing_posts = $missing_products = [];
        foreach (References::post_settings() as $key) {
            if ( ! array_key_exists($key, $settings)) continue;
            $mapped = [];
            foreach (References::ids($settings[$key]) as $old) {
                $ref = $export['posts'][$old] ?? null;
                $new = is_array($ref) ? References::find_post($ref) : 0;
                if ($new) {
                    $mapped[] = $new;
                } else {
                    $title = is_array($ref) ? sanitize_text_field((string)($ref['title'] ?? '')) : '';
                    if ('product' === ($ref['type'] ?? '')) $missing_products[] = $title ?: '#' . $old; else $missing_posts[] = $title ?: '#' . $old;
                }
            }
            $settings[$key] = References::reshape($settings[$key], $mapped);
        }
        if ($missing_posts) {
            $issues[] = sprintf(__("These pages aren't on this site, so they're left out of where it shows: %s.", 'mailoptin'), self::quote_list($missing_posts));
        }
        if ($missing_products) {
            $issues[] = sprintf(__('No product here has the same SKU as these, so they\'re left out: %s.', 'mailoptin'), self::quote_list($missing_products));
        }

        // Categories and tags.
        $missing_terms = [];
        foreach (References::term_settings() as $key => $taxonomy) {
            if ( ! array_key_exists($key, $settings)) continue;
            $mapped = [];
            foreach (References::ids($settings[$key]) as $old) {
                $ref = $export['terms'][$taxonomy . ':' . $old] ?? null;
                $new = is_array($ref) ? References::find_term($ref) : 0;
                if ($new) $mapped[] = $new; else $missing_terms[] = is_array($ref) ? sanitize_text_field((string)($ref['name'] ?? '')) : '#' . $old;
            }
            $settings[$key] = References::reshape($settings[$key], $mapped);
        }
        if ($missing_terms) {
            $issues[] = sprintf(__("These categories or tags aren't on this site, so they're left out of where it shows: %s.", 'mailoptin'), self::quote_list($missing_terms));
        }

        // Coupons.
        foreach (References::coupon_settings() as $key) {
            $code = isset($settings[$key]) && is_scalar($settings[$key]) ? trim((string)$settings[$key]) : '';
            if ('' === $code) continue;
            $exists = function_exists('wc_get_coupon_id_by_code') && wc_get_coupon_id_by_code($code);
            if ( ! $exists) {
                $issues[] = sprintf(__("The coupon %s isn't on this site yet. Create it in WooCommerce, or the offer has no discount.", 'mailoptin'), $code);
            }
        }

        // Integrations: services, MailOptin tags and custom fields.
        $integrations = References::integrations($settings);
        if ($integrations) {
            $tag_names = TagsRepository::is_ready() ? array_map('strtolower', TagsRepository::names()) : [];
            foreach ($integrations as $i => $integration) {
                $service = sanitize_key((string)($integration['connection_service'] ?? ''));
                if ('' !== $service && ! self::is_connected($integration['connection_service'])) {
                    $issues[] = sprintf(
                        __("Sends subscribers to %s, which isn't connected here. Until you connect it, subscribers are saved to MailOptin only.", 'mailoptin'),
                        self::service_label($integration['connection_service'])
                    );
                }

                if (isset($integration['MailOptinConnect_lead_tags'])) {
                    $names = [];
                    foreach (References::ids($integration['MailOptinConnect_lead_tags']) as $old) {
                        if (isset($export['tags'][$old])) $names[] = $export['tags'][$old];
                    }
                    // Tag names travel in place of IDs; run() turns them back into this site's IDs.
                    $integrations[$i]['MailOptinConnect_lead_tags'] = $names;
                    foreach ($names as $name) {
                        if ( ! in_array(strtolower($name), $tag_names, true)) $creates['tags'][$name] = true;
                    }
                }

                $mappings = References::field_mappings($integration);
                foreach (array_keys($mappings) as $key) {
                    if (CustomFieldsRepository::get_by_key($key)) continue;
                    $def = $export['fields'][$key] ?? null;
                    if (is_array($def) && ! empty($def['label']) && isset(CustomFieldsRepository::types()[$def['type'] ?? ''])) {
                        $creates['fields'][$key] = $def;
                    } else {
                        unset($mappings[$key]);
                        $issues[] = sprintf(__("Saves to the custom field %s, which isn't on this site, so that field isn't saved.", 'mailoptin'), $key);
                    }
                }
                if (isset($integration['custom_field_mappings'])) {
                    $integrations[$i]['custom_field_mappings'] = is_string($integration['custom_field_mappings']) ? wp_json_encode($mappings) : $mappings;
                }
            }
            $settings['integrations'] = is_string($c['settings']['integrations']) ? wp_json_encode($integrations) : $integrations;
        }

        if ($creates['tags']) {
            $issues[] = sprintf(_n('Creates the tag %s.', 'Creates the tags %s.', count($creates['tags']), 'mailoptin'), self::quote_list(array_keys($creates['tags'])));
        }
        foreach ($creates['fields'] as $def) {
            $issues[] = sprintf(__('Creates the custom field %s.', 'mailoptin'), '“' . sanitize_text_field((string)$def['label']) . '”');
        }

        // A name that's already used gets a number.
        $name = $c['name'];
        if (in_array(strtolower($name), $taken, true)) {
            $n = 2;
            while (in_array(strtolower($c['name'] . " ($n)"), $taken, true)) $n++;
            $name     = $c['name'] . " ($n)";
            $issues[] = sprintf(__('A campaign called %1$s already exists. This one comes in as %2$s.', 'mailoptin'), '“' . $c['name'] . '”', '“' . $name . '”');
        }

        $settings['activate_optin'] = false;

        return [
            'status'   => 'premium' === $template ? 'premium' : (empty($issues) ? 'ready' : 'check'),
            'issues'   => $issues,
            'settings' => $settings,
            'name'     => $name,
            'creates'  => $creates,
        ];
    }

    /**
     * The review screen's rows, parents first with their variants after.
     */
    public static function review(array $export)
    {
        $taken = self::existing_names();
        $rows  = [];

        foreach (self::ordered($export) as $c) {
            $plan    = self::plan($c, $export, $taken);
            $taken[] = strtolower($plan['name']);
            $rows[]  = [
                'id'         => $c['id'],
                'name'       => $c['name'],
                'type'       => $c['type'],
                'template'   => $c['template'],
                'variant_of' => $c['variant_of'],
                'status'     => $plan['status'],
                'issues'     => $plan['issues'],
            ];
        }

        return $rows;
    }

    /**
     * Add the chosen campaigns as drafts.
     *
     * @param int[] $selected export IDs of the campaigns to import; variants follow their parent
     *
     * @return array ['imported' => [new ID => name], 'skipped' => [names]]
     */
    public static function run(array $export, array $selected, $copy_images)
    {
        $selected = array_map('intval', $selected);
        $taken    = self::existing_names();
        $new_ids  = [];
        $result   = ['imported' => [], 'skipped' => []];
        $tag_ids  = [];
        $images   = [];

        foreach (self::ordered($export) as $c) {
            $wanted = $c['variant_of'] ? in_array($c['variant_of'], $selected, true) : in_array($c['id'], $selected, true);
            if ( ! $wanted || ($c['variant_of'] && ! isset($new_ids[$c['variant_of']]))) continue;

            $plan = self::plan($c, $export, $taken);
            if ('skip' === $plan['status']) {
                $result['skipped'][] = $c['name'];
                continue;
            }

            $settings = $plan['settings'];

            foreach ($plan['creates']['fields'] as $key => $def) {
                if ( ! CustomFieldsRepository::get_by_key($key)) {
                    CustomFieldsRepository::create(['label' => $def['label'], 'type' => $def['type'], 'merge_key' => $key, 'options' => $def['options'] ?? []]);
                }
            }

            // Tag names back to this site's IDs, creating any that are missing.
            $integrations = References::integrations($settings);
            if ($integrations && TagsRepository::is_ready()) {
                foreach ($integrations as $i => $integration) {
                    if ( ! isset($integration['MailOptinConnect_lead_tags'])) continue;
                    $ids = [];
                    foreach ((array)$integration['MailOptinConnect_lead_tags'] as $name) {
                        $name = (string)$name;
                        if ( ! isset($tag_ids[$name])) $tag_ids[$name] = (int)(TagsRepository::ensure([$name])[0] ?? 0);
                        if ($tag_ids[$name]) $ids[] = $tag_ids[$name];
                    }
                    $integrations[$i]['MailOptinConnect_lead_tags'] = $ids;
                }
                $settings['integrations'] = is_string($settings['integrations']) ? wp_json_encode($integrations) : $integrations;
            }

            if ($copy_images) {
                foreach ($c['images'] as $url) {
                    if ( ! array_key_exists($url, $images)) $images[$url] = self::copy_image($url);
                    if ($images[$url]) $settings = self::replace_in($settings, $url, $images[$url]);
                }
            }

            $uuid = AjaxHandler::generateUniqueId();
            if ('' !== $c['uuid']) $settings = self::replace_in($settings, '#' . $c['uuid'], '#' . $uuid);

            $id = OCR::add_optin_campaign($uuid, $plan['name'], $c['template'], $c['type']);
            if ( ! $id) {
                $result['skipped'][] = $c['name'];
                continue;
            }

            OCR::save_settings_by_id($id, $settings);
            OptinCampaignMeta::add_campaign_meta($id, 'imported_from', $export['site'] ?: '-');
            if ($c['variant_of']) OptinCampaignMeta::add_campaign_meta($id, 'split_test_parent', $new_ids[$c['variant_of']]);

            $new_ids[$c['id']]          = $id;
            $taken[]                    = strtolower($plan['name']);
            $result['imported'][$id]    = $plan['name'];
        }

        do_action('mailoptin_optin_campaigns_imported', $result['imported'], $export);

        return $result;
    }

    /** Parents before their variants, otherwise in file order. */
    protected static function ordered(array $export)
    {
        $out = [];
        foreach ($export['campaigns'] as $c) {
            if ($c['variant_of']) continue;
            $out[] = $c;
            foreach ($export['campaigns'] as $v) {
                if ($v['variant_of'] === $c['id']) $out[] = $v;
            }
        }

        return $out;
    }

    protected static function existing_names()
    {
        global $wpdb;
        $names = $wpdb->get_col('SELECT name FROM ' . $wpdb->prefix . \MailOptin\Core\Core::optin_campaigns_table_name);

        return array_map('strtolower', (array)$names);
    }

    protected static function is_connected($service)
    {
        $class = '\\MailOptin\\' . preg_replace('/[^A-Za-z0-9_]/', '', (string)$service) . '\\Connect';

        return class_exists($class) && method_exists($class, 'is_connected') && $class::is_connected();
    }

    protected static function service_label($service)
    {
        $service = preg_replace('/[^A-Za-z0-9_]/', '', (string)$service);
        $labels  = apply_filters('mailoptin_registered_connections', []);
        if ( ! empty($labels[$service]) && is_string($labels[$service])) return wp_strip_all_tags($labels[$service]);

        return preg_replace('/Connect$/', '', $service) ?: __('an email service', 'mailoptin');
    }

    protected static function quote_list(array $items)
    {
        $items = array_values(array_unique(array_filter(array_map('strval', $items), 'strlen')));
        $shown = array_slice($items, 0, 5);
        $text  = implode(', ', array_map(function ($i) { return '“' . $i . '”'; }, $shown));

        return count($items) > 5 ? sprintf(__('%1$s and %2$d more', 'mailoptin'), $text, count($items) - 5) : $text;
    }

    /** Replace a string everywhere in the settings, JSON-encoded values included. */
    protected static function replace_in($settings, $from, $to)
    {
        // In JSON text the slashes in a URL are escaped, so replace that form too.
        $search  = [$from, str_replace('/', '\/', $from)];
        $replace = [$to, str_replace('/', '\/', $to)];

        array_walk_recursive($settings, function (&$value) use ($search, $replace) {
            if (is_string($value)) $value = str_replace($search, $replace, $value);
        });

        return $settings;
    }

    /**
     * Copy an image into the Media Library. WordPress's downloader refuses local and private addresses.
     *
     * @return string|false the new URL
     */
    protected static function copy_image($url)
    {
        if ( ! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $tmp = download_url($url, 20);
        if (is_wp_error($tmp)) return false;

        $file = ['name' => wp_basename(wp_parse_url($url, PHP_URL_PATH)), 'tmp_name' => $tmp];
        $id   = media_handle_sideload($file, 0);
        if (is_wp_error($id)) {
            @unlink($tmp);

            return false;
        }

        return wp_get_attachment_url($id) ?: false;
    }
}
