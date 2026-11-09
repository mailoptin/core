<?php

namespace MailOptin\Tests\Core\Automations;

use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Automations\Engine;
use MailOptin\Core\Automations\Triggers;
use MailOptin\Core\Automations\Triggers\DateField;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository;

class BuiltInTriggersTest extends EngineTest
{
    protected function automation_for($trigger_id, $trigger_settings, $steps = null)
    {
        $id = Repo::create(['name' => 'Built in', 'trigger_id' => $trigger_id, 'trigger_settings' => $trigger_settings, 'status' => Repo::STATUS_LIVE]);
        Repo::save_steps($id, $steps ?: [['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 1, 'unit' => 'days']], $this->email_step('Hello {{list.name}}')]);

        return $id;
    }

    protected function runs($automation_id)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Repo::runs_table() . ' WHERE automation_id = %d', $automation_id), 'ARRAY_A');
    }

    public function test_the_registry_lists_built_in_triggers_and_ones_added_by_plugins()
    {
        $all = Triggers::get_instance()->all(false);

        foreach (['list_joined', 'tag_added', 'date_field', 'woo_order_status', 'woo_no_order', 'test_event'] as $id) {
            $this->assertArrayHasKey($id, $all);
        }

        $this->assertGreaterThan(0, TestEventTrigger::$listened);
    }

