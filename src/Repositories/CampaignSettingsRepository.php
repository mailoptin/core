<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;

/**
 * The Customizer settings of optin campaigns and email campaigns (broadcasts, post notifications and
 * digests): one row per campaign, its settings as JSON.
 *
 * They used to live in two option rows, mo_optin_campaign and mo_email_campaigns, holding every
 * campaign at once, so loading one form read all of them and saving one campaign rewrote them all.
 * Until migrate() has moved a site's settings here, everything still reads and writes those rows.
 * After it, get_option() and update_option() on them keep working through CampaignSettingsCompat.
 */
class CampaignSettingsRepository extends AbstractRepository
{
    const TYPE_OPTIN = 'optin';
    const TYPE_EMAIL = 'email';

    /** Set once a site's settings are in the table. */
    const READY_OPTION = 'mo_campaign_settings_ready';

    /** Set when moving them failed, with why; the site keeps the option rows meanwhile. */
    const ERROR_OPTION = 'mo_campaign_settings_migration_error';

    const LEGACY_SUFFIX = '_legacy';

    const CACHE_GROUP = 'mo_campaign_settings';

    /** @var array type => campaign id => [setting => value] being previewed in the Customizer */
    protected static $preview = [];

    public static function table()
    {
        return self::wpdb()->prefix . Core::campaign_settings_table_name;
    }

    public static function option_name($type)
    {
        return self::TYPE_OPTIN === $type ? MO_OPTIN_CAMPAIGN_WP_OPTION_NAME : MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME;
    }

    public static function type_for_option($option_name)
    {
        if (MO_OPTIN_CAMPAIGN_WP_OPTION_NAME === $option_name) return self::TYPE_OPTIN;
        if (MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME === $option_name) return self::TYPE_EMAIL;

        return null;
    }

    public static function is_ready()
    {
        return (bool)get_option(self::READY_OPTION);
    }

    public static function flush_cache()
    {
        self::$preview = [];
        if (function_exists('wp_cache_flush_group')) wp_cache_flush_group(self::CACHE_GROUP);
    }

    /* ---------------------------------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------------------------------ */

    /**
     * One campaign's settings, with any Customizer preview applied.
     *
     * @return array
     */
    public static function get($type, $campaign_id)
    {
        $campaign_id = absint($campaign_id);
        $settings    = self::get_saved($type, $campaign_id);

        if ( ! empty(self::$preview[$type][$campaign_id])) {
            $settings = array_replace($settings, self::$preview[$type][$campaign_id]);
        }

        return $settings;
    }

    /**
     * One campaign's saved settings, without the preview.
     */
    public static function get_saved($type, $campaign_id)
    {
        $campaign_id = absint($campaign_id);

        if ( ! self::is_ready()) {
            $all = self::legacy_all($type);

            return isset($all[$campaign_id]) && is_array($all[$campaign_id]) ? $all[$campaign_id] : [];
        }

        // The object cache: per request, or shared when the site has a persistent one.
        $key    = $type . ':' . $campaign_id;
        $cached = wp_cache_get($key, self::CACHE_GROUP);

        if (is_array($cached)) return $cached;

        $json = self::wpdb()->get_var(self::wpdb()->prepare(
            'SELECT settings FROM ' . self::table() . ' WHERE campaign_type = %s AND campaign_id = %d',
            $type,
            $campaign_id
        ));

        $settings = self::decode($json);

        wp_cache_set($key, $settings, self::CACHE_GROUP);

        return $settings;
    }

    /**
     * Load several campaigns in one query, for a page showing several forms.
     */
    public static function prime($type, $campaign_ids)
    {
        if ( ! self::is_ready()) return;

        $missing = [];

        foreach (array_unique(array_map('absint', (array)$campaign_ids)) as $id) {
            if ($id && ! is_array(wp_cache_get($type . ':' . $id, self::CACHE_GROUP))) $missing[] = $id;
        }

        if (empty($missing)) return;

        $rows = self::wpdb()->get_results(self::wpdb()->prepare(
            'SELECT campaign_id, settings FROM ' . self::table() . ' WHERE campaign_type = %s AND campaign_id IN (' . implode(',', $missing) . ')',
            $type
        ), ARRAY_A);

        $found = [];
        foreach ($rows as $row) {
            $found[(int)$row['campaign_id']] = self::decode($row['settings']);
        }

        foreach ($missing as $id) {
            wp_cache_set($type . ':' . $id, $found[$id] ?? [], self::CACHE_GROUP);
        }
    }

