<?php

namespace MailOptin\Tests\Core\Automations;

use MailOptin\Core\Admin\EmailEditor\EmailEditor;
use MailOptin\Core\Automations\Engine;
use MailOptin\Core\Automations\Stores\Store;
use MailOptin\Core\Automations\Stores\Stores;
use MailOptin\Core\Automations\Triggers;
use MailOptin\Core\EmailBlocks\Blocks;
use MailOptin\Core\EmailBlocks\Dynamic;
use MailOptin\Core\EmailCampaigns\Newsletter\Templatify;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/** A shop for tests: carts come from the run's context, codes are counted. */
class FakeStore extends Store
{
    public static $coupons = [];
    public static $existing = ['WELCOME10'];

    public function id() { return 'fakeshop'; }
    public function name() { return 'Fake shop'; }
    public function active() { return true; }
    public function money($amount, $currency = '') { return '£' . number_format((float)$amount, 2); }

    public function cart($context)
    {
        return isset($context['cart']) ? $context['cart'] + ['currency' => 'GBP', 'url' => 'https://shop.test/cart?restore=abc'] : null;
    }

    public function coupon_features() { return [self::COUPON_PERCENT, self::COUPON_EXPIRY, self::COUPON_PER_PERSON]; }

    public function create_coupon($args)
    {
        if (isset(self::$coupons[$args['key']])) return self::$coupons[$args['key']];

        $made = ['code' => 'SAVE-' . strtoupper(substr(md5($args['key']), 0, 6)), 'expires' => gmdate('Y-m-d', time() + $args['expiry_days'] * DAY_IN_SECONDS), 'args' => $args];
        self::$coupons[$args['key']] = $made;

        return $made;
    }

    public function coupon_exists($code) { return in_array($code, self::$existing, true); }
    public function apply_coupon_url($code) { return 'https://shop.test/cart?mo_coupon=' . rawurlencode($code); }
}

class CartTestTrigger extends TestEventTrigger
{
    public function id() { return 'test_cart'; }
    public function store() { return 'fakeshop'; }
    public function provides() { return ['cart']; }
}

/**
 * Cart contents, Products bought and Discount code: drawn for each person when their email is
 * filled in, from the shop the trigger names.
 */
class ShopBlocksTest extends EngineTest
{
    /** @var CartTestTrigger */
    protected static $cart_trigger;

    public function set_up()
    {
        parent::set_up();

        add_filter('mailoptin_automation_stores', function ($stores) { return array_merge($stores, [new FakeStore()]); });
        Stores::reset();
        FakeStore::$coupons = [];

        if ( ! self::$cart_trigger) {
            self::$cart_trigger = new CartTestTrigger();
            Triggers::get_instance()->add(self::$cart_trigger);
        }
    }

    public function tear_down()
    {
        Stores::reset();
        parent::tear_down();
    }

    protected function cart_email($blocks)
    {
        $id = ER::add_email_campaign('Cart email ' . uniqid(), Repo::EMAIL_CAMPAIGN_TYPE, 'Lucid');
        ER::update_settings_by_id($id, ['email_campaign_title' => 'You left something', 'email_newsletter_content' => wp_json_encode($blocks)]);

        return $id;
    }

    protected function cart_automation($email_id, $trigger = 'test_cart')
    {
        $id = Repo::create(['name' => 'Carts', 'trigger_id' => $trigger, 'status' => Repo::STATUS_LIVE]);
        Repo::save_steps($id, [['type' => Repo::STEP_EMAIL, 'settings' => ['email_campaign_id' => $email_id]]]);

        return $id;
    }

    protected function cart()
    {
        return ['items' => [
            ['name' => 'Tulip <b>bulbs</b>', 'quantity' => 2, 'total' => 18, 'image' => 'https://shop.test/tulip.jpg', 'url' => 'https://shop.test/tulips'],
            ['name' => 'Daffodil mix', 'quantity' => 1, 'total' => 14.5, 'image' => '', 'url' => ''],
            ['name' => 'Crocus', 'quantity' => 1, 'total' => 4, 'image' => '', 'url' => ''],
        ], 'total' => 36.5];
    }

    public function test_they_are_offered_only_where_the_trigger_has_what_they_show()
    {
        $email = $this->cart_email([]);
        $this->cart_automation($email);

        $offered = array_keys(Blocks::get_instance()->available_for($email));
        $this->assertContains('cart_contents', $offered);
        $this->assertContains('discount_code', $offered);
        $this->assertNotContains('products_bought', $offered, 'The trigger has a cart, not an order.');

        // Not a shop's trigger: none of them.
        $other = $this->cart_email([]);
        $this->cart_automation($other, 'test_event');
        $this->assertEmpty(array_intersect(['cart_contents', 'products_bought', 'discount_code'], array_keys(Blocks::get_instance()->available_for($other))));

        // A broadcast: none.
        $broadcast = ER::add_email_campaign('B ' . uniqid(), ER::NEWSLETTER, 'Lucid');
        $this->assertArrayNotHasKey('cart_contents', Blocks::get_instance()->available_for($broadcast));
    }

