<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Audience\DeliveryEvents;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\CampaignActivityRepository as Activity;
use MailOptin\Core\Repositories\SegmentsRepository;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use WP_UnitTestCase;

class DeliveryEventsTest extends WP_UnitTestCase
{
    protected $log_id;

    public function set_up()
    {
        parent::set_up();

        foreach ([17, 20, 21, 22] as $version) DBUpdates::get_instance()->{'update_routine_' . $version}();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        Activity::flush_cache();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();

        global $wpdb;
        $wpdb->insert($wpdb->prefix . \MailOptin\Core\Core::campaign_log_table_name, [
            'email_campaign_id' => 1, 'title' => 'November planting notes', 'content_html' => '<p>Hi</p>', 'content_text' => 'Hi', 'status' => 'processed', 'status_time' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->log_id = (int)$wpdb->insert_id;
    }

    protected function subscriber($email)
    {
        return Subscribers::create(['email' => $email, 'status' => Subscribers::STATUS_SUBSCRIBED]);
    }

    protected function queued($email, $subscriber_id, $log_id = null)
    {
        SendQueueRepository::enqueue($log_id ?: $this->log_id, 1, [['email' => $email, 'recipient_type' => 'subscriber', 'recipient_id' => $subscriber_id]]);

        global $wpdb;

        return (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SendQueueRepository::table() . ' WHERE email = %s AND campaign_log_id = %d', $email, $log_id ?: $this->log_id));
    }

    public function test_hard_bounce_suppresses_and_logs_against_the_campaign()
    {
        $id    = $this->subscriber('sam@old.example');
        $queue = $this->queued('sam@old.example', $id);

        $done = DeliveryEvents::record(['type' => 'hard_bounce', 'email' => 'Sam@Old.example', 'provider' => 'postmark', 'detail' => '550 5.1.1 no such user', 'metadata' => ['mo_q' => (string)$queue]]);

        $this->assertSame('suppressed', $done);
        $this->assertSame(Subscribers::STATUS_BOUNCED, Subscribers::get($id)['status']);
        $this->assertSame(SuppressionRepository::REASON_BOUNCED, SuppressionRepository::get_reason('sam@old.example'));

        $event = DeliveryEvents::last_event($id);
        $this->assertSame($this->log_id, (int)$event['campaign_log_id']);
        $this->assertSame(['provider' => 'postmark', 'detail' => '550 5.1.1 no such user'], json_decode($event['meta'], true));

        // The provider sending the webhook again doesn't log it twice.
        $this->assertSame('duplicate', DeliveryEvents::record(['type' => 'hard_bounce', 'email' => 'sam@old.example', 'provider' => 'postmark', 'metadata' => ['mo_q' => (string)$queue]]));
    }

    public function test_a_queue_id_for_another_address_isnt_trusted()
    {
        $a = $this->subscriber('a@x.example');
        $this->subscriber('b@x.example');
        $queue = $this->queued('a@x.example', $a);

        DeliveryEvents::record(['type' => 'complaint', 'email' => 'b@x.example', 'provider' => 'ses', 'metadata' => ['mo_q' => (string)$queue]]);

        $event = DeliveryEvents::last_event(Subscribers::get_by_email('b@x.example')['id'], true);
        $this->assertSame(0, (int)$event['campaign_log_id']);
        $this->assertSame(Subscribers::STATUS_COMPLAINED, Subscribers::get_by_email('b@x.example')['status']);
        $this->assertSame(Subscribers::STATUS_SUBSCRIBED, Subscribers::get($a)['status']);
    }

    public function test_complaint_replaces_an_unsubscribe_and_non_subscribers_are_suppressed_too()
    {
        SuppressionRepository::add('lead@x.example', SuppressionRepository::REASON_UNSUBSCRIBED);

        DeliveryEvents::record(['type' => 'complaint', 'email' => 'lead@x.example', 'provider' => 'sendgrid']);

        $this->assertSame(SuppressionRepository::REASON_COMPLAINED, SuppressionRepository::get_reason('lead@x.example'));
        $this->assertSame('ignored', DeliveryEvents::record(['type' => 'delivered', 'email' => 'lead@x.example']));
        $this->assertSame('ignored', DeliveryEvents::record(['type' => 'hard_bounce', 'email' => 'not an email']));
    }

    public function test_four_soft_bounces_in_a_row_count_as_a_bounce()
    {
        $id = $this->subscriber('full@x.example');

        global $wpdb;
        $logs = [];
        for ($i = 0; $i < 5; $i++) {
            $wpdb->insert($wpdb->prefix . \MailOptin\Core\Core::campaign_log_table_name, ['email_campaign_id' => 1, 'title' => 'C' . $i, 'content_html' => 'x', 'content_text' => 'x', 'status' => 'processed']);
            $logs[] = (int)$wpdb->insert_id;
        }

        $soft = function ($log) use ($id) {
            return DeliveryEvents::record(['type' => 'soft_bounce', 'email' => 'full@x.example', 'provider' => 'mailgun', 'detail' => '452 mailbox full', 'metadata' => ['mo_q' => (string)$this->queued('full@x.example', $id, $log)]]);
        };

        $this->assertSame('counted', $soft($logs[0]));
        $this->assertSame('counted', $soft($logs[1]));

        // A click in between starts the count again.
        Subscribers::log_event($id, Subscribers::EVENT_CLICKED, [], $logs[1]);
        $wpdb->query($wpdb->prepare('UPDATE ' . Subscribers::events_table() . " SET created_at = DATE_SUB(created_at, INTERVAL 1 HOUR) WHERE subscriber_id = %d AND type = 'soft_bounced'", $id));

        $this->assertSame('counted', $soft($logs[2]));
        $this->assertSame('counted', $soft($logs[3]));
        $this->assertSame(Subscribers::STATUS_SUBSCRIBED, Subscribers::get($id)['status']);

        // Same campaign again: a duplicate, not a third.
        $this->assertSame('duplicate', $soft($logs[3]));

        DeliveryEvents::record(['type' => 'soft_bounce', 'email' => 'full@x.example', 'provider' => 'mailgun', 'detail' => '452 mailbox full']);
        $this->assertSame('suppressed', $soft($logs[4]));

        $this->assertSame(Subscribers::STATUS_BOUNCED, Subscribers::get($id)['status']);
        $meta = json_decode(DeliveryEvents::last_event($id)['meta'], true);
        $this->assertSame(1, $meta['soft']);
        $this->assertStringContainsString('Soft bounced 4 times in a row', $meta['detail']);
    }

    public function test_the_same_email_reported_again_counts_once()
    {
        $id = $this->subscriber('retry@x.example');

        // A confirmation email (no campaign) reported as failing on each of the provider's retries, hours apart.
        for ($i = 0; $i < 5; $i++) {
            $done = DeliveryEvents::record(['type' => 'soft_bounce', 'email' => 'retry@x.example', 'provider' => 'mailgun', 'message_id' => 'mg-123@acme']);
            global $wpdb;
            $wpdb->query('UPDATE ' . Subscribers::events_table() . ' SET created_at = DATE_SUB(created_at, INTERVAL 2 HOUR)');
        }

        $this->assertSame('duplicate', $done);
        $this->assertSame(1, DeliveryEvents::soft_bounces_in_a_row($id));
        $this->assertSame(Subscribers::STATUS_SUBSCRIBED, Subscribers::get($id)['status']);
    }

    public function test_resubscribe_keeps_an_earlier_unsubscribe()
    {
        $id = $this->subscriber('optout@x.example');
        SuppressionRepository::add('optout@x.example', SuppressionRepository::REASON_UNSUBSCRIBED);

        DeliveryEvents::record(['type' => 'complaint', 'email' => 'optout@x.example', 'provider' => 'ses']);
        $this->assertSame(SuppressionRepository::REASON_COMPLAINED, SuppressionRepository::get_reason('optout@x.example'));

        DeliveryEvents::resubscribe($id);
        $this->assertSame(SuppressionRepository::REASON_UNSUBSCRIBED, SuppressionRepository::get_reason('optout@x.example'));
    }

    public function test_resubscribe_lifts_a_bounce()
    {
        $id = $this->subscriber('back@x.example');
        DeliveryEvents::record(['type' => 'hard_bounce', 'email' => 'back@x.example', 'provider' => 'ses']);

        $this->assertTrue(DeliveryEvents::resubscribe($id));
        $this->assertNull(SuppressionRepository::get_reason('back@x.example'));
        $this->assertNotSame(Subscribers::STATUS_BOUNCED, Subscribers::get($id)['status']);
        $this->assertSame(Subscribers::EVENT_RESUBSCRIBED, Subscribers::get_events($id, 1)[0]['type']);

        // An unsubscribe (not a bounce) isn't lifted this way.
        $other = $this->subscriber('gone@x.example');
        $this->assertFalse(DeliveryEvents::resubscribe($other));
    }

    public function test_summary()
    {
        $id = $this->subscriber('s@x.example');
        Subscribers::log_event($id, Subscribers::EVENT_SENT);
        Subscribers::log_event($id, Subscribers::EVENT_SENT);
        DeliveryEvents::record(['type' => 'hard_bounce', 'email' => 's@x.example', 'provider' => 'ses']);

        $summary = DeliveryEvents::summary();
        $this->assertSame(1, $summary['hard']);
        $this->assertSame(2, $summary['sent']);
        $this->assertSame(50.0, $summary['rate']);
    }

    public function test_never_engaged_needs_five_tracked_emails_and_no_activity()
    {
        global $wpdb;

        $quiet  = $this->subscriber('quiet@x.example');
        $reader = $this->subscriber('reader@x.example');
        $new    = $this->subscriber('new@x.example');

        for ($i = 0; $i < 5; $i++) {
            $wpdb->insert($wpdb->prefix . \MailOptin\Core\Core::campaign_log_table_name, ['email_campaign_id' => 1, 'title' => 'C' . $i, 'content_html' => 'x', 'content_text' => 'x', 'status' => 'processed']);
            $log = (int)$wpdb->insert_id;
            Activity::mark_tracked($log, Activity::METRIC_CLICK_TRACKED);

            foreach ([$quiet => 'quiet@x.example', $reader => 'reader@x.example'] + ($i < 2 ? [$new => 'new@x.example'] : []) as $sid => $email) {
                $this->queued($email, $sid, $log);
            }

            if (4 === $i) Activity::record_click($log, $reader, Activity::get_link(Activity::link_id($log, 'https://x.example')));
        }

        $wpdb->query('UPDATE ' . SendQueueRepository::table() . " SET status = 'sent', sent_at = '" . gmdate('Y-m-d H:i:s') . "'");

        $condition = Activity::clean_condition(['what' => 'never_engaged', 'campaign' => 9, 'days' => 90]);
        $this->assertSame(0, $condition['campaign']);

        $ids = $wpdb->get_col('SELECT s.id FROM ' . Subscribers::table() . ' s WHERE ' . Activity::condition_sql($condition));
        $this->assertSame([(string)$quiet], $ids);

        // Not for sending to: segments refuse it.
        $this->assertTrue(SegmentsRepository::uses_not_opened(['engagement' => [$condition]]));
        $this->assertStringContainsString('(estimate)', \MailOptin\Core\Admin\SettingsPage\Subscriber_Filters::engagement_label($condition));
    }
}
