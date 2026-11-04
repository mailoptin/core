<?php

namespace MailOptin\Core\Tests\Design;

use MailOptin\Core\Admin\SettingsPage\Settings;

class SettingsDesignTest extends \WP_UnitTestCase
{
    public function test_tabs_are_grouped_in_menu_order_and_unknown_tabs_are_kept()
    {
        $groups = Settings::group_tabs(['general_settings', 'optin_campaign_settings', 'email_campaign_settings', 'leadbank_settings', 'sending_settings', 'recaptcha_settings', 'some_addon_settings']);

        $this->assertSame([
            'General'         => ['general_settings'],
            'Sending'         => ['sending_settings', 'email_campaign_settings'],
            'Forms'           => ['optin_campaign_settings', 'leadbank_settings'],
            'Spam protection' => ['recaptcha_settings'],
            'More'            => ['some_addon_settings'],
        ], $groups);
    }

    public function test_empty_groups_are_dropped_and_a_tab_is_listed_once()
    {
        add_filter('mailoptin_settings_tab_groups', function ($groups) {
            $groups['Extra'] = ['general_settings', 'x_settings'];

            return $groups;
        });

        $groups = Settings::group_tabs(['general_settings', 'x_settings']);

        $this->assertSame(['General' => ['general_settings'], 'Extra' => ['x_settings']], $groups);
    }

    public function test_page_renders_grouped_menu_search_and_save_bar()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        set_current_screen('dashboard');

        $settings = Settings::get_instance();
        do_action('mailoptin_admin_settings_page_pre', 'general');

        ob_start();
        $settings->settings_admin_page_callback();
        $html = ob_get_clean();

        $this->assertStringContainsString('mailoptin-settings-wrap mo-settings-v2', $html);
        $this->assertStringContainsString('<span class="mo-settings-nav-group">General</span><a href="#general_settings"', $html);
        $this->assertStringContainsString('id="mo-settings-search"', $html);
        // The search box only appears with JavaScript, which unhides it.
        $this->assertMatchesRegularExpression('#id="mo-settings-search"[^>]*hidden#', $html);
        $this->assertStringContainsString('<div class="mo-settings-savebar" hidden>', $html);
        $this->assertStringContainsString('name="save_' . MAILOPTIN_SETTINGS_DB_OPTION_NAME . '"', $html);
        // Every registered tab still has a nav link the existing tab script can open.
        preg_match_all('#<div id="([a-z_]+)" class="mailoptin-group-wrapper">#', $html, $panels);
        foreach ($panels[1] as $key) {
            $this->assertStringContainsString('id="' . $key . '-tab"', $html);
        }
        $this->assertNotEmpty($panels[1]);
    }
}
