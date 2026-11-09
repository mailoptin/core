<?php

namespace MailOptin\Tests\Core\Automations;

use MailOptin\Core\AsyncHandler\AsyncHandler;
use MailOptin\Core\AsyncHandler\BGProcess;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Audience\Tracking;
use MailOptin\Core\Automations\AbstractTrigger;
use MailOptin\Core\Automations\Engine;
use MailOptin\Core\Automations\Triggers;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;
use MailOptin\Core\Repositories\CampaignActivityRepository as Activity;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SendQueueRepository as Queue;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\Repositories\TagsRepository;
use MailOptin\Core\SendQueue\QueueRunner;
use WP_UnitTestCase;

/**
 * A trigger the tests fire by hand.
 */
class TestEventTrigger extends AbstractTrigger
{
    public static $stop = '';
    public static $listened = 0;

    public function id() { return 'test_event'; }
    public function group() { return 'Tests'; }
    public function label() { return 'A test event'; }
    public function listen() { self::$listened++; }

    public function fire($email, $ref, $context = [])
    {
        return $this->start($email, $ref, $context);
    }

    public function matches($context, $settings, $audience)
    {
        return empty($settings['only']) || ($context['kind'] ?? '') === $settings['only'];
    }

    public function stop_reason($run)
    {
        return self::$stop;
    }

    public function placeholders()
    {
        return [
            'order.number' => ['label' => 'Order', 'value' => function ($c) { return $c['number'] ?? ''; }, 'sample' => '1048'],
            'order.note'   => ['label' => 'Note', 'value' => function ($c) { return $c['note'] ?? ''; }, 'sample' => 'Note'],
        ];
    }

    public function checks()
    {
        return ['is_vip' => ['label' => 'they are VIP', 'callback' => function ($run) { return ! empty($run['context']['vip']); }]];
    }
}

class EngineTest extends WP_UnitTestCase
{
    /** @var TestEventTrigger */
    protected static $trigger;

    public function set_up()
    {
        parent::set_up();

        foreach ([17, 20, 21, 22, 23, 24] as $version) DBUpdates::get_instance()->{'update_routine_' . $version}();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        Activity::flush_cache();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();

        if (is_null(AsyncHandler::get_instance()->bg_process)) AsyncHandler::get_instance()->bg_process = new BGProcess();

        add_filter('pre_http_request', function () { return ['response' => ['code' => 200], 'body' => '']; });
        add_filter('mailoptin_automations_available', '__return_true');

        if ( ! self::$trigger) {
            self::$trigger = new TestEventTrigger();
            Triggers::get_instance()->add(self::$trigger);
        }

        TestEventTrigger::$stop = '';
        update_option('timezone_string', 'Europe/London');
        reset_phpmailer_instance();
    }

    public function tear_down()
    {
        remove_all_filters('mailoptin_automations_available');
        remove_all_filters('mailoptin_automation_daily_cap');
        parent::tear_down();
    }

    protected function email($subject, $body)
    {
        static $n = 0;

        $id = ER::add_email_campaign('Automation email ' . (++$n) . ' ' . uniqid(), Repo::EMAIL_CAMPAIGN_TYPE, 'Lucid');

        ER::update_settings_by_id($id, [
            'email_campaign_title'     => $subject,
            'email_newsletter_content' => wp_json_encode([[
                'id'       => 'b1',
                'type'     => 'text',
                'settings' => ['text_content' => $body] + \MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Misc::elements_default_fields_values($id)['text'],
            ]]),
        ]);

        return $id;
    }

    protected function automation($steps, $trigger_settings = [], $settings = [], $audience = [])
    {
        $id = Repo::create(['name' => 'Test', 'trigger_id' => 'test_event', 'trigger_settings' => $trigger_settings, 'settings' => $settings, 'audience' => $audience, 'status' => Repo::STATUS_LIVE]);
        Repo::save_steps($id, $steps);

        return $id;
    }

    protected function email_step($subject = 'Your order {{order.number}}', $body = '<p>Hi, about order {{order.number}} — {{order.note|no note}}.</p>')
    {
        return ['type' => Repo::STEP_EMAIL, 'settings' => ['email_campaign_id' => $this->email($subject, $body)]];
    }

