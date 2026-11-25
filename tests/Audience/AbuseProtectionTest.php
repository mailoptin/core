<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\AjaxHandler;
use MailOptin\Core\Audience\ConfirmationThrottle;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Cron;
use MailOptin\Core\OptinForms\ConversionDataBuilder;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use WP_UnitTestCase;

class AbuseProtectionTest extends WP_UnitTestCase
{
    protected $list_id;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\Base::get_instance()->register_metadata_table();

        reset_phpmailer_instance();

        $this->list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 1]);
    }

    protected function sent_count()
    {
        return count(tests_retrieve_phpmailer_instance()->mock_sent);
    }

    protected function subscribe($email, $ip = '198.51.100.7')
    {
        // The rate limits use the connection's address; the form's reported IP is only recorded.
        $_SERVER['REMOTE_ADDR'] = $ip;

        return Subscription::subscribe($email, $this->list_id, ['ip_address' => $ip]);
    }

    protected function last_throttle_reason($email)
    {
        $subscriber = Subscribers::get_by_email($email);

        foreach (Subscribers::get_events($subscriber['id']) as $event) {
            if (Subscribers::EVENT_CONFIRMATION_THROTTLED === $event['type']) {
                return json_decode($event['meta'], true)['reason'];
            }
        }

        return null;
    }

    public function test_one_address_gets_at_most_three_confirmations_a_day()
    {
        add_filter('mo_confirmation_email_cooldown', '__return_zero');

        for ($i = 0; $i < 4; $i++) {
            // A different IP each time, as a bot rotating addresses would use.
            $this->subscribe('victim@example.com', '198.51.100.' . ($i + 1));
        }

        $this->assertSame(3, $this->sent_count());
        $this->assertSame(ConfirmationThrottle::REASON_ADDRESS_DAILY, $this->last_throttle_reason('victim@example.com'));
        $this->assertSame('pending', Subscribers::get_by_email('victim@example.com')['status']);
    }

    public function test_one_ip_triggers_at_most_five_confirmations_an_hour()
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->subscribe("person$i@example.com", '203.0.113.9');
        }

        $this->assertSame(5, $this->sent_count());
        $this->assertSame(ConfirmationThrottle::REASON_IP_HOURLY, $this->last_throttle_reason('person6@example.com'));
    }

    public function test_site_wide_hourly_limit_keeps_the_signup_and_flags_it_for_the_admin()
    {
        add_filter('mo_confirmation_email_hourly_limit', function () {
            return 2;
        });

        for ($i = 1; $i <= 3; $i++) {
            $this->subscribe("person$i@example.com", "198.51.100.$i");
        }

        $this->assertSame(2, $this->sent_count());
        $this->assertSame(ConfirmationThrottle::REASON_SITE_HOURLY, $this->last_throttle_reason('person3@example.com'));
        $this->assertSame('pending', Subscribers::get_by_email('person3@example.com')['status']);
        $this->assertNotEmpty(get_option(ConfirmationThrottle::SITE_LIMIT_REACHED_OPTION));
    }

    public function test_admin_resend_skips_the_limits()
    {
        $this->subscribe('tom@example.com');
        $subscriber = Subscribers::get_by_email('tom@example.com');

        // Inside the cooldown, an automatic resend is refused but an admin's is not.
        $this->assertFalse(Subscription::send_confirmation($subscriber['id'], '198.51.100.7'));
        $this->assertTrue(Subscription::send_confirmation($subscriber['id'], '', true));
        $this->assertSame(2, $this->sent_count());
    }

    public function test_cleanup_deletes_only_old_unconfirmed_signups()
    {
        global $wpdb;

        $this->subscribe('old-pending@example.com');
        $this->subscribe('new-pending@example.com', '198.51.100.8');
        $this->subscribe('old-confirmed@example.com', '198.51.100.9');
        Subscription::confirm(Subscribers::get_by_email('old-confirmed@example.com')['id']);

        $table = Subscribers::table();
        $wpdb->query("UPDATE $table SET created_at = '2020-01-01 00:00:00', updated_at = '2020-01-01 00:00:00' WHERE email IN ('old-pending@example.com', 'old-confirmed@example.com')");

        $old_pending_id = Subscribers::get_by_email('old-pending@example.com')['id'];

        $this->assertSame(1, Cron::get_instance()->cleanup_unconfirmed_subscribers());

        $this->assertNull(Subscribers::get_by_email('old-pending@example.com'));
        $this->assertEmpty(Subscribers::get_memberships($old_pending_id));
        $this->assertEmpty(Subscribers::get_events($old_pending_id));
        $this->assertNotNull(Subscribers::get_by_email('new-pending@example.com'));
        $this->assertNotNull(Subscribers::get_by_email('old-confirmed@example.com'));
    }

    public function test_existing_form_honeypot_still_rejects_bots()
    {
        $builder                    = new ConversionDataBuilder();
        $builder->optin_campaign_id = 1;
        $builder->email             = 'bot@example.com';
        $builder->payload           = ['email' => 'bot@example.com', 'mo-hp-website' => 'http://spam.example'];

        $response = AjaxHandler::do_optin_conversion($builder);

        $this->assertFalse($response['success']);
        $this->assertNull(Subscribers::get_by_email('bot@example.com'));
    }
}
