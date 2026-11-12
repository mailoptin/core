<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\Admin\EmailEditor\EmailEditor;
use MailOptin\Core\Automations\Stores\Store;
use MailOptin\Core\Automations\Stores\Stores;
use MailOptin\Core\EmailBlocks\Blocks;
use MailOptin\Core\EmailBlocks\Starters;
use MailOptin\Core\EmailBlocks\Templates;
use MailOptin\Core\EmailCampaigns\Newsletter\Templatify;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/** A shop with three products (one on sale) and one coupon. */
class BroadcastFakeShop extends Store
{
    public static $asked = [];

    public function id() { return 'broadcastshop'; }
    public function name() { return 'Fake shop'; }
    public function active() { return true; }
    public function money($amount, $currency = '') { return '£' . number_format((float)$amount, 2); }
    public function coupon_exists($code) { return 'AUTUMN20' === $code; }
    public function apply_coupon_url($code) { return 'https://shop.test/cart?mo_coupon=' . rawurlencode($code); }
    public function product_choices() { return ['11' => 'Tulip bulbs', '12' => 'Daffodil mix', '13' => 'Bulb planter']; }
    public function product_categories() { return ['5' => 'Bulbs']; }

    public function products($args)
    {
        self::$asked[] = $args;
        $all = [
            ['id' => 11, 'name' => 'Tulip bulbs', 'url' => 'https://shop.test/tulips', 'image' => 'https://shop.test/t.jpg', 'price' => '£11.50', 'regular' => '£14.00', 'percent' => 17],
            ['id' => 12, 'name' => 'Daffodil mix', 'url' => 'https://shop.test/daffs', 'image' => 'https://shop.test/d.jpg', 'price' => '£9.50', 'regular' => '', 'percent' => 0],
            ['id' => 13, 'name' => 'Bulb planter <b>', 'url' => 'https://shop.test/planter', 'image' => '', 'price' => '£18.00', 'regular' => '', 'percent' => 0],
        ];

        return array_slice($all, 0, (int)$args['count']);
    }
}

/**
 * Products and Discount code in broadcasts (Premium, with a shop running), and the Sale and Product
 * launch starter layouts.
 */