    /** Run due automation steps, then send what they queued. */
    protected function deliver()
    {
        Engine::get_instance()->run(time() + 5);
        reset_phpmailer_instance();
        QueueRunner::get_instance()->run_all();

        return tests_retrieve_phpmailer_instance()->mock_sent;
    }

    protected function make_due($run_id)
    {
        global $wpdb;
        $wpdb->update(Repo::runs_table(), ['next_run_at' => gmdate('Y-m-d H:i:s', time() - 1)], ['id' => $run_id]);
    }

    public function test_an_event_runs_the_email_with_placeholders_filled_and_escaped()
    {
        $this->automation([$this->email_step()]);

        $runs = self::$trigger->fire('Maya@Example.org', 'order-1', ['number' => '1048', 'note' => '<b>fragile</b>']);
        $this->assertCount(1, $runs);

        $sent = $this->deliver();

        $this->assertCount(1, $sent);
        $this->assertSame('maya@example.org', $sent[0]['to'][0][0]);
        $this->assertSame('Your order 1048', $sent[0]['subject']);
        $this->assertStringContainsString('about order 1048', $sent[0]['body']);
        $this->assertStringContainsString('&lt;b&gt;fragile&lt;/b&gt;', $sent[0]['body']);
        $this->assertStringNotContainsString('{{order.', $sent[0]['body']);
        $this->assertStringContainsString('mo_auto_unsub=', $sent[0]['body'] . $sent[0]['header']);
        // No shared web version: it would show the template's raw placeholders.
        $this->assertStringNotContainsString('mo_web=', $sent[0]['body']);

        $this->assertSame(Repo::RUN_COMPLETED, Repo::get_run($runs[0])['status']);
    }

    public function test_the_same_event_twice_starts_one_run_and_sends_once()
    {
        $this->automation([$this->email_step()]);

        $this->assertCount(1, self::$trigger->fire('ana@example.org', 'order-7', ['number' => '7']));
        $this->assertCount(0, self::$trigger->fire('ana@example.org', 'order-7', ['number' => '7']));

        $this->assertCount(1, $this->deliver());

        // A second pass over the same run, as if two ticks overlapped, finds nothing to send.
        global $wpdb;
        $wpdb->query('UPDATE ' . Repo::runs_table() . " SET status = 'active', current_step_id = 0, next_run_at = '2000-01-01 00:00:00'");
        $this->assertCount(0, $this->deliver());
    }

