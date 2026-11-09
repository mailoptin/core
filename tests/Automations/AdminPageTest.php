<?php

namespace MailOptin\Tests\Core\Automations;

use MailOptin\Core\Admin\SettingsPage\Automations_Page;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Automations\Engine;
use MailOptin\Core\Automations\Recipes;
use MailOptin\Core\Automations\Triggers;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;
use MailOptin\Core\Repositories\CampaignActivityRepository as Activity;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository;

/**
 * Emails › Automations: recipes, the builder's actions, and what the pages show.
 */
class AdminPageTest extends EngineTest
{
    public function set_up()
    {
        parent::set_up();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator', 'user_email' => 'owner@example.org']));
        set_current_screen('dashboard');
    }

    public function tear_down()
    {
        $_GET = $_POST = $_REQUEST = [];

        parent::tear_down();
    }

    /**
     * Post a builder action like the form does; returns where it redirected.
     */
    protected function post($automation_id, $action, $fields = [], $nonce = null)
    {
        $_GET     = ['page' => MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG, 'view' => Automations_Page::VIEW, 'screen' => 'edit', 'id' => $automation_id];
        $_POST    = $fields + ['mo_automation_action' => $action, 'id' => $automation_id, '_wpnonce' => $nonce ?: wp_create_nonce($automation_id ? 'mo_automation_' . $automation_id : Automations_Page::NONCE_NEW)];
        $_REQUEST = $_GET + $_POST;

        $location = null;
        add_filter('mailoptin_automations_page_redirect', $filter = function ($url) use (&$location) {
            $location = $url;
            throw new \RuntimeException('redirected');
        });

        try {
            Automations_Page::get_instance()->handle_actions();
        } catch (\RuntimeException $e) {
        }

        remove_filter('mailoptin_automations_page_redirect', $filter);

        return (string)$location;
    }

    protected function render($get)
    {
        $_GET = ['page' => MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG, 'view' => Automations_Page::VIEW] + $get;

        ob_start();
        Automations_Page::get_instance()->render();

        return ob_get_clean();
    }

    protected function types($automation_id)
    {
        return wp_list_pluck(Repo::steps($automation_id), 'type');
    }

