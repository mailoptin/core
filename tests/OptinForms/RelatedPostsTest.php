<?php

namespace MailOptin\Tests\Core\OptinForms;

use MailOptin\Core\OptinForms\RelatedPosts;
use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;
use MailOptin\Core\Repositories\OptinCampaignStat;

/**
 * Read Next: which posts are related, when the campaign shows, what it draws and how clicks count.
 */
class RelatedPostsTest extends \WP_UnitTestCase
{
    protected $bulbs;
    protected $roses;
    protected $current;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\RegisterActivation\CreateDBTables::make();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        // The settings cache lives for the request; tests reuse campaign ids.
        OCR::flush_saved_customizer_data_cache();

        $this->bulbs   = self::factory()->category->create(['name' => 'Bulbs']);
        $this->roses   = self::factory()->category->create(['name' => 'Roses']);
        $this->current = $this->post('The post being read', [$this->bulbs], 5);
    }

    protected function post($title, $cats, $days_ago, $extra = [])
    {
        return self::factory()->post->create($extra + [
                'post_title'    => $title,
                'post_category' => $cats,
                'post_date'     => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS * $days_ago),
            ]);
    }

    protected function campaign($class = 'ReadNext', $settings = [], $active = true)
    {
        $id = OCR::add_optin_campaign(\MailOptin\Core\AjaxHandler::generateUniqueId(), 'Read next ' . uniqid(), $class, 'slidein');
        $this->assertNotEmpty($id);
        if ($active) OCR::activate_campaign($id);

        $all      = get_option(MO_OPTIN_CAMPAIGN_WP_OPTION_NAME, []);
        $all[$id] = $settings + (isset($all[$id]) ? $all[$id] : []);
        update_option(MO_OPTIN_CAMPAIGN_WP_OPTION_NAME, $all);
        OCR::flush_saved_customizer_data_cache();

        return $id;
    }

    protected function titles($posts)
    {
        return wp_list_pluck($posts, 'post_title');
    }

    public function test_related_posts_share_a_category_newest_first_without_the_current_post()
    {
        $this->post('Old bulbs', [$this->bulbs], 30);
        $this->post('New bulbs', [$this->bulbs], 1);
        $this->post('Middle bulbs', [$this->bulbs], 10);
        $this->post('Roses only', [$this->roses], 0);
        $this->post('Draft bulbs', [$this->bulbs], 0, ['post_status' => 'draft']);
        $this->post('Locked bulbs', [$this->bulbs], 0, ['post_password' => 'secret']);

        $this->assertSame(['New bulbs', 'Middle bulbs', 'Old bulbs'], $this->titles(RelatedPosts::find($this->current, 3)));
        $this->assertSame(['New bulbs'], $this->titles(RelatedPosts::find($this->current, 1)));
        $this->assertSame(3, count(RelatedPosts::find($this->current, 12)), 'At most three.');
    }

    public function test_uncategorized_alone_relates_nothing()
    {
        $lonely = $this->post('Lonely', [(int)get_option('default_category')], 2);
        $this->post('Also uncategorized', [(int)get_option('default_category')], 1);

        $this->assertSame([], RelatedPosts::find($lonely));
    }

    public function test_related_by_tags_or_categories()
    {
        wp_set_post_tags($this->current, ['spring']);
        $this->post('Bulbs, no tag', [$this->bulbs], 1);
        $tagged = $this->post('Roses tagged spring', [$this->roses], 2);
        wp_set_post_tags($tagged, ['spring']);

        $this->assertSame(['Bulbs, no tag', 'Roses tagged spring'], $this->titles(RelatedPosts::find($this->current, 3, 'both')));
        $this->assertSame(['Bulbs, no tag'], $this->titles(RelatedPosts::find($this->current, 3, 'category')));
        $this->assertSame(['Roses tagged spring'], $this->titles(RelatedPosts::find($this->current, 3, 'tag')));
    }

    public function test_a_new_post_shows_up_straight_away()
    {
        $this->post('First', [$this->bulbs], 2);
        $this->assertSame(['First'], $this->titles(RelatedPosts::find($this->current)));

        $this->post('Second', [$this->bulbs], 1);
        $this->assertSame(['Second', 'First'], $this->titles(RelatedPosts::find($this->current)));
    }

    public function test_the_campaign_shows_only_on_posts_with_related_posts()
    {
        $campaign = $this->campaign();
        $other    = $this->campaign('Mimosa');

        $this->go_to(get_permalink($this->current));
        $this->assertFalse(apply_filters('mailoptin_show_optin_form_after_targeting', true, $campaign), 'Nothing related yet.');
        $this->assertTrue(apply_filters('mailoptin_show_optin_form_after_targeting', true, $other), 'Other templates are left alone.');

        $this->post('Related', [$this->bulbs], 1);
        $this->assertTrue(apply_filters('mailoptin_show_optin_form_after_targeting', true, $campaign));

        $this->go_to(home_url('/'));
        $this->assertFalse(apply_filters('mailoptin_show_optin_form_after_targeting', true, $campaign), 'Not on pages that are not a post.');

        $this->go_to(get_permalink($this->current));
        $this->assertFalse(apply_filters('mailoptin_show_optin_form_after_targeting', false, $campaign), 'Never shows what another rule hid.');
    }

    public function test_the_list_follows_the_settings_and_is_escaped()
    {
        $this->post('Tulips <script>alert(1)</script> & more', [$this->bulbs], 1);
        $this->post('Daffodils', [$this->bulbs], 2);
        $form = (object)['optin_campaign_id' => $this->campaign()];

        $this->go_to(get_permalink($this->current));
        $html = RelatedPosts::render($form, ['class' => 'readnext-posts']);

        $this->assertStringContainsString('mo-related-posts--count-2 mo-related-posts--images readnext-posts', $html);
        $this->assertSame(2, substr_count($html, 'class="mo-related-post-link"'));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Bulbs · ', $html, 'Category and date.');
        $this->assertStringContainsString('mo-related-post-image--none', $html, 'A post without an image keeps its place.');
        $this->assertStringNotContainsString('The post being read', $html);

        $form = (object)['optin_campaign_id' => $this->campaign('ReadNext', ['related_posts_count' => '1', 'related_posts_images' => false, 'related_posts_meta' => false])];
        $html = RelatedPosts::render($form);

        $this->assertSame(1, substr_count($html, 'class="mo-related-post-link"'));
        $this->assertStringNotContainsString('mo-related-post-image', $html);
        $this->assertStringNotContainsString('mo-related-post-meta', $html);
        $this->assertStringNotContainsString('mo-related-posts--images', $html);
    }

    public function test_settings_are_cleaned()
    {
        $options = RelatedPosts::options($this->campaign('ReadNext', ['related_posts_count' => '9', 'related_posts_match' => 'nonsense', 'related_posts_email_box' => 'true']));

        $this->assertSame(3, $options['count']);
        $this->assertSame('both', $options['match']);
        $this->assertTrue($options['email_box']);
        $this->assertTrue($options['images'], 'Default.');
    }

    public function test_a_click_counts_as_a_conversion_for_active_read_next_campaigns_only()
    {
        $campaign = $this->campaign();
        $paused   = $this->campaign('ReadNext', [], false);
        $other    = $this->campaign('Mimosa');

        $this->assertTrue(RelatedPosts::track_click(['optin_uuid' => OCR::get_optin_campaign_uuid($campaign)]));
        $this->assertFalse(RelatedPosts::track_click(['optin_uuid' => OCR::get_optin_campaign_uuid($paused)]));
        $this->assertFalse(RelatedPosts::track_click(['optin_uuid' => OCR::get_optin_campaign_uuid($other)]), 'A form campaign only converts on sign-up.');
        $this->assertFalse(RelatedPosts::track_click(['optin_uuid' => 'nope']));
        $this->assertFalse(RelatedPosts::track_click(['optin_uuid' => ['array']]));

        $this->assertFalse(RelatedPosts::track_click(['optin_uuid' => OCR::get_optin_campaign_uuid($campaign)]), 'The same visitor again soon: no second conversion.');

        $this->assertEquals(1, (new OptinCampaignStat($campaign))->get_conversions());
        $this->assertEquals(0, (new OptinCampaignStat($other))->get_conversions());
    }

    public function test_read_next_is_a_premium_slide_in_template()
    {
        $themes = array_filter(\MailOptin\Core\Repositories\OptinThemesRepository::premium_themes(), function ($t) {
            return 'ReadNext' === $t['optin_class'];
        });

        $this->assertCount(1, $themes);
        $this->assertSame('slidein', current($themes)['optin_type']);
        $this->assertFileExists(dirname(__DIR__, 2) . '/src/assets/images/optin-themes/readnext-slidein.png');
    }

    public function test_the_click_script_is_left_out_in_test_mode()
    {
        $live    = $this->campaign();
        $testing = $this->campaign('ReadNext', ['test_mode' => true]);
        $form    = function ($id) { return (object)['optin_campaign_id' => $id, 'optin_campaign_uuid' => OCR::get_optin_campaign_uuid($id)]; };

        $this->assertStringContainsString('track_optin_click', RelatedPosts::click_script($form($live)));
        $this->assertSame('', RelatedPosts::click_script($form($testing)));
    }
}
