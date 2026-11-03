<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Repositories\CampaignSettingsRepository as Settings;

/**
 * Keeps get_option() and update_option() on mo_optin_campaign and mo_email_campaigns working after the
 * move to the campaign settings table, for code that still uses them directly: custom snippets and
 * add-ons written before. Reading builds the old array from the table; writing saves the campaigns
 * that changed to the table, and nothing is stored in the option row.
 *
 * Reading every campaign at once is what the table is meant to avoid, so with WP_DEBUG on each such
 * call is reported; use OptinCampaignsRepository / EmailCampaignRepository instead.
 */
class CampaignSettingsCompat
{
    public static function init()
    {
        add_action('admin_init', [Settings::class, 'maybe_retry']);
        add_action('admin_notices', [__CLASS__, 'migration_notice']);

        foreach ([MO_OPTIN_CAMPAIGN_WP_OPTION_NAME, MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME] as $name) {
            add_filter('pre_option_' . $name, [__CLASS__, 'read'], 10, 2);
            add_filter('pre_update_option_' . $name, [__CLASS__, 'write'], 10, 3);
        }
    }

    public static function read($pre, $option)
    {
        if (false !== $pre || ! Settings::is_ready()) return $pre;

        self::notice($option, 'get_option');

        // get_option() skips option_{$option} filters for a value from pre_option: run them here, so
        // they (and the Customizer's preview of settings still registered as options) keep working.
        return apply_filters("option_{$option}", Settings::all(Settings::type_for_option($option)), $option);
    }

    /**
     * update_option() returns false afterwards, as WordPress stores nothing: the campaigns that changed
     * are saved to the table regardless. delete_option() on these names does nothing once moved.
     *
     * @return mixed the old value, so WordPress sees nothing to store
     */
    public static function write($value, $old_value, $option)
    {
        if ( ! Settings::is_ready()) return $value;

        self::notice($option, 'update_option');

        Settings::save_many(Settings::type_for_option($option), $value);

        return $old_value;
    }

    /**
     * The move to the campaign settings table failed: the site still works on the old rows.
     */
    public static function migration_notice()
    {
        if ( ! current_user_can('manage_options') || Settings::is_ready()) return;

        $error = (string)get_option(Settings::ERROR_OPTION);

        if ('' === $error) return;

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s %s</p></div>',
            esc_html__('MailOptin couldn\'t move your campaign settings to their new table yet.', 'mailoptin'),
            esc_html($error),
            esc_html__('Your forms and emails keep working as before, and MailOptin tries again every hour.', 'mailoptin')
        );
    }

    protected static function notice($option, $function)
    {
        if ( ! defined('WP_DEBUG') || ! WP_DEBUG || ! function_exists('_doing_it_wrong')) return;

        _doing_it_wrong($function . "('" . esc_html($option) . "')", sprintf(
            'MailOptin keeps campaign settings in their own table now. Use %s instead.',
            MO_OPTIN_CAMPAIGN_WP_OPTION_NAME === $option ? 'OptinCampaignsRepository::get_settings_by_id() / CampaignSettingsRepository' : 'EmailCampaignRepository::get_settings_by_id() / CampaignSettingsRepository'
        ), '1.2.79');
    }
}