    /**
     * Every campaign of a type: campaign id => settings. For screens and checks that need them all;
     * showing or sending one campaign should use get().
     *
     * @return array
     */
    public static function all($type)
    {
        if ( ! self::is_ready()) {
            $all = self::legacy_all($type);
        } else {
            $rows = self::wpdb()->get_results(self::wpdb()->prepare(
                'SELECT campaign_id, settings FROM ' . self::table() . ' WHERE campaign_type = %s ORDER BY campaign_id',
                $type
            ), ARRAY_A);

            $all = [];
            foreach ($rows as $row) {
                $all[(int)$row['campaign_id']] = self::decode($row['settings']);
                wp_cache_set($type . ':' . $row['campaign_id'], $all[(int)$row['campaign_id']], self::CACHE_GROUP);
            }
        }

        foreach (self::$preview[$type] ?? [] as $id => $values) {
            $all[$id] = array_replace(is_array($all[$id] ?? null) ? $all[$id] : [], $values);
        }

        return $all;
    }

    /**
     * The option row, before the move.
     */
    protected static function legacy_all($type)
    {
        $all = get_option(self::option_name($type), []);

        return is_array($all) ? $all : [];
    }

    /* ---------------------------------------------------------------------------------------------
     * Writing
     * ------------------------------------------------------------------------------------------ */

    /**
     * Replace one campaign's settings.
     */
    public static function save($type, $campaign_id, array $settings)
    {
        $campaign_id = absint($campaign_id);

        if ( ! $campaign_id) return false;

        if ( ! self::is_ready()) {
            $all               = self::legacy_all($type);
            $all[$campaign_id] = $settings;

            return update_option(self::option_name($type), $all, false);
        }

        $saved = false !== self::wpdb()->query(self::wpdb()->prepare(
                'INSERT INTO ' . self::table() . ' (campaign_type, campaign_id, settings, updated_at) VALUES (%s, %d, %s, %s)
                ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_at = VALUES(updated_at)',
                $type,
                $campaign_id,
                self::encode($settings),
                gmdate('Y-m-d H:i:s')
            ));

        wp_cache_set($type . ':' . $campaign_id, $settings, self::CACHE_GROUP);

        do_action('mo_campaign_settings_saved', $type, $campaign_id, $settings);

        return $saved;
    }

    /**
     * Change some settings of one campaign, keeping the rest.
     *
     * @param array $changes setting => value
     */
    public static function update($type, $campaign_id, array $changes)
    {
        return self::save($type, $campaign_id, array_replace(self::get_saved($type, $campaign_id), $changes));
    }

    /**
     * Save every campaign in a full array whose settings differ from what's stored. Campaigns missing
     * from the array are left alone: this backs update_option() on the old rows, and a caller holding
     * a stale copy shouldn't delete a campaign created since.
     *
     * @param array $all campaign id => settings
     */
    public static function save_many($type, $all)
    {
        if ( ! is_array($all)) return false;

        if ( ! self::is_ready()) return update_option(self::option_name($type), $all, false);

        $current = self::all_saved($type);

        foreach ($all as $id => $settings) {
            if ( ! is_array($settings) || ! absint($id)) continue;

            if ( ! isset($current[$id]) || $current[$id] != $settings) self::save($type, $id, $settings);
        }

        return true;
    }

    /**
     * Remove every campaign of a type. For uninstall and tests.
     */
    public static function delete_all($type)
    {
        if ( ! self::is_ready()) return delete_option(self::option_name($type));

        self::$preview[$type] = [];
        self::flush_cache();

        return false !== self::wpdb()->delete(self::table(), ['campaign_type' => $type]);
    }

    public static function delete($type, $campaign_id)
    {
        $campaign_id = absint($campaign_id);

        if ( ! self::is_ready()) {
            $all = self::legacy_all($type);
            unset($all[$campaign_id]);

            return update_option(self::option_name($type), $all, false);
        }

        unset(self::$preview[$type][$campaign_id]);
        wp_cache_delete($type . ':' . $campaign_id, self::CACHE_GROUP);

        return false !== self::wpdb()->delete(self::table(), ['campaign_type' => $type, 'campaign_id' => $campaign_id]);
    }

