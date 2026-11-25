<?php

namespace MailOptin\Tests\Core\EmailCampaigns;

use MailOptin\Core\Core;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\EmailCampaigns\PostsEmailDigest\PostsEmailDigest;
use MailOptin\Core\Repositories\CampaignSettingsRepository;
use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use WP_UnitTestCase;

/**
 * A post notification or digest whose post type was changed in the customizer keeps the old type's
 * filters saved. Those filters can never match a post of the new type, so they must not count.
 */
class LeftoverFiltersTest extends WP_UnitTestCase
{
    protected $npp;
    protected $digest;
    protected $planting;
    protected $tools;

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

        register_post_type('product', ['label' => 'Products', 'public' => true]);
        register_taxonomy('product_cat', 'product', ['label' => 'Product categories', 'hierarchical' => true]);

        $this->planting = self::factory()->category->create(['name' => 'Planting guides']);
        $this->tools    = self::factory()->category->create(['name' => 'Tools']);

        $list       = ListsRepository::create(['name' => 'Newsletter']);
        $subscriber = Subscribers::create(['email' => 'ann@example.org', 'status' => Subscribers::STATUS_SUBSCRIBED]);
        Subscribers::set_membership($subscriber, $list, Subscribers::STATUS_SUBSCRIBED);

        $this->npp    = ER::add_email_campaign('New post alert', ER::NEW_PUBLISH_POST, 'Lucid');
        $this->digest = ER::add_email_campaign('Weekly roundup', ER::POSTS_EMAIL_DIGEST, 'Lucid');

        foreach ([$this->npp, $this->digest] as $id) {
            EmailCampaignMeta::add_meta_data($id, 'created_at', date('Y-m-d H:i:s', current_time('timestamp') - HOUR_IN_SECONDS));
            ER::update_settings_by_id($id, [
                'email_campaign_subject' => 'New: {{title}}',
                'connection_service'     => 'MailOptinConnect',
                'connection_email_list'  => (string)$list,
                'send_immediately'       => true,
            ]);
        }
    }

    public function tear_down()
    {
        CampaignSettingsRepository::delete_all(CampaignSettingsRepository::TYPE_EMAIL);
        unregister_post_type('product');
        unregister_taxonomy('product_cat');

        parent::tear_down();
    }

    protected function logs()
    {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare('SELECT title FROM ' . $wpdb->prefix . Core::campaign_log_table_name . ' WHERE email_campaign_id = %d ORDER BY id', $this->npp));
    }

    protected function publish($title, $category, $post_type = 'post')
    {
        // Terms first, then publish, as the editor does.
        $id = wp_insert_post(['post_type' => $post_type, 'post_title' => $title, 'post_content' => 'Body', 'post_status' => 'draft', 'post_author' => 1, 'post_category' => 'post' === $post_type ? [$category] : []]);

        if ('product' === $post_type) wp_set_object_terms($id, [$category], 'product_cat');

        wp_publish_post($id);

        return $id;
    }

    public function test_a_product_filter_left_on_a_posts_notification_does_not_stop_it()
    {
        // Switched from products back to posts: the product category filter stays saved.
        ER::update_settings_by_id($this->npp, [
            'custom_post_type'          => 'post',
            'custom_post_type_settings' => wp_json_encode(['product_cat' => ['8']]),
            'post_categories'           => [(string)$this->planting],
        ]);
        ER::activate_email_campaign($this->npp);

        $this->publish('Sharpening secateurs', $this->tools);
        $this->publish('Planting garlic', $this->planting);

        // The post categories still filter; the product category no longer stops every send.
        $this->assertSame(['New: Planting garlic'], $this->logs());
    }

    public function test_post_categories_left_on_a_products_notification_do_not_stop_it()
    {
        $bulbs = self::factory()->term->create(['taxonomy' => 'product_cat', 'name' => 'Bulbs']);
        $seeds = self::factory()->term->create(['taxonomy' => 'product_cat', 'name' => 'Seeds']);

        // Switched from posts to products: the post categories stay saved, hidden in the customizer.
        ER::update_settings_by_id($this->npp, [
            'custom_post_type'          => 'product',
            'custom_post_type_settings' => wp_json_encode(['product_cat' => [(string)$bulbs]]),
            'post_categories'           => [(string)$this->planting],
        ]);
        ER::activate_email_campaign($this->npp);

        $this->publish('Seed tray', $seeds, 'product');
        $this->publish('Garlic bulbs', $bulbs, 'product');

        $this->assertSame(['New: Garlic bulbs'], $this->logs());
    }

    public function test_a_digest_ignores_filters_left_from_another_post_type()
    {
        ER::update_settings_by_id($this->digest, [
            'custom_post_type'          => 'post',
            'custom_post_type_settings' => wp_json_encode(['product_cat' => ['8']]),
            'post_categories'           => [(string)$this->tools],
            'post_tags'                 => ['3'],
        ]);

        $query = PostsEmailDigest::get_instance()->post_collect_query($this->digest);
        $this->assertSame([], $query['tax_query']);
        $this->assertSame((string)$this->tools, $query['category']);
        $this->assertSame('3', $query['tag_id']);

        ER::update_settings_by_id($this->digest, ['custom_post_type' => 'product', 'custom_post_type_settings' => wp_json_encode(['product_cat' => ['8']])]);

        $query = PostsEmailDigest::get_instance()->post_collect_query($this->digest);
        $this->assertSame([['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => [8]]], $query['tax_query']);
        $this->assertArrayNotHasKey('category', $query);
        $this->assertArrayNotHasKey('tag_id', $query);
    }

    public function test_a_digest_with_a_left_over_filter_still_finds_its_posts()
    {
        ER::update_settings_by_id($this->digest, [
            'custom_post_type'          => 'post',
            'custom_post_type_settings' => wp_json_encode(['product_cat' => ['8']]),
        ]);

        $id = $this->publish('Planting garlic', $this->planting);

        $this->assertSame([$id], wp_list_pluck(PostsEmailDigest::get_instance()->post_collection($this->digest), 'ID'));
    }
}
