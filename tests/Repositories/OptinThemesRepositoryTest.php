<?php

namespace MailOptin\Tests\Core\Repositories;

use MailOptin\Core\Repositories\OptinThemesRepository;
use WP_UnitTestCase;


class OptinThemesRepositoryTest extends WP_UnitTestCase
{
    public $kick_theme;

    public function set_up()
    {
        parent::set_up();

        $this->kick_theme = array(
            'name' => 'Kick',
            'optin_class' => 'kick',
            'optin_type' => 'kick',
            'screenshot' => ''
        );
    }

    public function tear_down()
    {
        parent::tear_down();
    }

    public function testGetAll()
    {
        $data = OptinThemesRepository::get_all();

        $this->assertEquals(
            array(
                'name' => 'BareMetal',
                'optin_class' => 'BareMetal',
                'optin_type' => 'lightbox',
                'screenshot' => MAILOPTIN_ASSETS_URL . 'images/optin-themes/baremetal-lightbox.png'
            ),
            $data[0]
        );
    }

    public function testGetByType()
    {
        // add kick optin theme to theme repo
        OptinThemesRepository::add($this->kick_theme);

        $data = OptinThemesRepository::get_by_type('kick');

        $this->assertEquals([$this->kick_theme], $data);
    }

    public function testGetByName()
    {
        // add kick optin theme to theme repo
        OptinThemesRepository::add($this->kick_theme);

        $data = OptinThemesRepository::get_by_name('Kick');

        $this->assertEquals($this->kick_theme, $data);
    }

    protected function listing($type, $with_objectives = true)
    {
        ob_start();
        OptinThemesRepository::listing_display_template($type, $with_objectives);

        return ob_get_clean();
    }

    public function test_templates_without_an_objective_are_for_collecting_emails()
    {
        $this->assertSame('collect_emails', OptinThemesRepository::objective_of(['name' => 'X']));
        $this->assertSame('woocommerce_product_offers', OptinThemesRepository::objective_of(['objective' => 'woocommerce_product_offers']));
        $this->assertArrayHasKey('collect_emails', OptinThemesRepository::objectives());
    }

    public function test_objective_links_show_only_when_there_is_more_than_one()
    {
        OptinThemesRepository::add(['name' => 'Plain', 'optin_class' => 'Plain', 'optin_type' => 'objtype', 'screenshot' => '']);

        $html = $this->listing('objtype');
        $this->assertStringNotContainsString('mo-optin-objectives', $html, 'One objective: no links');
        $this->assertStringContainsString('data-objective="collect_emails"', $html, 'Each template says what it is for');

        add_filter('mailoptin_optin_objectives', $f = function ($o) { return $o + ['offers' => 'Offers <b>']; });
        $this->assertStringNotContainsString('mo-optin-objectives', $this->listing('objtype'), 'An objective with no templates and no upsell is left out');

        OptinThemesRepository::add(['name' => 'Offer', 'optin_class' => 'Offer', 'optin_type' => 'objtype', 'screenshot' => '', 'objective' => 'offers']);
        $html = $this->listing('objtype');
        $this->assertStringContainsString('mo-optin-objectives', $html);
        $this->assertStringContainsString('All <span class="count">(2)</span>', $html);
        $this->assertStringContainsString('data-objective="offers">Offers &lt;b&gt; <span class="count">(1)</span>', $html, 'Labels are escaped');
        remove_filter('mailoptin_optin_objectives', $f);
    }

    public function test_free_sites_see_what_product_offers_are()
    {
        if (defined('MAILOPTIN_DETACH_LIBSODIUM')) $this->markTestSkipped('Premium is loaded');

        OptinThemesRepository::add(['name' => 'Plain2', 'optin_class' => 'Plain2', 'optin_type' => 'upselltype', 'screenshot' => '']);
        add_filter('mailoptin_optin_objectives', $f = function ($o) { return $o + ['woocommerce_product_offers' => 'WooCommerce product offers']; });

        $html = $this->listing('upselltype');
        $this->assertStringContainsString('WooCommerce product offers <span class="count">(Premium)</span>', $html);
        $this->assertStringContainsString('class="mo-objective-upsell" data-objective="woocommerce_product_offers" hidden', $html);
        $this->assertStringContainsString('Get MailOptin Premium', $html);
        $this->assertStringContainsString('utm_campaign=product_offers', $html);
        $this->assertStringNotContainsString('mo-optin-objectives', $this->listing('upselltype', false), 'Not in the Customizer template switcher');
        remove_filter('mailoptin_optin_objectives', $f);
    }

    public function test_free_sites_with_woocommerce_see_the_product_offer_templates_locked()
    {
        if (defined('MAILOPTIN_DETACH_LIBSODIUM')) $this->markTestSkipped('Premium is loaded');

        add_filter('mailoptin_show_locked_product_offer_themes', '__return_true');
        $locked = OptinThemesRepository::locked_product_offer_themes();
        remove_filter('mailoptin_show_locked_product_offer_themes', '__return_true');

        $this->assertCount(10, $locked);
        $this->assertSame(['lightbox' => 3, 'slidein' => 2, 'bar' => 1, 'inpost' => 2, 'sidebar' => 2], array_count_values(array_column($locked, 'optin_type')));

        foreach ($locked as $t) {
            $this->assertSame('premium', $t['flag'], 'Locked: links to the pricing page');
            $this->assertSame(OptinThemesRepository::OBJECTIVE_PRODUCT_OFFERS, $t['objective']);
            $this->assertFileExists(dirname(__DIR__, 2) . '/src/assets/images/optin-themes/product-offers/' . basename($t['screenshot']));
        }

        add_filter('mailoptin_show_locked_product_offer_themes', '__return_false');
        $this->assertSame([], OptinThemesRepository::locked_product_offer_themes(), 'Not without WooCommerce');
        remove_filter('mailoptin_show_locked_product_offer_themes', '__return_false');
    }
}
