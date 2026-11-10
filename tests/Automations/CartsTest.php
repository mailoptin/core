<?php

namespace MailOptin\Tests\Core\Automations;

use MailOptin\Core\Automations\Engine;
use MailOptin\Core\Automations\Triggers\WooCartAbandoned;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;
use MailOptin\Core\Repositories\CartsRepository as Carts;

/**
 * The cart trigger as if WooCommerce were active (it isn't loaded in tests).
 */
class AvailableCartTrigger extends WooCartAbandoned
{
    public function is_available()
    {
        return true;
    }
}

/**
 * Abandoned carts: storage, which carts a scan starts, recovery, and the trigger's checks and
 * placeholders. Recording carts and restoring them need WooCommerce; they are tested on a store.
 */
class CartsTest extends EngineTest
{
    /** @var AvailableCartTrigger */
    protected $carts;

    public function set_up()
    {
        parent::set_up();

        $this->carts = new AvailableCartTrigger();
    }

    protected function cart($session, $email, $minutes_ago, $items = null, $total = 32.5)
    {
        $id = Carts::save([
            'session_key' => $session,
            'email'       => $email,
            'first_name'  => 'Maya',
            'items'       => $items ?: [['product_id' => 1, 'variation_id' => 0, 'variation' => [], 'quantity' => 2, 'name' => 'Tulip <b>bulbs</b>', 'line_total' => 32.5]],
            'total'       => $total,
            'currency'    => 'GBP',
        ]);

        global $wpdb;
        $wpdb->update(Carts::table(), ['updated_at' => gmdate('Y-m-d H:i:s', time() - $minutes_ago * MINUTE_IN_SECONDS)], ['id' => $id]);

        return $id;
    }

    protected function cart_automation($settings = [], $audience = [])
    {
        $id = Repo::create(['name' => 'Carts', 'trigger_id' => WooCartAbandoned::ID, 'trigger_settings' => $settings + ['delay' => 60], 'audience' => $audience, 'status' => Repo::STATUS_LIVE]);
        Repo::save_steps($id, [['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 1, 'unit' => 'days']], $this->email_step('Your cart')]);

        return $id;
    }

    protected function runs($automation_id)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Repo::runs_table() . ' WHERE automation_id = %d ORDER BY id', $automation_id), 'ARRAY_A');
    }

    public function test_saving_a_cart_again_updates_it_and_keeps_its_email()
    {
        $id = Carts::save(['session_key' => 's1', 'email' => 'Maya@Example.org', 'items' => [['name' => 'A', 'quantity' => 1]], 'total' => 5]);
        $this->assertSame($id, Carts::save(['session_key' => 's1', 'email' => '', 'items' => [['name' => 'B', 'quantity' => 3]], 'total' => 9]));

        $cart = Carts::get($id);
        $this->assertSame('maya@example.org', $cart['email']);
        $this->assertSame('B', $cart['items'][0]['name']);
        $this->assertSame(9.0, $cart['total']);
        $this->assertSame(Carts::STATUS_ACTIVE, $cart['status']);

        // Still being shopped: emptying it forgets it.
        Carts::release_session('s1', true);
        $this->assertNull(Carts::get($id));
    }

    public function test_an_abandoned_cart_keeps_its_email_and_emptying_it_stops_its_emails()
    {
        $automation = $this->cart_automation();
        $cart       = $this->cart('s2', 'first@example.org', 90);
        $this->carts->scan(Repo::get($automation));
        $run = Repo::get_run($this->runs($automation)[0]['id']);

        // A new address typed after the emails started doesn't take them over.
        Carts::save(['session_key' => 's2', 'email' => 'second@example.org', 'items' => [['name' => 'B', 'quantity' => 1]], 'total' => 3]);
        $this->assertSame('first@example.org', Carts::get($cart)['email']);

        Carts::release_session('s2', true);
        $this->assertSame('cart_emptied', $this->carts->stop_reason($run));
        $this->assertSame('gone-' . $cart, Carts::get($cart)['session_key'], 'The next cart of this session is a new one.');
        $this->assertNull(Carts::get_by_session('s2'));
    }

