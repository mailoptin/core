<?php

namespace MailOptin\Tests\Core\OptinForms;

use MailOptin\Core\OptinForms\InPost;
use MailOptin\Core\Repositories\OptinCampaignsRepository as Repository;
use WP_UnitTestCase;

/**
 * In-post optins go in a post's content only at the content positions; other positions are drawn by
 * whoever added them, with display_variant() doing the same checks.
 */
class InPostPositionsTest extends WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();

        add_filter('mailoptin_inpost_external_positions', function ($p) { return array_merge($p, ['woocommerce_add_to_cart']); });

        // Set by mailoptin.php, which the core tests don't load; drawing a form needs it.
        if ( ! defined('MAILOPTIN_VERSION_NUMBER')) define('MAILOPTIN_VERSION_NUMBER', 'tests');
    }

    protected function inpost_optin($position)
    {
        $id = Repository::add_optin_campaign(wp_generate_password(10, false), 'In-post ' . wp_generate_password(6, false), 'BareMetal', 'inpost');

        $settings      = Repository::get_settings();
        $settings[$id] = ['inpost_form_optin_position' => $position, 'load_optin_globally' => true];
        Repository::updateSettings($settings);
        $this->settings_changed();
        Repository::activate_campaign($id);
        $this->settings_changed();

        return $id;
    }

    /** The in-memory settings are flushed by a hook the test framework resets between tests. */
    protected function settings_changed()
    {
        Repository::flush_saved_customizer_data_cache();
        delete_transient('mo_get_optin_ids_inpost_display');
    }

    public function test_a_content_position_is_put_in_the_content_and_another_is_left_to_its_owner()
    {
        $this->go_to(get_permalink(self::factory()->post->create(['post_content' => '<p>Hello</p>'])));

        $after = $this->inpost_optin('after_content');
        $html  = InPost::get_instance()->insert_optin('<p>Hello</p>');
        $this->assertNotSame('<p>Hello</p>', $html, 'After content is still inserted');

        Repository::deactivate_campaign($after);
        $this->inpost_optin('woocommerce_add_to_cart');

        $this->assertSame('<p>Hello</p>', InPost::get_instance()->insert_optin('<p>Hello</p>'), 'A position drawn elsewhere is not inserted');

        // An unknown value nobody draws still goes after the content, as before.
        $this->inpost_optin('some_old_position');
        $this->assertNotSame('<p>Hello</p>', InPost::get_instance()->insert_optin('<p>Hello</p>'));
    }

    public function test_display_variant_uses_the_same_checks()
    {
        $this->go_to(get_permalink(self::factory()->post->create()));

        $id = $this->inpost_optin('woocommerce_add_to_cart');
        $this->assertSame($id, InPost::get_instance()->display_variant($id, 'woocommerce_add_to_cart'));

        add_filter('mailoptin_show_optin_form', '__return_false');
        $this->assertSame(0, InPost::get_instance()->display_variant($id, 'woocommerce_add_to_cart'), 'The show filter applies');
        remove_filter('mailoptin_show_optin_form', '__return_false');

        Repository::deactivate_campaign($id);
        $this->settings_changed();
        $this->assertSame(0, InPost::get_instance()->display_variant($id, 'woocommerce_add_to_cart'), 'Not activated');
        $this->assertSame(0, InPost::get_instance()->display_variant(0, 'woocommerce_add_to_cart'));
        $this->assertSame(['before_content', 'between_content', 'after_content'], InPost::content_positions());
        $this->assertSame(['woocommerce_add_to_cart'], InPost::external_positions());
    }

    public function test_display_variant_is_only_for_its_own_position()
    {
        $this->go_to(get_permalink(self::factory()->post->create()));

        $id = $this->inpost_optin('after_content');
        $this->assertSame(0, InPost::get_instance()->display_variant($id, 'woocommerce_add_to_cart'), 'Set to show in the content, not at Add to cart');
        $this->assertSame($id, InPost::get_instance()->display_variant($id, 'after_content'));
    }
}
