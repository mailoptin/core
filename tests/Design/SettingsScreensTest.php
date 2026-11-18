<?php

namespace MailOptin\Core\Tests\Design;

use MailOptin\Core\Admin\SettingsPage\Connections;

/**
 * Settings and Integrations after the Refactoring UI pass.
 */
class SettingsScreensTest extends \WP_UnitTestCase
{
    public function test_integration_tiles_show_a_plain_name_and_a_connected_badge()
    {
        $this->assertSame(
            'Kit <span class="mo-ui-badge mo-ui-ok">Connected</span>',
            Connections::tile_title('Kit  <span style="color:#008000">(Connected)</span>')
        );
        $this->assertSame('Mailchimp', Connections::tile_title('Mailchimp Connection'), 'Not connected: no badge, no "Connection".');
        $this->assertSame('x', Connections::tile_title('<b>x</b>'), 'Tags are stripped.');
        $this->assertSame('A &amp; B', Connections::tile_title('A & B'), 'The rest is escaped.');
    }

    public function test_settings_say_what_each_option_does()
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Admin/SettingsPage/Settings.php');

        foreach (['Remove Data on Uninstall', 'Switch Customizer Loader Method', 'Global Interaction Cookie', 'Disable Impression Tracking'] as $old) {
            $this->assertStringNotContainsString($old, $source, "\"$old\" was replaced with plain wording.");
        }

        // Every checkbox says what ticking it does, not just "Enable" or "Disable".
        preg_match_all("/'checkbox_label'\s*=>\s*__\('([^']*)'/", $source, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $label) {
            $this->assertNotContains($label, ['Enable', 'Disable', 'Activate'], 'A checkbox labelled only "' . $label . '".');
        }
    }
}