class BroadcastShopBlocksTest extends \WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\RegisterActivation\CreateDBTables::make();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        add_filter('mailoptin_register_template_class', [Templates::get_instance(), 'template_class'], 10, 3);
        add_filter('mailoptin_automation_stores', function ($stores) { return array_merge($stores, [new BroadcastFakeShop()]); });
        add_filter('mailoptin_broadcast_shop_blocks_available', '__return_true');
        Stores::reset();
        BroadcastFakeShop::$asked = [];
    }

    public function tear_down()
    {
        Stores::reset();
        parent::tear_down();
    }

    protected function broadcast($content, $template = 'Folio')
    {
        $id = ER::add_email_campaign('Shop ' . uniqid(), ER::NEWSLETTER, $template);
        ER::update_settings_by_id($id, ['email_newsletter_content' => wp_json_encode($content)]);

        return $id;
    }

    public function test_blocks_need_premium_and_a_shop()
    {
        $id = $this->broadcast([]);
        $this->assertTrue(Blocks::get_instance()->get('products')->available_for($id));
        $this->assertTrue(Blocks::get_instance()->get('discount_code')->available_for($id));
        // Cart contents is about one person's cart: never in a broadcast.
        $this->assertFalse(Blocks::get_instance()->get('cart_contents')->available_for($id));

        remove_all_filters('mailoptin_broadcast_shop_blocks_available');
        $this->assertFalse(Blocks::get_instance()->get('products')->available_for($id));
        $this->assertFalse(Blocks::get_instance()->get('discount_code')->available_for($id));
    }

    public function test_products_in_two_columns()
    {
        $html = (new Templatify($this->broadcast([
            ['id' => 'p', 'type' => 'products', 'settings' => ['product_source' => 'on_sale', 'product_count' => '3', 'product_columns' => '2']],
        ])))->forge();

        $this->assertSame('on_sale', BroadcastFakeShop::$asked[0]['source']);
        $this->assertStringContainsString('Tulip bulbs', $html);
        $this->assertStringContainsString('Bulb planter &lt;b&gt;', $html, 'Names are escaped.');
        $this->assertMatchesRegularExpression('/line-through[^>]*>£14.00/', $html);
        $this->assertStringContainsString('href="https://shop.test/daffs"', $html);
        $this->assertStringContainsString('class="mj-column-per-50 ', $html);
        // The look's button colour.
        $this->assertMatchesRegularExpression('/background(-color)?:\s*#2e6b4f/i', $html);
        $this->assertStringNotContainsString('__MO_', $html);
    }

    public function test_one_product_has_its_image_on_the_left()
    {
        $html = (new Templatify($this->broadcast([
            ['id' => 'p', 'type' => 'products', 'settings' => ['product_source' => 'newest', 'product_count' => '1', 'product_columns' => '1']],
        ])))->forge();

        $this->assertStringContainsString('class="mj-column-per-33 ', $html);
        $this->assertStringContainsString('src="https://shop.test/t.jpg"', $html);
        $this->assertStringNotContainsString('Daffodil', $html);
    }

    public function test_discount_code_in_a_broadcast_is_the_coupon_typed_in()
    {
        $html = (new Templatify($this->broadcast([
            ['id' => 'd', 'type' => 'discount_code', 'settings' => ['code_mode' => 'new', 'existing_code' => 'autumn20']],
        ])))->forge();

        // Even set to "a new code for each person": a broadcast is one email, so the code typed in.
        $this->assertStringContainsString('AUTUMN20', $html);
        $this->assertStringContainsString('mo_coupon=AUTUMN20', $html);
        $this->assertStringNotContainsString('SAMPLE', $html);

        // No code: nothing drawn.
        $empty = (new Templatify($this->broadcast([['id' => 'd', 'type' => 'discount_code', 'settings' => ['existing_code' => '']]])))->forge();
        $this->assertStringNotContainsString('dashed', $empty);
    }

    public function test_checks_say_when_the_coupon_isnt_in_the_shop()
    {
        $warnings = function ($code) {
            $id    = $this->broadcast([['id' => 'd', 'type' => 'discount_code', 'settings' => ['existing_code' => $code]]]);
            $state = EmailEditor::state($id);

            return implode("\n", array_column(array_filter(EmailEditor::checks($id, $state, ''), function ($c) { return ! $c['ok']; }), 'text'));
        };

        $this->assertStringContainsString('enter a coupon your shop has', $warnings(''));
        $this->assertStringContainsString('no coupon "NOPE"', $warnings('nope'));
        $this->assertStringNotContainsString('coupon', $warnings('AUTUMN20'));
    }

    public function test_sale_and_launch_starters()
    {
        $this->assertArrayHasKey('sale', Starters::get_instance()->for_context('broadcast'));
        $this->assertArrayHasKey('product-launch', Starters::get_instance()->for_context('broadcast'));

        $id      = EmailEditor::start('broadcast', 'Market', ['name' => 'Sale ' . uniqid()], 'sale');
        $content = json_decode((string)ER::get_customizer_value_without_default($id, 'email_newsletter_content'), true);

        $this->assertSame(['text', 'discount_code', 'products', 'button'], array_column($content, 'type'));
        $this->assertSame('#111111', $content[2]['settings']['button_background_color'], 'Market\'s button colour.');

        // Its prompts are flagged, the code among them.
        $texts = implode("\n", array_column(EmailEditor::checks($id, EmailEditor::state($id), ''), 'text'));
        $this->assertStringContainsString('[Sale headline]', $texts);

        remove_all_filters('mailoptin_broadcast_shop_blocks_available');
        $this->assertArrayNotHasKey('sale', Starters::get_instance()->for_context('broadcast'));
    }
}
