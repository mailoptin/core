<?php

namespace MailOptin\Tests\Core\Automations;

use MailOptin\Core\Admin\SettingsPage\Automations_Page;
use MailOptin\Core\Automations\Recipes;
use MailOptin\Core\Automations\Triggers;
use MailOptin\Core\Automations\Triggers\WooOrderCount;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * "A customer reaches a number of orders" and the "Reward repeat customers" recipe. Counting orders
 * and reading their coupons need WooCommerce (not loaded in tests): they are checked on a store.
 */
class RepeatRewardTest extends \WP_UnitTestCase
{
    protected function hits($count, $settings, $audience = [], $context = [])
    {
        return (new WooOrderCount())->matches(['order_id' => 1, 'count' => $count] + $context, $settings, $audience);
    }

    public function test_it_is_registered_with_the_shop_triggers()
    {
        $trigger = Triggers::get_instance()->get('woo_order_count');

        $this->assertInstanceOf(WooOrderCount::class, $trigger);
        $this->assertSame('WooCommerce', $trigger->group());
        $this->assertSame('woocommerce', $trigger->store(), 'Its emails can make discount codes.');
        $this->assertSame(['order'], $trigger->provides());
        $this->assertArrayHasKey('customer.order_count', $trigger->placeholders());
        $this->assertArrayHasKey('not_ordered_again', $trigger->checks());
        $this->assertArrayNotHasKey('not_reviewed', $trigger->checks());
    }

    public function test_once_starts_only_at_the_number()
    {
        $once = ['orders' => 3, 'runs' => 'once'];

        $this->assertFalse($this->hits(2, $once));
        $this->assertTrue($this->hits(3, $once));
        $this->assertFalse($this->hits(4, $once), 'Customers past the number are never rewarded for it.');
        $this->assertFalse($this->hits(6, $once));
        $this->assertFalse($this->hits(0, $once));
    }

    public function test_every_starts_at_each_multiple()
    {
        $every = ['orders' => 3, 'runs' => 'every'];

        foreach ([3, 6, 9, 30] as $count) $this->assertTrue($this->hits($count, $every), (string)$count);
        foreach ([1, 2, 4, 5, 7] as $count) $this->assertFalse($this->hits($count, $every), (string)$count);
    }

    public function test_the_number_is_at_least_two()
    {
        // 1 would reward every first order (that's a welcome, not a repeat), 0 would divide by zero.
        foreach ([0, 1, '', 'x'] as $orders) {
            $this->assertTrue($this->hits(2, ['orders' => $orders, 'runs' => 'once']), var_export($orders, true));
            $this->assertTrue($this->hits(4, ['orders' => $orders, 'runs' => 'every']), var_export($orders, true));
            $this->assertFalse($this->hits(1, ['orders' => $orders, 'runs' => 'every']), var_export($orders, true));
        }
    }

    public function test_the_number_stays_within_its_limits_and_once_is_the_engine_s_once()
    {
        $trigger = new WooOrderCount();

        $this->assertTrue($this->hits(100, ['orders' => 5000, 'runs' => 'once']), 'At most 100.');
        $this->assertSame('once', $trigger->default_runs());
        $this->assertArrayHasKey('runs', $trigger->settings_fields(), 'The engine keeps a "once" run for good, so it is never sent twice.');
        $this->assertArrayNotHasKey('repeat', $trigger->settings_fields());
    }

    public function test_an_order_paid_with_a_reward_code_starts_no_reward()
    {
        $this->assertFalse($this->hits(6, ['orders' => 3, 'runs' => 'every'], [], ['reward_code' => 1]));
        $this->assertFalse($this->hits(3, ['orders' => 3, 'runs' => 'once'], [], ['reward_code' => 1]));
    }

    public function test_it_says_what_starts_it_in_plain_words()
    {
        $trigger = new WooOrderCount();

        $this->assertSame('A customer reaches 3 paid orders (once per customer)', $trigger->summary(['orders' => 3, 'runs' => 'once']));
        $this->assertSame('A customer reaches 4, 8, 12… paid orders', $trigger->summary(['orders' => 4, 'runs' => 'every']));

        // The automations list and the builder's "In plain words" use it.
        $method = new \ReflectionMethod(Automations_Page::class, 'starts_when');
        $method->setAccessible(true);
        $text = $method->invoke(null, ['trigger_settings' => ['orders' => 5, 'runs' => 'once']], $trigger);
        $this->assertStringStartsWith('A customer reaches 5 paid orders (once per customer)', $text);
    }

    public function test_a_recipe_email_can_end_with_a_discount_code()
    {
        $id     = Recipes::create_email(1, 'Thank you for coming back', '<p>Hi {{customer.first_name|there}},</p>', 'Lucid', ['discount_type' => 'percent', 'amount' => '15', 'expiry_days' => '30', 'prefix' => 'THANKS<b>', 'code_mode' => 'earlier']);
        $blocks = json_decode(ER::get_customizer_value($id, 'email_newsletter_content'), true);

        $this->assertSame(['text', 'discount_code'], array_column($blocks, 'type'));
        $this->assertSame('15', $blocks[1]['settings']['amount']);
        $this->assertSame('earlier', $blocks[1]['settings']['code_mode']);
        $this->assertSame('THANKS', $blocks[1]['settings']['prefix'], 'Settings are cleaned.');

        // Without one, as before: a single text block.
        $plain = Recipes::create_email(1, 'Hello', '<p>Hi</p>');
        $this->assertSame(['text'], array_column(json_decode(ER::get_customizer_value($plain, 'email_newsletter_content'), true), 'type'));
    }
}