    protected static function all_saved($type)
    {
        $preview = self::$preview[$type] ?? [];
        self::$preview[$type] = [];

        $all = self::all($type);

        self::$preview[$type] = $preview;

        return $all;
    }

    /* ---------------------------------------------------------------------------------------------
     * Customizer preview
     * ------------------------------------------------------------------------------------------ */

    /**
     * Show an unsaved value while the Customizer previews it.
     */
    public static function set_preview($type, $campaign_id, $setting, $value)
    {
        self::$preview[$type][absint($campaign_id)][$setting] = $value;
    }

    public static function clear_preview()
    {
        self::$preview = [];
    }

    /* ---------------------------------------------------------------------------------------------
     * Encoding
     * ------------------------------------------------------------------------------------------ */

    public static function encode(array $settings)
    {
        $json = wp_json_encode($settings);

        // Text that isn't valid UTF-8 even after WordPress's conversion: keep the rest, replace the bad bytes.
        if (false === $json) $json = json_encode($settings, JSON_INVALID_UTF8_SUBSTITUTE);

        return false === $json ? '{}' : $json;
    }

    public static function decode($json)
    {
        $settings = is_string($json) && '' !== $json ? json_decode($json, true) : [];

        return is_array($settings) ? $settings : [];
    }

    /* ---------------------------------------------------------------------------------------------
     * Moving a site's settings out of the option rows
     * ------------------------------------------------------------------------------------------ */

    /**
     * Copy every campaign from both option rows into the table, check each one reads back the same,
     * then keep the rows as *_legacy backups. Safe to run again: it stops once done, and a copy that
     * didn't check out leaves the site on the option rows, with the reason in ERROR_OPTION.
     *
     * @return bool whether the site now uses the table
     */
    public static function migrate()
    {
        if (self::is_ready()) return true;

        $wpdb = self::wpdb();

        if (self::table() !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::table())))) {
            update_option(self::ERROR_OPTION, 'The campaign settings table is missing.', false);

            return false;
        }

        $now = gmdate('Y-m-d H:i:s');

        foreach ([self::TYPE_OPTIN, self::TYPE_EMAIL] as $type) {
            $rows = [];

            foreach (self::legacy_all($type) as $id => $settings) {
                if ( ! absint($id) || ! is_array($settings)) continue;

                $rows[] = $wpdb->prepare('(%s, %d, %s, %s)', $type, $id, self::encode($settings), $now);
            }

            foreach (array_chunk($rows, 100) as $chunk) {
                $wpdb->query(
                    'INSERT INTO ' . self::table() . ' (campaign_type, campaign_id, settings, updated_at) VALUES ' . implode(',', $chunk) .
                    ' ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_at = VALUES(updated_at)'
                );
            }
        }

        // Every campaign must read back as it was before anything switches over.
        foreach ([self::TYPE_OPTIN, self::TYPE_EMAIL] as $type) {
            $copied = [];
            foreach ($wpdb->get_results($wpdb->prepare('SELECT campaign_id, settings FROM ' . self::table() . ' WHERE campaign_type = %s', $type), ARRAY_A) as $row) {
                $copied[(int)$row['campaign_id']] = self::decode($row['settings']);
            }

            foreach (self::legacy_all($type) as $id => $settings) {
                if ( ! absint($id) || ! is_array($settings)) continue;

                if ( ! isset($copied[(int)$id]) || $copied[(int)$id] != $settings) {
                    update_option(self::ERROR_OPTION, sprintf('The settings of %s campaign %d did not copy across unchanged.', $type, $id), false);

                    return false;
                }
            }
        }

        foreach ([self::TYPE_OPTIN, self::TYPE_EMAIL] as $type) {
            $name = self::option_name($type);
            $old  = get_option($name, null);

            if (null !== $old) {
                update_option($name . self::LEGACY_SUFFIX, $old, false);
                delete_option($name);
            }
        }

        delete_option(self::ERROR_OPTION);
        update_option(self::READY_OPTION, time(), true);
        self::flush_cache();

        return true;
    }
}