    public function test_a_scan_starts_carts_past_their_wait_once_and_marks_them_abandoned()
    {
        $automation = $this->cart_automation();
        $old        = $this->cart('old', 'old@example.org', 90);
        $recent     = $this->cart('recent', 'recent@example.org', 10);
        $ancient    = $this->cart('ancient', 'ancient@example.org', 8 * 24 * 60);
        $nobody     = $this->cart('nobody', '', 90);

        $this->carts->scan(Repo::get($automation));
        $this->carts->scan(Repo::get($automation));

        $this->assertSame(['old@example.org'], array_column($this->runs($automation), 'email'));
        $this->assertSame('cart-' . $old, $this->runs($automation)[0]['trigger_ref']);
        $this->assertSame(Carts::STATUS_ABANDONED, Carts::get($old)['status']);
        $this->assertNotEmpty(Carts::get($old)['abandoned_at']);
        $this->assertSame(Carts::STATUS_ACTIVE, Carts::get($recent)['status']);
        $this->assertSame(Carts::STATUS_ACTIVE, Carts::get($ancient)['status']);
        $this->assertSame(Carts::STATUS_ACTIVE, Carts::get($nobody)['status']);
        $this->assertSame([(int)$this->runs($automation)[0]['id']], Carts::run_ids($old));
    }

    public function test_carts_are_looked_at_once_even_when_nothing_starts_and_in_once_mode()
    {
        $once   = $this->cart_automation(['runs' => 'once']);
        $big    = $this->cart_automation([], ['min_total' => 1000]);
        $first  = $this->cart('one', 'same@example.org', 90);
        $second = $this->cart('two', 'same@example.org', 80);

        global $wpdb;
        foreach ([$once, $big] as $automation) $this->carts->scan(Repo::get($automation));

        $this->assertCount(1, $this->runs($once), 'Once per customer.');
        $this->assertSame('', $this->runs($once)[0]['trigger_ref']);
        $this->assertCount(0, $this->runs($big));
        $this->assertSame([(int)$this->runs($once)[0]['id']], Carts::run_ids($first), 'Found from the cart even though the run has no cart ref.');
        $this->assertSame(Carts::STATUS_ACTIVE, Carts::get($second)['status'], 'Not abandoned: no automation started for it.');

        // Nothing left for a later scan to do.
        foreach ([$once, $big] as $automation) {
            $this->assertSame([], Carts::due_for($automation, Carts::now(), Carts::now(-7 * DAY_IN_SECONDS), null, 200));
        }
    }

    public function test_the_audience_minimum_total_and_the_wait_are_per_automation()
    {
        $big   = $this->cart_automation(['delay' => 30], ['min_total' => 50]);
        $small = $this->cart_automation(['delay' => 120]);
        $cart  = $this->cart('c', 'c@example.org', 60, null, 20);

        $this->carts->scan(Repo::get($big));
        $this->carts->scan(Repo::get($small));

        $this->assertCount(0, $this->runs($big), 'Below the minimum total.');
        $this->assertCount(0, $this->runs($small), 'Its wait has not passed.');

        $this->assertSame(15, WooCartAbandoned::delay_minutes(['delay' => 1]), 'At least 15 minutes.');
    }

    public function test_an_ordered_or_emptied_cart_stops_its_emails()
    {
        $automation = $this->cart_automation();
        $cart       = $this->cart('o', 'o@example.org', 90);
        $this->carts->scan(Repo::get($automation));
        $run = Repo::get_run($this->runs($automation)[0]['id']);

        $this->assertSame('', $this->carts->stop_reason($run));
        $this->assertTrue($this->carts->checks()['not_recovered']['callback']($run));

        // Checked out but not paid (no such paid order): still abandoned, the reminders carry on.
        Carts::update($cart, ['order_id' => 77]);
        $this->assertSame('', $this->carts->stop_reason($run));

        Carts::update($cart, ['status' => Carts::STATUS_RECOVERED]);
        $this->assertSame('ordered', $this->carts->stop_reason($run));
        $this->assertFalse($this->carts->checks()['not_recovered']['callback']($run));

        Carts::delete($cart);
        $this->assertSame('cart_deleted', $this->carts->stop_reason($run));
    }

