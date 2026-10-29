<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\Broadcast_Page;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use WP_UnitTestCase;

class BroadcastsTest extends WP_UnitTestCase
{
    protected $list_id;
    protected $campaign_id;

    public function set_up()
    {
        parent::set_up();

        foreach ([17, 20, 21, 22] as $version) DBUpdates::get_instance()->{'update_routine_' . $version}();
        update_option('mo_db_ver', DBUpdates::DB_VER);

        // DB update 7 makes the email campaign meta table.
        DBUpdates::get_instance()->update_routine_7();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        if (is_null(\MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process)) {
            \MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process = new \MailOptin\Core\AsyncHandler\BGProcess();
        }
        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, ['from_name' => 'Acme Garden Supply', 'from_email' => 'hello@acmegarden.co']);

        $this->list_id     = ListsRepository::create(['name' => 'Newsletter']);
        $this->campaign_id = ER::add_email_campaign('November notes', ER::NEWSLETTER, 'Lucid');

        Broadcasts::save($this->campaign_id, ['email_campaign_title' => 'Hi {{subscriber.first_name|friend}}', 'connection_service' => 'MailOptinConnect', 'connection_email_list' => (string)$this->list_id]);
    }

    public function tear_down()
    {
        delete_option(MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME);
        $_POST = $_GET = $_REQUEST = [];

        parent::tear_down();
    }

    protected function member($email, $first_name = '')
    {
        $id = Subscribers::create(['email' => $email, 'first_name' => $first_name, 'status' => Subscribers::STATUS_SUBSCRIBED]);
        Subscribers::set_membership($id, $this->list_id, Subscribers::STATUS_SUBSCRIBED);

        return $id;
    }

    protected function queued()
    {
        global $wpdb;

        return (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . SendQueueRepository::table());
    }

    public function test_status_follows_the_last_send()
    {
        $this->assertSame(Broadcasts::STATUS_DRAFT, Broadcasts::status($this->campaign_id)['status']);

        Broadcasts::schedule($this->campaign_id, time() + HOUR_IN_SECONDS);
        $this->assertSame(Broadcasts::STATUS_SCHEDULED, Broadcasts::status($this->campaign_id)['status']);
        Broadcasts::cancel($this->campaign_id);

        global $wpdb;
        $logs = $wpdb->prefix . \MailOptin\Core\Core::campaign_log_table_name;

        foreach (['sending' => Broadcasts::STATUS_SENDING, 'processed' => Broadcasts::STATUS_SENT, 'failed' => Broadcasts::STATUS_FAILED] as $log_status => $expected) {
            $wpdb->insert($logs, ['email_campaign_id' => $this->campaign_id, 'title' => 'x', 'content_html' => '', 'content_text' => '', 'status' => $log_status]);
            $this->assertSame($expected, Broadcasts::status($this->campaign_id)['status'], $log_status);
        }
    }

    public function test_schedule_needs_a_future_time_and_can_be_cancelled()
    {
        $this->assertSame('mo_broadcast_past', Broadcasts::schedule($this->campaign_id, time() - 10)->get_error_code());

        $at = time() + DAY_IN_SECONDS;
        $this->assertTrue(Broadcasts::schedule($this->campaign_id, $at));
        $this->assertSame($at, wp_next_scheduled(Broadcasts::CRON_HOOK, [$this->campaign_id]));
        $this->assertSame(gmdate('Y-m-d H:i:s', $at), Broadcasts::scheduled_at($this->campaign_id));

        Broadcasts::cancel($this->campaign_id);
        $this->assertFalse(wp_next_scheduled(Broadcasts::CRON_HOOK, [$this->campaign_id]));
        $this->assertNull(Broadcasts::scheduled_at($this->campaign_id));
    }

    public function test_scheduled_send_goes_out_once()
    {
        $this->member('a@example.com');
        $this->member('b@example.com');

        Broadcasts::schedule($this->campaign_id, time() + HOUR_IN_SECONDS);

        Broadcasts::get_instance()->run_scheduled($this->campaign_id);
        $this->assertSame(2, $this->queued());
        $this->assertNull(Broadcasts::scheduled_at($this->campaign_id));

        // A repeated or overlapping cron run sends nothing more.
        Broadcasts::get_instance()->run_scheduled($this->campaign_id);
        $this->assertSame(2, $this->queued());
        $this->assertSame(Broadcasts::STATUS_SENDING, Broadcasts::status($this->campaign_id)['status']);
    }

    public function test_cancelled_schedule_doesnt_send()
    {
        $this->member('a@example.com');

        Broadcasts::schedule($this->campaign_id, time() + HOUR_IN_SECONDS);
        Broadcasts::cancel($this->campaign_id);

        Broadcasts::get_instance()->run_scheduled($this->campaign_id);
        $this->assertSame(0, $this->queued());
    }

    public function test_checks_block_on_missing_essentials_and_warn_about_a_test()
    {
        $labels = function () {
            return array_map(function ($check) {
                return [$check['label'], $check['ok'], $check['blocking']];
            }, Broadcasts::checks($this->campaign_id));
        };

        $this->assertFalse(Broadcasts::can_send($this->campaign_id), 'Nobody to send to.');
        $this->assertContains(['Audience: 0 people', false, true], $labels());

        $this->member('a@example.com');
        $this->assertTrue(Broadcasts::can_send($this->campaign_id));
        $this->assertContains(['Test email sent', false, false], $labels(), 'No test is a warning only.');

        Broadcasts::save($this->campaign_id, ['email_campaign_title' => ' ']);
        $this->assertFalse(Broadcasts::can_send($this->campaign_id));
    }

    public function test_test_email_uses_the_subscriber_with_that_address()
    {
        reset_phpmailer_instance();
        $this->member('amara@example.com', 'Amara');

        $this->assertTrue(Broadcasts::send_test($this->campaign_id, 'amara@example.com'));
        $this->assertTrue(Broadcasts::send_test($this->campaign_id, 'stranger@example.com'));
        $this->assertFalse(Broadcasts::send_test($this->campaign_id, 'not an email'));

        $sent = tests_retrieve_phpmailer_instance()->mock_sent;
        $this->assertSame('[Test] Hi Amara', $sent[0]['subject']);
        $this->assertSame('[Test] Hi friend', $sent[1]['subject']);
        $this->assertStringNotContainsString('{{unsubscribe}}', $sent[0]['body']);
        $this->assertNotEmpty(EmailCampaignMeta::get_meta_data($this->campaign_id, Broadcasts::TEST_META));
    }

    protected function post($action, $fields = [])
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $_GET     = ['page' => MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG, 'view' => Broadcast_Page::VIEW, 'id' => $this->campaign_id];
        $_POST    = $fields + ['mo_broadcast_action' => $action, 'id' => $this->campaign_id, '_wpnonce' => wp_create_nonce('mo_broadcast_' . $this->campaign_id)];
        $_REQUEST = $_GET + $_POST;

        set_current_screen('dashboard');

        $location = null;
        add_filter('mailoptin_broadcast_page_redirect', $filter = function ($url) use (&$location) {
            $location = $url;
            throw new \RuntimeException('redirected');
        });

        try {
            Broadcast_Page::get_instance()->handle_actions();
        } catch (\RuntimeException $e) {
        }

        remove_filter('mailoptin_broadcast_page_redirect', $filter);

        return (string)$location;
    }

    public function test_saving_the_page_updates_the_customizer_settings()
    {
        $other_list = ListsRepository::create(['name' => 'Workshops']);

        $location = $this->post('save', [
            'subject'               => 'Bulbs, frost and your last harvest',
            'preheader'             => 'Three jobs before the frost',
            'name'                  => 'November planting notes',
            'connection_service'    => 'MailOptinConnect',
            'connection_email_list' => (string)$other_list,
        ]);

        $this->assertStringContainsString('mo_notice=saved', $location);
        $this->assertSame('Bulbs, frost and your last harvest', Broadcasts::subject($this->campaign_id));
        $this->assertSame('Three jobs before the frost', Broadcasts::get($this->campaign_id, 'email_campaign_preheader'));
        $this->assertSame((string)$other_list, Broadcasts::get($this->campaign_id, 'connection_email_list'));
        $this->assertSame('November planting notes', ER::get_email_campaign_name($this->campaign_id));
    }

    public function test_send_now_is_refused_until_the_checks_pass_then_sends()
    {
        $this->assertStringContainsString('mo_notice=blocked', $this->post('send_now'));
        $this->assertSame(0, $this->queued());

        $this->member('a@example.com');

        $this->assertStringContainsString('mo_notice=sending', $this->post('send_now'));
        $this->assertSame(1, $this->queued());

        // Once sent, the page can't change or send it again.
        $this->post('save', ['subject' => 'Changed']);
        $this->post('send_now');
        $this->assertSame('Hi {{subscriber.first_name|friend}}', Broadcasts::subject($this->campaign_id));
        $this->assertSame(1, $this->queued());
    }

    public function test_review_rejects_a_past_schedule_and_schedule_sets_it()
    {
        $this->member('a@example.com');

        $location = $this->post('review', ['when' => 'later', 'send_date' => '2001-01-01', 'send_time' => '09:00']);
        $this->assertStringNotContainsString('review=1', $location);

        $tomorrow = (new \DateTimeImmutable('tomorrow 09:30', wp_timezone()));
        $location = $this->post('review', ['when' => 'later', 'send_date' => $tomorrow->format('Y-m-d'), 'send_time' => '09:30']);
        $this->assertStringContainsString('review=1', $location);
        $this->assertStringContainsString('at=' . $tomorrow->getTimestamp(), $location);

        $this->assertStringContainsString('mo_notice=scheduled', $this->post('schedule', ['at' => (string)$tomorrow->getTimestamp()]));
        $this->assertSame($tomorrow->getTimestamp(), wp_next_scheduled(Broadcasts::CRON_HOOK, [$this->campaign_id]));

        $this->assertStringContainsString('mo_notice=cancelled', $this->post('cancel_schedule'));
        $this->assertFalse(wp_next_scheduled(Broadcasts::CRON_HOOK, [$this->campaign_id]));
    }

    public function test_page_renders_and_links_the_designer()
    {
        $_GET = $_REQUEST = ['page' => MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG, 'view' => Broadcast_Page::VIEW, 'id' => $this->campaign_id];

        $html = Broadcast_Page::html();

        $this->assertStringContainsString('value="Hi {{subscriber.first_name|friend}}"', $html);
        $this->assertStringContainsString(Broadcast_Page::FROM_PAGE_ARG . '=1', $html);
        $this->assertStringContainsString('Before it goes', $html);

        $_GET = $_REQUEST = [];
    }
}
