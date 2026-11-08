<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\Subscribers_Suppressions as Screen;
use MailOptin\Core\Audience\DeliveryEvents;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository as Suppressions;
use WP_UnitTestCase;

/**
 * Subscribers > Suppression list.
 */
class SuppressionListTest extends WP_UnitTestCase
{
    protected $list_id;

    public function set_up()
    {
        parent::set_up();

        foreach ([17, 20, 21, 22] as $version) DBUpdates::get_instance()->{'update_routine_' . $version}();
        update_option('mo_db_ver', DBUpdates::DB_VER);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->list_id = ListsRepository::create(['name' => 'Newsletter']);
    }

    public function tear_down()
    {
        $_GET = $_POST = $_REQUEST = [];
        parent::tear_down();
    }

    protected function member($email)
    {
        $id = Subscribers::create(['email' => $email, 'status' => Subscribers::STATUS_SUBSCRIBED]);
        Subscribers::set_membership($id, $this->list_id, Subscribers::STATUS_SUBSCRIBED);

        return $id;
    }

    protected function events($subscriber_id)
    {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare('SELECT type FROM ' . Subscribers::events_table() . ' WHERE subscriber_id = %d ORDER BY id', $subscriber_id));
    }

    public function test_rows_show_the_address_only_when_a_subscriber_has_it()
    {
        $this->member('Ana@Example.org');
        Suppressions::add('ana@example.org', Suppressions::REASON_UNSUBSCRIBED, 'unsubscribe_link');
        Suppressions::add('gone@example.org', Suppressions::REASON_COMPLAINED, 'ses');

        $rows = [];
        foreach (Suppressions::page('', 20, 0) as $row) $rows[$row['reason']] = $row;

        $this->assertSame('ana@example.org', $rows['unsubscribed']['email']);
        $this->assertGreaterThan(0, $rows['unsubscribed']['subscriber_id']);
        $this->assertSame('', $rows['complained']['email']);

        $this->assertEquals(['all' => 2, 'unsubscribed' => 1, 'complained' => 1], array_intersect_key(Suppressions::counts(), ['all' => 1, 'unsubscribed' => 1, 'complained' => 1]));
        $this->assertCount(1, Suppressions::page(Suppressions::REASON_COMPLAINED, 20, 0));
    }

    public function test_adding_by_hand_lists_invalid_lines_and_unsubscribes_subscribers()
    {
        $id = $this->member('tom@example.org');
        Suppressions::add('old@example.org', Suppressions::REASON_BOUNCED, 'postmark');

        $result = Screen::add_by_hand("tom@example.org\n\"New@Example.net\", old@example.org\nnot an address\n\nnew@example.net\t<x@y>");

        $this->assertSame(2, $result['added']);
        $this->assertSame(1, $result['already']);
        $this->assertSame(1, $result['unsubscribed']);
        $this->assertSame(['not an address', 'x@y'], $result['invalid']);

        $this->assertSame(Suppressions::REASON_MANUAL, Suppressions::get_reason('tom@example.org'));
        $this->assertSame(Suppressions::REASON_MANUAL, Suppressions::get_reason('new@example.net'));
        // An existing bounce keeps its reason.
        $this->assertSame(Suppressions::REASON_BOUNCED, Suppressions::get_reason('old@example.org'));

        $this->assertSame(Subscribers::STATUS_UNSUBSCRIBED, Subscribers::get_membership($id, $this->list_id)['status']);
        $this->assertContains(Subscribers::EVENT_SUPPRESSED, $this->events($id));
    }

    public function test_an_address_added_by_hand_is_never_emailed_not_even_to_confirm()
    {
        Screen::add_by_hand('quiet@example.org');

        $result = Subscription::subscribe('quiet@example.org', $this->list_id);

        $this->assertSame(Subscription::RESULT_REFUSED, $result['result']);
    }

    public function test_lifting_an_unsubscribe_doesnt_add_them_back_to_lists()
    {
        $id = $this->member('ana@example.org');
        Subscription::unsubscribe($id);
        $hash = Suppressions::hash('ana@example.org');

        $this->assertSame('lifted', Screen::lift($hash, false));
        $this->assertNull(Suppressions::get_reason('ana@example.org'));
        $this->assertSame(Subscribers::STATUS_UNSUBSCRIBED, Subscribers::get_membership($id, $this->list_id)['status']);
        $this->assertContains(Subscribers::EVENT_SUPPRESSION_LIFTED, $this->events($id));

        $this->assertSame('lift_missing', Screen::lift($hash, false));
    }

    public function test_a_bounce_needs_the_box_ticked_and_goes_through_resubscribe()
    {
        $id = $this->member('tom@example.org');
        DeliveryEvents::record(['type' => 'hard_bounce', 'email' => 'tom@example.org', 'provider' => 'postmark']);
        $hash = Suppressions::hash('tom@example.org');

        $this->assertSame('lift_unchecked', Screen::lift($hash, false));
        $this->assertSame(Suppressions::REASON_BOUNCED, Suppressions::get_reason('tom@example.org'));

        $this->assertSame('lifted', Screen::lift($hash, true));
        $this->assertNull(Suppressions::get_reason('tom@example.org'));
        $this->assertNotSame(Subscribers::STATUS_BOUNCED, Subscribers::get($id)['status']);
        $this->assertContains(Subscribers::EVENT_RESUBSCRIBED, $this->events($id));
    }

    public function test_a_bounce_with_no_subscriber_is_lifted_by_hash()
    {
        Suppressions::add('gone@example.org', Suppressions::REASON_COMPLAINED, 'ses');

        $this->assertSame('lifted', Screen::lift(Suppressions::hash('gone@example.org'), true));
        $this->assertNull(Suppressions::get_reason('gone@example.org'));
        $this->assertSame('lift_missing', Screen::lift('not-a-hash', true));
    }

    public function test_the_screen_renders_and_escapes()
    {
        $this->member('ana@example.org');
        Suppressions::add('ana@example.org', Suppressions::REASON_UNSUBSCRIBED, 'unsubscribe_link');
        Suppressions::add('gone@example.org', Suppressions::REASON_BOUNCED, '<script>x</script>');

        $_GET = ['page' => 'mailoptin-subscribers', 'view' => Screen::TAB];
        $html = Screen::html();

        $this->assertStringContainsString('ana@example.org', $html);
        $this->assertStringContainsString('Address not stored', $html);
        $this->assertStringContainsString('Marked as spam', $html);
        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringNotContainsString('gone@example.org', $html);

        // Check an address: the address typed is shown back, escaped.
        $_POST = $_REQUEST = ['mo_suppression_check' => '1', 'check_email' => 'gone@example.org', '_wpnonce' => wp_create_nonce('mo_suppression_check')];
        $html = Screen::html();
        $this->assertStringContainsString('<strong>gone@example.org</strong> is suppressed', $html);
        $this->assertStringContainsString('lift=' . Suppressions::hash('gone@example.org'), $html);

        // The lift panel warns and asks for the tick box for a bounce.
        $_POST = $_REQUEST = [];
        $_GET['lift'] = Suppressions::hash('gone@example.org');
        $html = Screen::html();
        $this->assertStringContainsString('name="checked"', $html);
        $this->assertStringContainsString('<strong>bounced</strong>', $html);
    }
}
