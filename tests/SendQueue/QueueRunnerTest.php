<?php

namespace MailOptin\Tests\Core\SendQueue;

use MailOptin\Core\AsyncHandler\AsyncHandler;
use MailOptin\Core\AsyncHandler\BGProcess;
use MailOptin\Core\Core;
use MailOptin\Core\Repositories\SendQueueRepository as Queue;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\SendQueue\QueueRunner;
use MailOptin\Core\SendQueue\SenderInterface;
use MailOptin\Core\SendQueue\WpMailSender;
use WP_UnitTestCase;

class FakeSender implements SenderInterface
{
    public $sent = [];
    public $fail_with = null;

    public function send($row, $campaign_log_id)
    {
        if ($this->fail_with) return $this->fail_with;

        $this->sent[] = $row['email'];

        return true;
    }
}

class QueueRunnerTest extends WP_UnitTestCase
{
    /** @var FakeSender */
    protected $sender;

    /** @var QueueRunner */
    protected $runner;

    protected $campaign_log_id;

    public function set_up()
    {
        parent::set_up();

        global $wpdb;

        // tests/bootstrap.php boots the plugin after plugins_loaded has fired, so do what those hooks would have done.
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        if (is_null(AsyncHandler::get_instance()->bg_process)) {
            AsyncHandler::get_instance()->bg_process = new BGProcess();
        }

        // No loopback requests from AsyncHandler during tests.
        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        $this->sender = new FakeSender();
        add_filter('mo_send_queue_sender', function () {
            return $this->sender;
        });

        $wpdb->insert($wpdb->prefix . Core::campaign_log_table_name, [
            'email_campaign_id' => 7,
            'title'             => 'November Planting Notes',
            'content_html'      => '<p>Hello</p>',
            'content_text'      => 'Hello',
            'status'            => 'draft',
        ]);

        $this->campaign_log_id = $wpdb->insert_id;
        $this->runner          = QueueRunner::get_instance();
    }

    protected function recipients($emails)
    {
        return array_map(function ($email) {
            return ['email' => $email, 'recipient_type' => 'subscriber', 'recipient_id' => 0];
        }, $emails);
    }

