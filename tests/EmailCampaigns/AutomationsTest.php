<?php

namespace MailOptin\Tests\Core\EmailCampaigns;

use Carbon\CarbonImmutable;
use MailOptin\Core\Admin\SettingsPage\Automation_Page;
use MailOptin\Core\Core;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\EmailCampaigns\Automations;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\Core\EmailCampaigns\PostsEmailDigest\PostsEmailDigest;
use MailOptin\Core\Repositories\CampaignSettingsRepository;
use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use WP_UnitTestCase;

/**
 * Release 5: post notifications and digests on their own page.
 */
class AutomationsTest extends WP_UnitTestCase
{
    protected $list_id;
    protected $npp;
    protected $digest;
    protected $categories = [];

    public function set_up()
    {
        parent::set_up();

        foreach ([17, 20, 21, 22] as $version) DBUpdates::get_instance()->{'update_routine_' . $version}();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        DBUpdates::get_instance()->update_routine_7();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        if (is_null(\MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process)) {
            \MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process = new \MailOptin\Core\AsyncHandler\BGProcess();
        }
        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, ['from_name' => 'Acme Garden Supply', 'from_email' => 'hello@acmegarden.co']);

        // What Pro turns on.
        add_filter('mailoptin_enable_email_automation_cpt_support', '__return_true');
        add_filter('mailoptin_enable_email_automation_post_category_support', '__return_true');
        add_filter('mailoptin_enable_post_email_digest', '__return_true');

        register_post_type('product', ['label' => 'Products', 'public' => true, 'show_ui' => true]);
        register_taxonomy('product_cat', 'product', ['label' => 'Product categories', 'hierarchical' => true, 'show_ui' => true]);
        register_taxonomy('product_tag', 'product', ['label' => 'Product tags', 'show_ui' => true]);
        register_taxonomy('product_visibility', 'product', ['label' => 'Product visibility', 'show_ui' => false]);
        register_taxonomy('product_type', 'product', ['label' => 'Product type', 'show_ui' => true]);
        register_taxonomy('pa_color', 'product', ['label' => 'Color', 'show_ui' => true]);
        delete_transient('mo_get_post_types');

        foreach (['Planting guides', 'Tools'] as $name) {
            $this->categories[$name] = self::factory()->category->create(['name' => $name]);
        }

        $this->list_id = ListsRepository::create(['name' => 'Newsletter']);
        $subscriber    = Subscribers::create(['email' => 'ann@example.org', 'status' => Subscribers::STATUS_SUBSCRIBED]);
        Subscribers::set_membership($subscriber, $this->list_id, Subscribers::STATUS_SUBSCRIBED);

        $this->npp    = ER::add_email_campaign('New post alert', ER::NEW_PUBLISH_POST, 'Lucid');
        $this->digest = ER::add_email_campaign('Weekly roundup', ER::POSTS_EMAIL_DIGEST, 'Lucid');

