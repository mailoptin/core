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

    /** Held while one request moves the settings, so two never do it at once. */
    const LOCK_OPTION = 'mo_campaign_settings_migrating';

    /** Part of every cache key: changed when the table is emptied or filled, so no cache serves old rows. */
    const SALT_OPTION = 'mo_campaign_settings_cache_salt';

    const LEGACY_SUFFIX = '_legacy';

    // MD5 of each option row as it was copied into the table, to notice it changing afterwards.
    const SOURCE_HASH_OPTION = 'mo_campaign_settings_source_hash';

    const CACHE_GROUP = 'mo_campaign_settings';

    /** Every campaign of a type, kept for the request only. */
    const ALL_CACHE_GROUP = 'mo_campaign_settings_all';

    /** @var array blog id => type => campaign id => [setting => value] being previewed in the Customizer */
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

    /* ---------------------------------------------------------------------------------------------
     * Caching
     * ------------------------------------------------------------------------------------------ */

    protected static function cache_key($type, $campaign_id)
    {
        return self::salt() . ':' . $type . ':' . $campaign_id;
    }

    protected static function salt()
    {
        $salt = get_option(self::SALT_OPTION);

        if ( ! $salt) {
            $salt = wp_generate_password(8, false);
            update_option(self::SALT_OPTION, $salt, true);
        }

        return $salt;
    }

    /**
     * Forget every cached campaign: a new salt, so even an object cache that can't flush a group
     * stops serving the old rows.
     */
    public static function flush_cache()
    {
        self::$preview[get_current_blog_id()] = [];
        update_option(self::SALT_OPTION, wp_generate_password(8, false), true);
    }

    protected static function forget_all($type)
    {
        wp_cache_delete(self::salt() . ':' . $type, self::ALL_CACHE_GROUP);
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
        $preview     = self::$preview[get_current_blog_id()][$type][$campaign_id] ?? [];

        return $preview ? array_replace($settings, $preview) : $settings;
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
        $key    = self::cache_key($type, $campaign_id);
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
            if ($id && ! is_array(wp_cache_get(self::cache_key($type, $id), self::CACHE_GROUP))) $missing[] = $id;
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
            wp_cache_set(self::cache_key($type, $id), $found[$id] ?? [], self::CACHE_GROUP);
        }
    }

    /**
     * Every campaign of a type: campaign id => settings, with any preview applied. For screens and
     * checks that need them all; showing or sending one campaign should use get().
     *
     * @return array
     */
    public static function all($type)
    {
        $all = self::all_saved($type);

        foreach (self::$preview[get_current_blog_id()][$type] ?? [] as $id => $values) {
            $all[$id] = array_replace(is_array($all[$id] ?? null) ? $all[$id] : [], $values);
        }

        return $all;
    }

    protected static function all_saved($type)
    {
        if ( ! self::is_ready()) return self::legacy_all($type);

        // Every campaign is too much to keep in a persistent cache: this request only.
        wp_cache_add_non_persistent_groups([self::ALL_CACHE_GROUP]);

        $key    = self::salt() . ':' . $type;
        $cached = wp_cache_get($key, self::ALL_CACHE_GROUP);

        if (is_array($cached)) return $cached;

        $rows = self::wpdb()->get_results(self::wpdb()->prepare(
            'SELECT campaign_id, settings FROM ' . self::table() . ' WHERE campaign_type = %s ORDER BY campaign_id',
            $type
        ), ARRAY_A);

        $all = [];
        foreach ($rows as $row) {
            $all[(int)$row['campaign_id']] = self::decode($row['settings']);
        }

        wp_cache_set($key, $all, self::ALL_CACHE_GROUP);

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
     *
     * @param array $settings
     */
    public static function save($type, $campaign_id, $settings)
    {
        $campaign_id = absint($campaign_id);
        $settings    = self::to_array($settings);

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

        self::forget_all($type);

        // A failed write must not leave the cache saying it worked.
        if ($saved) {
            wp_cache_set(self::cache_key($type, $campaign_id), $settings, self::CACHE_GROUP);
            do_action('mo_campaign_settings_saved', $type, $campaign_id, $settings);
        } else {
            wp_cache_delete(self::cache_key($type, $campaign_id), self::CACHE_GROUP);
        }

        return $saved;
    }

    /**
     * Change some settings of one campaign, keeping the rest.
     *
     * @param array $changes setting => value
     */
    public static function update($type, $campaign_id, $changes)
    {
        return self::save($type, $campaign_id, array_replace(self::get_saved($type, $campaign_id), self::to_array($changes)));
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
            if ( ! absint($id) || ! (is_array($settings) || is_object($settings))) continue;

            $settings = self::to_array($settings);

            // Strict: "1" and true, or 0 and "abc", are different values to save.
            if ( ! isset($current[$id]) || $current[$id] !== $settings) self::save($type, $id, $settings);
        }

        return true;
    }

    /**
     * Remove every campaign of a type. For uninstall and tests.
     */
    public static function delete_all($type)
    {
        if ( ! self::is_ready()) return delete_option(self::option_name($type));

        self::$preview[get_current_blog_id()][$type] = [];
        $deleted = false !== self::wpdb()->delete(self::table(), ['campaign_type' => $type]);
        self::flush_cache();

        return $deleted;
    }

    public static function delete($type, $campaign_id)
    {
        $campaign_id = absint($campaign_id);

        if ( ! self::is_ready()) {
            $all = self::legacy_all($type);
            unset($all[$campaign_id]);

            return update_option(self::option_name($type), $all, false);
        }

        unset(self::$preview[get_current_blog_id()][$type][$campaign_id]);

        $deleted = false !== self::wpdb()->delete(self::table(), ['campaign_type' => $type, 'campaign_id' => $campaign_id]);

        wp_cache_delete(self::cache_key($type, $campaign_id), self::CACHE_GROUP);
        self::forget_all($type);

        return $deleted;
    }

    /* ---------------------------------------------------------------------------------------------
     * Customizer preview
     * ------------------------------------------------------------------------------------------ */

    /**
     * Show an unsaved value while the Customizer previews it.
     */
    public static function set_preview($type, $campaign_id, $setting, $value)
    {
        self::$preview[get_current_blog_id()][$type][absint($campaign_id)][$setting] = $value;
    }

    public static function clear_preview()
    {
        self::$preview = [];
    }

    /* ---------------------------------------------------------------------------------------------
     * Encoding
     * ------------------------------------------------------------------------------------------ */

    /**
     * Settings as arrays all the way down: objects from old option data or other plugins become arrays.
     */
    public static function to_array($value)
    {
        if (is_object($value)) $value = get_object_vars($value);

        if ( ! is_array($value)) return [];

        foreach ($value as $key => $item) {
            if (is_object($item) || is_array($item)) $value[$key] = self::to_array($item);
        }

        return $value;
    }

    public static function encode($settings)
    {
        $settings = self::to_array($settings);
        $json     = wp_json_encode($settings);

        // Text that isn't valid UTF-8 even after WordPress's conversion, or a value JSON can't hold
        // (INF, NAN): keep the rest, replace the bad parts.
        if (false === $json) $json = json_encode($settings, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

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
     * The option row as stored, past every cache: another request may have just changed it.
     */
    protected static function legacy_fresh($type, &$raw = null)
    {
        $wpdb = self::wpdb();
        $name = self::option_name($type);

        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');

        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", $name));
        $all = null === $raw ? [] : maybe_unserialize($raw);

        return is_array($all) || is_object($all) ? (array)$all : [];
    }

    /**
     * MD5 of an option row as stored, worked out by the database so a big row isn't loaded.
     */
    protected static function source_hash($type)
    {
        $wpdb = self::wpdb();

        return (string)$wpdb->get_var($wpdb->prepare("SELECT MD5(option_value) FROM $wpdb->options WHERE option_name = %s LIMIT 1", self::option_name($type)));
    }

    /**
     * What each campaign must read back as from the table: its settings through JSON and back, so
     * objects and text WordPress converted to UTF-8 compare equal.
     *
     * @return array campaign id => settings
     */
    protected static function expected($all)
    {
        $expected = [];

        foreach ($all as $id => $settings) {
            if ( ! absint($id) || ! (is_array($settings) || is_object($settings))) continue;

            $expected[(int)$id] = self::decode(self::encode($settings));
        }

        ksort($expected);

        return $expected;
    }

    /**
     * Copy every campaign from both option rows into the table, check each one reads back the same,
     * switch the site over, then keep the rows as *_legacy backups. Safe to run again and from
     * several requests at once: one holds a lock, and a copy that doesn't check out leaves the site
     * on the option rows, with the reason in ERROR_OPTION, for maybe_retry() to try again later.
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

        // One request at a time; a lock older than 10 minutes belongs to a request that died. Taking
        // it over is one UPDATE, so two requests can't both take a stale lock.
        if ( ! add_option(self::LOCK_OPTION, time(), '', 'no')) {
            $taken = $wpdb->query($wpdb->prepare(
                "UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
                time(), self::LOCK_OPTION, time() - 10 * MINUTE_IN_SECONDS
            ));

            wp_cache_delete(self::LOCK_OPTION, 'options');

            if (1 !== (int)$taken) {
                // Another request is moving them. If it dies, the hourly retry takes over once its lock is stale.
                if ( ! get_option(self::ERROR_OPTION)) update_option(self::ERROR_OPTION, 'Moving the campaign settings didn\'t finish. Trying again shortly.', false);

                return false;
            }
        }

        try {
            wp_cache_delete(self::READY_OPTION, 'options');
            wp_cache_delete('alloptions', 'options');
            if (self::is_ready()) return true;

            // The table isn't used until the switch, so rows from an earlier failed attempt can go.
            $wpdb->query('DELETE FROM ' . self::table());

            $copied = $hashes = [];

            // Until the switch, a request that dies leaves a reason for the hourly retry to act on.
            update_option(self::ERROR_OPTION, 'Moving the campaign settings didn\'t finish. Trying again shortly.', false);

            foreach ([self::TYPE_OPTIN, self::TYPE_EMAIL] as $type) {
                $raw    = null;
                $legacy = self::legacy_fresh($type, $raw);

                $hashes[$type] = null === $raw ? '' : md5($raw);

                foreach ($legacy as $id => $settings) {
                    if ( ! absint($id) || ! (is_array($settings) || is_object($settings))) continue;

                    // One row at a time: custom HTML and CSS can make rows too big to batch.
                    $inserted = $wpdb->query($wpdb->prepare(
                        'INSERT INTO ' . self::table() . ' (campaign_type, campaign_id, settings, updated_at) VALUES (%s, %d, %s, %s)
                        ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_at = VALUES(updated_at)',
                        $type,
                        $id,
                        self::encode($settings),
                        gmdate('Y-m-d H:i:s')
                    ));

                    if (false === $inserted) {
                        update_option(self::ERROR_OPTION, sprintf('Copying the settings of %s campaign %d failed: %s', $type, $id, $wpdb->last_error), false);

                        return false;
                    }
                }

                $copied[$type] = $legacy;
            }

            // Every campaign must read back as it was, and nothing may have changed meanwhile.
            foreach ([self::TYPE_OPTIN, self::TYPE_EMAIL] as $type) {
                $in_table = [];
                foreach ($wpdb->get_results($wpdb->prepare('SELECT campaign_id, settings FROM ' . self::table() . ' WHERE campaign_type = %s', $type), ARRAY_A) as $row) {
                    $in_table[(int)$row['campaign_id']] = self::decode($row['settings']);
                }

                $expected = self::expected($copied[$type]);

                foreach ($expected as $id => $settings) {
                    if ( ! array_key_exists($id, $in_table) || $in_table[$id] !== $settings) {
                        update_option(self::ERROR_OPTION, sprintf('The settings of %s campaign %d did not copy across unchanged.', $type, $id), false);

                        return false;
                    }
                }

                if (self::expected(self::legacy_fresh($type)) !== $expected) {
                    update_option(self::ERROR_OPTION, 'Campaign settings changed while they were being moved. Trying again shortly.', false);

                    return false;
                }
            }

            // What was copied, so a later change to the rows (an older MailOptin writing them after a
            // downgrade, or a save that raced the move) can be noticed and brought across.
            update_option(self::SOURCE_HASH_OPTION, $hashes, false);

            // A copy of what was moved: sync_changed_rows() compares the rows with it.
            foreach ([self::TYPE_OPTIN, self::TYPE_EMAIL] as $type) {
                update_option(self::option_name($type) . self::LEGACY_SUFFIX, $copied[$type], false);
            }

            // Switch: from here every read and write uses the table. The option rows stay as they are,
            // so going back to an older MailOptin still finds every form and email.
            update_option(self::READY_OPTION, time(), true);
            delete_option(self::ERROR_OPTION);
            self::flush_cache();

            return true;
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    /**
     * A move that failed is tried again, at most once an hour, on an admin page. Once moved, the option
     * rows are checked as often for changes made after the move.
     */
    public static function maybe_retry()
    {
        if (get_transient('mo_campaign_settings_retry')) return;

        if (self::is_ready()) {
            set_transient('mo_campaign_settings_retry', 1, HOUR_IN_SECONDS);
            self::sync_changed_rows();

            return;
        }

        if ( ! get_option(self::ERROR_OPTION)) return;

        set_transient('mo_campaign_settings_retry', 1, HOUR_IN_SECONDS);

        self::migrate();
    }

    /**
     * Bring across campaigns whose option row entry changed after the move: edits made while an older
     * MailOptin ran (it reads and writes the rows), or a save that raced the move. Campaigns that didn't
     * change there keep what the table has.
     *
     * @return int campaigns brought across
     */
    public static function sync_changed_rows()
    {
        if ( ! self::is_ready()) return 0;

        $hashes  = (array)get_option(self::SOURCE_HASH_OPTION, []);
        $changed = 0;

        foreach ([self::TYPE_OPTIN, self::TYPE_EMAIL] as $type) {
            $now = self::source_hash($type);

            if ( ! isset($hashes[$type]) || $now === (string)$hashes[$type]) continue;

            $raw     = null;
            $current = self::expected(self::legacy_fresh($type, $raw));
            $before  = self::expected((array)get_option(self::option_name($type) . self::LEGACY_SUFFIX, []));

            foreach ($current as $id => $settings) {
                if (array_key_exists($id, $before) && $before[$id] === $settings) continue;

                self::save($type, $id, $settings);
                $changed++;
            }

            // Campaigns deleted from the row while it was in use were deleted from the plugin too.
            foreach (array_diff(array_keys($before), array_keys($current)) as $id) {
                self::delete($type, $id);
                $changed++;
            }

            update_option(self::option_name($type) . self::LEGACY_SUFFIX, null === $raw ? [] : maybe_unserialize($raw), false);
            $hashes[$type] = $now;
        }

        update_option(self::SOURCE_HASH_OPTION, $hashes, false);

        return $changed;
    }
}
