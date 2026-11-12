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

    /* Ledger, Letter, Spotlight ------------------------------------------------------------------ */

    protected function make($template, $type, $settings = [])
    {
        $id = ER::add_email_campaign($template . ' ' . uniqid(), $type, $template);
        if ($settings) ER::update_settings_by_id($id, $settings);

        return ER::NEW_PUBLISH_POST === $type
            ? (new NewPostTemplatify($id, $this->posts[0]))->forge()
            : (new DigestTemplatify($id, $this->posts))->forge();
    }

    public function test_every_template_is_listed_after_folio_and_before_lucid()
    {
        $property = new \ReflectionProperty(EmailTemplatesRepository::class, 'email_templates');
        $property->setAccessible(true);
        $property->setValue(null, null);

        foreach ([ER::NEW_PUBLISH_POST, ER::POSTS_EMAIL_DIGEST] as $type) {
            $this->assertSame(['Folio', 'Ledger', 'Letter', 'Spotlight', 'Lucid'], array_values(array_intersect(
                wp_list_pluck(EmailTemplatesRepository::get_by_type($type), 'template_class'),
                ['Folio', 'Ledger', 'Letter', 'Spotlight', 'Lucid']
            )));
        }
    }

    public function provide_templates()
    {
        return [['Folio'], ['Ledger'], ['Letter'], ['Spotlight']];
    }

    /**
     * @dataProvider provide_templates
     */
    public function test_each_template_draws_both_emails($template)
    {
        $html = $this->make($template, ER::NEW_PUBLISH_POST);
        $this->assertStringContainsString('Tulips in November', $html, $template);
        $this->assertStringContainsString('Notes on tulips in november', $html, $template);
        $this->assertStringNotContainsString('__MO_', $html);
        $this->assertStringNotContainsString('{{post.', $html);

        // The digest follows the Columns setting.
        foreach (['1' => null, '2' => 'mj-column-per-50', '3' => 'mj-column-per-33-33'] as $count => $class) {
            $html = $this->make($template, ER::POSTS_EMAIL_DIGEST, ['column_count' => $count]);

            foreach ($this->posts as $post) $this->assertStringContainsString($post->post_title, $html, "$template, $count");
            $this->assertStringNotContainsString('__MO_', $html);
            if ($class) $this->assertStringContainsString('class="' . $class . ' ', $html, "$template, $count columns");
            if ( ! $class) $this->assertStringNotContainsString('class="mj-column-per-50 ', $html, "$template, one column");
        }
    }

    public function test_defaults_stay_with_their_template()
    {
        // Several emails drawn in one request, as when a cron run sends them: Letter's opening line
        // and its images-off default must not reach the others.
        $letter = $this->make('Letter', ER::NEW_PUBLISH_POST);
        $this->assertStringContainsString('just published something', $letter);
        $this->assertStringNotContainsString('flower.jpg', $letter, 'Letter leaves images out by default.');

        foreach (['Folio', 'Spotlight', 'Ledger'] as $template) {
            $html = $this->make($template, ER::NEW_PUBLISH_POST);
            $this->assertStringNotContainsString('just published something', $html, $template);
            $this->assertStringContainsString('flower.jpg', $html, $template);
        }

        // And a digest's defaults stay with digests.
        $this->assertStringContainsString('went up on the blog', $this->make('Letter', ER::POSTS_EMAIL_DIGEST));
        $this->assertStringNotContainsString('went up on the blog', $this->make('Letter', ER::NEW_PUBLISH_POST));
    }

    public function test_category_labels()
    {
        $term = self::factory()->category->create(['name' => 'Bulbs & Seeds']);
        foreach ($this->posts as $post) wp_set_post_categories($post->ID, [$term]);

        foreach (['Ledger', 'Spotlight'] as $template) {
            $this->assertStringContainsString('Bulbs &amp; Seeds', $this->make($template, ER::NEW_PUBLISH_POST), $template);
            $this->assertStringContainsString('Bulbs &amp; Seeds', $this->make($template, ER::POSTS_EMAIL_DIGEST), $template);
        }

        // Folio has none.
        $this->assertStringNotContainsString('Bulbs &amp; Seeds', $this->make('Folio', ER::NEW_PUBLISH_POST));

        // "Uncategorized" isn't shown, and its empty label leaves no row behind.
        foreach ($this->posts as $post) wp_set_post_categories($post->ID, [(int)get_option('default_category')]);
        $html = $this->make('Ledger', ER::NEW_PUBLISH_POST);
        $this->assertStringNotContainsString('Uncategorized', $html);
        $this->assertStringNotContainsString('<!--mo-post-category-->', $html);
    }

    public function test_spotlight_leads_with_the_newest_post()
    {
        $html = $this->make('Spotlight', ER::POSTS_EMAIL_DIGEST, ['column_count' => '3']);

        // The first post is drawn once, in the lead, before the others.
        $first = $this->posts[0]->post_title;
        $this->assertSame(1, substr_count($html, '>' . $first . '<'));
        $this->assertLessThan(strpos($html, $this->posts[1]->post_title), strpos($html, $first));
        // The other three fill one row of three.
        $this->assertSame(3, substr_count($html, 'class="mj-column-per-33-33 '));
    }

    public function test_ledger_and_letter_use_links_not_buttons()
    {
        $ledger = $this->make('Ledger', ER::NEW_PUBLISH_POST);
        $this->assertStringContainsString('Continue reading →', $ledger);
        $this->assertStringNotContainsString('bgcolor="#9b2226"', $ledger);

        $this->assertStringContainsString('Read it on the blog →', $this->make('Letter', ER::NEW_PUBLISH_POST));
    }

    public function test_spotlight_lead_keeps_its_link_and_text()
    {
        // Links off: the lead's button is still there, and its excerpt opens the body.
        $html = $this->make('Spotlight', ER::POSTS_EMAIL_DIGEST, ['content_remove_post_link' => true]);
        $this->assertStringContainsString('href="' . esc_url(get_permalink($this->posts[0])) . '"', $html);
        $this->assertStringContainsString('Notes on tulips in november', $html);

        // One post, excerpt and button off: no empty white band.
        $this->posts = [$this->posts[0]];
        $html = $this->make('Spotlight', ER::POSTS_EMAIL_DIGEST, ['content_remove_post_body' => true, 'content_remove_ellipsis_button' => true]);
        $this->assertStringContainsString('Tulips in November', $html);
        $this->assertStringNotContainsString('<tbody></tbody>', preg_replace('/\s+/', '', $html));
    }

    public function test_spotlight_yellow_stays_on_the_dark_band()
    {
        $html = $this->make('Spotlight', ER::POSTS_EMAIL_DIGEST, ['column_count' => '2']);

        // The compact links on white use the dark button colour, not the yellow.
        $this->assertDoesNotMatchRegularExpression('/<a [^>]*color:\s*#f2b705[^>]*>[^<]*Read the story/i', $html);
        $this->assertMatchesRegularExpression('/color:\s*#f2b705/i', $html, 'The lead keeps its yellow label.');
    }

    public function test_letter_has_a_web_version_link()
    {
        $this->assertStringContainsString('{{webversion}}', $this->make('Letter', ER::NEW_PUBLISH_POST));
    }
}
