<?php

namespace MailOptin\Tests\Core\OptinForms;

use MailOptin\Core\Admin\Customizer\OptinForm\OptinFormFactory;
use MailOptin\Core\Repositories\OptinCampaignsRepository as Repository;
use WP_UnitTestCase;

/**
 * "When the visitor goes idle" and the floating tab: what reaches the page's config. The behaviour in
 * the browser is in mailoptin.js, checked on a test site.
 */
class DisplayTriggersTest extends WP_UnitTestCase
{
    protected $made = [];

    public function set_up()
    {
        parent::set_up();
        if ( ! defined('MAILOPTIN_VERSION_NUMBER')) define('MAILOPTIN_VERSION_NUMBER', 'tests');
        add_filter('mailoptin_premium_display_rules', '__return_true');
        Repository::flush_saved_customizer_data_cache();
    }

    public function tear_down()
    {
        foreach ($this->made as $id) Repository::delete_optin_campaign($id);
        remove_filter('mailoptin_premium_display_rules', '__return_true');
        Repository::flush_saved_customizer_data_cache();
        parent::tear_down();
    }

    protected function config($type, $settings, $class = 'AnnouncementPopup')
    {
        $id           = Repository::add_optin_campaign(wp_generate_password(10, false), 'DT ' . wp_generate_password(6, false), $class, $type);
        $this->made[] = $id;

        $all      = Repository::get_settings();
        $all[$id] = array_merge($all[$id] ?? [], $settings);
        Repository::updateSettings($all);
        Repository::flush_saved_customizer_data_cache();

        // The config is printed as "var uuid = uuid_type = {json};".
        preg_match('/=\s*(\{.*\});\s*$/s', OptinFormFactory::make($id)->optin_js_config(), $m);

        return json_decode($m[1] ?? '{}', true);
    }

    public function test_idle_is_sent_with_its_seconds_kept_in_range()
    {
        $this->assertSame(30, $this->config('lightbox', ['idle_status' => true, 'idle_value' => 30])['idle_value']);
        $this->assertSame(5, $this->config('lightbox', ['idle_status' => true, 'idle_value' => 1])['idle_value']);
        $this->assertSame(600, $this->config('lightbox', ['idle_status' => true, 'idle_value' => 99999])['idle_value']);
        $this->assertArrayNotHasKey('idle_status', $this->config('lightbox', ['idle_status' => false, 'idle_value' => 30]));
        $this->assertArrayNotHasKey('idle_status', $this->config('lightbox', ['idle_status' => true, 'idle_value' => 0]));
        $this->assertSame(30, $this->config('lightbox', ['idle_status' => true])['idle_value'], 'An untouched slider: its 30 seconds.');
        $this->assertSame(30, $this->config('lightbox', ['idle_status' => true, 'idle_value' => ''])['idle_value']);
    }

    public function test_the_tab_is_sent_with_clean_values()
    {
        $tab = $this->config('lightbox', [
            'launcher_status'     => true,
            'launcher_text'       => '<b>Get</b> 10% off',
            'launcher_style'      => 'tab',
            'launcher_position'   => 'nowhere',
            'launcher_background' => 'red;position:fixed',
            'launcher_color'      => '#123456',
            'launcher_when'       => 'after_close',
        ])['launcher'];

        $this->assertSame('Get 10% off', $tab['text'], 'Text only.');
        $this->assertSame('tab', $tab['style']);
        $this->assertSame('bottom_right', $tab['position'], 'Unknown positions fall back.');
        $this->assertSame('#1f7a4d', $tab['background'], 'Only colours.');
        $this->assertSame('#123456', $tab['color']);
        $this->assertSame('after_close', $tab['when']);
        $this->assertFalse($tab['only']);
        $this->assertTrue($tab['mobile']);
    }

    public function test_a_campaign_opened_only_from_its_tab_always_has_the_tab()
    {
        // "Only after they close it" would leave nothing to open it.
        $tab = $this->config('lightbox', ['launcher_status' => true, 'launcher_only' => true, 'launcher_when' => 'after_close'])['launcher'];

        $this->assertTrue($tab['only']);
        $this->assertSame('always', $tab['when']);
    }

    public function test_the_tab_is_for_pop_ups_slide_ins_and_bars_only()
    {
        $this->assertArrayHasKey('launcher', $this->config('lightbox', ['launcher_status' => true]));
        $this->assertArrayNotHasKey('launcher', $this->config('inpost', ['launcher_status' => true], 'NoticeBox'));
        $this->assertArrayNotHasKey('launcher', $this->config('sidebar', ['launcher_status' => true], 'SimpleNotice'));
        $this->assertSame('Get 10% off', $this->config('lightbox', ['launcher_status' => true, 'launcher_text' => '   '])['launcher']['text'], 'Empty text: the default.');
    }

    public function test_without_premium_neither_is_sent()
    {
        remove_filter('mailoptin_premium_display_rules', '__return_true');
        add_filter('mailoptin_premium_display_rules', '__return_false');

        $config = $this->config('lightbox', ['idle_status' => true, 'idle_value' => 30, 'launcher_status' => true]);

        remove_filter('mailoptin_premium_display_rules', '__return_false');

        $this->assertArrayNotHasKey('idle_status', $config);
        $this->assertArrayNotHasKey('launcher', $config);
    }
}