    public function test_placeholders_show_the_cart_escaped_in_emails()
    {
        $automation = $this->cart_automation();
        $this->cart('p', 'p@example.org', 90);
        $this->carts->scan(Repo::get($automation));
        $run = Repo::get_run($this->runs($automation)[0]['id']);

        $this->assertSame('Maya: Tulip &lt;b&gt;bulbs&lt;/b&gt; × 2', Engine::replace_placeholders('{{customer.first_name}}: {{cart.items}}', $this->carts, $run, true));
        $this->assertStringContainsString('mo_cart_restore=', Engine::replace_placeholders('{{cart.url}}', $this->carts, $run, false));
    }

    public function test_an_order_recovers_one_cart_its_own_or_the_newest_of_that_address()
    {
        $older = $this->cart('a', 'buyer@example.org', 900);
        $newer = $this->cart('b', 'buyer@example.org', 600);
        $this->cart('c', 'someone@example.org', 500);
        $this->cart('d', 'buyer@example.org', 40 * 24 * 60);

        $this->assertSame($newer, Carts::for_order(99, 'Buyer@example.org', true)['id']);
        $this->assertNull(Carts::for_order(99, 'buyer@example.org', false), 'An admin-made order or renewal recovers nothing by email alone.');

        Carts::update($older, ['order_id' => 99]);
        $this->assertSame($older, Carts::for_order(99, 'buyer@example.org', false)['id'], 'The checked-out cart first.');
    }

    public function test_the_carts_screen_views_and_recovery_numbers()
    {
        $automation = $this->cart_automation();
        $lost       = $this->cart('l', 'lost@example.org', 90);
        $going      = $this->cart('g', 'going@example.org', 90);
        $won        = $this->cart('w', 'won@example.org', 90);
        $this->carts->scan(Repo::get($automation));

        Carts::update($won, ['status' => Carts::STATUS_RECOVERED, 'recovered_total' => 40, 'recovered_at' => Carts::now()]);
        Repo::stop_runs(['email' => 'lost@example.org'], 'error');

        $this->assertSame(['' => 3, 'in_progress' => 1, 'recovered' => 1, 'lost' => 1], Carts::view_counts());
        $this->assertSame([$going], wp_list_pluck(Carts::page('in_progress')[0], 'id'));
        $this->assertSame(['abandoned' => 3, 'recovered' => 1, 'revenue' => 40.0], Carts::recovery_stats($automation, Carts::now(-DAY_IN_SECONDS)));
        $this->assertSame(['abandoned' => 0, 'recovered' => 0, 'revenue' => 0.0], Carts::recovery_stats($automation + 1000, Carts::now(-DAY_IN_SECONDS)));
        $this->assertSame([], Carts::sent_counts([$won, $going, $lost]), 'Nothing sent yet: their first step is a wait.');
    }

    public function test_old_carts_are_pruned_and_privacy_erases_them()
    {
        $old  = $this->cart('old', 'gone@example.org', 31 * 24 * 60);
        $keep = $this->cart('new', 'gone@example.org', 10);

        Carts::prune();
        $this->assertNull(Carts::get($old));
        $this->assertNotNull(Carts::get($keep));

        $erasers = apply_filters('wp_privacy_personal_data_erasers', []);
        $result  = call_user_func($erasers['mailoptin-carts']['callback'], 'gone@example.org');
        $this->assertTrue($result['items_removed']);
        $this->assertNull(Carts::get($keep));
    }

    public function test_the_live_flag_follows_cart_automations()
    {
        delete_option(\MailOptin\Core\Automations\Carts::LIVE_OPTION);

        $id = $this->cart_automation();
        // Cart automations need WooCommerce, which tests don't load: the flag still follows the table.
        $this->assertSame('1', (string)get_option(\MailOptin\Core\Automations\Carts::LIVE_OPTION));

        Repo::update($id, ['status' => Repo::STATUS_DRAFT]);
        $this->assertSame('0', (string)get_option(\MailOptin\Core\Automations\Carts::LIVE_OPTION));
    }

    public function test_guest_carts_are_off_unless_turned_on()
    {
        $this->assertFalse((new WooCartAbandoned())->settings_fields()['guests']['default']);

        $id = $this->cart_automation();
        // records_guests() needs WooCommerce (not loaded in tests): an automation without the setting has it off.
        $this->assertArrayNotHasKey('guests', Repo::get($id)['trigger_settings']);
    }
}