    public function test_the_cart_is_drawn_for_each_person_when_sent()
    {
        $email = $this->cart_email([
            ['id' => 'c1', 'type' => 'cart_contents', 'settings' => ['max_items' => '2']],
        ]);
        $this->cart_automation($email);

        // Drawn once with a marker in its place, which survives the CSS inlining.
        $this->assertTrue(Dynamic::has_markers((new Templatify($email))->forge()));

        self::$cart_trigger->fire('maya@example.org', 'cart-1', ['cart' => $this->cart()]);
        $sent = $this->deliver();

        $this->assertCount(1, $sent);
        // The HTML part is quoted-printable.
        $body = quoted_printable_decode($sent[0]['body']);
        $this->assertStringContainsString('Tulip &lt;b&gt;bulbs&lt;/b&gt;', $body, 'Names are escaped.');
        $this->assertStringContainsString('£18.00', $body);
        $this->assertStringContainsString('https://shop.test/tulip.jpg', $body);
        $this->assertStringContainsString('and 1 more', $body, 'Two shown, of three.');
        $this->assertStringNotContainsString('Crocus', $body);
        $this->assertStringContainsString('£36.50', $body, 'The total.');
        $this->assertStringContainsString('Return to your cart', $body);
        $this->assertStringContainsString('restore=abc', $body);
        $this->assertFalse(Dynamic::has_markers($body));
    }

    public function test_a_discount_code_is_made_once_per_person_when_sent_and_never_for_a_preview()
    {
        $email = $this->cart_email([
            ['id' => 'd1', 'type' => 'discount_code', 'settings' => ['discount_type' => 'percent', 'amount' => '15', 'expiry_days' => '7', 'prefix' => 'BACK', 'exclude_sale' => true]],
        ]);
        $automation = $this->cart_automation($email);

        // The editor's preview: a sample code, no coupon.
        $preview = EmailEditor::context('automation')->preview_html($email, (new Templatify($email))->forge());
        $this->assertStringContainsString('BACK-SAMPLE', $preview);
        $this->assertStringContainsString('15% off', $preview);
        $this->assertSame([], FakeStore::$coupons);

        $run  = self::$cart_trigger->fire('ana@example.org', 'cart-2', ['cart' => $this->cart()])[0];
        $sent = $this->deliver();

        $this->assertCount(1, FakeStore::$coupons);
        $coupon = reset(FakeStore::$coupons);
        $this->assertSame('ana@example.org', $coupon['args']['email'], 'Only they can use it.');
        $this->assertSame($automation, $coupon['args']['automation_id']);
        $this->assertFalse($coupon['args']['exclude_sale'], 'An option this shop can\'t do is left out.');
        $body = quoted_printable_decode($sent[0]['body']);
        $this->assertStringContainsString($coupon['code'], $body);
        $this->assertStringContainsString('mo_coupon=' . $coupon['code'], $body);

        // A retried send of the same email gives the same code.
        $again = Dynamic::expand((new Templatify($email))->forge(), ['trigger' => self::$cart_trigger, 'run' => Repo::get_run($run), 'mode' => 'send', 'email_campaign_id' => $email]);
        $this->assertStringContainsString($coupon['code'], $again);
        $this->assertCount(1, FakeStore::$coupons);

        // A test: the latest person's email, a sample code, no new coupon.
        $this->assertTrue(Engine::send_test(Repo::get($automation), Repo::steps($automation)[0], 'owner@example.org'));
        $this->assertCount(1, FakeStore::$coupons);
    }

    public function test_an_existing_coupon_is_shown_only_if_the_shop_has_it()
    {
        $email = $this->cart_email([['id' => 'd2', 'type' => 'discount_code', 'settings' => ['code_mode' => 'existing', 'existing_code' => 'welcome10']]]);
        $this->cart_automation($email);

        $run  = ['id' => 5, 'automation_id' => 1, 'email' => 'x@example.org', 'context' => []];
        $html = Dynamic::expand((new Templatify($email))->forge(), ['trigger' => self::$cart_trigger, 'run' => $run, 'mode' => 'send', 'email_campaign_id' => $email]);
        $this->assertStringContainsString('WELCOME10', $html);
        $this->assertSame([], FakeStore::$coupons);

        FakeStore::$existing = [];
        $html = Dynamic::expand((new Templatify($email))->forge(), ['trigger' => self::$cart_trigger, 'run' => $run, 'mode' => 'send', 'email_campaign_id' => $email]);
        $this->assertStringNotContainsString('WELCOME10', $html, 'Deleted in the shop: not sent.');
        FakeStore::$existing = ['WELCOME10'];
    }

