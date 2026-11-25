<?php

namespace MailOptin\Tests\Core\SendQueue;

use MailOptin\Core\AsyncHandler\AsyncHandler;
use MailOptin\Core\AsyncHandler\BGProcess;
use MailOptin\Core\Core;
use MailOptin\Core\Repositories\SendQueueRepository as Queue;
use MailOptin\Core\SendQueue\BatchSenderInterface;
use MailOptin\Core\SendQueue\Mailer;
use MailOptin\Core\SendQueue\QueueRunner;
use MailOptin\Core\SendQueue\WpMailSender;
use WP_UnitTestCase;

class FakeBatchSender implements BatchSenderInterface
{
    public $calls = [];
    public $batch = 2;
    public $per_second = null;
    /** @var callable|null row => result */
    public $result_for;

    public function send($row, $campaign_log_id)
    {
        return current($this->send_batch([$row['id'] => $row], $campaign_log_id));
    }

    public function send_batch(array $rows, $campaign_log_id)
    {
        $this->calls[] = array_column($rows, 'email');

        $results = [];
        foreach ($rows as $id => $row) {
            $results[$id] = $this->result_for ? call_user_func($this->result_for, $row) : true;
        }

        return $results;
    }

    public function batch_size()
    {
        return $this->batch;
    }

    public function max_per_second()
    {
        return $this->per_second;
    }
}

class BatchSendingTest extends WP_UnitTestCase
{
    /** @var FakeBatchSender */
    protected $sender;

    protected $campaign_log_id;

    public function set_up()
    {
        parent::set_up();

        global $wpdb;

        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        if (is_null(AsyncHandler::get_instance()->bg_process)) {
            AsyncHandler::get_instance()->bg_process = new BGProcess();
        }

        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        $this->sender = new FakeBatchSender();
        add_filter('mo_send_queue_sender', function () {
            return $this->sender;
        });

        QueueRunner::unblock();

        $wpdb->insert($wpdb->prefix . Core::campaign_log_table_name, [
            'email_campaign_id' => 7, 'title' => 'Bulbs', 'content_html' => '<p>Hi</p>', 'content_text' => 'Hi', 'status' => 'draft',
        ]);

        $this->campaign_log_id = $wpdb->insert_id;
    }

    protected function start($emails)
    {
        QueueRunner::get_instance()->start($this->campaign_log_id, 7, array_map(function ($email) {
            return ['email' => $email, 'recipient_type' => 'test', 'recipient_id' => 0];
        }, $emails));
    }

    public function test_sends_in_batches_of_the_senders_size()
    {
        $this->start(['a@x.example', 'b@x.example', 'c@x.example', 'd@x.example', 'e@x.example']);
        QueueRunner::get_instance()->process($this->campaign_log_id);

        $this->assertSame([2, 2, 1], array_map('count', $this->sender->calls));
        $this->assertSame(5, Queue::counts($this->campaign_log_id)['sent']);
    }

    public function test_keeps_to_the_per_second_limit()
    {
        $this->sender->batch      = 500;
        $this->sender->per_second = 2;

        $this->start(['a@x.example', 'b@x.example', 'c@x.example', 'd@x.example', 'e@x.example']);

        $start = microtime(true);
        QueueRunner::get_instance()->process($this->campaign_log_id);

        // Batches shrink to the limit, and the third waits for the next second.
        $this->assertSame([2, 2, 1], array_map('count', $this->sender->calls));
        $this->assertGreaterThanOrEqual(1.9, microtime(true) - $start);
    }

    public function test_failed_and_retryable_results_per_row()
    {
        $this->sender->result_for = function ($row) {
            if (0 === strpos($row['email'], 'bad')) return new \WP_Error('x', 'Invalid address');
            if (0 === strpos($row['email'], 'slow')) return new \WP_Error('x', 'Rate limited', ['retryable' => true, 'retry_after' => 600]);

            return true;
        };

        $this->start(['ok@x.example', 'bad@x.example', 'slow@x.example']);
        QueueRunner::get_instance()->process($this->campaign_log_id);

        $counts = Queue::counts($this->campaign_log_id);
        $this->assertSame(1, $counts['sent']);
        $this->assertSame(1, $counts['failed']);
        $this->assertSame(1, $counts['pending']);

        global $wpdb;
        $next = $wpdb->get_var($wpdb->prepare('SELECT next_attempt_at FROM ' . Queue::table() . ' WHERE email = %s', 'slow@x.example'));
        // The provider's Retry-After (10 minutes) beats the first backoff (1 minute).
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s', time() + 540), $next);
    }

