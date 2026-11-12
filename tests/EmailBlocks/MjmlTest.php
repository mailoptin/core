<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\EmailBlocks\Blocks;
use MailOptin\Core\EmailBlocks\Style;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * The blocks draw exactly what MJML makes of the same values: tests/EmailBlocks/fixtures holds
 * MJML's own output for the build's samples (bin/email-blocks/build.js), and each sample is drawn
 * here through its block, with its settings, as an email would be.
 */
class MjmlTest extends \WP_UnitTestCase
{
    protected $email;

    protected static $fixtures;

    public function set_up()
    {
        parent::set_up();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        $this->email = ER::add_email_campaign('Mjml ' . uniqid(), ER::NEWSLETTER, 'Lucid');

        if (null === self::$fixtures) self::$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/mjml-samples.json'), true);
    }

    protected function padding($s)
    {
        return ['top' => $s['pad_t'], 'right' => $s['pad_r'], 'bottom' => $s['pad_b'], 'left' => $s['pad_l']];
    }

    /**
     * A sample's values as the block's saved settings.
     */
    protected function settings($fixture, $s)
    {
        switch (preg_replace('/-.*/', '', $fixture)) {
            case 'text':
                return ['text', ['block_padding' => $this->padding($s), 'text_font_size' => $s['font_size'], 'text_content' => 'Hello there']];
            case 'image':
                return ['image', ['block_padding' => $this->padding($s), 'image_width' => $s['width'], 'image_alignment' => $s['align'], 'image_url' => $s['src'], 'image_alt_text' => $s['alt'], 'image_link' => 'image-link' === $fixture ? $s['href'] : '']];
            case 'button':
                return ['button', [
                    'block_padding' => $this->padding($s), 'button_padding' => ['top' => $s['in_t'], 'right' => $s['in_r'], 'bottom' => $s['in_b'], 'left' => $s['in_l']],
                    'button_width'  => $s['width'], 'button_alignment' => $s['align'], 'button_link' => $s['href'], 'button_background_color' => $s['background'],
                    'button_color'  => $s['color'], 'button_font_size' => $s['font_size'], 'button_font_weight' => $s['font_weight'], 'button_border_radius' => $s['radius'],
                    'button_text'   => $s['text'],
                ]];
            case 'divider':
                return ['divider', ['block_padding' => $this->padding($s), 'divider_width' => $s['width'], 'divider_height' => $s['thickness'], 'divider_style' => $s['style'], 'divider_color' => $s['color']]];
            case 'spacer':
                return ['spacer', ['block_padding' => $this->padding($s), 'spacer_height' => $s['height']]];
        }

        return [null, []];
    }

    /**
     * Font stacks come from the template; the samples use one font, so the template is left out.
     */
    protected function draw($type, $settings, $width)
    {
        $style = (new Style($this->email))->with_width($width);
        $block = Blocks::get_instance()->get($type);

        return $block->render(array_replace($block->defaults($this->email), $settings, [
            'text_font_family'   => 'Arial, sans-serif',
            'button_font_family' => 'Arial, sans-serif',
        ]), [], $style, 'x');
    }

    public function test_every_block_draws_what_mjml_makes()
    {
        $checked = 0;

        foreach (self::$fixtures as $fixture => $cases) {
            if (0 === strpos($fixture, 'columns-')) continue;

            // Filled directly, with values already escaped: the Posts block (post-text) and the post
            // templates such as Folio (section).
            if (in_array($fixture, ['post-text', 'section'], true)) {
                foreach ($cases as $case) {
                    $values = 'section' === $fixture ? $case['sample'] + ['inner_width' => $case['sample']['container'] - $case['sample']['pad_l'] - $case['sample']['pad_r']] : $case['sample'];
                    $this->assertSame($case['html'], \MailOptin\Core\EmailBlocks\Mjml::fill($fixture, $values), $fixture);
                }
                continue;
            }

            foreach ($cases as $case) {
                [$type, $settings] = $this->settings($fixture, $case['sample']);
                $this->assertNotNull($type, $fixture);

                $expected = $case['html'];
                // The text block's content goes through wpautop.
                if ('text' === $type) $expected = str_replace('<p>Hello there</p>', wpautop('Hello there'), $expected);

                if ('text' === $type) ER::update_settings_by_id($this->email, ['content_text_color' => $case['sample']['color']]);

                $this->assertSame($expected, $this->draw($type, $settings, $case['sample']['container']), "$fixture: " . wp_json_encode($case['sample']));
                $checked++;
            }
        }

        $this->assertGreaterThan(15, $checked);
    }

    public function test_columns_draw_what_mjml_makes()
    {
        $checked = 0;

        foreach (self::$fixtures as $fixture => $cases) {
            if ( ! preg_match('/^columns-(\d\d-\d\d(?:-\d\d)?)-(top|middle)(-nostack)?$/', $fixture, $m)) continue;

            foreach ($cases as $case) {
                $s        = $case['sample'];
                $settings = [
                    'columns_layout' => $m[1], 'columns_valign' => $m[2], 'columns_stack' => empty($m[3]),
                    'columns_gap'    => 2 * $s['half_gap'], 'columns_background' => $s['background'], 'block_padding' => $this->padding($s),
                ];
                $children = [];
                foreach ($s as $key => $value) if (preg_match('/^col(\d)$/', $key, $c)) $children[(int)$c[1]] = $value;

                $html = (new \MailOptin\Core\EmailBlocks\Builtin\Columns())->render_columns($settings, $children, 'x', $s['container']);

                $this->assertSame('<tr><td style="padding:0;">' . $case['html'] . '</td></tr>', $html, $fixture);
                $checked++;
            }
        }

        $this->assertSame(16, $checked);
    }
}
