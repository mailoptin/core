<?php

namespace MailOptin\Tests\Core\Sending;

use MailOptin\Core\Admin\SettingsPage\SendingSettings;
use MailOptin\Core\EmailSenders\ConnectionResult;
use MailOptin\Core\EmailSenders\SenderInterface;
use MailOptin\Core\EmailSenders\SendResult;
use MailOptin\Core\EmailSenders\SiteMailer;
use MailOptin\Core\Sending\EmailLog;
use MailOptin\Core\Sending\Sending;
use MailOptin\Core\Sending\SendingStore;
use MailOptin\Core\Sending\SendingScreen;
use WP_UnitTestCase;

class Redirected extends \Exception
{
}

class FakeSiteSender implements SenderInterface
{
    public $sent = [];
    public $result;

    public function id()
    {
        return 'fake';
    }

    public function send(array $messages)
    {
        $out = [];
        foreach ($messages as $key => $message) {
            $this->sent[] = $message;
            $out[$key]    = $this->result ?: SendResult::sent('<id@example.com>');
        }

        return $out;
    }

    public function batch_size()
    {
        return 1;
    }

    public function max_per_second()
    {
        return null;
    }

    public function test_connection()
    {
        return new ConnectionResult(true, 'ok');
    }
}

class SendingTest extends WP_UnitTestCase
{
    /** @var Sending */
    protected $sending;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\DBUpdates::get_instance()->update_routine_26();
        EmailLog::delete_all();
        delete_option(SendingStore::CREDENTIALS_OPTION);
        delete_option(SendingStore::STATE_OPTION);
        delete_option(SiteMailer::OPTION);
        delete_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME);

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $this->sending = new Sending();

        add_filter('wp_redirect', function ($location) {
            throw new Redirected($location);
        });
    }

    public function tear_down()
    {
        remove_all_filters('pre_wp_mail');
        remove_all_filters('mailoptin_site_mail_sender');
        remove_all_filters('pre_http_request');
        remove_all_filters('wp_redirect');
        $_GET = [];
        parent::tear_down();
    }

    protected function choose($service, array $extra = [])
    {
        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, array_merge([SendingSettings::SERVICE_SETTING => $service], $extra));
    }

    public function test_credentials_leave_the_settings_and_are_stored_encrypted()
    {
        $data = $this->sending->take_credentials([
            'mo_sending_smtp_host'     => 'smtp://mail.example.com',
            'mo_sending_smtp_port'     => '587x',
            'mo_sending_smtp_username' => 'me@example.com',
            'mo_sending_smtp_password' => 'hunter2',
            'other_setting'            => 'kept',
        ], MAILOPTIN_SETTINGS_DB_OPTION_NAME);

        $this->assertSame(['other_setting' => 'kept'], $data);
        $this->assertStringNotContainsString('hunter2', (string)get_option(SendingStore::CREDENTIALS_OPTION));

        $smtp = SendingStore::credentials()['smtp'];
        $this->assertSame('mail.example.com', $smtp['host']);
        $this->assertSame('587', $smtp['port']);
        $this->assertSame('hunter2', $smtp['password']);
        $this->assertFalse(SendingStore::state()['connected']['smtp']);
    }

    public function test_a_blank_secret_keeps_the_saved_one_and_premium_credentials_survive()
    {
        SendingStore::save_credentials(['smtp' => ['host' => 'mail.example.com', 'password' => 'hunter2'], 'postmark' => ['server_token' => 'pm-token']]);
        SendingStore::update_state(['connected' => ['smtp' => true]]);

        $this->sending->take_credentials(['mo_sending_smtp_host' => 'mail.example.com', 'mo_sending_smtp_password' => ''], MAILOPTIN_SETTINGS_DB_OPTION_NAME);

        $credentials = SendingStore::credentials();
        $this->assertSame('hunter2', $credentials['smtp']['password']);
        $this->assertSame('pm-token', $credentials['postmark']['server_token']);
        // Nothing changed: still connected.
        $this->assertTrue(SendingStore::state()['connected']['smtp']);
    }

    public function test_a_new_app_drops_the_old_sign_in()
    {
        SendingStore::save_credentials(['gmail' => ['client_id' => 'old', 'client_secret' => 's', 'token' => ['access_token' => 'a', 'refresh_token' => 'r', 'email' => 'me@example.com']]]);

        $this->sending->take_credentials(['mo_sending_gmail_client_id' => 'new', 'mo_sending_gmail_client_secret' => ''], MAILOPTIN_SETTINGS_DB_OPTION_NAME);

        $gmail = SendingStore::credentials()['gmail'];
        $this->assertSame('new', $gmail['client_id']);
        $this->assertArrayNotHasKey('token', $gmail);
    }

    public function test_only_a_connected_free_service_is_active()
    {
        SendingStore::save_credentials(['smtp' => ['host' => 'mail.example.com']]);

        $this->choose('smtp');
        $this->assertSame('', SendingStore::active());

        SendingStore::update_state(['connected' => ['smtp' => true]]);
        $this->assertSame('smtp', SendingStore::active());

        $this->choose('postmark');
        $this->assertSame('', SendingStore::active());
    }

    public function test_credentials_from_old_security_keys_are_unreadable_and_dropped_on_save()
    {
        update_option(SendingStore::CREDENTIALS_OPTION, base64_encode(str_repeat('x', 64)));
        $this->assertTrue(SendingStore::credentials_unreadable());

        $this->sending->take_credentials(['mo_sending_smtp_host' => 'mail.example.com'], MAILOPTIN_SETTINGS_DB_OPTION_NAME);

        $this->assertFalse(SendingStore::credentials_unreadable());
        $this->assertSame('mail.example.com', SendingStore::credentials()['smtp']['host']);
    }

    public function test_turning_on_site_mail_takes_it_from_another_plugin_and_off_releases_it()
    {
        SiteMailer::claim('fusewp', 'FuseWP');

        $this->choose('smtp', [Sending::SITE_ENABLED => '1']);
        $this->sending->after_save([], MAILOPTIN_SETTINGS_DB_OPTION_NAME);

        $this->assertSame(Sending::OWNER, SiteMailer::owner());
        $this->assertSame('FuseWP', get_transient('mo_site_mail_taken_' . get_current_user_id()));

        $this->choose('smtp', [Sending::SITE_ENABLED => '']);
        $this->sending->after_save([], MAILOPTIN_SETTINGS_DB_OPTION_NAME);

        $this->assertSame('', SiteMailer::owner());
    }

    public function test_site_emails_go_through_the_service_and_are_logged()
    {
        SiteMailer::claim(Sending::OWNER, 'MailOptin');
        $this->choose('smtp', [Sending::SITE_FROM_EMAIL => 'shop@example.com']);

        $fake = new FakeSiteSender();
        add_filter('mailoptin_site_mail_sender', function () use ($fake) {
            return $fake;
        });
        new Sending();

        $this->assertTrue(wp_mail('a@example.com', 'Order received', 'Thanks'));
        $this->assertSame('shop@example.com', $fake->sent[0]->from->email);

        $fake->result = SendResult::failed('No such user');
        $this->assertFalse(wp_mail('b@example.com', 'Password reset', 'Hi'));

        $this->assertSame(2, EmailLog::count('all'));
        $this->assertSame(1, EmailLog::count('failed'));
        $rows = EmailLog::rows('all');
        $this->assertSame('b@example.com', $rows[0]['recipients']);
        $this->assertSame('No such user', $rows[0]['error']);
        $this->assertSame('Order received', $rows[1]['subject']);
    }

    public function test_sending_is_loaded_outside_admin_too()
    {
        global $wp_filter;

        // The tests boot as a front-end request: cron and front-end pages send email too.
        $this->assertFalse(is_admin());

        $found = false;
        foreach ($wp_filter['mo_send_queue_sender']->callbacks ?? [] as $callbacks) {
            foreach ($callbacks as $callback) {
                if (is_array($callback['function']) && $callback['function'][0] instanceof Sending && $callback['function'][0] !== $this->sending) $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function test_the_log_keeps_30_days()
    {
        global $wpdb;

        EmailLog::add(['to' => ['old@example.com'], 'subject' => 'Old', 'status' => 'sent']);
        EmailLog::add(['to' => ['new@example.com'], 'subject' => 'New', 'status' => 'fallback']);
        $wpdb->query("UPDATE " . EmailLog::table() . " SET sent_at = '2000-01-01 00:00:00' WHERE subject = 'Old'");

        EmailLog::purge();

        $rows = EmailLog::rows('all');
        $this->assertCount(1, $rows);
        $this->assertSame('New', $rows[0]['subject']);
        // A fallback counts as failed: the service refused it.
        $this->assertSame(1, EmailLog::count('failed'));
    }

    protected function start_sign_in($service = 'gmail', $user = null)
    {
        $state = 'mo' . str_repeat('a', 40);
        set_transient(Sending::OAUTH_TRANSIENT . $state, ['service' => $service, 'verifier' => 'v', 'user' => $user ?: get_current_user_id()], 900);
        SendingStore::save_credentials([$service => ['client_id' => 'cid', 'client_secret' => 'secret']]);

        return $state;
    }

    protected function run_callback()
    {
        try {
            $this->sending->oauth_callback();
        } catch (Redirected $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function test_sign_in_callback_ignores_unknown_state_and_other_users()
    {
        $_GET = ['state' => 'mo' . str_repeat('b', 40), 'code' => 'x'];
        $this->assertNull($this->run_callback());

        $other = self::factory()->user->create(['role' => 'administrator']);
        $state = $this->start_sign_in('gmail', $other);
        $_GET  = ['state' => $state, 'code' => 'x'];
        $this->assertNull($this->run_callback());
        // Used up all the same: it can't be replayed.
        $this->assertFalse(get_transient(Sending::OAUTH_TRANSIENT . $state));
    }

    public function test_sign_in_callback_reports_a_refusal()
    {
        $_GET = ['state' => $this->start_sign_in(), 'error' => 'access_denied'];

        $this->assertStringContainsString('mo_sending_notice=oauth_failed', $this->run_callback());
        $this->assertStringContainsString('access_denied', SendingStore::state()['tests']['gmail']['message']);
    }

    public function test_sign_in_callback_stores_the_token_and_tests_it()
    {
        $requests = [];
        add_filter('pre_http_request', function ($pre, $args, $url) use (&$requests) {
            $requests[] = [$url, $args['body']];
            $payload    = rtrim(strtr(base64_encode(wp_json_encode(['email' => 'me@example.com'])), '+/', '-_'), '=');

            return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => wp_json_encode([
                'access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600, 'id_token' => 'h.' . $payload . '.s',
            ]), 'cookies' => [], 'filename' => null];
        }, 10, 3);

        $_GET = ['state' => $this->start_sign_in(), 'code' => 'the-code'];
        // Nothing listens here, so the connection test fails, but the sign-in is kept.
        SendingStore::update_service_credentials('gmail', ['smtp' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none']]);

        $location = $this->run_callback();

        $this->assertStringContainsString('https://oauth2.googleapis.com/token', $requests[0][0]);
        $this->assertSame('v', is_array($requests[0][1]) ? $requests[0][1]['code_verifier'] : wp_parse_args($requests[0][1])['code_verifier']);
        $this->assertSame('me@example.com', SendingStore::account_email('gmail'));
        $this->assertSame('rt', SendingStore::credentials()['gmail']['token']['refresh_token']);
        $this->assertStringContainsString('mo_sending_notice=test_failed', $location);
    }

    public function test_the_screens_render()
    {
        SendingStore::save_credentials(['smtp' => ['host' => 'mail.example.com', 'password' => 'hunter2']]);
        $this->choose('smtp');
        EmailLog::add(['to' => ['a@example.com'], 'subject' => '<b>Hi</b>', 'status' => 'failed', 'error' => '<script>x</script>']);

        ob_start();
        SendingScreen::service_cards('smtp');
        $cards = ob_get_clean();

        $this->assertStringContainsString('name="mailoptin_settings[mo_sending_smtp_host]"', $cards);
        $this->assertStringContainsString('Saved. Leave blank to keep it.', $cards);
        $this->assertStringNotContainsString('hunter2', $cards);
        $this->assertStringContainsString(esc_html(Sending::redirect_uri()), $cards);

        $this->assertStringContainsString('mo-site-mail-enabled', SendingScreen::site_mail_html());

        $log = SendingScreen::email_log_html();
        $this->assertStringContainsString('a@example.com', $log);
        $this->assertStringNotContainsString('<script>x', $log);
    }
}