    public function test_a_recipe_creates_a_draft_with_its_waits_checks_and_emails()
    {
        ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 0]);

        $location = $this->post(0, 'create', ['recipe' => 'welcome_series']);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        $automation = Repo::get($query['id']);

        $this->assertSame(Repo::STATUS_DRAFT, $automation['status']);
        $this->assertSame('list_joined', $automation['trigger_id']);
        $this->assertNotEmpty($automation['trigger_settings']['list_id']);
        $this->assertSame(['email', 'wait', 'email', 'wait', 'email'], $this->types($automation['id']));

        $email = Repo::steps($automation['id'])[0]['settings']['email_campaign_id'];
        $this->assertTrue(ER::is_automation_email($email));
        $this->assertSame('Welcome to {{store.name}}', ER::get_customizer_value($email, 'email_campaign_title'));
        $this->assertLessThanOrEqual(50, strlen(ER::get_email_campaign_name($email)));

        // Without WooCommerce there are no order recipes to offer.
        $this->assertArrayNotHasKey('review_reminder', Recipes::all());
    }

    public function test_starting_from_a_trigger_uses_its_defaults()
    {
        $location = $this->post(0, 'create', ['trigger' => 'list_joined']);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        $automation = Repo::get($query['id']);

        $this->assertSame('list_joined', $automation['trigger_id']);
        $this->assertSame('once', $automation['trigger_settings']['runs']);
        $this->assertSame([], Repo::steps($automation['id']));

        // A made-up trigger creates nothing.
        $before = count(Repo::all());
        $this->post(0, 'create', ['trigger' => 'nope']);
        $this->assertCount($before, Repo::all());
    }

    public function test_saving_keeps_only_valid_values_and_never_takes_an_email_from_the_form()
    {
        $list  = ListsRepository::create(['name' => 'Club', 'double_optin' => 0]);
        $tag   = TagsRepository::create('wholesale');
        $id    = Repo::create(['name' => 'Join', 'trigger_id' => 'list_joined', 'trigger_settings' => ['list_id' => $list], 'status' => Repo::STATUS_DRAFT]);
        $email = $this->email_step()['settings']['email_campaign_id'];
        $other = $this->email_step()['settings']['email_campaign_id'];
        Repo::save_steps($id, [['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 1, 'unit' => 'days']], ['type' => Repo::STEP_EMAIL, 'settings' => ['email_campaign_id' => $email]]]);
        $steps = Repo::steps($id);

        $this->post($id, 'save', [
            'name'     => 'Join <script>x</script>',
            'trigger'  => ['list_id' => 999999, 'runs' => 'sometimes'],
            'audience' => ['segment_id' => 424242, 'exclude_tag_ids' => [$tag, 'abc', ['nested']]],
            'settings' => ['send_from' => '25:00', 'send_to' => '20:00', 'hours_on' => '1'],
            'steps'    => [
                ['id' => $steps[1]['id'], 'type' => 'email', 'email_campaign_id' => $other],
                ['id' => $steps[0]['id'], 'type' => 'wait', 'amount' => '-4', 'unit' => 'fortnights', 'until_on' => '1', 'until' => '9am', 'weekdays_only' => '1'],
                ['id' => 987654, 'type' => 'wait', 'amount' => 3],
            ],
        ]);

        $automation = Repo::get($id);
        $steps      = Repo::steps($id);

        $this->assertSame('Join', $automation['name']);
        $this->assertSame('', $automation['trigger_settings']['list_id'], 'A list that does not exist is not kept.');
        $this->assertSame('once', $automation['trigger_settings']['runs']);
        $this->assertSame(0, $automation['audience']['segment_id']);
        $this->assertSame([$tag], $automation['audience']['exclude_tag_ids']);
        $this->assertSame('', $automation['settings']['send_from']);

        // Reordered as posted; the unknown step isn't added.
        $this->assertSame(['email', 'wait'], wp_list_pluck($steps, 'type'));
        $this->assertSame($email, $steps[0]['settings']['email_campaign_id']);
        $this->assertSame(['amount' => 0, 'unit' => 'days', 'until' => '', 'weekdays_only' => true], $steps[1]['settings']);
    }

    public function test_the_trigger_can_change_on_a_draft_but_not_once_live()
    {
        $id = $this->automation([$this->email_step()]);
        Repo::update($id, ['status' => Repo::STATUS_DRAFT]);

        $this->post($id, 'save', ['name' => 'X', 'trigger_id' => 'tag_added']);
        $this->assertSame('tag_added', Repo::get($id)['trigger_id']);

        Repo::update($id, ['status' => Repo::STATUS_LIVE]);
        $this->post($id, 'save', ['name' => 'X', 'trigger_id' => 'list_joined']);
        $this->assertSame('tag_added', Repo::get($id)['trigger_id']);
    }

    public function test_adding_moving_pausing_duplicating_and_removing_steps()
    {
        $id = $this->automation([$this->email_step('First')]);
        Repo::update($id, ['status' => Repo::STATUS_DRAFT]);

        $this->post($id, 'add_wait');
        $this->post($id, 'add_email');
        $this->assertSame(['email', 'wait', 'email'], $this->types($id));
        $this->assertSame('New email', ER::get_customizer_value(Repo::steps($id)[2]['settings']['email_campaign_id'], 'email_campaign_title'));

        $wait = Repo::steps($id)[1]['id'];
        $this->post($id, 'up:' . $wait);
        $this->assertSame(['wait', 'email', 'email'], $this->types($id));

        $first = Repo::steps($id)[1];
        $this->post($id, 'pause:' . $first['id']);
        $this->assertTrue(Repo::steps($id)[1]['settings']['paused']);

        $this->post($id, 'duplicate_email:' . $first['id']);
        $steps = Repo::steps($id);
        $this->assertSame(['wait', 'email', 'email', 'email'], wp_list_pluck($steps, 'type'));
        $this->assertNotSame($steps[1]['settings']['email_campaign_id'], $steps[2]['settings']['email_campaign_id']);
        $this->assertSame('First', ER::get_customizer_value($steps[2]['settings']['email_campaign_id'], 'email_campaign_title'));

        $this->post($id, 'remove:' . $wait);
        $this->assertSame(['email', 'email', 'email'], $this->types($id));

        // A step of another automation can't be changed from this one.
        $other = $this->automation([$this->email_step()]);
        $this->post($id, 'remove:' . Repo::steps($other)[0]['id']);
        $this->assertCount(1, Repo::steps($other));
    }

    public function test_going_live_needs_an_email_with_a_subject()
    {
        $id = Repo::create(['name' => 'Empty', 'trigger_id' => 'test_event', 'status' => Repo::STATUS_DRAFT]);

        $this->post($id, 'go_live');
        $this->assertSame(Repo::STATUS_DRAFT, Repo::get($id)['status']);
        $this->assertStringContainsString('at least one email', get_transient('mo_automation_error_' . get_current_user_id()));

        $step = $this->email_step('');
        Repo::save_steps($id, [$step]);
        $this->post($id, 'go_live');
        $this->assertSame(Repo::STATUS_DRAFT, Repo::get($id)['status']);

        ER::update_settings_by_id($step['settings']['email_campaign_id'], ['email_campaign_title' => 'Hello']);
        $this->assertStringContainsString('mo_notice=live', $this->post($id, 'go_live'));
        $this->assertSame(Repo::STATUS_LIVE, Repo::get($id)['status']);

        $this->post($id, 'stop');
        $this->assertSame(Repo::STATUS_DRAFT, Repo::get($id)['status']);
    }

    public function test_actions_need_the_automations_own_nonce()
    {
        $id    = $this->automation([$this->email_step()]);
        $other = $this->automation([$this->email_step()]);

        $this->expectException(\WPDieException::class);
        $this->post($id, 'delete', [], wp_create_nonce('mo_automation_' . $other));
    }

    public function test_a_paused_email_is_passed_by()
    {
        $id    = $this->automation([$this->email_step('One'), $this->email_step('Two')]);
        $steps = Repo::steps($id);
        $steps[0]['settings']['paused'] = true;
        Repo::save_steps($id, $steps);

        add_filter('mailoptin_automation_daily_cap', '__return_false');
        self::$trigger->fire('pass@example.org', 'r1', ['number' => '1']);

        $this->assertSame(['Two'], wp_list_pluck($this->deliver(), 'subject'));
    }

    public function test_a_test_email_uses_sample_values_then_the_latest_real_person()
    {
        $id   = $this->automation([$this->email_step('Order {{order.number}}', '<p>Note: {{order.note}}</p>')]);
        $step = Repo::steps($id)[0];

        reset_phpmailer_instance();
        $this->assertTrue(Engine::send_test(Repo::get($id), $step, 'owner@example.org'));
        $sent = tests_retrieve_phpmailer_instance()->mock_sent;
        $this->assertSame('[Test] Order 1048', $sent[0]['subject']);

        self::$trigger->fire('real@example.org', 'r1', ['number' => '77', 'note' => '<i>gift</i>']);

        reset_phpmailer_instance();
        $this->assertStringContainsString('mo_notice=test_sent', $this->post($id, 'test:' . $step['id'], ['test_email' => 'owner@example.org']));
        $sent = tests_retrieve_phpmailer_instance()->mock_sent;
        $this->assertSame('owner@example.org', $sent[0]['to'][0][0]);
        $this->assertSame('[Test] Order 77', $sent[0]['subject']);
        $this->assertStringContainsString('&lt;i&gt;gift&lt;/i&gt;', $sent[0]['body']);
        $this->assertStringNotContainsString('mo_auto_unsub', $sent[0]['body']);
    }

    public function test_duplicating_copies_the_emails_and_deleting_removes_them()
    {
        $id     = $this->automation([$this->email_step('Original')]);
        $email  = Repo::steps($id)[0]['settings']['email_campaign_id'];

        $location = $this->post($id, 'duplicate');
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        $copy = Repo::get($query['id']);

        $this->assertSame(Repo::STATUS_DRAFT, $copy['status']);
        $copy_email = Repo::steps($copy['id'])[0]['settings']['email_campaign_id'];
        $this->assertNotSame($email, $copy_email);
        $this->assertSame('Original', ER::get_customizer_value($copy_email, 'email_campaign_title'));

        $this->post($id, 'delete');
        $this->assertNull(Repo::get($id));
        $this->assertEmpty(ER::get_email_campaign_by_id($email));
        $this->assertNotEmpty(ER::get_email_campaign_by_id($copy_email));
    }

    public function test_open_rates_count_only_subscribers()
    {
        $list = ListsRepository::create(['name' => 'Rates', 'double_optin' => 0]);
        Subscription::subscribe('member@example.org', $list);

        $id = $this->automation([$this->email_step()]);
        self::$trigger->fire('member@example.org', 'r1', ['number' => '1']);
        self::$trigger->fire('buyer@example.org', 'r2', ['number' => '2']);
        add_filter('mailoptin_automation_daily_cap', '__return_false');
        $this->assertCount(2, $this->deliver());

        $log = Engine::existing_log_id(Repo::steps($id)[0]['settings']['email_campaign_id']);
        $this->assertSame(1, Activity::stats($log)[Activity::METRIC_SENT]);
        $this->assertSame([Repo::steps($id)[0]['id'] => 2], Repo::step_sent_counts($id));
    }

    public function test_the_pages_render_escaped_and_show_the_journey_on_a_profile()
    {
        $list = ListsRepository::create(['name' => 'Journey', 'double_optin' => 0]);
        Subscription::subscribe('journey@example.org', $list);

        $id = $this->automation([['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 2, 'unit' => 'days']], $this->email_step('Hi <b>there</b>')]);
        Repo::update($id, ['name' => 'Tom & Jerry\'s "club"']);
        self::$trigger->fire('journey@example.org', 'r1', ['number' => '1']);

        $list_html = $this->render([]);
        $this->assertStringContainsString('Tom &amp; Jerry&#039;s &quot;club&quot;', $list_html);

        $edit = $this->render(['screen' => 'edit', 'id' => $id]);
        $this->assertStringContainsString('Hi &lt;b&gt;there&lt;/b&gt;', $edit);
        $this->assertStringContainsString('{{order.number}}', $edit, 'The placeholders list');

        $new = $this->render(['screen' => 'new']);
        $this->assertStringContainsString('value="welcome_series"', $new);

        ob_start();
        Automations_Page::get_instance()->profile_card(Subscribers::get_by_email('journey@example.org'));
        $card = ob_get_clean();
        $this->assertStringContainsString('In progress', $card);
        $this->assertStringContainsString('Tom &amp; Jerry&#039;s', $card);
    }

    public function test_the_list_join_count_counts_recent_subscribed_members()
    {
        $list  = ListsRepository::create(['name' => 'Count', 'double_optin' => 0]);
        $other = ListsRepository::create(['name' => 'Other', 'double_optin' => 0]);

        foreach (['a', 'b', 'c'] as $who) Subscription::subscribe("$who@example.org", $list);
        Subscription::subscribe('d@example.org', $other);
        Subscription::remove_by_admin(Subscribers::get_by_email('c@example.org')['id'], $list);

        $automation = ['id' => 0, 'trigger_settings' => ['list_id' => $list], 'audience' => []];
        $count      = Triggers::get_instance()->get('list_joined')->count_recent($automation);

        $this->assertSame(2, $count['matched']);
        $this->assertSame(2, $count['total']);

        // Tags to leave out need Premium's segments (not loaded in tests): no count rather than a wrong one.
        $tag = TagsRepository::create('skip');
        $automation['audience']['exclude_tag_ids'] = [$tag];
        $this->assertNull(Triggers::get_instance()->get('list_joined')->count_recent($automation));
    }

    public function test_free_sites_see_what_automations_are_for()
    {
        remove_all_filters('mailoptin_automations_available');
        add_filter('mailoptin_automations_available', '__return_false');

        $html = $this->render([]);

        $this->assertStringContainsString('Get MailOptin Premium', $html);
        $this->assertStringNotContainsString('Add New', $html);
    }

    public function test_emails_added_to_a_live_automation_start_paused()
    {
        $id    = $this->automation([$this->email_step('A'), ['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 1, 'unit' => 'days']], $this->email_step('B')]);
        $first = Repo::steps($id)[0];

        add_filter('mailoptin_automation_daily_cap', '__return_false');
        $run = self::$trigger->fire('partway@example.org', 'r1', ['number' => '1'])[0];
        $this->assertSame(['A'], wp_list_pluck($this->deliver(), 'subject'));

        $this->post($id, 'duplicate_email:' . $first['id']);
        $this->post($id, 'add_email');

        $steps = Repo::steps($id);
        $this->assertTrue($steps[1]['settings']['paused']);
        $this->assertTrue($steps[4]['settings']['paused']);
        $this->assertStringContainsString('paused', get_transient('mo_automation_warning_' . get_current_user_id()));

        $this->make_due($run);
        $this->assertSame(['B'], wp_list_pluck($this->deliver(), 'subject'), 'Not the copy of A, nor the unwritten new email.');
    }

    public function test_a_draft_keeps_a_trigger_that_is_not_available_right_now()
    {
        $id = $this->automation([$this->email_step()], ['only' => 'x']);
        Repo::update($id, ['status' => Repo::STATUS_DRAFT]);
        TestEventTrigger::$available = false;

        $html = $this->render(['screen' => 'edit', 'id' => $id]);
        $this->assertMatchesRegularExpression('/<option value="test_event" selected>[^<]*\(not available\)/', $html);

        $this->post($id, 'save', ['name' => 'Renamed', 'trigger_id' => 'test_event']);
        $this->assertSame('test_event', Repo::get($id)['trigger_id']);
        $this->assertSame('Renamed', Repo::get($id)['name']);
    }

    public function test_an_only_if_step_that_does_not_apply_blocks_going_live()
    {
        $id = Repo::create(['name' => 'Checks', 'trigger_id' => 'test_event', 'status' => Repo::STATUS_DRAFT]);
        Repo::save_steps($id, [['type' => Repo::STEP_CHECK, 'settings' => ['check' => 'is_vip']], $this->email_step('Hi')]);

        // Another trigger has no "is_vip": the step keeps it, unchosen, and says so.
        $this->post($id, 'save', ['name' => 'Checks', 'trigger_id' => 'tag_added']);
        $this->assertStringContainsString('Only if', get_transient('mo_automation_warning_' . get_current_user_id()));
        $this->assertStringContainsString('<option value="" selected>', $this->render(['screen' => 'edit', 'id' => $id]));

        $step = Repo::steps($id)[0];
        $this->post($id, 'save', ['name' => 'Checks', 'steps' => [['id' => $step['id'], 'type' => 'check', 'check' => '']], 'trigger' => ['tag_id' => TagsRepository::create('t')]]);
        $this->assertSame('', Repo::steps($id)[0]['settings']['check'], 'Not silently the first choice.');

        $this->post($id, 'go_live');
        $this->assertSame(Repo::STATUS_DRAFT, Repo::get($id)['status']);
        $this->assertStringContainsString('Only if', get_transient('mo_automation_error_' . get_current_user_id()));
    }

    public function test_going_live_needs_a_list_that_still_exists()
    {
        $list = ListsRepository::create(['name' => 'Gone', 'double_optin' => 0]);
        $id   = Repo::create(['name' => 'Gone', 'trigger_id' => 'list_joined', 'trigger_settings' => ['list_id' => $list], 'status' => Repo::STATUS_DRAFT]);
        Repo::save_steps($id, [$this->email_step('Hi')]);
        ListsRepository::delete($list);

        $this->post($id, 'go_live');
        $this->assertSame(Repo::STATUS_DRAFT, Repo::get($id)['status']);
    }

    public function test_reports_use_one_lookup_for_many_emails_and_label_every_stop()
    {
        $id     = $this->automation([$this->email_step('One'), $this->email_step('Two')]);
        $emails = wp_list_pluck(wp_list_pluck(Repo::steps($id), 'settings'), 'email_campaign_id');

        $this->assertSame([], Engine::existing_log_ids($emails));

        add_filter('mailoptin_automation_daily_cap', '__return_false');
        self::$trigger->fire('two@example.org', 'r1', ['number' => '1']);
        $this->deliver();

        $logs = Engine::existing_log_ids($emails);
        $this->assertCount(2, $logs);
        $this->assertSame(Engine::existing_log_id($emails[0]), $logs[$emails[0]]);
        $this->assertSame(['active' => 0, 'sent_30' => 2], Repo::automation_stats($id));
        $this->assertSame([Repo::get($id), Repo::steps($id)[1]], Repo::email_owner($emails[1]));

        $this->assertSame('Unsubscribed', Automations_Page::reason_label('unsubscribed'));
    }
}
