<?php

namespace MailOptin\Core\Tests\Design;

use MailOptin\Core\Admin\SettingsPage\Subscribers_Page;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Repositories\ListsRepository;
use W3Guy\Custom_Settings_Page_Api;

class SubscribersListDesignTest extends \WP_UnitTestCase
{
    protected $list_id;

    public function set_up()
    {
        parent::set_up();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        set_current_screen('dashboard');
        $this->list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 0]);
    }

    public function tear_down()
    {
        $_GET = [];
        parent::tear_down();
    }

    protected function render($get = [])
    {
        $_GET = array_merge(['page' => Subscribers_Page::SLUG], $get);

        $instance = Custom_Settings_Page_Api::instance();
        $instance->option_name(Subscribers_Page::OPTION_NAME);
        $page = Subscribers_Page::get_instance();
        $page->set_settings_page_instance($instance);

        ob_start();
        $page->render_subscribers();

        return ob_get_clean();
    }

    public function test_list_shows_heading_actions_and_two_line_subscriber_cell()
    {
        Subscription::add_by_admin('amara@example.com', [$this->list_id], ['first_name' => 'Amara', 'last_name' => 'Obi'], true);
        Subscription::add_by_admin('noname@example.com', [$this->list_id], [], true);

        $html = $this->render();

        $this->assertStringContainsString('<h1>Subscribers</h1>', $html);
        $this->assertStringContainsString('2 matching', $html);
        $this->assertStringContainsString('+ Add subscriber', $html);
        $this->assertStringContainsString('mo-ui-btn mo-ui-primary', $html);
        // Admin notices go below the heading row, not between the title and its buttons.
        $this->assertMatchesRegularExpression('#\+ Add subscriber</a>\s*</span>\s*</div>\s*<hr class="wp-header-end">#', $html);

        // Name on the first line, email on the second.
        $this->assertMatchesRegularExpression('#class="row-title"[^>]*>Amara Obi</a></strong><span class="mo-subscriber-email">amara@example.com</span>#', $html);
        // No name: the email is the title and isn't repeated.
        $this->assertMatchesRegularExpression('#class="row-title"[^>]*>noname@example.com</a></strong><div class="row-actions#', $html);
        $this->assertStringNotContainsString('column-name', $html);

        $this->assertStringContainsString('mo-ui-badge mo-ui-ok mo-pill mo-pill-subscribed', $html);
    }

    public function test_search_and_table_forms_keep_the_current_view()
    {
        $html = $this->render(['status' => 'subscribed', 'list' => $this->list_id, 's' => 'amara', 'segment' => 3, 'orderby' => 'email', 'order' => 'asc']);

        preg_match('#<form method="get" class="mo-subscriber-search-form".*?</form>#s', $html, $search);
        $this->assertNotEmpty($search);
        $this->assertStringContainsString('name="status" value="subscribed"', $search[0]);
        $this->assertStringContainsString('name="list" value="' . $this->list_id . '"', $search[0]);
        $this->assertStringContainsString('name="segment" value="3"', $search[0]);
        $this->assertStringContainsString('name="s" value="amara"', $search[0]);
        $this->assertStringContainsString('name="orderby" value="email"', $search[0]);
        $this->assertStringContainsString('name="order" value="asc"', $search[0]);

        preg_match('#<form method="get" class="mo-subscribers-table-form">.*?<table#s', $html, $table);
        $this->assertNotEmpty($table);
        $this->assertStringContainsString('<input type="hidden" name="s" value="amara">', $table[0], 'Paging and bulk actions keep the search.');
        $this->assertStringContainsString('name="orderby" value="email"', $table[0], 'The list filter keeps the sort.');
    }

    public function test_array_search_param_is_ignored_without_warnings()
    {
        $html = $this->render(['s' => ['x']]);

        $this->assertStringContainsString('id="mo-subscriber-search-input" name="s" value=""', $html);
    }

    public function test_status_pill_tones()
    {
        $this->assertStringContainsString('mo-ui-warn', Subscribers_Page::status_pill('pending'));
        $this->assertStringContainsString('mo-ui-bad', Subscribers_Page::status_pill('bounced'));
        $this->assertStringContainsString('class="mo-ui-badge mo-pill mo-pill-unsubscribed"', Subscribers_Page::status_pill('unsubscribed'));
    }
}
