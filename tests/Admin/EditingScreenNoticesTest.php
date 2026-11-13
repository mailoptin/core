<?php

namespace MailOptin\Tests\Core\Admin;

use MailOptin\Core\Admin\AdminNotices;

use function MailOptin\Core\is_mailoptin_editing_screen;

/**
 * Review requests and feature promotions stay off the screens where emails and automations are written.
 */
class EditingScreenNoticesTest extends \WP_UnitTestCase
{
    public function tear_down()
    {
        $_GET = [];
        parent::tear_down();
    }

    protected function on_screen($query)
    {
        set_current_screen('dashboard');
        $_GET = $query;
    }

    public function test_editing_screens_are_recognised()
    {
        $this->on_screen(['page' => 'mailoptin-emails', 'view' => 'edit-email', 'id' => 3]);
        $this->assertTrue(is_mailoptin_editing_screen());

        $this->on_screen(['page' => 'mailoptin-emails', 'view' => 'broadcast', 'id' => 3]);
        $this->assertTrue(is_mailoptin_editing_screen());

        $this->on_screen(['page' => 'mailoptin-emails', 'view' => 'automations', 'screen' => 'edit', 'id' => 3]);
        $this->assertTrue(is_mailoptin_editing_screen());

        $this->on_screen(['page' => 'mailoptin-emails', 'view' => 'automations']);
        $this->assertFalse(is_mailoptin_editing_screen(), 'The automations list is not an editor.');

        $this->on_screen(['page' => 'mailoptin-settings', 'view' => 'edit-email']);
        $this->assertFalse(is_mailoptin_editing_screen());
    }

    public function test_promotions_stay_off_editing_screens()
    {
        $notices = new AdminNotices();

        $this->on_screen(['page' => 'mailoptin-emails', 'view' => 'automations']);
        $this->assertTrue($notices->is_admin_notice_show());

        $this->on_screen(['page' => 'mailoptin-emails', 'view' => 'automations', 'screen' => 'edit', 'id' => 3]);
        $this->assertFalse($notices->is_admin_notice_show());

        // The review request too.
        $this->_setRole('administrator');
        update_option('mo_install_date', gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS));
        $this->on_screen(['page' => 'mailoptin-emails', 'view' => 'automations']);
        ob_start();
        $notices->review_plugin_notice();
        $this->assertStringContainsString('5-star rating', ob_get_clean(), 'Shown elsewhere.');

        $this->on_screen(['page' => 'mailoptin-emails', 'view' => 'automations', 'screen' => 'edit', 'id' => 3]);
        ob_start();
        $notices->review_plugin_notice();
        $this->assertSame('', ob_get_clean());
    }

    protected function _setRole($role)
    {
        wp_set_current_user(self::factory()->user->create(['role' => $role]));
    }
}
