<?php

namespace MailOptin\Core\Tests\Design;

use MailOptin\Core\Admin\SettingsPage\Subscribers_List;

/**
 * The Subscribers area after the Refactoring UI pass.
 */
class SubscribersScreensTest extends \WP_UnitTestCase
{
    public function test_search_box_has_a_short_button_and_the_hint_in_the_field()
    {
        set_current_screen('toplevel_page_mailoptin-subscribers');
        $_REQUEST['s']       = '"><script>x</script>';
        $_REQUEST['orderby'] = 'email';
        $_REQUEST['order']   = 'asc';

        ob_start();
        (new Subscribers_List())->search_box('Name, email or any field', 'mo-subscriber-search');
        $html = ob_get_clean();
        unset($_REQUEST['s'], $_REQUEST['orderby'], $_REQUEST['order']);

        $this->assertStringContainsString('placeholder="Name, email or any field"', $html);
        $this->assertMatchesRegularExpression('/<input[^>]+type="submit"[^>]+value="Search"/', $html);
        $this->assertStringContainsString('Search subscribers: Name, email or any field', $html, 'Screen readers still hear what it searches.');
        $this->assertStringNotContainsString('<script>x', $html, 'The query is escaped.');
        $this->assertStringContainsString('<input type="hidden" name="orderby" value="email" />', $html, 'Searching keeps the sort.');
        $this->assertStringContainsString('<input type="hidden" name="order" value="asc" />', $html);
    }

    public function test_drag_handle_is_readable()
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Admin/SettingsPage/Subscribers_Tags_Fields.php');
        $this->assertStringContainsString('.mo-handle{display:none;color:#646970', $source, 'At least 4.5:1 on white (was #a7aaad, 2.3:1).');
    }
}
