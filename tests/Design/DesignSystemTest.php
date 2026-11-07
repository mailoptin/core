<?php

namespace MailOptin\Core\Tests\Design;

use MailOptin\Core\Admin\UI;
use MailOptin\Core\RegisterScripts;

class DesignSystemTest extends \WP_UnitTestCase
{
    public function test_badge_escapes_and_only_accepts_known_tones()
    {
        $this->assertSame('<span class="mo-ui-badge mo-ui-ok">Subscribed</span>', UI::badge('Subscribed', 'ok'));
        $this->assertSame('<span class="mo-ui-badge">&lt;b&gt;x</span>', UI::badge('<b>x', '" onclick="x'));
    }

    public function test_pro_pill_defaults_to_premium()
    {
        $this->assertSame('<span class="mo-ui-pro">Premium</span>', UI::pro_pill());
    }

    public function test_button_variants_attributes_and_new_tab_rel()
    {
        $html = UI::button('See plans', 'https://mailoptin.io/pricing/?a=1&b=2', 'primary', ['target' => '_blank', 'data-x' => '"y"']);

        $this->assertStringContainsString('class="mo-ui-btn mo-ui-primary"', $html);
        $this->assertStringContainsString('href="https://mailoptin.io/pricing/?a=1&#038;b=2"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringContainsString('data-x="&quot;y&quot;"', $html);
        $this->assertStringContainsString('class="mo-ui-btn"', UI::button('Back', '#', 'bogus'));
        $this->assertStringNotContainsString('javascript', UI::button('x', 'javascript:alert(1)'));
    }

    public function test_chip_with_and_without_remove_link()
    {
        $this->assertStringNotContainsString('mo-ui-chip-x', UI::chip('List', 'Newsletter'));
        $html = UI::chip('Tag', 'vip', 'https://example.com/?tag=');
        $this->assertStringContainsString('<b>vip</b>', $html);
        $this->assertStringContainsString('aria-label="Remove Tag filter"', $html);
    }

    public function test_note_strips_scripts()
    {
        $html = UI::note('<b>Saved</b><script>alert(1)</script>', 'ok');
        $this->assertStringStartsWith('<div class="mo-ui-note mo-ui-ok">', $html);
        $this->assertStringContainsString('<b>Saved</b>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_stylesheet_loads_on_mailoptin_screens_only()
    {
        if ( ! defined('MAILOPTIN_VERSION_NUMBER')) define('MAILOPTIN_VERSION_NUMBER', '1.0.0');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        set_current_screen('dashboard');
        RegisterScripts::get_instance()->admin_css();
        $this->assertFalse(wp_style_is('mailoptin-design', 'enqueued'));

        set_current_screen('toplevel_page_mailoptin-settings');
        RegisterScripts::get_instance()->admin_css();
        $this->assertTrue(wp_style_is('mailoptin-design', 'enqueued'));
        $this->assertContains('mailoptin-admin', wp_styles()->registered['mailoptin-design']->deps);
        $this->assertFileExists(MAILOPTIN_ASSETS_DIR . 'css/admin/mo-design.css');
    }
}