    public function test_bad_credentials_stop_all_sending_without_using_up_attempts()
    {
        $this->sender->result_for = function () {
            return new \WP_Error('auth', 'The server token is wrong.', ['retryable' => true, 'stop' => true]);
        };

        $this->start(['a@x.example', 'b@x.example', 'c@x.example', 'd@x.example']);
        QueueRunner::get_instance()->process($this->campaign_log_id);

        // The first batch failed; nothing else was tried.
        $this->assertCount(1, $this->sender->calls);
        $this->assertSame('The server token is wrong.', QueueRunner::blocked()['error']);
        $this->assertSame(4, Queue::counts($this->campaign_log_id)['pending']);

        global $wpdb;
        $this->assertSame(['0'], array_unique($wpdb->get_col('SELECT attempts FROM ' . Queue::table() . ' WHERE campaign_log_id = ' . (int)$this->campaign_log_id)));

        // While stopped, nothing sends.
        $this->sender->result_for = null;
        $this->assertSame(0, QueueRunner::get_instance()->process($this->campaign_log_id));

        // Once fixed (settings saved), sending picks up and the notice clears with the first email out.
        QueueRunner::unblock();
        update_option(QueueRunner::BLOCKED_OPTION, ['error' => 'old', 'until' => time() - 1], false);
        $wpdb->query('UPDATE ' . Queue::table() . " SET next_attempt_at = '2000-01-01 00:00:00'");
        QueueRunner::get_instance()->process($this->campaign_log_id);

        $this->assertSame(4, Queue::counts($this->campaign_log_id)['sent']);
        $this->assertNull(QueueRunner::block_notice());
    }

    public function test_throttled_rows_wait_without_using_up_attempts()
    {
        $this->sender->result_for = function () {
            return new \WP_Error('x', 'Rate limited', ['retryable' => true, 'throttled' => true, 'retry_after' => 120]);
        };

        $this->start(['a@x.example']);
        QueueRunner::get_instance()->process($this->campaign_log_id);

        global $wpdb;
        $row = $wpdb->get_row('SELECT status, attempts, next_attempt_at FROM ' . Queue::table() . ' WHERE campaign_log_id = ' . (int)$this->campaign_log_id, ARRAY_A);
        $this->assertSame(['pending', '0'], [$row['status'], $row['attempts']]);
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s', time() + 100), $row['next_attempt_at']);
    }

    public function test_a_stop_set_during_a_batch_isnt_cleared_by_it()
    {
        $this->sender->result_for = function () {
            // Another worker stops sending while this batch is out.
            QueueRunner::block('Revoked');

            return true;
        };

        $this->start(['a@x.example']);
        QueueRunner::get_instance()->process($this->campaign_log_id);

        $this->assertSame('Revoked', QueueRunner::block_notice()['error']);
    }

    public function test_no_redispatch_while_stopped()
    {
        $this->start(['a@x.example']);
        QueueRunner::block('Revoked');

        $pushed = 0;
        add_filter('pre_http_request', function ($pre) use (&$pushed) {
            $pushed++;

            return $pre;
        }, 1);

        QueueRunner::get_instance()->async_job(QueueRunner::ASYNC_ACTION, ['campaign_log_id' => $this->campaign_log_id]);
        $this->assertSame(0, $pushed);
    }

    public function test_compose_gives_the_same_email_wp_mail_sends()
    {
        $content = (new WpMailSender())->compose(['id' => 1, 'email' => 'a@x.example', 'recipient_type' => 'test', 'recipient_id' => 0], $this->campaign_log_id);

        $this->assertSame('Bulbs', $content['subject']);
        $this->assertSame('<p>Hi</p>', $content['html']);
        $this->assertContains('Content-Type: text/html; charset=UTF-8', $content['headers']);
    }

    public function test_mailer_uses_the_sending_service_when_one_answers()
    {
        reset_phpmailer_instance();

        $this->assertTrue(Mailer::send('a@x.example', 'Hi', '<p>Hi</p>', [], 'Hi'));
        $this->assertSame('Hi', tests_retrieve_phpmailer_instance()->AltBody);

        $seen = null;
        add_filter('mo_send_email_now', function ($result, $args) use (&$seen) {
            $seen = $args;

            return new \WP_Error('x', 'Service said no');
        }, 10, 2);

        $result = Mailer::send('b@x.example', 'Hello', '<p>Hello</p>', ['From: a@b.example'], 'Hello');
        $this->assertSame('Service said no', $result->get_error_message());
        $this->assertSame(['to' => 'b@x.example', 'subject' => 'Hello', 'html' => '<p>Hello</p>', 'headers' => ['From: a@b.example'], 'text' => 'Hello'], $seen);
    }
}
