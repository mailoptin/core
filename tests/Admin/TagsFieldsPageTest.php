<?php

namespace MailOptin\Tests\Core\Admin;

use MailOptin\Core\Admin\SettingsPage\Subscribers_Page;
use MailOptin\Core\Admin\SettingsPage\Subscribers_Tags_Fields;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use W3Guy\Custom_Settings_Page_Api;
use WP_UnitTestCase;

class TagsFieldsPageTest extends WP_UnitTestCase
{
    protected $list_id;

    /** @var Subscribers_Page */
    protected $page;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        DBUpdates::get_instance()->update_routine_17();
        DBUpdates::get_instance()->update_routine_20();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        Fields::flush_cache();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        set_current_screen('dashboard');

        $this->list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 0]);
        $this->page    = Subscribers_Page::get_instance();
    }

    public function tear_down()
    {
        $_GET = $_POST = $_REQUEST = [];
        Fields::flush_cache();

        parent::tear_down();
    }

    /**
     * @return array [notice, redirect url]
     */
    protected function request($get, $post = [], $nonce_action = '')
    {
        $get['page'] = Subscribers_Page::SLUG;

        if ($nonce_action) {
            $nonce = wp_create_nonce($nonce_action);
            $post ? $post['_wpnonce'] = $nonce : $get['_wpnonce'] = $nonce;
        }

        $_GET     = $get;
        $_POST    = $post;
        $_REQUEST = array_merge($get, $post);

        ob_start();
        try {
            $this->page->handle_actions();
        } finally {
            $output = ob_get_clean();
        }

        preg_match('/mo_notice=([a-z_]+)/', $output, $match);

        return $match[1] ?? '';
    }

    protected function render($method, $get = [])
    {
        $_GET = array_merge(['page' => Subscribers_Page::SLUG], $get);

        $instance = Custom_Settings_Page_Api::instance();
        $instance->option_name(Subscribers_Page::OPTION_NAME);
        $this->page->set_settings_page_instance($instance);

        ob_start();
        $this->page->$method();

        return ob_get_clean();
    }

    protected function subscriber($email)
    {
        Subscription::add_by_admin($email, [$this->list_id], [], true);

        return (int)Subscribers::get_by_email($email)['id'];
    }

    public function test_tags_tab_actions()
    {
        $this->assertSame('tag_added', $this->request(['view' => 'tags'], ['mo_subscriber_action' => 'add_tag', 'name' => 'roses'], 'mo_add_tag'));
        $this->assertSame('error', $this->request(['view' => 'tags'], ['mo_subscriber_action' => 'add_tag', 'name' => 'Roses'], 'mo_add_tag'));
        $this->assertStringContainsString('already a tag called', Subscribers_Tags_Fields::take_error()['message']);

        $roses = (int)Tags::get_by_name('roses')['id'];
        $seeds = Tags::create('seeds');
        $a     = $this->subscriber('a@example.com');
        Tags::add_to_subscribers([$roses], [$a]);

        $this->assertSame('error', $this->request(['view' => 'tags'], ['mo_subscriber_action' => 'rename_tag', 'tag' => $roses, 'name' => 'Seeds'], 'mo_rename_tag_' . $roses));
        $this->assertSame('tag_renamed', $this->request(['view' => 'tags'], ['mo_subscriber_action' => 'rename_tag', 'tag' => $roses, 'name' => 'rose lovers'], 'mo_rename_tag_' . $roses));
        $this->assertSame('rose lovers', Tags::get($roses)['name']);

        $this->assertSame('tag_merged', $this->request(['view' => 'tags'], ['mo_subscriber_action' => 'merge_tag', 'tag' => $roses, 'into' => $seeds], 'mo_merge_tag_' . $roses));
        $this->assertSame([$seeds => 'seeds'], Tags::for_subscriber($a));

        // A nonce for another tag doesn't delete this one.
        $this->expectException(\WPDieException::class);
        try {
            $this->request(['view' => 'tags', 'mo_subscriber_action' => 'delete_tag', 'tag' => $seeds], [], 'mo_delete_tag_999');
        } finally {
            $this->assertNotNull(Tags::get($seeds));
            $this->assertSame('tag_deleted', $this->request(['view' => 'tags', 'mo_subscriber_action' => 'delete_tag', 'tag' => $seeds], [], 'mo_delete_tag_' . $seeds));
            $this->assertNull(Tags::get($seeds));
        }
    }

    public function test_field_panel_actions()
    {
        $this->assertSame('field_added', $this->request(['view' => 'fields'], [
            'mo_subscriber_action' => 'save_field',
            'field_id'             => 0,
            'type'                 => 'dropdown',
            'label'                => 'Garden size',
            'merge_key'            => '',
            'options'              => "Small\nMedium\nLarge",
        ], 'mo_save_field'));

        $field = Fields::get_by_key('garden_size');
        $this->assertSame(['Small', 'Medium', 'Large'], $field['options']);

        // Invalid: back to the panel with the error and what was typed.
        $this->assertSame('error', $this->request(['view' => 'fields'], ['mo_subscriber_action' => 'save_field', 'type' => 'text', 'label' => 'Garden size', 'merge_key' => 'garden_size'], 'mo_save_field'));
        $panel = $this->render('render_fields', ['view' => 'fields', 'field' => 'new']);
        $this->assertStringContainsString('Another field already uses', $panel);
        $this->assertStringContainsString('value="Garden size"', $panel);

        // Editing keeps the type and merge key.
        $this->assertSame('field_saved', $this->request(['view' => 'fields'], [
            'mo_subscriber_action' => 'save_field',
            'field_id'             => $field['id'],
            'type'                 => 'text',
            'label'                => 'Plot size',
            'merge_key'            => 'plot',
            'options'              => "S\nL",
        ], 'mo_save_field'));
        $field = Fields::get($field['id']);
        $this->assertSame(['Plot size', 'garden_size', 'dropdown', ['S', 'L']], [$field['label'], $field['merge_key'], $field['type'], $field['options']]);

        $second = Fields::create(['type' => 'text', 'label' => 'Company']);
        $this->assertSame('fields_reordered', $this->request(['view' => 'fields', 'mo_subscriber_action' => 'move_field', 'field_id' => $second, 'dir' => 'up'], [], 'mo_move_field_' . $second));
        $this->assertSame(['company', 'garden_size'], wp_list_pluck(Fields::all(), 'merge_key'));

        $this->assertSame('fields_reordered', $this->request(['view' => 'fields'], ['mo_subscriber_action' => 'reorder_fields', 'order' => $field['id'] . ',' . $second], 'mo_reorder_fields'));
        $this->assertSame(['garden_size', 'company'], wp_list_pluck(Fields::all(), 'merge_key'));

        $this->assertSame('field_deleted', $this->request(['view' => 'fields', 'mo_subscriber_action' => 'delete_field', 'field_id' => $second], [], 'mo_delete_field_' . $second));
        $this->assertNull(Fields::get($second));
    }

    public function test_bulk_tag_and_field_actions()
    {
        $a     = $this->subscriber('a@example.com');
        $b     = $this->subscriber('b@example.com');
        $vip   = Tags::create('vip');
        $field = Fields::get(Fields::create(['type' => 'number', 'label' => 'Orders']));

        $this->assertSame('bulk_done', $this->request(['action' => 'add_tags', 'mo_bulk_tags' => 'VIP, new one', 'subscriber_ids' => [$a, $b]], [], 'bulk-subscribers'));
        $this->assertSame(['new one', 'vip'], array_values(Tags::for_subscriber($b)));
        $this->assertSame('tags_added', Subscribers::get_events($b)[0]['type']);

        // Removing never creates tags.
        $this->assertSame('bulk_done', $this->request(['action' => 'remove_tags', 'mo_bulk_tags' => 'vip,ghost', 'subscriber_ids' => [$a]], [], 'bulk-subscribers'));
        $this->assertSame(['new one'], array_values(Tags::for_subscriber($a)));
        $this->assertNull(Tags::get_by_name('ghost'));

        $this->assertSame('error', $this->request(['action' => 'add_tags', 'mo_bulk_tags' => ' ', 'subscriber_ids' => [$a]], [], 'bulk-subscribers'));
        Subscribers_Tags_Fields::take_error();

        $this->assertSame('error', $this->request(['action' => 'set_field', 'mo_bulk_field' => $field['id'], 'mo_bulk_value' => 'lots', 'subscriber_ids' => [$a, $b]], [], 'bulk-subscribers'));
        $this->assertStringContainsString('must be a number', Subscribers_Tags_Fields::take_error()['message']);

        $this->assertSame('bulk_done', $this->request(['action' => 'set_field', 'mo_bulk_field' => $field['id'], 'mo_bulk_value' => '4', 'subscriber_ids' => [$a, $b]], [], 'bulk-subscribers'));
        $this->assertSame(['orders' => '4'], Fields::merge_values($b));
        $this->assertSame([], Tags::for_subscriber(999999));
    }

    public function test_profile_saves_fields_and_tags_and_shows_them_on_the_timeline()
    {
        $a       = $this->subscriber('a@example.com');
        $company = Fields::create(['type' => 'text', 'label' => 'Company']);
        $orders  = Fields::create(['type' => 'number', 'label' => 'Orders']);
        $plants  = Fields::create(['type' => 'multiselect', 'label' => 'Plants', 'options' => ['Roses', 'Veg']]);
        $sms     = Fields::create(['type' => 'checkbox', 'label' => 'Wants SMS']);
        $vip     = Tags::create('vip');
        $old     = Tags::create('old');
        Tags::add_to_subscribers([$old], [$a]);

        $notice = $this->request([], [
            'mo_subscriber_action' => 'update',
            'subscriber'           => $a,
            'first_name'           => 'Amara',
            'last_name'            => '',
            'list_ids'             => [$this->list_id],
            'fields'               => [$company => 'Okafor Landscaping', $orders => 'many', $plants => ['', 'Veg'], $sms => '1'],
            'tags_submitted'       => '1',
            'tag_ids'              => [$vip],
            'new_tags'             => 'customer',
        ], 'mo_update_subscriber_' . $a);

        $this->assertSame('saved', $notice);
        $this->assertSame(['company' => 'Okafor Landscaping', 'orders' => '', 'plants' => 'Veg', 'wants_sms' => 'Yes'], Fields::merge_values($a));
        $this->assertSame(['customer', 'vip'], array_values(Tags::for_subscriber($a)));

        $profile = $this->render('render_subscribers', ['subscriber' => $a, 'mo_notice' => 'saved']);
        $this->assertStringContainsString('Changes saved, except: Orders must be a number.', $profile);
        $this->assertStringContainsString('value="Okafor Landscaping"', $profile);
        $this->assertStringContainsString('Company set to Okafor Landscaping', $profile);
        $this->assertStringContainsString('Tags customer, vip added by you', $profile);
        $this->assertStringContainsString('Tag old removed by you', $profile);

        // Unticking the checkbox stores "No"; clearing a text field removes it.
        $this->request([], ['mo_subscriber_action' => 'update', 'subscriber' => $a, 'list_ids' => [$this->list_id], 'fields' => [$company => '', $sms => '0']], 'mo_update_subscriber_' . $a);
        $this->assertSame(['company' => '', 'orders' => '', 'plants' => 'Veg', 'wants_sms' => 'No'], Fields::merge_values($a));
        $this->assertSame(['customer', 'vip'], array_values(Tags::for_subscriber($a)), 'Tags stay when the form has no tag inputs.');
    }

    public function test_screens_render_with_filters()
    {
        $a     = $this->subscriber('amara@example.com');
        $b     = $this->subscriber('tom@example.com');
        $vip   = Tags::create('vip');
        $zone  = Fields::create(['type' => 'dropdown', 'label' => 'Zone', 'options' => ['6a', '7b']]);
        Tags::add_to_subscribers([$vip], [$a]);
        Fields::set_values($b, [$zone => '7b']);

        $tags = $this->render('render_tags', ['view' => 'tags']);
        $this->assertStringContainsString('<span class="mo-tag">vip</span>', $tags);
        $this->assertStringContainsString('Rename', $tags);
        $this->assertStringNotContainsString('Merge into', $tags, 'Nothing to merge into with one tag.');

        $fields = $this->render('render_fields', ['view' => 'fields']);
        $this->assertStringContainsString('{{subscriber.zone}}', $fields);
        $this->assertStringNotContainsString('class="mo-panel"', $fields);
        $this->assertStringContainsString('class="mo-panel"', $this->render('render_fields', ['view' => 'fields', 'field' => $zone]));

        $list = $this->render('render_subscribers', ['tags' => [$vip]]);
        $this->assertStringContainsString('amara@example.com', $list);
        $this->assertStringNotContainsString('tom@example.com', $list);
        $this->assertStringContainsString('Tag is any of', $list);
        $this->assertStringContainsString('mo-bulk-bar', $list);
        $this->assertStringContainsString('name="tags[0]"', $list, 'The search form keeps the filter.');

        $list = $this->render('render_subscribers', ['f' => [['field' => $zone, 'op' => 'is', 'value' => ['7b']]]]);
        $this->assertStringContainsString('tom@example.com', $list);
        $this->assertStringNotContainsString('amara@example.com', $list);
        $this->assertStringContainsString('Zone is any of <b>7b</b>', $list);

        $adding = $this->render('render_subscribers', ['mo_add_filter' => (string)$zone]);
        $this->assertStringContainsString('name="f[0][op]"', $adding);
    }

    public function test_tabs_are_hidden_until_the_database_update_runs()
    {
        $this->subscriber('amara@example.com');
        update_option('mo_db_ver', 19);

        $this->assertSame(['subscribers', 'lists'], wp_list_pluck($this->page->header_menu_tabs(), 'id'));
        $this->assertSame('', $this->render('render_tags', ['view' => 'tags']));
        $this->assertStringContainsString('Search email or name', $this->render('render_subscribers'));
    }
}