    public function test_a_broken_marker_draws_nothing_rather_than_stopping_the_email()
    {
        $html = '<p>Before</p><!--mo-dynamic:bm90IGpzb24--><!--mo-dynamic:' . rtrim(strtr(base64_encode('{"type":"text"}'), '+/', '-_'), '=') . '--><p>After</p>';

        $this->assertSame('<p>Before</p><p>After</p>', Dynamic::expand($html, ['mode' => 'send']));
    }

    public function test_insert_placeholder_lists_what_this_email_fills_in()
    {
        $email = $this->cart_email([]);
        $this->cart_automation($email);

        $groups = EmailEditor::context('automation')->placeholders($email);
        $labels = wp_list_pluck($groups, 'label');
        $keys   = [];
        foreach ($groups as $group) $keys = array_merge($keys, wp_list_pluck($group['items'], 'key'));

        $this->assertSame(['Order', 'Store', 'Subscriber'], $labels, "The trigger's, by kind, then the subscriber's.");
        $this->assertContains('order.number', $keys);
        $this->assertContains('store.name', $keys);
        $this->assertContains('subscriber.first_name', $keys);

        // A broadcast: the subscriber's only.
        $broadcast = ER::add_email_campaign('B ' . uniqid(), ER::NEWSLETTER, 'Lucid');
        $this->assertSame(['Subscriber'], wp_list_pluck(EmailEditor::context('broadcast')->placeholders($broadcast), 'label'));
    }

    public function test_only_markers_mailoptin_drew_are_drawn()
    {
        $email = $this->cart_email([]);
        $this->cart_automation($email);
        $run   = ['id' => 9, 'automation_id' => 1, 'email' => 'author@example.org', 'context' => []];
        $args  = ['trigger' => self::$cart_trigger, 'run' => $run, 'mode' => 'send', 'email_campaign_id' => $email];

        // Text that looks like a marker (in a post an Author wrote, say): unsigned, so nothing, and no coupon.
        $json   = wp_json_encode(['type' => 'discount_code', 'id' => 'x', 'width' => 570, 'settings' => ['amount' => '100', 'expiry_days' => '0']]);
        $forged = '<!--mo-dynamic:' . rtrim(strtr(base64_encode($json), '+/', '-_'), '=') . '-->';
        $this->assertSame('', Dynamic::expand($forged, $args));
        $this->assertSame('', Dynamic::expand(str_replace('-->', '.' . str_repeat('a', 64) . '-->', $forged), $args), 'A wrong signature: nothing either.');
        $this->assertSame([], FakeStore::$coupons);

        // One MailOptin drew: drawn, its settings cleaned again (an unknown discount type becomes the default).
        $marker = Dynamic::marker('discount_code', ['id' => 'x', 'width' => 570, 'settings' => ['discount_type' => 'everything_free', 'amount' => '20']]);
        $this->assertStringContainsString('SAVE-', Dynamic::expand($marker, $args));
        $this->assertSame('percent', reset(FakeStore::$coupons)['args']['type']);
    }

    public function test_a_copied_email_makes_its_own_code()
    {
        $block  = [['id' => 'd1', 'type' => 'discount_code', 'settings' => ['amount' => '10']]];
        $first  = $this->cart_email($block);
        $second = $this->cart_email($block);
        $this->cart_automation($first);

        $run = ['id' => 3, 'automation_id' => 1, 'email' => 'p@example.org', 'context' => []];
        foreach ([$first, $second] as $email) {
            Dynamic::expand((new Templatify($email))->forge(), ['trigger' => self::$cart_trigger, 'run' => $run, 'mode' => 'send', 'email_campaign_id' => $email]);
        }

        $this->assertCount(2, FakeStore::$coupons, 'Same block id, same person, two emails: two codes.');
    }

    public function test_automation_emails_have_no_web_version_link_and_no_empty_address_tags()
    {
        $email = $this->cart_email([['id' => 't', 'type' => 'text', 'settings' => ['text_content' => 'Hello']]]);
        $this->cart_automation($email);

        // Through the settings object: it reads the option once per request.
        foreach (['company_name' => 'Acme', 'company_address' => '412 Orchard Lane', 'company_address_2' => '', 'company_city' => 'Portland'] as $key => $value) {
            \MailOptin\Core\PluginSettings\Settings::instance()->update($key, $value);
        }
        $html = (new Templatify($email))->forge();

        $this->assertStringNotContainsString('{{webversion}}', $html, 'No web version link in an automation email.');
        $this->assertStringNotContainsString('{{company_address_2}}', $html, 'An empty address line goes.');
        $this->assertDoesNotMatchRegularExpression('/412 Orchard Lane,<br[^>]*>\\s*<br/', $html, 'With its line.');
        $this->assertStringContainsString('412 Orchard Lane', $html);

        // A broadcast keeps its web version link.
        $broadcast = ER::add_email_campaign('B ' . uniqid(), ER::NEWSLETTER, 'Lucid');
        $this->assertStringContainsString('{{webversion}}', (new Templatify($broadcast))->forge());
    }
}
