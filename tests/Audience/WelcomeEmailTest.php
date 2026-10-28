<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\Subscribers_Page;
use MailOptin\Core\AsyncHandler\AsyncHandler;
use MailOptin\Core\AsyncHandler\BGProcess;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Audience\WelcomeEmail;
use MailOptin\Core\Core;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\SendQueue\QueueRunner;
use WP_UnitTestCase;

class WelcomeEmailTest extends WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        if (is_null(AsyncHandler::get_instance()->bg_process)) {
            AsyncHandler::get_instance()->bg_process = new BGProcess();
        }

        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        reset_phpmailer_instance();
    }

    protected function list_with_welcome($double_optin, $body = '')
    {
        $list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => $double_optin ? 1 : 0]);
        WelcomeEmail::save($list_id, true, 'Welcome to Monthly planting notes', $body ?: '<p>Hi {{subscriber.first_name|there}}, thanks for joining {{list.name}}.</p>');

        return $list_id;
    }

    /**
     * Run the send queue for every campaign, then return what was mailed (welcome emails only).
     */
    protected function deliver()
    {
        reset_phpmailer_instance();
        QueueRunner::get_instance()->run_all();

        return array_values(array_filter(tests_retrieve_phpmailer_instance()->mock_sent, function ($mail) {
            return false === strpos($mail['subject'], 'confirm');
        }));
    }

    public function test_single_opt_in_signup_gets_a_personal_welcome_email()
    {
        $list_id = $this->list_with_welcome(false);

        Subscription::subscribe('maria@example.com', $list_id, ['first_name' => 'Maria']);

        $mail = $this->deliver();

        $this->assertCount(1, $mail);
        $this->assertSame('maria@example.com', $mail[0]['to'][0][0]);
        $this->assertSame('Welcome to Monthly planting notes', $mail[0]['subject']);
        $this->assertStringContainsString('Hi Maria, thanks for joining Newsletter.', $mail[0]['body']);
        $this->assertStringContainsString("You're receiving this because you subscribed to Newsletter.", html_entity_decode($mail[0]['body'], ENT_QUOTES));
        $this->assertStringContainsString('mo_unsub=', $mail[0]['body']);
    }

    public function test_double_opt_in_waits_for_confirmation()
    {
        $list_id = $this->list_with_welcome(true);

        Subscription::subscribe('tom@example.com', $list_id, []);
        $this->assertEmpty($this->deliver(), 'No welcome before they confirm.');

        Subscription::confirm(Subscribers::get_by_email('tom@example.com')['id']);

        $mail = $this->deliver();
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('Hi there,', $mail[0]['body'], 'No first name falls back to "there".');
    }

    public function test_confirming_only_welcomes_the_lists_just_confirmed()
    {
        $first  = $this->list_with_welcome(true);
        $second = ListsRepository::create(['name' => 'Workshops', 'double_optin' => 1]);
        WelcomeEmail::save($second, true, 'Welcome to Workshops', 'Workshops welcome');

        Subscription::subscribe('tom@example.com', $first, []);
        $tom = Subscribers::get_by_email('tom@example.com');
        Subscription::confirm($tom['id']);
        $this->deliver();

        // Already confirmed, so joining Workshops subscribes straight away and welcomes only to Workshops.
        Subscription::subscribe('tom@example.com', $second, []);
        $mail = $this->deliver();

        $this->assertCount(1, $mail);
        $this->assertSame('Welcome to Workshops', $mail[0]['subject']);
    }

    public function test_off_by_default_and_when_turned_off()
    {
        $list_id = ListsRepository::create(['name' => 'Wholesale', 'double_optin' => 0]);
        Subscription::subscribe('amara@example.com', $list_id, []);

        $other = $this->list_with_welcome(false);
        WelcomeEmail::save($other, false, 'Welcome', 'Hello');
        Subscription::subscribe('priya@example.com', $other, []);

        $this->assertEmpty($this->deliver());
    }

    public function test_sent_once_per_person_per_list()
    {
        $list_id = $this->list_with_welcome(false);

        Subscription::subscribe('maria@example.com', $list_id, []);
        $this->deliver();

        $maria = Subscribers::get_by_email('maria@example.com');
        Subscription::unsubscribe($maria['id']);
        Subscription::subscribe('maria@example.com', $list_id, []);

        $this->assertEmpty($this->deliver());
        $this->assertSame(1, WelcomeEmail::sent_count(ListsRepository::get($list_id)));
    }

    public function test_admin_added_subscribers_are_not_welcomed()
    {
        $list_id = $this->list_with_welcome(false);

        Subscription::add_by_admin('amara@example.com', [$list_id], [], true);

        $this->assertEmpty($this->deliver());
    }

    public function test_edits_apply_to_emails_not_sent_yet()
    {
        $list_id = $this->list_with_welcome(false);

        Subscription::subscribe('maria@example.com', $list_id, []);
        WelcomeEmail::save($list_id, true, 'New subject', 'New message');

        $mail = $this->deliver();
        $this->assertSame('New subject', $mail[0]['subject']);
        $this->assertStringContainsString('New message', $mail[0]['body']);
    }

    public function test_log_is_recreated_if_deleted_from_the_campaign_log()
    {
        global $wpdb;

        $list_id = $this->list_with_welcome(false);
        Subscription::subscribe('maria@example.com', $list_id, []);
        $this->deliver();

        $old_log = (int)ListsRepository::get($list_id)['welcome_campaign_log_id'];
        $wpdb->delete($wpdb->prefix . Core::campaign_log_table_name, ['id' => $old_log]);

        Subscription::subscribe('priya@example.com', $list_id, []);

        $this->assertCount(1, $this->deliver());
        $this->assertNotSame($old_log, (int)ListsRepository::get($list_id)['welcome_campaign_log_id']);
    }

    public function test_send_test_uses_sample_values()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator', 'first_name' => 'Ada']));
        $list_id = $this->list_with_welcome(false);

        $this->assertTrue(WelcomeEmail::send_test($list_id, 'ada@example.com'));

        $mail = tests_retrieve_phpmailer_instance()->mock_sent[0];
        $this->assertSame('[Test] Welcome to Monthly planting notes', $mail['subject']);
        $this->assertStringContainsString('Hi Ada, thanks for joining Newsletter.', $mail['body']);
    }

    public function test_editor_screen_renders()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        set_current_screen('dashboard');
        $list_id = $this->list_with_welcome(false);

        $_GET = ['page' => Subscribers_Page::SLUG, 'view' => 'lists', 'welcome' => $list_id];
        ob_start();
        Subscribers_Page::get_instance()->render_lists();
        $html = ob_get_clean();
        $_GET = [];

        $this->assertStringContainsString('Welcome email for Newsletter', $html);
        $this->assertStringContainsString('value="Welcome to Monthly planting notes"', $html);
        $this->assertStringContainsString('{{list.name}}', $html);
    }
}