        foreach ([$this->npp, $this->digest] as $id) {
            EmailCampaignMeta::add_meta_data($id, 'created_at', current_time('mysql'));
            Automations::save($id, [
                'email_campaign_subject' => 'New: {{title}}',
                'connection_service'     => 'MailOptinConnect',
                'connection_email_list'  => (string)$this->list_id,
            ]);
        }
    }

    public function tear_down()
    {
        CampaignSettingsRepository::delete_all(CampaignSettingsRepository::TYPE_EMAIL);
        unregister_post_type('product');
        foreach (['product_cat', 'product_tag', 'product_visibility', 'product_type', 'pa_color'] as $taxonomy) unregister_taxonomy($taxonomy);
        $_POST = $_GET = $_REQUEST = [];

        parent::tear_down();
    }

    protected function logs($email_campaign_id)
    {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare('SELECT title FROM ' . $wpdb->prefix . Core::campaign_log_table_name . ' WHERE email_campaign_id = %d ORDER BY id', $email_campaign_id));
    }

    protected function publish($title, $category = null, $args = [])
    {
        return wp_insert_post($args + [
                'post_title'    => $title,
                'post_content'  => 'Body',
                'post_status'   => 'publish',
                'post_author'   => 1,
                'post_category' => $category ? [$this->categories[$category]] : [],
            ]);
    }

    /* ---------------------------------------------------------------------------------------------
     * Which posts
     * ------------------------------------------------------------------------------------------ */

    public function test_filter_taxonomies_leave_out_woocommerce_internals_and_attributes()
    {
        $this->assertSame(['product_cat' => 'Product categories', 'product_tag' => 'Product tags'], Automations::filter_taxonomies('product'));
        $this->assertArrayHasKey('category', Automations::filter_taxonomies('post'));
        $this->assertArrayHasKey('post_tag', Automations::filter_taxonomies('post'));
    }

    public function test_filters_left_over_from_another_post_type_are_ignored()
    {
        // A product category filter kept after switching back to posts, and post categories kept
        // after switching to products: neither can match, so neither may stop a send.
        Automations::save($this->npp, [
            'custom_post_type'          => 'post',
            'custom_post_type_settings' => wp_json_encode(['product_cat' => ['8']]),
            'post_categories'           => [(string)$this->categories['Planting guides']],
        ]);

        $this->assertSame(['category' => [$this->categories['Planting guides']]], Automations::term_filters($this->npp));

        Automations::save($this->npp, ['custom_post_type' => 'product']);

        $this->assertSame(['product_cat' => [8]], Automations::term_filters($this->npp));
    }

    public function test_post_matches_needs_one_term_of_each_filter_and_an_author()
    {
        Automations::save($this->npp, ['post_categories' => [(string)$this->categories['Planting guides']]]);

        $this->assertTrue(Automations::post_matches($this->npp, get_post($this->publish('Garlic', 'Planting guides'))));
        $this->assertFalse(Automations::post_matches($this->npp, get_post($this->publish('Secateurs', 'Tools'))));

        $other = self::factory()->user->create(['role' => 'author']);
        Automations::save($this->npp, ['post_authors' => [(string)$other]]);

        $this->assertFalse(Automations::post_matches($this->npp, get_post($this->publish('Onions', 'Planting guides'))));
    }

    public function test_a_new_post_sends_only_when_it_matches_even_with_left_over_filters()
    {
        Automations::save($this->npp, [
            'send_immediately'          => true,
            'post_categories'           => [(string)$this->categories['Planting guides']],
            // Before Release 5 this stopped every send: no post has a product category.
            'custom_post_type_settings' => wp_json_encode(['product_cat' => ['8']]),
        ]);
        ER::activate_email_campaign($this->npp);

        $this->publish('Sharpening secateurs', 'Tools');
        $this->publish('Planting garlic', 'Planting guides');

        $this->assertSame(['New: Planting garlic'], $this->logs($this->npp));
    }

    public function test_digest_query_counts_only_the_post_types_own_filters()
    {
        Automations::save($this->digest, [
            'custom_post_type'          => 'post',
            'custom_post_type_settings' => wp_json_encode(['product_cat' => ['8']]),
            'post_categories'           => [(string)$this->categories['Tools']],
            'item_number'               => 3,
        ]);

        $query = PostsEmailDigest::get_instance()->post_collect_query($this->digest);

        $this->assertSame('post', $query['post_type']);
        $this->assertSame(3, $query['posts_per_page']);
        $this->assertSame([['taxonomy' => 'category', 'field' => 'term_id', 'terms' => [$this->categories['Tools']]]], $query['tax_query']);

        // Posts since the digest was made.
        EmailCampaignMeta::update_meta_data($this->digest, 'created_at', date('Y-m-d H:i:s', current_time('timestamp') - HOUR_IN_SECONDS));
        $tools = $this->publish('Sharpening secateurs', 'Tools');
        $this->publish('Planting garlic', 'Planting guides');

        $this->assertSame([$tools], wp_list_pluck(PostsEmailDigest::get_instance()->post_collection($this->digest), 'ID'));
    }

    /* ---------------------------------------------------------------------------------------------
     * When
     * ------------------------------------------------------------------------------------------ */

    public function test_next_digest()
    {
        update_option('timezone_string', 'UTC');
        update_option('start_of_week', 1);
        $wednesday = CarbonImmutable::create(2026, 11, 4, 10, 0, 0, 'UTC');

        Automations::save($this->digest, ['schedule_interval' => 'every_week', 'schedule_day' => '1', 'schedule_time' => '09']);
        $this->assertSame('2026-11-09 09:00', Automations::next_digest_at($this->digest, $wednesday)->format('Y-m-d H:i'));

        Automations::save($this->digest, ['schedule_interval' => 'every_day', 'schedule_time' => '09']);
        // 9:00 passed less than an hour and a half ago: the hourly run still sends it at 10:00...
        $this->assertSame('2026-11-04 09:00', Automations::next_digest_at($this->digest, $wednesday)->format('Y-m-d H:i'));
        // ...unless today's went out.
        EmailCampaignMeta::update_meta_data($this->digest, 'last_processed_at', '2026-11-04 09:05:00');
        $this->assertSame('2026-11-05 09:00', Automations::next_digest_at($this->digest, $wednesday)->format('Y-m-d H:i'));

        Automations::save($this->digest, ['schedule_interval' => 'every_month', 'schedule_month_date' => '1', 'schedule_time' => '08']);
        $this->assertSame('2026-12-01 08:00', Automations::next_digest_at($this->digest, $wednesday)->format('Y-m-d H:i'));
    }

    public function test_delay_label_and_the_empty_delay_that_never_sends()
    {
        Automations::save($this->npp, ['send_immediately' => false, 'schedule_digit' => '']);
        $this->assertSame(0, Automations::delay_minutes($this->npp));
        $this->assertFalse(Automations::can_send($this->npp));

        Automations::save($this->npp, ['schedule_digit' => '2', 'schedule_type' => 'hours']);
        $this->assertSame(120, Automations::delay_minutes($this->npp));
        $this->assertSame('2 hours after publishing', Automations::delay_label($this->npp));

        Automations::save($this->npp, ['send_immediately' => true]);
        $this->assertNull(Automations::delay_minutes($this->npp));
        $this->assertSame('straight away', Automations::delay_label($this->npp));
    }

    /* ---------------------------------------------------------------------------------------------
     * The page's form
     * ------------------------------------------------------------------------------------------ */

    public function test_changing_the_post_type_clears_the_old_filters()
    {
        Automations::save($this->npp, ['post_categories' => [(string)$this->categories['Tools']], 'post_tags' => ['3']]);

        $values = Automation_Page::posted_values($this->npp, [
            'post_type'     => 'product',
            'filters_shown' => '1',
            // Still posted from the page as it was: the old post type's fields.
            'filter_terms'  => ['category' => [(string)$this->categories['Tools']]],
        ]);

        $this->assertSame('product', $values['custom_post_type']);
        $this->assertSame('', $values['custom_post_type_settings']);
        $this->assertSame([], $values['post_categories']);
        $this->assertSame([], $values['post_tags']);
        $this->assertArrayNotHasKey('post_authors', $values);
    }

    public function test_filters_are_stored_where_the_customizer_keeps_them()
    {
        $values = Automation_Page::posted_values($this->npp, [
            'filters_shown'  => '1',
            'filter_terms'   => ['category' => [(string)$this->categories['Tools'], '0', 'x'], 'product_cat' => ['8']],
            'filter_authors' => ['1'],
        ]);

        $this->assertSame([(string)$this->categories['Tools']], $values['post_categories']);
        $this->assertSame([], $values['post_tags']);
        $this->assertSame(['1'], $values['post_authors']);
        $this->assertArrayNotHasKey('custom_post_type_settings', $values);

        Automations::save($this->npp, ['custom_post_type' => 'product']);

        $values = Automation_Page::posted_values($this->npp, [
            'filters_shown' => '1',
            'filter_terms'  => ['product_cat' => ['8', '9'], 'pa_color' => ['5'], 'category' => ['2']],
        ]);

        $this->assertSame('{"product_cat":["8","9"]}', $values['custom_post_type_settings']);
    }

    public function test_unknown_post_types_and_free_sites_keep_the_post_type()
    {
        $this->assertArrayNotHasKey('custom_post_type', Automation_Page::posted_values($this->npp, ['post_type' => 'nope']));

        remove_all_filters('mailoptin_enable_email_automation_cpt_support');

        $values = Automation_Page::posted_values($this->npp, ['post_type' => 'product', 'filters_shown' => '1', 'filter_terms' => ['category' => ['2']]]);

        $this->assertArrayNotHasKey('custom_post_type', $values);
        $this->assertArrayNotHasKey('post_categories', $values);
    }

    public function test_when_values_are_stored_as_the_customizer_stores_them()
    {
        $values = Automation_Page::posted_values($this->npp, ['send_when' => 'later', 'schedule_digit' => '5000', 'schedule_type' => 'weeks']);
        $this->assertFalse($values['send_immediately']);
        $this->assertSame('999', $values['schedule_digit']);
        $this->assertSame('hours', $values['schedule_type']);

        $values = Automation_Page::posted_values($this->npp, ['send_when' => 'now', 'schedule_interval' => 'every_week']);
        $this->assertTrue($values['send_immediately']);
        $this->assertArrayNotHasKey('schedule_interval', $values, 'Digest fields are not a post notification\'s.');

        $values = Automation_Page::posted_values($this->digest, ['schedule_interval' => 'hourly', 'schedule_time' => '9', 'schedule_day' => '8', 'schedule_month_date' => '31', 'item_number' => '0', 'send_when' => 'now']);
        $this->assertArrayNotHasKey('schedule_interval', $values);
        $this->assertSame('09', $values['schedule_time']);
        $this->assertSame('1', $values['schedule_day']);
        $this->assertSame('28', $values['schedule_month_date']);
        $this->assertSame(1, $values['item_number']);
        $this->assertArrayNotHasKey('send_immediately', $values);
    }

    public function test_a_new_connection_clears_the_list()
    {
        add_filter('mailoptin_registered_connections', function ($connections) {
            return $connections + ['OtherConnect' => 'Other'];
        });
        add_filter('mailoptin_enable_email_customizer_connections', '__return_true');

        $values = Automation_Page::posted_values($this->npp, ['connection_service' => 'OtherConnect', 'connection_email_list' => (string)$this->list_id]);

        $this->assertSame('OtherConnect', $values['connection_service']);
        $this->assertSame('', $values['connection_email_list']);
    }

    /* ---------------------------------------------------------------------------------------------
     * Checks and tests
     * ------------------------------------------------------------------------------------------ */

    public function test_checks()
    {
        Automations::save($this->npp, ['send_immediately' => true]);
        $this->assertTrue(Automations::can_send($this->npp));

        Automations::save($this->npp, ['connection_email_list' => '']);
        $this->assertFalse(Automations::can_send($this->npp));

        Automations::save($this->npp, ['connection_email_list' => (string)$this->list_id, 'custom_post_type' => 'gone']);
        $this->assertFalse(Automations::can_send($this->npp), 'A post type whose plugin is off can never send.');

        remove_all_filters('mailoptin_enable_post_email_digest');
        $this->assertFalse(Automations::can_send($this->digest), 'Digests need Pro.');
    }

    public function test_a_test_counts_until_the_next_change()
    {
        $this->publish('Planting garlic', 'Planting guides');
        reset_phpmailer_instance();

        $this->assertTrue(Automations::send_test($this->npp, 'me@example.org'));
        $this->assertSame('[Test] New: Planting garlic', tests_retrieve_phpmailer_instance()->get_sent()->subject);
        $this->assertTrue(Automations::test_is_current($this->npp));

        sleep(1);
        Automations::save($this->npp, ['email_campaign_subject' => 'Fresh: {{title}}']);
        $this->assertFalse(Automations::test_is_current($this->npp));

        $this->assertFalse(Automations::send_test($this->npp, 'not an email'));
    }

    public function test_connections_without_lists_do_not_need_one()
    {
        if ( ! class_exists('\MailOptin\RegisteredUsersConnect\Connect')) {
            eval('namespace MailOptin\RegisteredUsersConnect; class Connect {}');
        }
        if ( ! class_exists('\MailOptin\FakeShopConnect\Connect')) {
            eval('namespace MailOptin\FakeShopConnect; class Connect extends \MailOptin\RegisteredUsersConnect\Connect {}');
        }

        $this->assertFalse(Broadcasts::connection_has_lists('FakeShopConnect'));
        $this->assertFalse(Broadcasts::connection_has_lists('RegisteredUsersConnect'));
        $this->assertTrue(Broadcasts::connection_has_lists('MailOptinConnect'));
        $this->assertTrue(Broadcasts::connection_has_lists('MailChimpConnect'), 'Unknown or unloaded connections need a list.');

        Automations::save($this->npp, ['connection_service' => 'FakeShopConnect', 'connection_email_list' => '']);
        $this->assertTrue(Broadcasts::has_recipients_set($this->npp));
    }

    public function test_last_logs_and_recent_logs()
    {
        global $wpdb;
        $table = $wpdb->prefix . Core::campaign_log_table_name;

        foreach (['one', 'two', 'three', 'four'] as $title) {
            $wpdb->insert($table, ['email_campaign_id' => $this->npp, 'title' => $title, 'content_html' => '', 'content_text' => '', 'status' => 'processed']);
        }
        $wpdb->insert($table, ['email_campaign_id' => $this->digest, 'title' => 'digest', 'content_html' => '', 'content_text' => '', 'status' => 'processed']);

        $this->assertSame(['four', 'three', 'two'], wp_list_pluck(Automations::recent_logs($this->npp, 3), 'title'));

        $last = Automations::last_logs([$this->npp, $this->digest, 999]);
        $this->assertSame('four', $last[$this->npp]['title']);
        $this->assertSame('digest', $last[$this->digest]['title']);
        $this->assertArrayNotHasKey(999, $last);
    }
}