    public function test_once_means_one_run_per_person_and_reentry_is_off_by_default()
    {
        $once = $this->automation([$this->email_step()], ['runs' => 'once']);

        $this->assertCount(1, self::$trigger->fire('sam@example.org', 'order-1'));
        $this->assertCount(0, self::$trigger->fire('sam@example.org', 'order-2'));

        Repo::delete($once);
        $every = $this->automation([['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 1, 'unit' => 'days']], $this->email_step()]);

        $this->assertCount(1, self::$trigger->fire('lee@example.org', 'order-1'));
        // Still part-way through the first order: not started again unless re-entry is allowed.
        $this->assertCount(0, self::$trigger->fire('lee@example.org', 'order-2'));

        Repo::update($every, ['trigger_settings' => ['reenter' => 1]]);
        $this->assertCount(1, self::$trigger->fire('lee@example.org', 'order-3'));
    }

    public function test_a_wait_holds_the_run_until_it_is_due()
    {
        $this->automation([['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 2, 'unit' => 'days']], $this->email_step()]);

        $run_id = self::$trigger->fire('kim@example.org', 'r1', ['number' => '9'])[0];

        $this->assertCount(0, $this->deliver());

        $run = Repo::get_run($run_id);
        $this->assertGreaterThan(time() + 2 * DAY_IN_SECONDS - 60, strtotime($run['next_run_at'] . ' UTC'));

        $this->make_due($run_id);
        $this->assertCount(1, $this->deliver());
    }

    public function test_a_failed_check_stops_the_run_with_its_reason()
    {
        $this->automation([['type' => Repo::STEP_CHECK, 'settings' => ['check' => 'is_vip']], $this->email_step()]);

        $no  = self::$trigger->fire('no@example.org', 'r1', ['vip' => 0])[0];
        $yes = self::$trigger->fire('yes@example.org', 'r1', ['vip' => 1])[0];

        $sent = $this->deliver();

        $this->assertCount(1, $sent);
        $this->assertSame('yes@example.org', $sent[0]['to'][0][0]);
        $this->assertSame('check:is_vip', Repo::get_run($no)['stop_reason']);
        $this->assertSame(Repo::RUN_COMPLETED, Repo::get_run($yes)['status']);
    }

    public function test_conditions_are_checked_again_just_before_sending()
    {
        $this->automation([$this->email_step()]);

        $run_id = self::$trigger->fire('late@example.org', 'r1')[0];

        // Queued, then the customer buys before the queue sends it.
        Engine::get_instance()->run(time() + 5);
        TestEventTrigger::$stop = 'purchased';

        reset_phpmailer_instance();
        QueueRunner::get_instance()->run_all();

        $this->assertCount(0, tests_retrieve_phpmailer_instance()->mock_sent);
        $this->assertSame('purchased', Repo::get_run($run_id)['stop_reason']);
    }

    public function test_one_automation_email_per_address_per_day()
    {
        $this->automation([$this->email_step('First')]);
        $this->automation([$this->email_step('Second')]);

        $runs = self::$trigger->fire('busy@example.org', 'r1');
        $this->assertCount(2, $runs);

        $this->assertCount(1, $this->deliver());

        $waiting = array_values(array_filter(array_map([Repo::class, 'get_run'], $runs), function ($run) {
            return Repo::RUN_ACTIVE === $run['status'];
        }));

        $this->assertCount(1, $waiting);
        $this->assertGreaterThan(time() + DAY_IN_SECONDS - 120, strtotime($waiting[0]['next_run_at'] . ' UTC'));

        add_filter('mailoptin_automation_daily_cap', '__return_false');
        $this->make_due($waiting[0]['id']);
        $this->assertCount(1, $this->deliver());
    }

    public function test_sending_hours_including_ones_that_cross_midnight()
    {
        $tz  = wp_timezone();
        $day = function ($time) use ($tz) {
            return (new \DateTimeImmutable($time, $tz))->getTimestamp();
        };

        $office = ['send_from' => '08:00', 'send_to' => '20:00'];
        $this->assertSame($day('2026-11-09 10:00'), Engine::next_in_window($office, $day('2026-11-09 10:00')));
        $this->assertSame($day('2026-11-09 08:00'), Engine::next_in_window($office, $day('2026-11-09 06:30')));
        $this->assertSame($day('2026-11-10 08:00'), Engine::next_in_window($office, $day('2026-11-09 21:15')));

        $night = ['send_from' => '22:00', 'send_to' => '06:00'];
        $this->assertSame($day('2026-11-09 23:30'), Engine::next_in_window($night, $day('2026-11-09 23:30')));
        $this->assertSame($day('2026-11-10 02:00'), Engine::next_in_window($night, $day('2026-11-10 02:00')));
        $this->assertSame($day('2026-11-10 22:00'), Engine::next_in_window($night, $day('2026-11-10 12:00')));

        $local = function ($time) use ($tz) {
            return (new \DateTimeImmutable('@' . $time))->setTimezone($tz)->format('Y-m-d H:i');
        };

        // Days are calendar days: across the clocks going back (London, 25 Oct 2026) the hour stays.
        $this->assertSame('2026-10-25 10:00', $local(Engine::wait_until(['amount' => 1, 'unit' => 'days'], $day('2026-10-24 10:00'))));
        // "1 day, then until 09:00" is 09:00 the next day.
        $this->assertSame('2026-10-25 09:00', $local(Engine::wait_until(['amount' => 1, 'unit' => 'days', 'until' => '09:00'], $day('2026-10-24 10:00'))));
        // "0 days, then until 09:00" after 09:00 is tomorrow's 09:00.
        $this->assertSame('2026-10-25 09:00', $local(Engine::wait_until(['amount' => 0, 'unit' => 'days', 'until' => '09:00'], $day('2026-10-24 10:00'))));
        // Hours, then a time: the first 09:00 after the wait.
        $this->assertSame('2026-10-25 09:00', $local(Engine::wait_until(['amount' => 2, 'unit' => 'hours', 'until' => '09:00'], $day('2026-10-24 10:00'))));
        // Weekdays only: Saturday 31 Oct moves to Monday.
        $this->assertSame('2026-11-02 09:00', $local(Engine::wait_until(['amount' => 7, 'unit' => 'days', 'until' => '09:00', 'weekdays_only' => 1], $day('2026-10-24 10:00'))));
    }

    public function test_an_email_outside_sending_hours_waits_for_them()
    {
        $tz  = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        $h   = (int)$now->format('G');

        // A two-hour window that starts three hours from now.
        $settings = ['send_from' => sprintf('%02d:00', ($h + 3) % 24), 'send_to' => sprintf('%02d:00', ($h + 5) % 24)];
        $this->automation([$this->email_step()], [], $settings);

        $run_id = self::$trigger->fire('night@example.org', 'r1')[0];

        $this->assertCount(0, $this->deliver());
        $this->assertGreaterThan(time() + HOUR_IN_SECONDS, strtotime(Repo::get_run($run_id)['next_run_at'] . ' UTC'));
    }

    public function test_editing_a_live_automation_never_resends_and_removing_an_email_cancels_it()
    {
        add_filter('mailoptin_automation_daily_cap', '__return_false');
        $first  = $this->email_step('First');
        $second = $this->email_step('Second');
        $id     = $this->automation([$first, ['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 1, 'unit' => 'days']], $second]);
        $steps  = Repo::steps($id);

        $run_id = self::$trigger->fire('edit@example.org', 'r1')[0];
        $this->assertCount(1, $this->deliver());

        // A new email added before where they are, and the steps kept by ID: nothing is sent again.
        $new = $this->email_step('Inserted');
        Repo::save_steps($id, [$new, ['id' => $steps[0]['id']] + $first, ['id' => $steps[1]['id']] + $steps[1], ['id' => $steps[2]['id']] + $second]);

        $this->make_due($run_id);
        $sent = $this->deliver();
        $this->assertSame(['Second'], wp_list_pluck($sent, 'subject'));

        // Another run, queued but not sent yet, loses its email when the step is removed.
        $other = self::$trigger->fire('edit2@example.org', 'r2')[0];
        add_filter('mailoptin_automation_daily_cap', '__return_false');
        Engine::get_instance()->run(time() + 5);

        $steps = Repo::steps($id);
        $kept  = array_values(array_filter($steps, function ($step) use ($steps) { return $step['id'] !== $steps[0]['id']; }));
        Repo::save_steps($id, array_map(function ($step) { return ['id' => $step['id'], 'type' => $step['type'], 'settings' => $step['settings']]; }, $kept));

        reset_phpmailer_instance();
        QueueRunner::get_instance()->run_all();

        // "Inserted" was queued and is cancelled; "First", queued in the same pass, still goes.
        $this->assertSame(['First'], wp_list_pluck(tests_retrieve_phpmailer_instance()->mock_sent, 'subject'));
        $this->assertSame(Repo::RUN_ACTIVE, Repo::get_run($other)['status']);
    }

    public function test_a_run_whose_worker_died_is_taken_again_then_given_up()
    {
        global $wpdb;

        $this->automation([$this->email_step()]);
        $run_id = self::$trigger->fire('stuck@example.org', 'r1')[0];

        // Claimed by a worker that never finished, lease already over.
        $wpdb->update(Repo::runs_table(), ['claimed_by' => 'dead', 'claimed_until' => gmdate('Y-m-d H:i:s', time() - 10)], ['id' => $run_id]);
        $this->assertCount(1, $this->deliver());

        // One that keeps failing stops after MAX_ATTEMPTS.
        $other = self::$trigger->fire('broken@example.org', 'r1')[0];
        $wpdb->update(Repo::runs_table(), ['attempts' => Engine::MAX_ATTEMPTS + 1], ['id' => $other]);
        Engine::get_instance()->run(time() + 5);
        $this->assertSame('error', Repo::get_run($other)['stop_reason']);
    }

    public function test_suppressed_addresses_are_not_started_and_a_store_customer_can_unsubscribe()
    {
        $this->automation([['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 1, 'unit' => 'days']], $this->email_step()]);

        SuppressionRepository::add('gone@example.org', SuppressionRepository::REASON_BOUNCED, 'test');
        $this->assertCount(0, self::$trigger->fire('gone@example.org', 'r1'));

        $run_id = self::$trigger->fire('shopper@example.org', 'r1')[0];
        $url    = Engine::unsubscribe_url(Repo::get_run($run_id), null, 0);

        parse_str(wp_parse_url($url, PHP_URL_QUERY), $query);
        $_GET                      = $query;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        add_filter('wp_die_handler', function () {
            return function () { throw new \RuntimeException('died'); };
        });

        try {
            Engine::get_instance()->unsubscribe_request();
        } catch (\RuntimeException $e) {
        }

        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assertTrue(SuppressionRepository::is_suppressed('shopper@example.org'));
        $this->assertSame('unsubscribed', Repo::get_run($run_id)['stop_reason']);
    }

    public function test_subscribers_are_tracked_and_unsubscribing_stops_their_runs()
    {
        $list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 0]);
        Subscription::subscribe('reader@example.org', $list_id, ['first_name' => 'Rae']);
        $subscriber = Subscribers::get_by_email('reader@example.org');

        $this->automation([$this->email_step('Hi {{subscriber.first_name|there}}', '<p><a href="https://example.org/shop">Shop</a></p>')]);
        self::$trigger->fire('reader@example.org', 'r1');

        $sent = $this->deliver();
        $this->assertSame('Hi Rae', $sent[0]['subject']);

        // The open pixel is for this subscriber, and counts.
        preg_match('/mo_open=([0-9a-f.]+)/', $sent[0]['body'], $m);
        $this->assertNotEmpty($m);
        $this->assertTrue(Tracking::open_token($m[1]));

        global $wpdb;
        $this->assertSame('1', $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Activity::table() . ' WHERE subscriber_id = %d AND type = %s', $subscriber['id'], Activity::TYPE_OPEN)));

        // A second run, part-way, stops when they unsubscribe from everything.
        $this->automation([['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 1, 'unit' => 'days']], $this->email_step()]);
        $run_id = self::$trigger->fire('reader@example.org', 'r2')[0];
        Subscription::unsubscribe($subscriber['id']);
        $this->assertSame('unsubscribed', Repo::get_run($run_id)['stop_reason']);
    }

    public function test_audience_segment_and_left_out_tags()
    {
        $list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 0]);
        Subscription::subscribe('vip@example.org', $list_id);
        $vip    = Subscribers::get_by_email('vip@example.org');
        $tag_id = TagsRepository::create('wholesale');
        TagsRepository::add_to_subscribers([$tag_id], [$vip['id']]);

        add_filter('mailoptin_segments_available', '__return_true');
        $this->automation([$this->email_step()], [], [], ['exclude_tag_ids' => [$tag_id]]);

        $this->assertCount(0, self::$trigger->fire('vip@example.org', 'r1'));
        // Not a subscriber: no tags, so not left out.
        $this->assertCount(1, self::$trigger->fire('guest@example.org', 'r1'));
    }

    public function test_the_queue_takes_one_address_twice_for_different_runs()
    {
        $this->assertSame(1, Queue::enqueue(999, 1, [['email' => 'x@example.org', 'recipient_type' => 'automation', 'recipient_id' => 1]]));
        $this->assertSame(1, Queue::enqueue(999, 1, [['email' => 'x@example.org', 'recipient_type' => 'automation', 'recipient_id' => 2]]));
        $this->assertSame(0, Queue::enqueue(999, 1, [['email' => 'x@example.org', 'recipient_type' => 'automation', 'recipient_id' => 2]]));
    }

    public function test_privacy_export_and_erase()
    {
        $this->automation([['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 1, 'unit' => 'days']], $this->email_step()]);
        self::$trigger->fire('private@example.org', 'r1');

        $exporters = apply_filters('wp_privacy_personal_data_exporters', []);
        $export    = call_user_func($exporters['mailoptin-automations']['callback'], 'private@example.org');
        $this->assertCount(1, $export['data']);

        $erasers = apply_filters('wp_privacy_personal_data_erasers', []);
        $result  = call_user_func($erasers['mailoptin-automations']['callback'], 'private@example.org');
        $this->assertTrue($result['items_removed']);
        $this->assertSame([], Repo::runs_for_email('private@example.org'));
    }

    public function test_nothing_runs_without_premium()
    {
        remove_all_filters('mailoptin_automations_available');
        add_filter('mailoptin_automations_available', '__return_false');

        $this->automation([$this->email_step()]);
        $this->assertSame([], self::$trigger->fire('free@example.org', 'r1'));
    }
}
