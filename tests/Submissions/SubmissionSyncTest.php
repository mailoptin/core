<?php

namespace MailOptin\FakeSyncConnect {

    use MailOptin\Core\Connections\AbstractConnect;

    /**
     * Test integration. Fails with an API error until $accept is set.
     */
    class Connect extends AbstractConnect
    {
        public static $accept = false;
        public static $throw = false;
        public static $calls = [];

        public static function get_instance()
        {
            static $instance = null;

            return $instance ?: $instance = new self();
        }

        public function subscribe($email, $name, $list_id, $extras = null)
        {
            self::$calls[] = ['email' => $email, 'list' => $list_id, 'ip' => $extras['mo_ip_address'] ?? '', 'ip_lookup' => \MailOptin\Core\get_ip_address(), 'company' => $extras['company'] ?? ''];

            if (self::$throw) throw new \RuntimeException('Fatal in /var/www/html/wp-content/plugins/sdk/Client.php line 42');

            if (self::$accept) return self::ajax_success();

            self::save_optin_error_log('401: API key is invalid or revoked', 'fakesync', $extras['optin_campaign_id'] ?? null);

            return self::ajax_failure();
        }

        public function get_email_list()
        {
            return [];
        }

        public function send_newsletter($email_campaign_id, $campaign_log_id, $subject, $content_html, $content_text)
        {
        }

        public function replace_placeholder_tags($content, $type = 'html')
        {
            return $content;
        }

        public static function features_support()
        {
            return [self::OPTIN_CAMPAIGN_SUPPORT];
        }
    }
}

namespace MailOptin\Tests\Core\Submissions {

    use MailOptin\Core\AjaxHandler;
    use MailOptin\Core\OptinForms\ConversionDataBuilder;
    use MailOptin\Core\Repositories\ConversionSyncRepository as Syncs;
    use MailOptin\Core\Repositories\ListsRepository;
    use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;
    use MailOptin\Core\Repositories\OptinConversionsRepository;
    use MailOptin\Core\Submissions\SubmissionSync;
    use MailOptin\FakeSyncConnect\Connect as Fake;
    use WP_UnitTestCase;

    class SubmissionSyncTest extends WP_UnitTestCase
    {
        protected $list_id;

        public function set_up()
        {
            parent::set_up();

            \MailOptin\Core\Base::get_instance()->register_metadata_table();

            // The test install skips the DB updates that add these columns to the conversions table.
            global $wpdb;
            $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . $wpdb->prefix . 'mo_conversions');
            if ( ! in_array('custom_fields', $columns, true)) \MailOptin\Core\DBUpdates::get_instance()->update_routine_9();
            if ( ! in_array('meta_data', $columns, true)) \MailOptin\Core\DBUpdates::get_instance()->update_routine_15();

            // The test install doesn't run DB updates, so record that the sync table's one has.
            update_option('mo_db_ver', \MailOptin\Core\DBUpdates::DB_VER);

            add_filter('pre_http_request', function () {
                return ['response' => ['code' => 200], 'body' => ''];
            });
            add_filter('mailoptin_disable_send_optin_error_email', '__return_true');

            // Hooks are reset between tests; the instance is only constructed once.
            add_action('mo_optin_error_logged', [SubmissionSync::class, 'capture_error']);

            Fake::$accept = false;
            Fake::$throw  = false;
            Fake::$calls  = [];
            $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