    protected function log_status()
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}mo_campaign_log WHERE id = %d", $this->campaign_log_id));
    }

    public function test_tables_exist()
    {
        global $wpdb;

        foreach (['mo_subscribers', 'mo_lists', 'mo_list_subscribers', 'mo_suppressions', 'mo_subscriber_events', 'mo_send_queue'] as $table) {
            $this->assertSame($wpdb->prefix . $table, $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}$table'"));
        }
    }

    public function test_db_update_routine_is_safe_to_run_twice()
    {
        global $wpdb;

        $wpdb->suppress_errors(false);
        $wpdb->last_error = '';

        \MailOptin\Core\DBUpdates::get_instance()->update_routine_17();
        \MailOptin\Core\DBUpdates::get_instance()->update_routine_17();

        $this->assertSame('', $wpdb->last_error);
        $this->assertSame($wpdb->prefix . 'mo_send_queue', $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}mo_send_queue'"));
    }

    public function test_enqueue_normalizes_dedupes_and_skips_invalid()
    {
        $added = Queue::enqueue($this->campaign_log_id, 7, $this->recipients([
            'Priya@Example.com', ' priya@example.com ', 'not-an-email', 'tom@example.com'
        ]));

        $this->assertSame(2, $added);

        // Queuing the same campaign again adds nobody twice.
        $this->assertSame(0, Queue::enqueue($this->campaign_log_id, 7, $this->recipients(['tom@example.com'])));
        $this->assertSame(2, Queue::counts($this->campaign_log_id)['pending']);
    }

    public function test_two_workers_never_claim_the_same_row()
    {
        Queue::enqueue($this->campaign_log_id, 7, $this->recipients(['a@example.com', 'b@example.com', 'c@example.com']));

        $first  = Queue::claim($this->campaign_log_id, 'worker-one', 2, 300);
        $second = Queue::claim($this->campaign_log_id, 'worker-two', 2, 300);

        $this->assertCount(2, $first);
        $this->assertCount(1, $second);
        $this->assertEmpty(array_intersect(wp_list_pluck($first, 'id'), wp_list_pluck($second, 'id')));
        $this->assertEmpty(Queue::claim($this->campaign_log_id, 'worker-three', 2, 300));
    }

    public function test_sends_everyone_and_completes_the_campaign()
    {
        $this->runner->start($this->campaign_log_id, 7, $this->recipients(['a@example.com', 'b@example.com', 'c@example.com']));

        $this->assertSame('sending', $this->log_status());

        $this->runner->process($this->campaign_log_id);

        $this->assertEqualsCanonicalizing(['a@example.com', 'b@example.com', 'c@example.com'], $this->sender->sent);
        $this->assertSame(3, Queue::counts($this->campaign_log_id)['sent']);
        $this->assertSame('processed', $this->log_status());
        $this->assertFalse($this->runner->is_sending($this->campaign_log_id));
    }

    public function test_suppressed_addresses_are_skipped()
    {
        SuppressionRepository::add('B@example.com', SuppressionRepository::REASON_UNSUBSCRIBED);

        $this->runner->start($this->campaign_log_id, 7, $this->recipients(['a@example.com', 'b@example.com']));
        $this->runner->process($this->campaign_log_id);

        $this->assertSame(['a@example.com'], $this->sender->sent);
        $this->assertSame(1, Queue::counts($this->campaign_log_id)['skipped']);
    }

    public function test_retryable_failure_waits_then_fails_after_max_attempts()
    {
        $this->sender->fail_with = new \WP_Error('smtp', 'Connection timed out', ['retryable' => true]);

        $this->runner->start($this->campaign_log_id, 7, $this->recipients(['a@example.com']));
        $this->runner->process($this->campaign_log_id);

        $row = $this->row('a@example.com');
        $this->assertSame('pending', $row['status']);
        $this->assertSame('1', $row['attempts']);
        $this->assertGreaterThan(Queue::now(), $row['next_attempt_at']);
        $this->assertSame('sending', $this->log_status());

        // Make the retries due now and run until the attempts run out (3 by default).
        for ($i = 0; $i < 3; $i++) {
            $this->make_due();
            $this->runner->process($this->campaign_log_id);
        }

        $row = $this->row('a@example.com');
        $this->assertSame('failed', $row['status']);
        $this->assertSame('3', $row['attempts']);
        $this->assertSame('processed', $this->log_status());
    }

    public function test_non_retryable_failure_fails_immediately()
    {
        $this->sender->fail_with = new \WP_Error('invalid', 'Mailbox does not exist');

        $this->runner->start($this->campaign_log_id, 7, $this->recipients(['a@example.com']));
        $this->runner->process($this->campaign_log_id);

        $this->assertSame('failed', $this->row('a@example.com')['status']);
        $this->assertSame('Mailbox does not exist', $this->row('a@example.com')['error']);
    }

    public function test_crashed_worker_rows_are_recovered_without_double_sending()
    {
        global $wpdb;

        Queue::enqueue($this->campaign_log_id, 7, $this->recipients(['never-started@example.com', 'mid-send@example.com']));

        $rows = Queue::claim($this->campaign_log_id, 'dead-worker', 2, 300);
        Queue::mark_sending($rows[1]['id'], 'dead-worker');

        // The dead worker's lease runs out.
        $wpdb->query("UPDATE {$wpdb->prefix}mo_send_queue SET claimed_until = '2000-01-01 00:00:00'");

        Queue::release_expired($this->campaign_log_id);

        $this->assertSame('pending', $this->row('never-started@example.com')['status']);
        $this->assertSame('failed', $this->row('mid-send@example.com')['status']);
    }

    public function test_hourly_limit_caps_sending()
    {
        add_filter('mo_send_queue_hourly_limit', function () {
            return 2;
        });

        $this->runner->start($this->campaign_log_id, 7, $this->recipients(['a@example.com', 'b@example.com', 'c@example.com']));
        $this->runner->process($this->campaign_log_id);

        $this->assertCount(2, $this->sender->sent);
        $this->assertSame(1, Queue::counts($this->campaign_log_id)['pending']);
        $this->assertSame('sending', $this->log_status());
    }

    public function test_paused_campaign_does_not_send_until_resumed()
    {
        $this->runner->start($this->campaign_log_id, 7, $this->recipients(['a@example.com']));
        $this->runner->pause($this->campaign_log_id);

        $this->assertSame(0, $this->runner->process($this->campaign_log_id));
        $this->assertSame('paused', $this->log_status());

        $this->runner->resume($this->campaign_log_id);
        $this->runner->process($this->campaign_log_id);

        $this->assertSame(['a@example.com'], $this->sender->sent);
    }

    public function test_run_all_processes_every_sending_campaign()
    {
        $this->runner->start($this->campaign_log_id, 7, $this->recipients(['a@example.com', 'b@example.com']));

        $this->assertSame(2, $this->runner->run_all());
        $this->assertSame('processed', $this->log_status());
    }

    public function test_run_key_is_stable_until_regenerated()
    {
        $key = QueueRunner::run_key();

        $this->assertSame(32, strlen($key));
        $this->assertSame($key, QueueRunner::run_key());
        $this->assertNotSame($key, QueueRunner::regenerate_run_key());
    }

    public function test_wp_mail_sender_sends_html_with_plain_text_part()
    {
        reset_phpmailer_instance();

        $sender = new WpMailSender();
        $result = $sender->send(['email' => 'priya@example.com', 'email_campaign_id' => 7], $this->campaign_log_id);

        $this->assertTrue($result);

        $mailer = tests_retrieve_phpmailer_instance();
        $this->assertSame('priya@example.com', $mailer->get_recipient('to')->address);
        $this->assertSame('November Planting Notes', $mailer->get_sent()->subject);
        $this->assertStringContainsString('<p>Hello</p>', $mailer->get_sent()->body);
        $this->assertSame('Hello', $mailer->AltBody);
    }

    protected function row($email)
    {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}mo_send_queue WHERE campaign_log_id = %d AND email = %s", $this->campaign_log_id, $email),
            ARRAY_A
        );
    }

    protected function make_due()
    {
        global $wpdb;

        $wpdb->query("UPDATE {$wpdb->prefix}mo_send_queue SET next_attempt_at = '2000-01-01 00:00:00' WHERE status = 'pending'");
    }
}
