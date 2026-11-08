<?php

namespace MailOptin\Tests\Core\EmailCampaigns;

use MailOptin\Core\DBUpdates;
use MailOptin\Core\EmailCampaigns\NewPublishPost\Templatify as NewPostTemplatify;
use MailOptin\Core\EmailCampaigns\Newsletter\Templates\Lucid as NewsletterLucid;
use MailOptin\Core\EmailCampaigns\PostMetaFields;
use MailOptin\Core\EmailCampaigns\PostsEmailDigest\Templatify as DigestTemplatify;
use MailOptin\Core\EmailCampaigns\TemplateTrait;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use WP_UnitTestCase;

/**
 * A stand-in for WooCommerce's product, for the price/rating/stock/SKU details.
 */
class FakeProduct
{
    public function get_price_html() { return '<del aria-hidden="true"><span class="amount">£14.00</span></del> <span class="screen-reader-text">Original price was: £14.00.</span><ins aria-hidden="true"><span class="amount">£11.50</span></ins><span class="screen-reader-text">Current price is: £11.50.</span><script>x</script>'; }
    public function get_average_rating() { return '4.6'; }
    public function get_stock_status() { return 'instock'; }
    public function get_sku() { return 'TUL-20'; }
}

class PostMetaSlotDouble
{
    use TemplateTrait;

    public $email_campaign_id;
}

class PostMetaFieldsTest extends WP_UnitTestCase
{
    protected $post_id;

    public function set_up()
    {
        parent::set_up();

        DBUpdates::get_instance()->update_routine_7();
        foreach ([17, 20, 21, 22] as $version) DBUpdates::get_instance()->{'update_routine_' . $version}();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        \MailOptin\Core\Base::get_instance()->register_metadata_table();

        $author        = self::factory()->user->create(['display_name' => 'Maya Okafor']);
        $this->post_id = self::factory()->post->create([
            'post_title'   => 'Planting tulips in November',
            'post_content' => str_repeat('Plant the bulbs deep and water them in. ', 50), // 400 words
            'post_author'  => $author,
            'post_date'    => '2026-11-07 10:00:00',
        ]);

        wp_set_post_categories($this->post_id, [self::factory()->category->create(['name' => 'Bulbs'])]);
        wp_set_post_tags($this->post_id, ['spring', 'tulips']);
        self::factory()->comment->create_post_comments($this->post_id, 3);
        update_post_meta($this->post_id, 'brand', 'Hortus <b>& Co</b>');
        update_post_meta($this->post_id, 'Grower_Name', 'Okafor Gardens');

        register_taxonomy('season', 'post', ['public' => true, 'label' => 'Seasons']);
        wp_set_object_terms($this->post_id, ['Autumn'], 'season');
    }

    public function tear_down()
    {
        unregister_taxonomy('season');
        \MailOptin\Core\Repositories\CampaignSettingsRepository::delete_all(\MailOptin\Core\Repositories\CampaignSettingsRepository::TYPE_EMAIL);

        parent::tear_down();
    }

    /**
     * The email after its CSS is inlined: drop style attributes and treat non-breaking spaces as spaces.
     */
    protected function plain($html)
    {
        return str_replace(['&nbsp;', "\xC2\xA0"], ' ', preg_replace('/ style="[^"]*"/', '', (string)$html));
    }

    protected function campaign($type, $settings)
    {
        $id = ER::add_email_campaign('Notes ' . wp_rand(), $type, 'Lucid');
        ER::update_settings_by_id($id, $settings);

        return $id;
    }

    public function test_keys_are_cleaned_and_keep_their_order()
    {
        // Duplicates and empty or broken keys go; custom field keys keep their case but lose unsafe characters.
        $this->assertSame(
            ['date', 'author', 'meta:GrowerName', 'tax:season', 'woo:price', 'script'],
            PostMetaFields::parse('date, author,author,meta:Grower Name!,tax:Season,woo:price,,meta:,tax:,<script>')
        );
        $this->assertSame(['author', 'category'], PostMetaFields::parse(['author', ['x'], 'category']));
    }

    public function test_each_detail_renders_for_a_post()
    {
        $post = get_post($this->post_id);

        $this->assertSame('Maya Okafor', PostMetaFields::value($post, 'author'));
        $this->assertSame(get_the_date(get_option('date_format'), $post), PostMetaFields::value($post, 'date'));
        $this->assertSame('Bulbs', PostMetaFields::value($post, 'category'));
        $this->assertSame('spring, tulips', PostMetaFields::value($post, 'tags'));
        $this->assertSame('3 comments', PostMetaFields::value($post, 'comments'));
        $this->assertSame('2 min read', PostMetaFields::value($post, 'reading_time'));
        $this->assertSame('Autumn', PostMetaFields::value($post, 'tax:season'));
        $this->assertSame('Okafor Gardens', PostMetaFields::value($post, 'meta:Grower_Name'));

        // Words in any script count towards reading time.
        $russian = self::factory()->post->create(['post_content' => str_repeat('Посадите луковицы глубоко и полейте их. ', 60)]);
        $this->assertSame('2 min read', PostMetaFields::value(get_post($russian), 'reading_time'));

        // Custom field values are text, never HTML.
        $this->assertSame('Hortus &amp; Co', PostMetaFields::value($post, 'meta:brand'));

        // Nothing to show: left out rather than an empty separator.
        $this->assertSame('', PostMetaFields::value($post, 'meta:missing'));
        $this->assertSame('', PostMetaFields::value($post, 'tax:no_such_taxonomy'));
        $this->assertSame(['<span>Bulbs</span>', '<span>Okafor Gardens</span>'], PostMetaFields::render($post, ['category', 'meta:missing', 'meta:Grower_Name']));
    }

