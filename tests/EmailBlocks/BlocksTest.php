<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\EmailBlocks\AbstractBlock;
use MailOptin\Core\EmailBlocks\Blocks;
use MailOptin\Core\EmailBlocks\Builtin\ElementBlock;
use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Style;
use MailOptin\Core\EmailCampaigns\Newsletter\Templatify;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

class QuoteBlock extends AbstractBlock
{
    public function id() { return 'quote'; }
    public function title() { return 'Quote'; }
    public function available_for($email_campaign_id) { return 'automation_email' === ER::get_email_campaign_type($email_campaign_id); }

    public function fields()
    {
        return [
            'quote'  => ['type' => 'text', 'label' => 'Quote', 'default' => 'Hello'],
            'colour' => ['type' => 'color_picker', 'label' => 'Colour', 'default' => '#333333'],
            'align'  => ['type' => 'select', 'label' => 'Align', 'choices' => ['left' => 'Left', 'right' => 'Right'], 'default' => 'left'],
        ];
    }

    public function render($settings, $context, Style $style, $id)
    {
        return '<tr><td id="' . esc_attr($id) . '" style="color:' . esc_attr($settings['colour']) . '">' . esc_html($settings['quote']) . ' ' . esc_html($context['name'] ?? '') . '</td></tr>';
    }
}

class OverridingTemplate
{
    public function quote_block($id, $settings)
    {
        return '<tr><td>template quote</td></tr>';
    }
}

class BlocksTest extends \WP_UnitTestCase
{
    protected $email;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        ElementBlock::flush_defaults();

        $this->email = ER::add_email_campaign('Blocks ' . uniqid(), ER::NEWSLETTER, 'Lucid');

        // Hooks are reset after each test.
        add_action('mailoptin_email_blocks', function (Blocks $blocks) { $blocks->add(new QuoteBlock()); });
    }

    protected function blocks()
    {
        // A fresh registry each time, so the action above is seen.
        return new Blocks();
    }

    public function test_built_in_and_registered_blocks()
    {
        $all = $this->blocks()->all();

        foreach (['text', 'image', 'button', 'divider', 'spacer', 'posts', 'quote'] as $id) {
            $this->assertArrayHasKey($id, $all);
        }

        // Offered only where it says so.
        $this->assertArrayNotHasKey('quote', $this->blocks()->available_for($this->email));
        $automation = ER::add_email_campaign('Automation ' . uniqid(), 'automation_email', 'Lucid');
        $this->assertArrayHasKey('quote', $this->blocks()->available_for($automation));

        $this->assertSame('Text', $all['text']->to_array($this->email)['title']);
        $this->assertArrayHasKey('text_content', $all['text']->to_array($this->email)['fields']);
    }

    public function test_rendering_uses_defaults_context_and_template_overrides()
    {
        $html = $this->blocks()->render([['id' => 'q1', 'type' => 'quote', 'settings' => []], ['id' => 'x', 'type' => 'nope']], $this->email, ['name' => 'Maya']);
        $this->assertSame('<tr><td id="q1" style="color:#333333">Hello Maya</td></tr>', $html);

        $html = $this->blocks()->render([['id' => 'q1', 'type' => 'quote', 'settings' => []]], $this->email, [], new OverridingTemplate());
        $this->assertSame('<tr><td>template quote</td></tr>', $html);
    }

    public function test_built_in_blocks_render_safely()
    {
        $html = $this->blocks()->render([
            ['id' => 'b"1', 'type' => 'button', 'settings' => [
                'button_text'             => '<script>x</script>Buy',
                'button_link'             => '{{cart.url}}',
                'button_background_color' => 'red;background:url(x)',
                'button_font_size'        => '',
            ]],
            ['id' => 't1', 'type' => 'text', 'settings' => ['text_content' => 'Hi', 'text_font_size' => '']],
            ['id' => 'i1', 'type' => 'image', 'settings' => ['image_url' => 'javascript:alert(1)', 'image_link' => 'https://example.org/"x']],
        ], $this->email);

        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;Buy', $html);
        $this->assertStringContainsString('href="{{cart.url}}"', $html, 'Placeholders are filled in when sending.');
        $this->assertStringNotContainsString('url(x)', $html);
        $this->assertStringNotContainsString('id="b"1"', $html);
        $this->assertStringNotContainsString('font-size:0px;line-height', $html, 'An unset size is left out, not 0.');
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('"x"', $html);
    }

    public function test_content_is_cleaned_on_save()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        if (is_multisite()) $this->markTestSkipped('Editors have no unfiltered_html on multisite either way.');
        // Single-site editors can post unfiltered HTML; take it away to test the filtered path.
        add_filter('map_meta_cap', $f = function ($caps, $cap) { return 'unfiltered_html' === $cap ? ['do_not_allow'] : $caps; }, 10, 2);

        $clean = $this->blocks()->sanitize_content([
            ['id' => 'a<b', 'type' => 'quote', 'settings' => ['quote' => '<b>x</b>', 'colour' => 'blue;', 'align' => 'middle', 'extra' => 'dropped']],
            ['id' => 't', 'type' => 'text', 'settings' => ['text_content' => '<p onclick="x()">Hi</p><script>bad()</script>', 'block_padding' => ['top' => '-5', 'left' => 'x']]],
            ['type' => 'nope'],
            'junk',
        ]);

        remove_filter('map_meta_cap', $f, 10);

        $this->assertCount(2, $clean);
        $this->assertSame('ab', $clean[0]['id']);
        $this->assertSame(['quote' => 'x', 'colour' => '', 'align' => ''], $clean[0]['settings']);
        $this->assertSame('<p>Hi</p>bad()', $clean[1]['settings']['text_content']);
        $this->assertSame(['top' => '5', 'bottom' => '0', 'right' => '0', 'left' => '0'], $clean[1]['settings']['block_padding']);
    }

    public function test_field_helpers()
    {
        $this->assertSame('#abc', Fields::color('#abc'));
        $this->assertSame('rgba(1, 2, 3, 0.5)', Fields::color('rgba(1, 2, 3, 0.5)'));
        $this->assertSame('', Fields::color('expression(alert(1))'));
        $this->assertSame('1px 2px 3px 4px', Fields::padding(['top' => 1, 'right' => 2, 'bottom' => 3, 'left' => 4]));
        $this->assertSame('5', Fields::sanitize(['type' => 'range', 'min' => 5, 'max' => 10], '1'));
        $this->assertTrue(Fields::sanitize(['type' => 'checkbox'], 'true'));
    }

    public function test_a_broadcast_renders_through_the_blocks()
    {
        ER::update_settings_by_id($this->email, ['email_newsletter_content' => wp_json_encode([
            ['id' => 'one', 'type' => 'text', 'settings' => ['text_content' => 'First paragraph']],
            ['id' => 'two', 'type' => 'spacer', 'settings' => ['spacer_height' => '30']],
        ])]);

        $html = (new Templatify($this->email))->builderHtml();

        $this->assertStringContainsString('id="one"', $html);
        $this->assertStringContainsString('<p>First paragraph</p>', $html);
        $this->assertStringContainsString('height:30px', $html);
        $this->assertStringContainsString('First paragraph', (new Templatify($this->email))->forge());
    }
}