            $this->list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 0]);
        }

        protected function form()
        {
            $id = OCR::add_optin_campaign(wp_generate_password(10, false), 'Form ' . wp_generate_password(6, false), 'BareMetal', 'inpost');

            $all      = OCR::get_settings();
            $all[$id] = ['integrations' => wp_json_encode([
                ['connection_service' => 'FakeSyncConnect', 'connection_email_list' => 'abc123'],
                ['connection_service' => 'MailOptinConnect', 'connection_email_list' => $this->list_id],
            ])];
            OCR::updateSettings($all);

            return $id;
        }

        protected function submit($form_id, $email = 'maria@example.net')
        {
            $builder                            = new ConversionDataBuilder();
            $builder->payload                   = ['email' => $email, 'name' => 'Maria Costa', 'company' => 'Costa Gardens', 'optin_campaign_id' => $form_id, 'account_password' => 'hunter2', 'g-recaptcha-response' => 'abc'];
            $builder->optin_campaign_id         = $form_id;
            $builder->email                     = $email;
            $builder->name                      = 'Maria Costa';
            $builder->conversion_page           = 'https://example.org/newsletter/';
            $builder->is_timestamp_check_active = false;

            return AjaxHandler::do_optin_conversion($builder);
        }

        protected function conversion_id($email = 'maria@example.net')
        {
            return (int)OptinConversionsRepository::get_conversions_by_email($email)[0]['id'];
        }

        protected function sync_for($service)
        {
            return wp_list_filter(Syncs::for_conversion($this->conversion_id()), ['connection_service' => $service]) ? array_values(wp_list_filter(Syncs::for_conversion($this->conversion_id()), ['connection_service' => $service]))[0] : null;
        }

        public function test_each_integration_send_is_recorded_with_the_real_error()
        {
            $response = $this->submit($this->form());

            $this->assertTrue($response['success'], 'One integration accepted it, so the visitor sees success.');

            $fake = $this->sync_for('FakeSyncConnect');
            $this->assertSame(Syncs::STATUS_RETRYING, $fake['status']);
            $this->assertSame('401: API key is invalid or revoked', $fake['last_error']);
            $this->assertSame('abc123', $fake['connection_email_list']);
            $this->assertNotNull($fake['next_attempt_at']);
            $this->assertNotEmpty($fake['retry_data']);

            $built_in = $this->sync_for('MailOptinConnect');
            $this->assertSame(Syncs::STATUS_SENT, $built_in['status']);
            $this->assertNull($built_in['retry_data'], 'Form data is not kept once it has been sent.');
        }

        public function test_automatic_retries_follow_the_schedule_then_give_up()
        {
            $this->submit($this->form());
            $sync = $this->sync_for('FakeSyncConnect');

            $this->assertSame(0, SubmissionSync::run_due(), 'Nothing is due yet.');

            global $wpdb;
            foreach ([2, 3, 4] as $attempt) {
                $wpdb->query($wpdb->prepare('UPDATE ' . Syncs::table() . " SET next_attempt_at = '2000-01-01 00:00:00' WHERE id = %d", $sync['id']));
                $this->assertSame(1, SubmissionSync::run_due());
                $this->assertSame($attempt, (int)Syncs::get($sync['id'])['attempts']);
            }

            $sync = Syncs::get($sync['id']);
            $this->assertSame(Syncs::STATUS_FAILED, $sync['status'], 'Three automatic retries, then it waits for a person.');
            $this->assertNull($sync['next_attempt_at']);
            $this->assertNotEmpty($sync['retry_data'], 'It can still be retried by hand.');
        }

        public function test_retry_resends_the_original_submission_and_marks_it_sent()
        {
            $this->submit($this->form());
            $sync = $this->sync_for('FakeSyncConnect');

            Fake::$accept = true;
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; // cron runs from the server

            $this->assertTrue(SubmissionSync::retry($sync['id'], true));

            $call = end(Fake::$calls);
            $this->assertSame('maria@example.net', $call['email']);
            $this->assertSame('abc123', $call['list']);
            $this->assertSame('Costa Gardens', $call['company'], 'Custom fields go along.');
            $this->assertNotSame('127.0.0.1', $call['ip'], "The visitor's IP is sent, not the server's.");

            $sync = Syncs::get($sync['id']);
            $this->assertSame(Syncs::STATUS_SENT, $sync['status']);
            $this->assertNull($sync['retry_data']);
            $this->assertInstanceOf(\WP_Error::class, SubmissionSync::retry($sync['id'], true), 'A sent submission is not sent again.');
        }

        public function test_a_retry_that_is_already_running_is_not_sent_twice()
        {
            $this->submit($this->form());
            $sync = $this->sync_for('FakeSyncConnect');

            global $wpdb;
            $wpdb->query($wpdb->prepare('UPDATE ' . Syncs::table() . " SET next_attempt_at = '2000-01-01 00:00:00' WHERE id = %d", $sync['id']));

            $this->assertTrue(Syncs::claim($sync['id']));
            $this->assertFalse(Syncs::claim($sync['id']), 'A second cron run gets nothing.');
        }

        public function test_deleting_a_submission_deletes_its_sync_history()
        {
            $this->submit($this->form());
            $id = $this->conversion_id();

            OptinConversionsRepository::delete($id);

            $this->assertSame([], Syncs::for_conversion($id));
        }

        public function test_a_row_being_sent_cannot_be_claimed_again()
        {
            $this->submit($this->form());
            $sync = $this->sync_for('FakeSyncConnect');

            global $wpdb;
            $wpdb->query($wpdb->prepare('UPDATE ' . Syncs::table() . " SET next_attempt_at = '2000-01-01 00:00:00' WHERE id = %d", $sync['id']));

            $this->assertTrue(Syncs::claim($sync['id']), 'The cron job takes it.');
            $this->assertFalse(Syncs::claim($sync['id'], true), 'An admin retry while cron is sending it is refused.');
            $this->assertFalse(Syncs::schedule_now($sync['id']), '"Retry all" leaves it alone too.');

            Syncs::record_attempt($sync['id'], false, 'still down', null);
            $this->assertTrue(Syncs::claim($sync['id'], true), 'Released once the attempt is recorded.');
            $this->assertFalse(Syncs::claim($sync['id'], true), 'And a second admin click is refused.');
        }

        public function test_an_exception_shows_the_visitor_the_generic_message_only()
        {
            Fake::$throw = true;

            $builder                            = new ConversionDataBuilder();
            $builder->payload                   = ['email' => 'x@example.net'];
            $builder->email                     = 'x@example.net';
            $builder->is_timestamp_check_active = false;
            $id = OptinConversionsRepository::add(['email' => 'x@example.net']);

            $response = SubmissionSync::send($id, 'FakeSyncConnect', 'abc123', $builder);

            $this->assertFalse($response['success']);
            $this->assertStringNotContainsString('/var/www', $response['message']);
            $this->assertStringContainsString('/var/www', Syncs::for_conversion($id)[0]['last_error'], 'The admin still sees the details.');
        }

        public function test_retry_gives_integrations_the_visitors_ip()
        {
            $this->submit($this->form());
            $sync = $this->sync_for('FakeSyncConnect');

            Fake::$accept = true;
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            SubmissionSync::retry($sync['id'], true);

            $this->assertSame('203.0.113.9', end(Fake::$calls)['ip_lookup']);
            $this->assertSame('127.0.0.1', \MailOptin\Core\get_ip_address(), 'Only during the retry.');
        }

        public function test_sensitive_fields_are_never_stored()
        {
            $this->submit($this->form());
            $data = json_decode($this->sync_for('FakeSyncConnect')['retry_data'], true);

            $this->assertArrayNotHasKey('account_password', $data['payload']);
            $this->assertArrayNotHasKey('g-recaptcha-response', $data['payload']);
            $this->assertSame('Costa Gardens', $data['payload']['company']);
            $this->assertArrayHasKey('integration_data', $data['payload'], 'The form\'s own settings are kept.');
        }

        public function test_a_retry_that_is_the_first_success_counts_the_conversion()
        {
            $id = OptinConversionsRepository::add(['email' => 'solo@example.net', 'optin_campaign_id' => 0]);
            $builder        = new ConversionDataBuilder();
            $builder->email = 'solo@example.net';
            SubmissionSync::send($id, 'FakeSyncConnect', 'abc123', $builder);

            $tracked = [];
            add_action('mailoptin_track_conversions', function ($lead) use (&$tracked) {
                $tracked[] = $lead['email'];
            });

            Fake::$accept = true;
            SubmissionSync::retry(Syncs::for_conversion($id)[0]['id'], true);

            $this->assertSame(['solo@example.net'], $tracked);
        }

        public function test_retry_data_expires_and_erasure_clears_the_error()
        {
            $this->submit($this->form());
            $sync = $this->sync_for('FakeSyncConnect');

            global $wpdb;
            $wpdb->query($wpdb->prepare('UPDATE ' . Syncs::table() . " SET created_at = '2020-01-01 00:00:00' WHERE id = %d", $sync['id']));
            $this->assertSame(1, SubmissionSync::expire_retry_data());

            $sync = Syncs::get($sync['id']);
            $this->assertNull($sync['retry_data']);
            $this->assertSame(Syncs::STATUS_FAILED, $sync['status']);
            $this->assertSame(0, Syncs::count_unsent_submissions(), 'Nothing left that can be resent, so no alert.');

            Syncs::forget_retry_data([$sync['conversion_id']]);
            $this->assertNull(Syncs::get($sync['id'])['last_error']);
        }

        public function test_nothing_is_tracked_before_the_db_update_has_run()
        {
            update_option('mo_db_ver', 18);

            $response = $this->submit($this->form());

            $this->assertTrue($response['success']);
            $this->assertSame([], Syncs::for_conversion($this->conversion_id()));
            $this->assertSame(0, SubmissionSync::run_due());

            update_option('mo_db_ver', \MailOptin\Core\DBUpdates::DB_VER);
        }

        public function test_unencodable_data_fails_instead_of_retrying_forever()
        {
            $id = OptinConversionsRepository::add(['email' => 'bad@example.net']);
            $builder          = new ConversionDataBuilder();
            $builder->email   = 'bad@example.net';
            $builder->payload = ['weight' => INF]; // JSON can't represent it

            SubmissionSync::send($id, 'FakeSyncConnect', 'abc123', $builder);

            $sync = Syncs::for_conversion($id)[0];
            $this->assertSame(Syncs::STATUS_FAILED, $sync['status']);
            $this->assertNull($sync['next_attempt_at']);
        }

        public function test_counts_and_latest_failure_for_the_alert()
        {
            $form = $this->form();
            $this->submit($form, 'a@example.net');
            $this->submit($form, 'b@example.net');

            $this->assertSame(2, Syncs::count_unsent_submissions());
            $this->assertSame('401: API key is invalid or revoked', Syncs::latest_failure()['last_error']);
        }
    }
}