    public function test_choices_include_post_type_taxonomies_and_woocommerce_only_when_active()
    {
        $choices = PostMetaFields::choices('post');

        $this->assertSame(['author', 'date', 'category', 'tags', 'comments', 'reading_time', 'tax:season'], array_keys($choices));
        $this->assertArrayNotHasKey('woo:price', $choices);

        // Taxonomies that mean nothing to readers aren't offered.
        register_taxonomy('product_shipping_class', 'post', ['public' => true]);
        $this->assertArrayNotHasKey('tax:product_shipping_class', PostMetaFields::choices('post'));
        unregister_taxonomy('product_shipping_class');
    }

    /**
     * Fakes WooCommerce, which then stays for the whole process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_woocommerce_details()
    {
        if ( ! function_exists('wc_get_product')) {
            // Only defined for this test run, as WooCommerce would.
            eval('function wc_get_product($id) { return new \MailOptin\Tests\Core\EmailCampaigns\FakeProduct(); }');
            eval('function wc_get_product_stock_status_options() { return ["instock" => "In stock", "outofstock" => "Out of stock"]; }');
        }

        $post = get_post($this->post_id);

        $this->assertArrayHasKey('woo:price', PostMetaFields::choices('post'));
        // Screen reader text would show in an email, so it goes with its words.
        $this->assertSame('<del><span>£14.00</span></del> <ins style="text-decoration:none"><span>£11.50</span></ins>x', PostMetaFields::value($post, 'woo:price'));
        $this->assertSame('★★★★★ 4.6', PostMetaFields::value($post, 'woo:rating'));
        $this->assertSame('In stock', PostMetaFields::value($post, 'woo:stock'));
        $this->assertSame('TUL-20', PostMetaFields::value($post, 'woo:sku'));

        // The post content shows WooCommerce's price itself, unless the details already include it.
        if ( ! class_exists('WooCommerce')) eval('class WooCommerce {}');
        $campaign = $this->campaign(ER::NEW_PUBLISH_POST, ['content_post_meta' => 'woo:price,date']);
        $html     = (new NewPostTemplatify($campaign, $this->post_id))->forge();
        $this->assertStringNotContainsString('mo-wc-price', $html);
        $this->assertSame(1, substr_count($html, '£11.50'));

        ER::update_settings_by_id($campaign, ['content_post_meta' => 'date']);
        $this->assertStringContainsString('mo-wc-price', (new NewPostTemplatify($campaign, $this->post_id))->forge());
    }

    public function test_existing_emails_show_the_same_details_in_the_same_place()
    {
        $campaign = $this->campaign(ER::NEW_PUBLISH_POST, ['content_post_meta' => 'author,date,category']);

        $html = $this->plain((new NewPostTemplatify($campaign, $this->post_id))->forge());

        $this->assertStringContainsString('<div class="mo-post-meta mo-content-text-color"><span>Maya Okafor</span><span> • </span><span>' . get_the_date(get_option('date_format'), $this->post_id) . '</span><span> • </span><span>Bulbs</span></div>', $html);
        $this->assertLessThan(strpos($html, 'class="mo-imgix"'), strpos($html, 'mo-post-meta mo-content'));
        $this->assertStringNotContainsString('{{post.meta', $html);
    }

    public function test_details_below_the_featured_image()
    {
        $campaign = $this->campaign(ER::NEW_PUBLISH_POST, ['content_post_meta' => 'tags,meta:Grower_Name', 'content_post_meta_position' => PostMetaFields::POSITION_BELOW_IMAGE]);

        $html = $this->plain((new NewPostTemplatify($campaign, $this->post_id))->forge());

        $this->assertSame(1, substr_count($html, '<div class="mo-post-meta-below-image"><div class="mo-post-meta mo-content-text-color">'));
        $this->assertSame(1, substr_count($html, 'class="mo-post-meta '));
        $this->assertGreaterThan(strpos($html, 'class="mo-imgix"'), strpos($html, 'mo-post-meta-below-image"'));
        $this->assertStringContainsString('<span>spring, tulips</span><span> • </span><span>Okafor Gardens</span>', $html);
        $this->assertStringNotContainsString('{{post.meta', $html);

        // Digest emails too.
        $digest = $this->campaign(ER::POSTS_EMAIL_DIGEST, ['content_post_meta' => 'comments', 'content_post_meta_position' => PostMetaFields::POSITION_BELOW_IMAGE]);
        $html   = $this->plain((new DigestTemplatify($digest, [get_post($this->post_id)]))->forge());

        $this->assertGreaterThan(strpos($html, 'class="mo-imgix"'), strpos($html, '<span>3 comments</span>'));
        $this->assertStringNotContainsString('{{post.meta', $html);
    }

    public function test_a_template_without_the_image_slot_keeps_details_under_the_title()
    {
        $double                    = new PostMetaSlotDouble();
        $double->email_campaign_id = $this->campaign(ER::NEW_PUBLISH_POST, ['content_post_meta_position' => PostMetaFields::POSITION_BELOW_IMAGE]);

        $this->assertSame('<h1>T</h1>{{post.meta}}<img>', $double->place_post_meta_slot('<h1>T</h1>{{post.meta}}<img>'));
        $this->assertSame('<h1>T</h1><img><div class="mo-post-meta-below-image">{{post.meta}}</div>', $double->place_post_meta_slot('<h1>T</h1>{{post.meta}}<img>{{post.meta.below_image}}'));

        ER::update_settings_by_id($double->email_campaign_id, ['content_post_meta_position' => PostMetaFields::POSITION_BELOW_TITLE]);
        $this->assertSame('<h1>T</h1>{{post.meta}}<img>', $double->place_post_meta_slot('<h1>T</h1>{{post.meta}}<img>{{post.meta.below_image}}'));
    }

    public function test_newsletter_posts_block_position()
    {
        $campaign = $this->campaign(ER::NEWSLETTER, []);
        $template = new NewsletterLucid($campaign);
        $settings = [
            'block_padding'         => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10],
            'read_more_text'        => 'Read more',
            'post_title_color'      => '',
            'read_more_color'       => '',
            'post_font_family'      => '',
            'post_metas'            => ['tags', 'reading_time'],
            'remove_feature_image'  => false,
            'remove_post_content'   => true,
            'remove_read_more_link' => true,
            'post_content_length'   => 10,
        ];

        $html = $template->posts_block_tmpl('b1', get_post($this->post_id), $settings, 1);
        $this->assertStringContainsString('<span>spring, tulips</span><span>&nbsp;•&nbsp;</span><span>2 min read</span>', $html);
        $this->assertLessThan(strpos($html, '<img'), strpos($html, 'spring, tulips'));

        $html = $template->posts_block_tmpl('b1', get_post($this->post_id), ['post_meta_position' => PostMetaFields::POSITION_BELOW_IMAGE] + $settings, 1);
        $this->assertGreaterThan(strpos($html, '<img'), strpos($html, 'spring, tulips'));

        // No image: the details stay under the title.
        $html = $template->posts_block_tmpl('b1', get_post($this->post_id), ['post_meta_position' => PostMetaFields::POSITION_BELOW_IMAGE, 'remove_feature_image' => true] + $settings, 1);
        $this->assertStringContainsString('spring, tulips', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_builder_labels_are_escaped_and_suggestions_skip_hidden_fields()
    {
        update_post_meta($this->post_id, '_secret_token', 'x');
        delete_transient('mo_post_meta_keys_' . md5('post'));
        add_filter('mailoptin_post_meta_choices', function ($choices) {
            return $choices + ['custom' => 'Price <b>& tax</b>'];
        });

        $choices = \MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements\Posts::post_meta_choices();

        $this->assertSame('Price &lt;b&gt;&amp; tax&lt;/b&gt;', $choices['custom']);
        $this->assertArrayHasKey('meta:Grower_Name', $choices);
        $this->assertArrayHasKey('meta:brand', $choices);
        $this->assertArrayNotHasKey('meta:_secret_token', $choices);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_the_price_is_kept_where_the_details_row_is_not_shown()
    {
        if ( ! class_exists('WooCommerce')) eval('class WooCommerce {}');
        if ( ! function_exists('wc_get_product')) eval('function wc_get_product($id) { return new \\MailOptin\\Tests\\Core\\EmailCampaigns\\FakeProduct(); }');

        $double                    = new PostMetaSlotDouble();
        $double->email_campaign_id = $this->campaign(ER::NEW_PUBLISH_POST, ['content_post_meta' => 'woo:price']);

        // No details passed (the [post-content] shortcode): the price stays, whatever the setting.
        $this->assertStringContainsString('mo-wc-price', $double->post_content($this->post_id));
        // Details with the price: left out. As a comma string too.
        $this->assertStringNotContainsString('mo-wc-price', $double->post_content($this->post_id, null, ['woo:price']));
        $this->assertStringNotContainsString('mo-wc-price', $double->post_content($this->post_id, null, 'author,woo:price'));
        $this->assertStringContainsString('mo-wc-price', $double->post_content($this->post_id, null, []));
    }
}
