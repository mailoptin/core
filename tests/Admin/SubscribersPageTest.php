<?php

namespace MailOptin\Tests\Core\Admin;

use MailOptin\Core\Admin\SettingsPage\Campaign_Log_List;
use MailOptin\Core\Admin\SettingsPage\Subscribers_Page;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Core;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SendQueueRepository as Queue;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\SendQueue\QueueRunner;
use W3Guy\Custom_Settings_Page_Api;
use WP_UnitTestCase;

class SubscribersPageTest extends WP_UnitTestCase
{
    protected $list_id;

    /** @var Subscribers_Page */
    protected $page;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\Base::get_instance()->register_metadata_table();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        set_current_screen('dashboard');

        reset_phpmailer_instance();

        $this->list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 1]);
        $this->page    = Subscribers_Page::get_instance();
    }

    public function tear_down()
    {
        $_GET = $_POST = $_REQUEST = [];

        parent::tear_down();
    }

    /**
     * Run an admin request against the page. Redirects print a fallback page in tests, so output is buffered.
     *
     * @return string the redirect target's mo_notice
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

    public function test_admin_adds_subscriber_who_must_confirm()
    {
        $notice = $this->request(['mo_subscriber_action' => 'add'], [
            'mo_subscriber_action' => 'add',
            'email'                => 'Tom@Example.com',
            'first_name'           => 'Tom',
            'list_ids'             => [$this->list_id],
            'consent'              => 'confirm',
        ], 'mo_add_subscriber');

        $this->assertSame(Subscription::ADMIN_CONFIRMATION_SENT, $notice);

        $subscriber = Subscribers::get_by_email('tom@example.com');
        $this->assertSame('pending', $subscriber['status']);
        $this->assertSame('admin', $subscriber['source']);
        $this->assertCount(1, tests_retrieve_phpmailer_instance()->mock_sent);
    }

    public function test_admin_adds_subscriber_with_consent()
    {
        $this->request(['mo_subscriber_action' => 'add'], [
            'mo_subscriber_action' => 'add',
            'email'                => 'amara@example.com',
            'list_ids'             => [$this->list_id],
            'consent'              => 'has_consent',
        ], 'mo_add_subscriber');

        $this->assertSame('subscribed', Subscribers::get_by_email('amara@example.com')['status']);
        $this->assertEmpty(tests_retrieve_phpmailer_instance()->mock_sent);
    }

    public function test_admin_cannot_resubscribe_someone_who_unsubscribed()
    {
        SuppressionRepository::add('johan@example.com', SuppressionRepository::REASON_UNSUBSCRIBED);

        $notice = $this->request(['mo_subscriber_action' => 'add'], [
            'mo_subscriber_action' => 'add',
            'email'                => 'johan@example.com',
            'list_ids'             => [$this->list_id],
            'consent'              => 'has_consent',
        ], 'mo_add_subscriber');

        $this->assertSame(Subscription::ADMIN_REFUSED_UNSUBSCRIBED, $notice);
        $this->assertNull(Subscribers::get_by_email('johan@example.com'));
    }

    public function test_add_without_valid_nonce_is_rejected()
    {
        $this->expectException(\WPDieException::class);

        $this->request(['mo_subscriber_action' => 'add'], [
            'mo_subscriber_action' => 'add',
            'email'                => 'amara@example.com',
            'list_ids'             => [$this->list_id],
            '_wpnonce'             => 'forged',
        ]);
    }

    public function test_non_admin_cannot_act()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->request(['mo_subscriber_action' => 'add'], [
            'mo_subscriber_action' => 'add',
            'email'                => 'amara@example.com',
            'list_ids'             => [$this->list_id],
            'consent'              => 'has_consent',
        ], 'mo_add_subscriber');

        $this->assertNull(Subscribers::get_by_email('amara@example.com'));
    }

    public function test_profile_update_changes_names_and_lists()
    {
        $workshops = ListsRepository::create(['name' => 'Workshops', 'double_optin' => 0]);
        Subscription::add_by_admin('amara@example.com', [$this->list_id], [], true);
        $subscriber = Subscribers::get_by_email('amara@example.com');

        $this->request(['subscriber' => $subscriber['id']], [
            'mo_subscriber_action' => 'update',
            'subscriber'           => $subscriber['id'],
            'first_name'           => 'Amara',
            'last_name'            => 'Okafor',
            'list_ids'             => [$workshops],
        ], 'mo_update_subscriber_' . $subscriber['id']);

        $subscriber = Subscribers::get($subscriber['id']);
        $this->assertSame('Okafor', $subscriber['last_name']);
        $this->assertSame('unsubscribed', Subscribers::get_membership($subscriber['id'], $this->list_id)['status']);
        $this->assertSame('subscribed', Subscribers::get_membership($subscriber['id'], $workshops)['status']);
        $this->assertSame('subscribed', $subscriber['status']);
    }

    public function test_bulk_add_to_list_skips_people_who_unsubscribed_from_it()
    {
        $workshops = ListsRepository::create(['name' => 'Workshops', 'double_optin' => 0]);
        Subscription::add_by_admin('amara@example.com', [$this->list_id, $workshops], [], true);
        Subscription::add_by_admin('priya@example.com', [$this->list_id], [], true);

        $amara = Subscribers::get_by_email('amara@example.com');
        $priya = Subscribers::get_by_email('priya@example.com');
        Subscription::unsubscribe($amara['id'], $workshops);

        $notice = $this->request([
            'action'         => 'add_to_list',
            'mo_bulk_list'   => $workshops,
            'subscriber_ids' => [$amara['id'], $priya['id']],
        ], [], 'bulk-subscribers');

        $this->assertSame('bulk_done', $notice);
        $this->assertSame('unsubscribed', Subscribers::get_membership($amara['id'], $workshops)['status']);
        $this->assertSame('subscribed', Subscribers::get_membership($priya['id'], $workshops)['status']);
    }

    public function test_bulk_list_action_needs_a_list()
    {
        Subscription::add_by_admin('amara@example.com', [$this->list_id], [], true);

        $notice = $this->request([
            'action'         => 'add_to_list',
            'subscriber_ids' => [Subscribers::get_by_email('amara@example.com')['id']],
        ], [], 'bulk-subscribers');

        $this->assertSame('bulk_no_list', $notice);
    }

    public function test_bulk_unsubscribe_and_delete()
    {
        Subscription::add_by_admin('amara@example.com', [$this->list_id], [], true);
        Subscription::add_by_admin('priya@example.com', [$this->list_id], [], true);
        $amara = Subscribers::get_by_email('amara@example.com');
        $priya = Subscribers::get_by_email('priya@example.com');

        $this->request(['action' => 'unsubscribe', 'subscriber_ids' => [$amara['id']]], [], 'bulk-subscribers');
        $this->request(['action' => 'delete', 'subscriber_ids' => [$priya['id']]], [], 'bulk-subscribers');

        $this->assertSame('unsubscribed', Subscribers::get($amara['id'])['status']);
        $this->assertTrue(SuppressionRepository::is_suppressed('amara@example.com'));
        $this->assertNull(Subscribers::get($priya['id']));
    }

    public function test_save_and_delete_list()
    {
        $this->request(['view' => 'lists'], [
            'mo_subscriber_action' => 'save_list',
            'name'                 => 'Spring Planting Guide',
            'description'          => 'Monthly tips',
        ], 'mo_save_list');

        $lists = wp_list_pluck(ListsRepository::get_all(), 'double_optin', 'name');
        $this->assertSame('0', $lists['Spring Planting Guide'], 'An unticked double opt-in box turns it off.');

        Subscription::add_by_admin('amara@example.com', [$this->list_id], [], true);

        $notice = $this->request(['view' => 'lists', 'mo_subscriber_action' => 'delete_list', 'list_id' => $this->list_id], [], 'mo_delete_list_' . $this->list_id);

        $this->assertSame('list_deleted', $notice);
        $this->assertNull(ListsRepository::get($this->list_id));
        $this->assertNotNull(Subscribers::get_by_email('amara@example.com'), 'Deleting a list keeps its subscribers.');
    }

    public function test_screens_render()
    {
        Subscription::add_by_admin('amara@example.com', [$this->list_id], ['first_name' => 'Amara'], true);
        $subscriber = Subscribers::get_by_email('amara@example.com');

        $list = $this->render('render_subscribers');
        $this->assertStringContainsString('amara@example.com', $list);
        $this->assertStringContainsString('mo-pill-subscribed', $list);
        $this->assertStringContainsString('Newsletter', $list);

        $profile = $this->render('render_subscribers', ['subscriber' => $subscriber['id']]);
        $this->assertStringContainsString('Consent record', $profile);
        $this->assertStringContainsString('Added by an admin', $profile);

        $add = $this->render('render_subscribers', ['new' => '1']);
        $this->assertStringContainsString('Add a subscriber', $add);

        $lists = $this->render('render_lists', ['view' => 'lists']);
        $this->assertStringContainsString('Newsletter', $lists);
        $this->assertStringContainsString('Add new list', $lists);
    }

    public function test_campaign_log_shows_queue_progress()
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . Core::campaign_log_table_name, [
            'email_campaign_id' => 7,
            'title'             => 'November Planting Notes',
            'content_html'      => '<p>Hi</p>',
            'content_text'      => 'Hi',
            'status'            => QueueRunner::LOG_STATUS_SENDING,
        ]);
        $campaign_log_id = $wpdb->insert_id;

        Queue::enqueue($campaign_log_id, 7, [
            ['email' => 'a@example.com'], ['email' => 'b@example.com'], ['email' => 'c@example.com'], ['email' => 'd@example.com'],
        ]);
        $rows = Queue::claim($campaign_log_id, 'w', 1, 60);
        Queue::mark_sent($rows[0]['id']);

        $table  = new Campaign_Log_List($wpdb);
        $method = new \ReflectionMethod($table, 'queue_progress_html');
        $method->setAccessible(true);

        $html = $method->invoke($table, ['id' => $campaign_log_id, 'status' => QueueRunner::LOG_STATUS_SENDING]);

        $this->assertStringContainsString('Sending · 25%', $html);
        $this->assertStringContainsString('1 of 4 sent', $html);

        $table->delete_a_campaign_log($campaign_log_id);
        $this->assertSame(0, array_sum(Queue::counts($campaign_log_id)), 'Deleting a log removes its queue rows.');
    }
}
