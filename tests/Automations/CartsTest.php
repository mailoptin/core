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

        Carts::delete_active('s1');
        $this->assertNull(Carts::get($id));
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

        Carts::update($cart, ['status' => Carts::STATUS_RECOVERED, 'order_id' => 77]);
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

    public function test_orders_find_the_checked_out_cart_and_open_carts_of_the_same_address()
    {
        $checked_out = $this->cart('a', 'buyer@example.org', 5);
        $abandoned   = $this->cart('b', 'buyer@example.org', 600);
        $other       = $this->cart('c', 'someone@example.org', 600);
        $stale       = $this->cart('d', 'buyer@example.org', 40 * 24 * 60);

        Carts::update($checked_out, ['order_id' => 99]);
        Carts::update($abandoned, ['status' => Carts::STATUS_ABANDONED, 'abandoned_at' => Carts::now(-500 * MINUTE_IN_SECONDS)]);

        $found = wp_list_pluck(Carts::for_order(99, 'Buyer@example.org'), 'id');
        sort($found);

        $this->assertSame([$checked_out, $abandoned], $found);
        $this->assertNotContains($other, $found);
        $this->assertNotContains($stale, $found);
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
        $this->assertSame(['abandoned' => 3, 'recovered' => 1, 'revenue' => 40.0], Carts::recovery_stats(Carts::now(-DAY_IN_SECONDS)));
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
}
