<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\PreviewCampaignLog;
use MailOptin\Core\AsyncHandler\AsyncHandler;
use MailOptin\Core\AsyncHandler\BGProcess;
use MailOptin\Core\Audience\PublicEndpoints;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Audience\Tokens;
use MailOptin\Core\Audience\WelcomeEmail;
use MailOptin\Core\Connections\AbstractConnect;
use MailOptin\Core\Cron;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SendQueueRepository as Queue;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\SendQueue\QueueRunner;
use WP_UnitTestCase;
use WPDieException;

/**
 * Regression tests for defects found reviewing built-in lists before Release 2.
 */
class ReviewFixesTest extends WP_UnitTestCase
{
    protected $single;
    protected $double;

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

        $_SERVER['REMOTE_ADDR'] = '198.51.100.20';

        $this->single = ListsRepository::create(['name' => 'Updates', 'double_optin' => 0]);
        $this->double = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 1]);

        reset_phpmailer_instance();
    }

    public function tear_down()
    {
        $_GET = $_POST = $_REQUEST = [];
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        parent::tear_down();
    }

    protected function sent()
    {
        return tests_retrieve_phpmailer_instance()->mock_sent;
    }

    protected function id($email)
    {
        return (int)Subscribers::get_by_email($email)['id'];
    }

    public function test_error_log_preview_needs_privilege()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $_GET = ['mailoptin' => 'preview-campaign-error-log', 'id' => md5('1')];

        try {
            PreviewCampaignLog::get_instance()->preview_campaign_error_log();
            $this->fail('Expected wp_die.');
        } catch (WPDieException $e) {
            $this->assertSame('', $e->getMessage());
        }
    }

    public function test_error_logs_without_a_campaign_have_unguessable_names_and_a_protected_folder()
    {
        $name = AbstractConnect::campaign_error_log_filename(0, 12);

        $this->assertNotSame(md5('12'), $name);
        $this->assertSame($name, AbstractConnect::campaign_error_log_filename(0, 12));

        AbstractConnect::save_campaign_error_log('Email address: a@example.com; Note: test', 12, 0);

        $this->assertFileExists(MAILOPTIN_CAMPAIGN_ERROR_LOG . $name . '.log');
        $this->assertFileExists(MAILOPTIN_CAMPAIGN_ERROR_LOG . '.htaccess');
        $this->assertFileExists(MAILOPTIN_CAMPAIGN_ERROR_LOG . 'index.php');
    }

    public function test_forwarded_for_header_does_not_get_around_the_per_ip_limit()
    {
        foreach (range(1, 7) as $n) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = "203.0.113.$n";
            Subscription::subscribe("victim$n@example.com", $this->double, ['ip_address' => "203.0.113.$n"]);
        }

        $this->assertCount(5, $this->sent(), 'Only 5 confirmation emails per connecting IP per hour.');
        $this->assertSame('203.0.113.7', Subscribers::get_by_email('victim7@example.com')['ip_address'], 'The reported IP is still recorded.');
    }

    public function test_a_signup_does_not_overwrite_an_existing_name_or_consent()
    {
        Subscription::subscribe('ines@example.com', $this->single, ['first_name' => 'Ines', 'consent_text' => 'I agree.', 'consent_method' => 'checkbox']);
        Subscription::subscribe('ines@example.com', $this->double, ['first_name' => 'Call 555-0100 now', 'last_name' => 'Silva', 'consent_method' => 'none']);

        $subscriber = Subscribers::get_by_email('ines@example.com');
        $this->assertSame('Ines', $subscriber['first_name']);
        $this->assertSame('Silva', $subscriber['last_name'], 'Missing details are filled in.');
        $this->assertSame('I agree.', $subscriber['consent_text']);
        $this->assertSame('checkbox', $subscriber['consent_method']);
    }

    public function test_rejoining_a_list_after_unsubscribing_needs_confirmation_even_without_double_opt_in()
    {
        $other = ListsRepository::create(['name' => 'Offers', 'double_optin' => 0]);
        Subscription::subscribe('ines@example.com', $this->single, []);
        Subscription::subscribe('ines@example.com', $other, []);
        Subscription::unsubscribe($this->id('ines@example.com'), $this->single);

        $result = Subscription::subscribe('ines@example.com', $this->single, []);

        $this->assertSame(Subscription::RESULT_PENDING, $result['result']);
        $this->assertSame('pending', Subscribers::get_membership($this->id('ines@example.com'), $this->single)['status']);
        $sent = $this->sent();
        $this->assertSame('Please confirm your subscription', end($sent)['subject']);
    }

    public function test_a_form_signup_does_not_lift_an_unsubscribe_on_a_single_opt_in_list()
    {
        Subscription::subscribe('ines@example.com', $this->single, []);
        Subscription::unsubscribe($this->id('ines@example.com'));

        Subscription::subscribe('ines@example.com', $this->single, []);

        $this->assertTrue(SuppressionRepository::is_suppressed('ines@example.com'));
        $this->assertSame('pending', Subscribers::get_membership($this->id('ines@example.com'), $this->single)['status']);

        Subscription::confirm($this->id('ines@example.com'));

        $this->assertFalse(SuppressionRepository::is_suppressed('ines@example.com'), 'Confirming by email lifts it.');
        $this->assertSame('subscribed', Subscribers::get_by_email('ines@example.com')['status']);
    }

    public function test_complained_addresses_are_not_emailed_and_stay_suppressed()
    {
        Subscription::subscribe('angry@example.com', $this->single, []);
        Subscribers::update($this->id('angry@example.com'), ['status' => Subscribers::STATUS_COMPLAINED]);
        SuppressionRepository::add('angry@example.com', SuppressionRepository::REASON_COMPLAINED);
        reset_phpmailer_instance();

        $result = Subscription::subscribe('angry@example.com', $this->double, []);

        $this->assertSame(Subscription::RESULT_REFUSED, $result['result']);
        $this->assertCount(0, $this->sent());
        $this->assertSame('complained', Subscribers::get_by_email('angry@example.com')['status']);
    }

    public function test_confirming_does_not_lift_a_bounce()
    {
        Subscription::subscribe('ines@example.com', $this->double, []);
        SuppressionRepository::add('ines@example.com', SuppressionRepository::REASON_BOUNCED);

        Subscription::confirm($this->id('ines@example.com'));

        $this->assertSame(SuppressionRepository::REASON_BOUNCED, SuppressionRepository::get_reason('ines@example.com'));
    }

    public function test_a_bounce_replaces_an_earlier_unsubscribe()
    {
        SuppressionRepository::add('ines@example.com', SuppressionRepository::REASON_UNSUBSCRIBED);
        SuppressionRepository::add('ines@example.com', SuppressionRepository::REASON_BOUNCED);
        SuppressionRepository::add('ines@example.com', SuppressionRepository::REASON_UNSUBSCRIBED);

        $this->assertSame(SuppressionRepository::REASON_BOUNCED, SuppressionRepository::get_reason('ines@example.com'));
    }

    public function test_paused_welcome_email_stays_paused_when_people_join()
    {
        WelcomeEmail::save($this->single, true, 'Welcome', 'Hello');
        Subscription::subscribe('a@example.com', $this->single, []);

        $log_id = ListsRepository::get($this->single)['welcome_campaign_log_id'];
        QueueRunner::get_instance()->pause($log_id);

        Subscription::subscribe('b@example.com', $this->single, []);
        reset_phpmailer_instance();
        QueueRunner::get_instance()->run_all();

        $this->assertFalse(QueueRunner::get_instance()->is_sending($log_id));
        $this->assertCount(0, $this->sent());
        $this->assertSame(2, Queue::counts($log_id)[Queue::STATUS_PENDING], 'New joiners still wait in the queue.');
    }

    public function test_queued_email_skips_deleted_and_departed_subscribers()
    {
        $other = ListsRepository::create(['name' => 'Offers', 'double_optin' => 0]);
        WelcomeEmail::save($this->single, true, 'Welcome', 'Hello <a href="{{unsubscribe}}">u</a>');

        Subscription::subscribe('gone@example.com', $this->single, []);
        Subscription::subscribe('left@example.com', $this->single, []);
        Subscription::subscribe('left@example.com', $other, []);
        Subscription::subscribe('stays@example.com', $this->single, []);

        Subscribers::delete($this->id('gone@example.com'));
        Subscription::unsubscribe($this->id('left@example.com'), $this->single);

        reset_phpmailer_instance();
        QueueRunner::get_instance()->run_all();

        $this->assertSame(['stays@example.com'], array_map(function ($mail) {
            return $mail['to'][0][0];
        }, $this->sent()));

        $log_id = ListsRepository::get($this->single)['welcome_campaign_log_id'];
        $this->assertSame(2, Queue::counts($log_id)[Queue::STATUS_SKIPPED]);
    }

    public function test_cleanup_keeps_old_records_that_signed_up_again()
    {
        global $wpdb;

        Subscription::subscribe('back@example.com', $this->double, []);
        $wpdb->query($wpdb->prepare('UPDATE ' . Subscribers::table() . " SET created_at = '2020-01-01 00:00:00', updated_at = '2020-01-01 00:00:00' WHERE email = %s", 'back@example.com'));

        // Signs up again today.
        Subscription::subscribe('back@example.com', ListsRepository::create(['name' => 'Offers', 'double_optin' => 1]), []);

        Cron::get_instance()->cleanup_unconfirmed_subscribers();

        $this->assertNotNull(Subscribers::get_by_email('back@example.com'));
    }

    public function test_resend_does_not_retry_interrupted_sends()
    {
        global $wpdb;

        Queue::enqueue(99, 0, [['email' => 'x@example.com', 'recipient_type' => 'test'], ['email' => 'y@example.com', 'recipient_type' => 'test']]);
        Queue::claim(99, 'w', 2, 60);
        $wpdb->query('UPDATE ' . Queue::table() . " SET status = 'sending', claimed_until = '2000-01-01 00:00:00' WHERE email = 'x@example.com'");
        $wpdb->query('UPDATE ' . Queue::table() . " SET status = 'failed' WHERE email = 'y@example.com'");
        Queue::release_expired(99);

        $this->assertSame(1, Queue::retry_failed(99));
        $this->assertSame('failed', $wpdb->get_var('SELECT status FROM ' . Queue::table() . " WHERE email = 'x@example.com'"));
    }

    public function test_unsubscribe_link_for_a_deleted_list_unsubscribes_from_everything()
    {
        $other = ListsRepository::create(['name' => 'Offers', 'double_optin' => 0]);
        Subscription::subscribe('ines@example.com', $this->single, []);
        Subscription::subscribe('ines@example.com', $other, []);
        $subscriber = Subscribers::get_by_email('ines@example.com');
        $url        = Tokens::unsubscribe_url($subscriber, $this->single);

        ListsRepository::delete($this->single);

        parse_str(wp_parse_url($url, PHP_URL_QUERY), $query);
        $_GET = $_REQUEST = $query;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        try {
            PublicEndpoints::get_instance()->handle_request();
        } catch (WPDieException $e) {
        }

        $this->assertSame('unsubscribed', Subscribers::get_membership($subscriber['id'], $other)['status']);
        $this->assertTrue(SuppressionRepository::is_suppressed('ines@example.com'));
    }
}
