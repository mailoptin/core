<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\EmailCampaigns\NewPublishPost\Templatify as NewPostTemplatify;
use MailOptin\Core\EmailCampaigns\PostsEmailDigest\Templates\Folio as FolioDigest;
use MailOptin\Core\EmailCampaigns\PostsEmailDigest\Templatify as DigestTemplatify;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\EmailTemplatesRepository;

/**
 * The MJML post templates (Folio) for new-post emails and digests.
 */
class PostTemplatesTest extends \WP_UnitTestCase
{
    protected $posts = [];

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\RegisterActivation\CreateDBTables::make();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();

        $image = 'https://example.com/flower.jpg';
        add_filter('mo_email_automation_post_feature_image', function () use ($image) { return $image; });

        foreach (['Tulips in November', 'Pruning roses', 'Bulb planters', 'Autumn lawns'] as $i => $title) {
            $this->posts[] = get_post(self::factory()->post->create([
                'post_title'   => $title,
                'post_content' => '<p>Notes on ' . strtolower($title) . ' for the garden.</p>',
                'post_date'    => '2026-11-0' . ($i + 1) . ' 10:00:00',
            ]));
        }
    }

    protected function campaign($type, $settings = [])
    {
        $id = ER::add_email_campaign('Folio ' . uniqid(), $type, 'Folio');
        if ($settings) ER::update_settings_by_id($id, $settings);

        return $id;
    }

    protected function new_post_email($settings = [])
    {
        return (new NewPostTemplatify($this->campaign(ER::NEW_PUBLISH_POST, $settings), $this->posts[0]))->forge();
    }

    protected function digest($settings = [])
    {
        return (new DigestTemplatify($this->campaign(ER::POSTS_EMAIL_DIGEST, $settings), $this->posts))->forge();
    }

    public function test_folio_comes_first_and_lucid_stays()
    {
        $property = new \ReflectionProperty(EmailTemplatesRepository::class, 'email_templates');
        $property->setAccessible(true);
        $property->setValue(null, null);

        foreach ([ER::NEW_PUBLISH_POST, ER::POSTS_EMAIL_DIGEST] as $type) {
            $names = wp_list_pluck(EmailTemplatesRepository::get_by_type($type), 'template_class');
            $this->assertSame('Folio', $names[0], $type);
            $this->assertContains('Lucid', $names, $type);
        }

        // Not offered for broadcasts: Folio draws posts.
        $this->assertNotContains('Folio', wp_list_pluck((array)EmailTemplatesRepository::get_by_type(ER::NEWSLETTER), 'template_class'));
    }

    public function test_new_post_email()
    {
        $html = $this->new_post_email();

        $this->assertStringContainsString('Tulips in November', $html);
        $this->assertStringContainsString('Notes on tulips in november', $html);
        $this->assertStringContainsString('src="https://example.com/flower.jpg"', $html);
        $this->assertStringContainsString(get_permalink($this->posts[0]), $html);
        // Folio's accent on the button, its heading font, the email 600 wide.
        $this->assertMatchesRegularExpression('/background(-color)?:\s*#2e6b4f/i', $html);
        $this->assertStringContainsString('Georgia', $html);
        $this->assertMatchesRegularExpression('/max-width:\s*600px/', $html);
        // Every slot and placeholder filled; MJML's column widths in the head.
        $this->assertStringNotContainsString('__MO_', $html);
        $this->assertStringNotContainsString('{{post.', $html);
        $this->assertMatchesRegularExpression('#\.mj-column-per-100\s*\{[^}]*width:\s*100%#', $html);
        $this->assertStringContainsString('{{unsubscribe}}', $html, 'Left for the sender to fill in.');
    }

    public function test_new_post_email_leaves_out_a_missing_image()
    {
        add_filter('mo_email_automation_post_feature_image', '__return_empty_string', 20);

        $html = $this->new_post_email();

        $this->assertStringNotContainsString('<img alt="" src=""', $html);
        $this->assertDoesNotMatchRegularExpression('/src=""/', $html);
        $this->assertStringContainsString('Tulips in November', $html);
    }

    public function test_new_post_switches()
    {
        $html = $this->new_post_email([
            'content_remove_ellipsis_button' => true,
            'content_remove_post_body'       => true,
            'content_remove_feature_image'   => true,
            'content_remove_post_link'       => true,
        ]);

        $this->assertStringContainsString('Tulips in November', $html);
        $this->assertStringNotContainsString('Notes on tulips', $html);
        $this->assertStringNotContainsString('flower.jpg', $html);
        $this->assertStringNotContainsString('href="' . get_permalink($this->posts[0]) . '"', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*Read more\s*</', $html);
    }

    public function test_new_post_uses_customizer_colours()
    {
        $html = $this->new_post_email([
            'content_ellipsis_button_background_color' => '#123456',
            'content_headline_color'                   => '#abcdef',
            'page_background_color'                    => 'red;background:url(x)',
        ]);

        $this->assertStringContainsString('#123456', $html);
        $this->assertStringContainsString('#abcdef', $html);
        // Not a colour: Folio's own instead.
        $this->assertStringNotContainsString('url(x)', $html);
        $this->assertStringContainsString('#eef1f4', $html);
    }

    public function test_digest_columns()
    {
        $one = $this->digest(['column_count' => '1']);
        $this->assertStringContainsString('class="mj-column-per-33 ', $one);
        $this->assertStringContainsString('class="mj-column-per-67 ', $one);

        $two = $this->digest(['column_count' => '2']);
        $this->assertStringContainsString('class="mj-column-per-50 ', $two);
        $this->assertStringContainsString('Notes on pruning roses', $two);

        $three = $this->digest(['column_count' => '3']);
        $this->assertStringContainsString('class="mj-column-per-33-33 ', $three);
        // Three across: image, title and link only.
        $this->assertStringNotContainsString('Notes on pruning roses', $three);

        foreach ([$one, $two, $three] as $html) {
            foreach ($this->posts as $post) {
                $this->assertStringContainsString($post->post_title, $html);
                $this->assertStringContainsString(esc_url(get_permalink($post)), $html);
            }
            $this->assertStringNotContainsString('__MO_', $html);
            $this->assertMatchesRegularExpression('#\.mj-column-per-50\s*\{#', $html, 'The columns head is added.');
        }
    }

    public function test_digest_odd_count_and_bad_column_setting()
    {
        array_pop($this->posts);

        $html = $this->digest(['column_count' => '2']);
        $this->assertStringContainsString('Bulb planters', $html);

        // Out of range: one column.
        $html = $this->digest(['column_count' => '7']);
        $this->assertStringContainsString('class="mj-column-per-67 ', $html);
        $this->assertStringNotContainsString('class="mj-column-per-50 ', $html);
    }

    public function test_digest_keeps_the_post_filters()
    {
        add_filter('mo_posts_email_digest_post_title', function ($title) { return 'Filtered: ' . $title; });

        $this->assertStringContainsString('Filtered: Pruning roses', $this->digest());
    }

    public function test_digest_customizer_settings_redraw_the_preview()
    {
        $template = new FolioDigest($this->campaign(ER::POSTS_EMAIL_DIGEST), $this->posts);

        $settings = $template->customizer_content_settings(['content_text_color' => ['default' => '#000', 'transport' => 'postMessage']]);

        $this->assertSame('refresh', $settings['content_text_color']['transport']);
        $this->assertSame('refresh', $settings['column_count']['transport']);
        $this->assertSame('1', $settings['column_count']['default']);
    }

    public function test_content_alignment_is_honoured()
    {
        $html = $this->new_post_email(['content_alignment' => 'center']);

        $this->assertMatchesRegularExpression('/text-align:\s*center;?">\s*(<a [^>]*>)?\s*<span class="mo-content-headline-color"/', $html);
        $this->assertMatchesRegularExpression('/<td[^>]*align="center"[^>]*>\s*<table[^>]*>\s*<tbody>\s*<tr>\s*<td[^>]*bgcolor/s', $html, 'The button is centred.');

        // Anything else: left.
        $this->assertMatchesRegularExpression('/text-align:\s*left;?">\s*(<a [^>]*>)?\s*<span class="mo-content-headline-color"/', $this->new_post_email(['content_alignment' => 'evil;']));
    }

    public function test_digest_column_sizes_survive_the_customizer_css()
    {
        $html = $this->digest(['column_count' => '3', 'content_title_font_size' => '30']);

        $this->assertMatchesRegularExpression('/<span class="mo-content-headline-color" style="[^"]*font-size:\s*16px/', $html);
        $this->assertDoesNotMatchRegularExpression('/<span class="mo-content-headline-color" style="[^"]*font-size:\s*30px/', $html);
    }

    public function test_digest_runs_the_per_post_hooks()
    {
        add_filter('mo_email_campaign_ped_search_args', function ($search) { $search[] = '{{post.id}}'; return $search; });
        add_filter('mo_email_campaign_ped_replace_args', function ($replace, $post) { $replace[] = 'post-' . $post->ID; return $replace; }, 10, 2);
        add_filter('mo_email_campaign_ped_single_post_item', function ($html, $post) { return $html . '<!--item ' . $post->ID . '-->'; }, 10, 2);
        add_filter('mo_posts_email_digest_post_title', function ($title) { return $title . ' {{post.id}}'; });

        $html = $this->digest(['column_count' => '2']);

        foreach ($this->posts as $post) {
            $this->assertStringContainsString('<!--item ' . $post->ID . '-->', $html);
            $this->assertStringContainsString($post->post_title . ' post-' . $post->ID, $html);
        }
    }

    public function test_settings_are_looked_up_once()
    {
        global $wpdb;

        $template = new FolioDigest($this->campaign(ER::POSTS_EMAIL_DIGEST), $this->posts);
        $setting  = new \ReflectionMethod($template, 'setting');
        $setting->setAccessible(true);

        $setting->invoke($template, 'content_text_color');
        $before = $wpdb->num_queries;
        $setting->invoke($template, 'content_text_color');

        $this->assertSame($before, $wpdb->num_queries);
    }

    public function test_large_logos_fit_the_header()
    {
        $this->assertStringContainsString('.mo-header-text img { max-width:100%', (new FolioDigest($this->campaign(ER::POSTS_EMAIL_DIGEST), $this->posts))->get_styles());
    }
}
