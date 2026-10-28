<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\AsyncHandler\AsyncHandler;
use MailOptin\Core\AsyncHandler\BGProcess;
use MailOptin\Core\Audience\PublicEndpoints;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Audience\Tokens;
use MailOptin\Core\Connections\ConnectionFactory;
use MailOptin\Core\Core;
use MailOptin\Core\EmailCampaigns\NewPublishPost\NewPublishPost;
use MailOptin\Core\Repositories\ConnectionsRepository;
use MailOptin\Core\Repositories\EmailCampaignRepository;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SendQueueRepository as Queue;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\SendQueue\QueueRunner;
use MailOptin\Core\SendQueue\WpMailSender;
use WP_UnitTestCase;
use WPDieException;

class BuiltInListsTest extends WP_UnitTestCase
{
    protected $double_optin_list;
    protected $single_optin_list;

    public function set_up()
    {
        parent::set_up();

        // tests/bootstrap.php boots the plugin after plugins_loaded has fired, so do what those hooks would have done.
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        if (is_null(AsyncHandler::get_instance()->bg_process)) {
            AsyncHandler::get_instance()->bg_process = new BGProcess();
        }

        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        // The email campaign meta table is only created by an old DB update the bootstrap doesn't run.
        \MailOptin\Core\DBUpdates::get_instance()->update_routine_7();

        reset_phpmailer_instance();

        $this->double_optin_list = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 1]);
        $this->single_optin_list = ListsRepository::create(['name' => 'Workshops', 'double_optin' => 0]);
    }

    public function tear_down()
    {
        $_GET = $_POST = $_REQUEST = [];
        unset($_SERVER['REQUEST_METHOD']);

        parent::tear_down();
    }

    protected function sent_mail()
    {
        return tests_retrieve_phpmailer_instance()->mock_sent;
    }

    protected function connection()
    {
        return ConnectionFactory::make('MailOptinConnect');
    }

    protected function call_endpoint($query, $method = 'GET')
    {
        $_GET                      = $query;
        $_POST                     = [];
        $_REQUEST                  = $query;
        $_SERVER['REQUEST_METHOD'] = $method;

        try {
            PublicEndpoints::get_instance()->handle_request();
        } catch (WPDieException $e) {
            return html_entity_decode($e->getMessage(), ENT_QUOTES);
        }

        return null;
    }

    protected function query_from_url($url)
    {
        parse_str(wp_parse_url(html_entity_decode($url), PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_connection_is_available_for_forms_and_email_campaigns()
    {
        $this->assertArrayHasKey('MailOptinConnect', ConnectionsRepository::get_connections(ConnectionsRepository::OPTIN_CAMPAIGN_TYPE));
        $this->assertArrayHasKey('MailOptinConnect', ConnectionsRepository::get_connections(ConnectionsRepository::EMAIL_CAMPAIGN_TYPE));

        $this->assertSame(
            [$this->double_optin_list => 'Newsletter', $this->single_optin_list => 'Workshops'],
            $this->connection()->get_email_list()
        );
    }

    public function test_single_opt_in_subscribes_straight_away()
    {
        $response = $this->connection()->subscribe('Amara.O@Example.com', 'Amara Okafor', $this->single_optin_list, [
            'optin_campaign_id' => 14,
            'mo_ip_address'     => '203.0.113.57',
            'conversion_page'   => 'https://example.org/blog/pruning/',
        ]);

        $this->assertTrue($response['success']);

        $subscriber = Subscribers::get_by_email('amara.o@example.com');
        $this->assertSame('subscribed', $subscriber['status']);
        $this->assertSame('Amara', $subscriber['first_name']);
        $this->assertSame('Okafor', $subscriber['last_name']);
        $this->assertSame('203.0.113.57', $subscriber['ip_address']);
        $this->assertSame('14', $subscriber['source_optin_id']);
        $this->assertSame('subscribed', Subscribers::get_membership($subscriber['id'], $this->single_optin_list)['status']);
        $this->assertEmpty($this->sent_mail());
    }

    public function test_double_opt_in_sends_one_confirmation_and_waits()
    {
        $this->connection()->subscribe('tom@example.com', 'Tom Becker', $this->double_optin_list, []);
        $this->connection()->subscribe('tom@example.com', 'Tom Becker', $this->double_optin_list, []);

        $subscriber = Subscribers::get_by_email('tom@example.com');
        $this->assertSame('pending', $subscriber['status']);

        $mail = $this->sent_mail();
        $this->assertCount(1, $mail, 'A second signup inside the cooldown must not send another email.');
        $this->assertSame('Please confirm your subscription', $mail[0]['subject']);
        $this->assertStringContainsString('Newsletter', $mail[0]['body']);
        $this->assertStringContainsString('mo_confirm=' . $subscriber['id'], html_entity_decode($mail[0]['body']));
    }

    public function test_confirm_link_subscribes_and_bad_token_is_rejected()
    {
        $this->connection()->subscribe('tom@example.com', 'Tom', $this->double_optin_list, []);
        $subscriber = Subscribers::get_by_email('tom@example.com');

        $bad = $this->call_endpoint(['mo_confirm' => $subscriber['id'], 'mo_token' => str_repeat('a', 64)]);
        $this->assertStringContainsString('invalid', $bad);
        $this->assertSame('pending', Subscribers::get($subscriber['id'])['status']);

        $page = $this->call_endpoint($this->query_from_url(Tokens::confirm_url($subscriber)));
        $this->assertStringContainsString("You're subscribed", $page);

        $subscriber = Subscribers::get($subscriber['id']);
        $this->assertSame('subscribed', $subscriber['status']);
        $this->assertNotEmpty($subscriber['confirmed_at']);
        $this->assertSame('subscribed', Subscribers::get_membership($subscriber['id'], $this->double_optin_list)['status']);
    }

    public function test_confirmed_subscriber_joins_another_double_opt_in_list_without_confirming_again()
    {
        $other_list = ListsRepository::create(['name' => 'Wholesale', 'double_optin' => 1]);

        $this->connection()->subscribe('tom@example.com', 'Tom', $this->double_optin_list, []);
        Subscription::confirm(Subscribers::get_by_email('tom@example.com')['id']);
        reset_phpmailer_instance();

        $this->connection()->subscribe('tom@example.com', 'Tom', $other_list, []);

        $subscriber = Subscribers::get_by_email('tom@example.com');
        $this->assertSame('subscribed', Subscribers::get_membership($subscriber['id'], $other_list)['status']);
        $this->assertEmpty($this->sent_mail());
    }

    public function test_unsubscribe_needs_a_post_and_suppresses_when_no_lists_remain()
    {
        $this->connection()->subscribe('amara@example.com', 'Amara', $this->single_optin_list, []);
        $subscriber = Subscribers::get_by_email('amara@example.com');

        $query = $this->query_from_url(Tokens::unsubscribe_url($subscriber, $this->single_optin_list));

        $page = $this->call_endpoint($query);
        $this->assertStringContainsString('<form method="post"', $page);
        $this->assertSame('subscribed', Subscribers::get($subscriber['id'])['status'], 'Opening the link must not unsubscribe.');

        $page = $this->call_endpoint($query, 'POST');
        $this->assertStringContainsString("You've been unsubscribed", $page);
        $this->assertSame('unsubscribed', Subscribers::get($subscriber['id'])['status']);
        $this->assertTrue(SuppressionRepository::is_suppressed('amara@example.com'));
    }

    public function test_unsubscribing_from_one_list_keeps_the_others()
    {
        $this->connection()->subscribe('amara@example.com', 'Amara', $this->single_optin_list, []);
        $other_list = ListsRepository::create(['name' => 'Wholesale', 'double_optin' => 0]);
        $this->connection()->subscribe('amara@example.com', 'Amara', $other_list, []);

        $subscriber = Subscribers::get_by_email('amara@example.com');
        Subscription::unsubscribe($subscriber['id'], $this->single_optin_list);

        $this->assertSame('subscribed', Subscribers::get($subscriber['id'])['status']);
        $this->assertSame('unsubscribed', Subscribers::get_membership($subscriber['id'], $this->single_optin_list)['status']);
        $this->assertFalse(SuppressionRepository::is_suppressed('amara@example.com'));
    }

    public function test_unsubscribed_person_who_signs_up_again_must_confirm()
    {
        $this->connection()->subscribe('amara@example.com', 'Amara', $this->double_optin_list, []);
        $subscriber = Subscribers::get_by_email('amara@example.com');
        Subscription::confirm($subscriber['id']);
        Subscription::unsubscribe($subscriber['id']);

        $this->connection()->subscribe('amara@example.com', 'Amara', $this->double_optin_list, []);
        $this->assertSame('pending', Subscribers::get($subscriber['id'])['status']);
        $this->assertTrue(SuppressionRepository::is_suppressed('amara@example.com'));

        Subscription::confirm($subscriber['id']);
        $this->assertSame('subscribed', Subscribers::get($subscriber['id'])['status']);
        $this->assertFalse(SuppressionRepository::is_suppressed('amara@example.com'));
    }

    public function test_invalid_list_fails_without_saving()
    {
        $response = $this->connection()->subscribe('amara@example.com', 'Amara', 99999, []);

        $this->assertFalse($response['success']);
        $this->assertNull(Subscribers::get_by_email('amara@example.com'));
    }

    protected function create_newsletter()
    {
        global $wpdb;

        $email_campaign_id = EmailCampaignRepository::add_email_campaign('November Planting Notes', EmailCampaignRepository::NEWSLETTER, 'Lucid');

        $settings                     = EmailCampaignRepository::get_settings();
        $settings[$email_campaign_id] = [
            'connection_service'    => 'MailOptinConnect',
            'connection_email_list' => $this->single_optin_list,
        ];
        EmailCampaignRepository::updateSettings($settings);

        $wpdb->insert($wpdb->prefix . Core::campaign_log_table_name, [
            'email_campaign_id' => $email_campaign_id,
            'title'             => 'November Planting Notes',
            'content_html'      => '<p>Hi {{subscriber.first_name}}</p><a href="{{unsubscribe}}">Unsubscribe</a> <a href="{{webversion}}">View online</a>',
            'content_text'      => 'Hi {{subscriber.first_name}}. Unsubscribe: {{unsubscribe}}',
            'status'            => 'draft',
        ]);

        return [$email_campaign_id, $wpdb->insert_id];
    }

    protected function log_status($campaign_log_id)
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}mo_campaign_log WHERE id = %d", $campaign_log_id));
    }

    public function test_newsletter_sends_personalized_email_to_subscribed_members_only()
    {
        foreach (['Amara' => 'amara@example.com', 'Priya' => 'priya@example.com', 'Mei' => 'mei@example.com'] as $name => $email) {
            $this->connection()->subscribe($email, $name, $this->single_optin_list, []);
        }

        $this->connection()->subscribe('johan@example.com', 'Johan', $this->single_optin_list, []);
        Subscription::unsubscribe(Subscribers::get_by_email('johan@example.com')['id']);

        // Pending on the double opt-in list only, so not a member of the newsletter's list.
        $this->connection()->subscribe('tom@example.com', 'Tom', $this->double_optin_list, []);

        list($email_campaign_id, $campaign_log_id) = $this->create_newsletter();

        NewPublishPost::get_instance()->send_campaign($email_campaign_id, $campaign_log_id);

        $this->assertSame('sending', $this->log_status($campaign_log_id), 'The trigger must not mark a queued campaign processed.');
        $this->assertSame(3, Queue::counts($campaign_log_id)['pending']);

        reset_phpmailer_instance();
        QueueRunner::get_instance()->process($campaign_log_id);

        $mail = $this->sent_mail();
        $this->assertCount(3, $mail);
        $this->assertSame('processed', $this->log_status($campaign_log_id));

        $amara = null;
        foreach ($mail as $message) {
            if ('amara@example.com' === $message['to'][0][0]) $amara = $message;
        }

        $this->assertNotNull($amara);
        $this->assertStringContainsString('<p>Hi Amara</p>', $amara['body']);
        $this->assertStringContainsString('mo_unsub=', $amara['body']);
        $this->assertStringContainsString('mo_web=' . $campaign_log_id, $amara['body']);
        // The test mailer uses PHP mail(), so the one-click headers are dropped rather than sent corrupted.
        $this->assertStringNotContainsString('List-Unsubscribe', $amara['header']);
        $this->assertNotEmpty(get_option(WpMailSender::ONE_CLICK_UNAVAILABLE_OPTION));

        $subscriber = Subscribers::get_by_email('amara@example.com');
        $this->assertSame('sent', Subscribers::get_events($subscriber['id'], 1)[0]['type']);
    }

    public function test_one_click_unsubscribe_headers_are_sent_intact_over_smtp()
    {
        $this->connection()->subscribe('amara@example.com', 'Amara', $this->single_optin_list, []);

        list($email_campaign_id, $campaign_log_id) = $this->create_newsletter();
        NewPublishPost::get_instance()->send_campaign($email_campaign_id, $campaign_log_id);

        // What an SMTP plugin does.
        add_action('phpmailer_init', function ($phpmailer) {
            $phpmailer->isSMTP();
        }, 1);

        reset_phpmailer_instance();
        QueueRunner::get_instance()->process($campaign_log_id);

        $header = $this->sent_mail()[0]['header'];

        $this->assertMatchesRegularExpression('#List-Unsubscribe: <http://example\.org/\?mo_unsub=\d+&mo_list=\d+&mo_cl=\d+&mo_token=[a-f0-9]{64}>#', $header);
        $this->assertStringContainsString('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $header);
        $this->assertStringNotContainsString('=?us-ascii?Q?', $header);
    }

    public function test_unsubscribing_mid_send_cancels_their_email()
    {
        $this->connection()->subscribe('amara@example.com', 'Amara', $this->single_optin_list, []);
        $this->connection()->subscribe('priya@example.com', 'Priya', $this->single_optin_list, []);

        list($email_campaign_id, $campaign_log_id) = $this->create_newsletter();
        NewPublishPost::get_instance()->send_campaign($email_campaign_id, $campaign_log_id);

        $priya = Subscribers::get_by_email('priya@example.com');
        Subscription::unsubscribe($priya['id'], $this->single_optin_list, $campaign_log_id);

        reset_phpmailer_instance();
        QueueRunner::get_instance()->process($campaign_log_id);

        $this->assertCount(1, $this->sent_mail());
        $this->assertSame(1, Queue::counts($campaign_log_id)['skipped']);
    }

    public function test_resend_retries_failures_without_emailing_anyone_twice()
    {
        $this->connection()->subscribe('amara@example.com', 'Amara', $this->single_optin_list, []);
        $this->connection()->subscribe('priya@example.com', 'Priya', $this->single_optin_list, []);

        list($email_campaign_id, $campaign_log_id) = $this->create_newsletter();
        NewPublishPost::get_instance()->send_campaign($email_campaign_id, $campaign_log_id);

        $fail_priya = function ($null, $atts) {
            return 'priya@example.com' === $atts['to'] ? false : null;
        };
        add_filter('pre_wp_mail', $fail_priya, 10, 2);
        add_filter('mo_send_queue_max_attempts', '__return_zero');

        QueueRunner::get_instance()->process($campaign_log_id);
        $this->assertSame(1, Queue::counts($campaign_log_id)['failed']);

        remove_filter('pre_wp_mail', $fail_priya, 10);
        reset_phpmailer_instance();

        NewPublishPost::get_instance()->send_campaign($email_campaign_id, $campaign_log_id);
        QueueRunner::get_instance()->process($campaign_log_id);

        $mail = $this->sent_mail();
        $this->assertCount(1, $mail);
        $this->assertSame('priya@example.com', $mail[0]['to'][0][0]);
        $this->assertSame(2, Queue::counts($campaign_log_id)['sent']);
    }

    public function test_web_version_has_no_personal_links()
    {
        $this->connection()->subscribe('amara@example.com', 'Amara', $this->single_optin_list, []);

        list($email_campaign_id, $campaign_log_id) = $this->create_newsletter();
        NewPublishPost::get_instance()->send_campaign($email_campaign_id, $campaign_log_id);

        $html = PublicEndpoints::web_version_html($campaign_log_id);

        $this->assertStringContainsString('<p>Hi </p>', $html);
        $this->assertStringNotContainsString('{{unsubscribe}}', $html);
        $this->assertStringNotContainsString('mo_unsub=', $html);

        $this->assertStringContainsString('invalid', $this->call_endpoint(['mo_web' => $campaign_log_id, 'mo_token' => 'nope']));
    }

    public function test_newsletter_to_a_deleted_list_fails()
    {
        list($email_campaign_id, $campaign_log_id) = $this->create_newsletter();
        ListsRepository::delete($this->single_optin_list);

        NewPublishPost::get_instance()->send_campaign($email_campaign_id, $campaign_log_id);

        $this->assertSame('failed', $this->log_status($campaign_log_id));
    }
}
