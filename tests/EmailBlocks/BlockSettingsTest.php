<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements\SettingsFields;
use MailOptin\Core\Admin\EmailEditor\EmailEditor;
use MailOptin\Core\EmailBlocks\Blocks;

/**
 * A block's settings form: Content and Style panels, style sections and the friendlier controls.
 */
class BlockSettingsTest extends \WP_UnitTestCase
{
    protected function form($type)
    {
        $block = Blocks::get_instance()->get($type);
        $this->assertNotNull($block, $type);

        return EmailEditor::block_form_html($block);
    }

    public function test_button_form_has_content_and_style_panels_with_sections()
    {
        $html = $this->form('button');

        $this->assertStringContainsString('mo-ee-subtab', $html);
        $this->assertStringContainsString('data-tab="content"', $html);
        $this->assertStringContainsString('data-tab="style"', $html);
        $this->assertStringContainsString('mo-ee-section-h', $html);
        $this->assertStringContainsString('mo-ee-reset-style', $html);
        // Every setting keeps its input, so filling and saving work as before.
        foreach (array_keys(Blocks::get_instance()->get('button')->fields()) as $name) {
            $this->assertStringContainsString('data-name="' . $name . '"', $html, $name);
        }
    }

    public function test_controls_take_friendlier_forms()
    {
        $html = $this->form('button');

        $this->assertStringContainsString('mo-ee-range', $html);       // slider with its value
        $this->assertStringContainsString('mo-ee-seg-icons', $html);   // alignment as icons
        $this->assertStringContainsString('mo-ee-seg-source', $html);  // the select behind the buttons
        $this->assertStringContainsString('mo-ee-sides', $html);       // left/right tucked away
        $this->assertStringNotContainsString('(px)', $html);           // the unit moves beside the slider
        $this->assertStringContainsString('Inside the button', $html);
    }

    public function test_image_alt_text_sits_with_the_content()
    {
        $html = $this->form('image');
        $content = substr($html, 0, strpos($html, 'data-tab="style"', strpos($html, 'mo-ee-subpanel')));

        $this->assertStringContainsString('data-name="image_alt_text"', $content);
        $this->assertStringContainsString('mo-ee-image-preview', $html);
    }

    public function test_products_fields_show_only_for_their_source()
    {
        $html = $this->form('products');

        $this->assertMatchesRegularExpression('/data-show-if=\'\{&quot;product_source&quot;:\[&quot;chosen&quot;\]\}\'[^>]*>.*?data-name="product_ids"/s', $html);
        $this->assertStringContainsString('&quot;!chosen&quot;', $html);
    }

    public function test_show_if_is_only_used_inside_blocks()
    {
        $field = ['type' => 'text', 'label' => 'X', 'show_if' => ['a' => ['b']]];

        $this->assertStringNotContainsString('data-show-if', EmailEditor::field_html('x', $field));
        $this->assertStringContainsString('data-show-if', EmailEditor::field_html('x', $field, true));
    }

    public function test_font_choices_lead_with_the_emails_own_font()
    {
        $choices = SettingsFields::font_choices();

        $this->assertArrayHasKey('', $choices);
        $this->assertSame('', array_key_first($choices));
        $this->assertStringContainsString('Same as the email', EmailEditor::field_html('f', ['type' => 'font_family', 'label' => 'Font'], true));
    }

    public function test_presentation_can_be_filtered()
    {
        add_filter('mailoptin_email_block_field_presentation', function ($p) {
            $p['button_text'] = ['label' => 'Words on the button'];

            return $p;
        });

        $this->assertStringContainsString('Words on the button', $this->form('button'));
    }

    /** Columns has no tabs: its layout is content, so resetting the style can't change the columns. */
    public function test_untabbed_block_keeps_its_layout_out_of_style()
    {
        $html  = $this->form('columns');
        $style = strpos($html, '<div class="mo-ee-subpanel" data-tab="style">');

        $this->assertNotFalse($style);
        $this->assertLessThan($style, strpos($html, 'data-name="columns_layout"'));
        $this->assertLessThan($style, strpos($html, 'data-name="columns_stack"'));
        $this->assertGreaterThan($style, strpos($html, 'data-name="columns_background"'));
        $this->assertGreaterThan($style, strpos($html, 'data-name="block_padding"'));
    }

    public function test_number_field_keeps_its_unit_in_the_label()
    {
        $this->assertStringContainsString('Gap (px)', EmailEditor::field_html('g', ['type' => 'number', 'label' => 'Gap (px)'], true));
        $this->assertStringNotContainsString('(px)', EmailEditor::field_html('g', ['type' => 'range', 'label' => 'Gap (px)'], true));
    }
}