    public function test_joining_a_list_starts_it_once_and_leaving_stops_it()
    {
        $newsletter = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 0]);
        $other      = ListsRepository::create(['name' => 'Other', 'double_optin' => 0]);
        $id         = $this->automation_for('list_joined', ['list_id' => $newsletter, 'runs' => 'once']);

        Subscription::subscribe('join@example.org', $other);
        $this->assertCount(0, $this->runs($id));

        Subscription::subscribe('join@example.org', $newsletter);
        $runs = $this->runs($id);
        $this->assertCount(1, $runs);

        // The placeholder, as the email will show it.
        $run = Repo::get_run($runs[0]['id']);
        $this->assertSame('Hello Newsletter', Engine::replace_placeholders('Hello {{list.name}}', Triggers::get_instance()->get('list_joined'), $run, false));

        // Leaving the list stops it when it next runs.
        $subscriber = Subscribers::get_by_email('join@example.org');
        Subscription::remove_by_admin($subscriber['id'], $newsletter);
        $this->make_due($run['id']);
        Engine::get_instance()->run(time() + 5);
        $this->assertSame('left_list', Repo::get_run($run['id'])['stop_reason']);
    }

    public function test_double_opt_in_starts_when_they_confirm()
    {
        $list = ListsRepository::create(['name' => 'Confirmed', 'double_optin' => 1]);
        $id   = $this->automation_for('list_joined', ['list_id' => $list]);

        Subscription::subscribe('confirm@example.org', $list);
        $this->assertCount(0, $this->runs($id));

        $subscriber = Subscribers::get_by_email('confirm@example.org');
        Subscription::confirm($subscriber['id']);
        $this->assertCount(1, $this->runs($id));
    }

    public function test_getting_a_tag_starts_it_and_losing_it_stops_it()
    {
        $list = ListsRepository::create(['name' => 'Tags', 'double_optin' => 0]);
        Subscription::subscribe('tag@example.org', $list);
        $subscriber = Subscribers::get_by_email('tag@example.org');
        $vip        = TagsRepository::create('vip');
        $other      = TagsRepository::create('other');

        $id = $this->automation_for('tag_added', ['tag_id' => $vip], [['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 1, 'unit' => 'days']], $this->email_step('VIP {{tag.name}}')]);

        TagsRepository::add_to_subscribers([$other], [$subscriber['id']]);
        $this->assertCount(0, $this->runs($id));

        TagsRepository::add_to_subscribers([$vip], [$subscriber['id']]);
        // Adding it again isn't a new event.
        TagsRepository::add_to_subscribers([$vip], [$subscriber['id']]);
        $runs = $this->runs($id);
        $this->assertCount(1, $runs);

        TagsRepository::remove_from_subscribers([$vip], [$subscriber['id']]);
        $this->make_due($runs[0]['id']);
        Engine::get_instance()->run(time() + 5);
        $this->assertSame('tag_removed', Repo::get_run($runs[0]['id'])['stop_reason']);
    }

    public function test_a_date_field_starts_people_once_a_year_from_the_given_time()
    {
        $list = ListsRepository::create(['name' => 'Birthdays', 'double_optin' => 0]);
        $tz   = wp_timezone();
        $now  = new \DateTimeImmutable('now', $tz);

        $field = Fields::create(['label' => 'Birthday', 'type' => Fields::TYPE_DATE]);
        $field = is_array($field) ? $field['id'] : $field;

        foreach (['today@example.org' => $now->format('1990-m-d'), 'tomorrow@example.org' => $now->modify('+1 day')->format('1985-m-d')] as $email => $date) {
            Subscription::subscribe($email, $list);
            Fields::set_values(Subscribers::get_by_email($email)['id'], [$field => $date]);
        }

        // Before the start time: nobody yet.
        $this->assertNull(DateField::target_date(['time' => '23:59'], $now->setTime(0, 1)->getTimestamp()));

        $id         = $this->automation_for('date_field', ['field_id' => $field, 'days_before' => 0, 'time' => '00:00']);
        $automation = Repo::get($id);
        $trigger    = Triggers::get_instance()->get('date_field');

        $trigger->scan($automation);
        $trigger->scan($automation);

        $runs = $this->runs($id);
        $this->assertSame(['today@example.org'], array_column($runs, 'email'));
        $this->assertSame($now->format('Y'), $runs[0]['trigger_ref']);

        // A day ahead finds tomorrow's.
        Repo::update($id, ['trigger_settings' => ['field_id' => $field, 'days_before' => 1, 'time' => '00:00']]);
        $trigger->scan(Repo::get($id));
        $this->assertContains('tomorrow@example.org', array_column($this->runs($id), 'email'));
    }

    public function test_triggers_list_their_settings_and_placeholders_without_errors()
    {
        foreach (Triggers::get_instance()->all(false) as $trigger) {
            foreach (array_merge($trigger->settings_fields(), $trigger->audience_fields()) as $key => $field) {
                $this->assertArrayHasKey('label', $field, $trigger->id() . ' ' . $key);
                $this->assertArrayHasKey('type', $field, $trigger->id() . ' ' . $key);
            }

            foreach (Engine::placeholders($trigger) as $key => $placeholder) {
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*\.[a-z0-9_.]+$/', $key);
                $this->assertIsCallable($placeholder['value']);
            }

            foreach (Engine::checks($trigger) as $check) {
                $this->assertIsCallable($check['callback']);
            }
        }
    }

    public function test_a_scan_only_starts_the_automation_it_is_scanning()
    {
        $list  = ListsRepository::create(['name' => 'Two birthdays', 'double_optin' => 0]);
        $week  = (new \DateTimeImmutable('now', wp_timezone()))->modify('+7 days');
        $field = Fields::create(['label' => 'Birthday two', 'type' => Fields::TYPE_DATE]);
        $field = is_array($field) ? $field['id'] : $field;

        Subscription::subscribe('week@example.org', $list);
        Fields::set_values(Subscribers::get_by_email('week@example.org')['id'], [$field => $week->format('1990-m-d')]);

        $on_the_day = $this->automation_for('date_field', ['field_id' => $field, 'days_before' => 0, 'time' => '00:00']);
        $week_ahead = $this->automation_for('date_field', ['field_id' => $field, 'days_before' => 7, 'time' => '00:00']);

        $trigger = Triggers::get_instance()->get('date_field');
        $trigger->scan(Repo::get($week_ahead));
        $trigger->scan(Repo::get($on_the_day));

        $this->assertCount(1, $this->runs($week_ahead));
        // A week early for this one: it starts on the day, not now.
        $this->assertCount(0, $this->runs($on_the_day));
    }

    public function test_a_tag_on_someone_who_has_not_confirmed_starts_nothing()
    {
        $list = ListsRepository::create(['name' => 'Ebook', 'double_optin' => 1]);
        $tag  = TagsRepository::create('ebook');
        $id   = $this->automation_for('tag_added', ['tag_id' => $tag], [$this->email_step('Ebook')]);

        // A public form with double opt-in, and someone else's address typed in.
        Subscription::subscribe('victim@example.org', $list, ['tags' => [$tag]]);

        $this->assertCount(0, $this->runs($id));
        $this->assertCount(0, $this->deliver());
    }

    public function test_someone_who_unsubscribes_while_waiting_gets_nothing_more()
    {
        $list = ListsRepository::create(['name' => 'Tagged', 'double_optin' => 0]);
        Subscription::subscribe('leaver@example.org', $list);
        $subscriber = Subscribers::get_by_email('leaver@example.org');
        $tag        = TagsRepository::create('leaver');
        $id         = $this->automation_for('tag_added', ['tag_id' => $tag], [['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 1, 'unit' => 'days']], $this->email_step('Later')]);

        TagsRepository::add_to_subscribers([$tag], [$subscriber['id']]);
        $run = $this->runs($id)[0];

        global $wpdb;
        $wpdb->update(Subscribers::table(), ['status' => Subscribers::STATUS_UNSUBSCRIBED], ['id' => $subscriber['id']]);
        $this->make_due($run['id']);

        $this->assertCount(0, $this->deliver());
        $this->assertSame('not_subscribed', Repo::get_run($run['id'])['stop_reason']);
    }

    public function test_bulk_tagging_with_a_tag_nobody_automates_does_no_per_person_work()
    {
        $list = ListsRepository::create(['name' => 'Bulk', 'double_optin' => 0]);
        $ids  = [];
        for ($i = 0; $i < 50; $i++) {
            Subscription::subscribe("bulk$i@example.org", $list);
            $ids[] = Subscribers::get_by_email("bulk$i@example.org")['id'];
        }
        $watched   = TagsRepository::create('watched');
        $unwatched = TagsRepository::create('unwatched');
        $this->automation_for('tag_added', ['tag_id' => $watched]);

        global $wpdb;
        $before = $wpdb->num_queries;
        do_action('mo_subscriber_tags_added', array_fill_keys($ids, [$unwatched]));

        $this->assertLessThanOrEqual(2, $wpdb->num_queries - $before);
    }
}
