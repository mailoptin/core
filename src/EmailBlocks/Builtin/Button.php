<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Mjml;
use MailOptin\Core\EmailBlocks\Style;

class Button extends ElementBlock
{
    protected function element_class()
    {
        return 'MailOptin\\Core\\Admin\\Customizer\\CustomControls\\EmailContentBuilder\\Elements\Button';
    }

    public function render($settings, $context, Style $style, $id)
    {
        $inner      = Mjml::padding($settings['button_padding']);
        $background = Fields::color($settings['button_background_color']);
        $color      = Fields::color($settings['button_color']);

        return Mjml::fill('button', Mjml::padding($settings['block_padding']) + [
            'in_t'        => $inner['pad_t'],
            'in_r'        => $inner['pad_r'],
            'in_b'        => $inner['pad_b'],
            'in_l'        => $inner['pad_l'],
            'width'       => min(100, absint($settings['button_width']) ?: 100),
            'align'       => in_array($settings['button_alignment'], ['left', 'center', 'right'], true) ? $settings['button_alignment'] : 'center',
            // A link, or a placeholder such as {{cart.url}} filled in when sending.
            'href'        => Fields::link($settings['button_link']),
            // Unset colours: no background, link-blue text, as these buttons looked before.
            'background'  => esc_attr('' !== $background ? $background : 'transparent'),
            'color'       => esc_attr('' !== $color ? $color : '#2271b1'),
            'font_family' => esc_attr($style->font_stack($settings['button_font_family'])),
            // An unset size would be MJML's 13px: the template's 18px then.
            'font_size'   => absint($settings['button_font_size']) ?: 18,
            'font_weight' => esc_attr(preg_replace('/[^a-z0-9]/i', '', (string)$settings['button_font_weight']) ?: 'normal'),
            'radius'      => absint($settings['button_border_radius']),
            'text'        => esc_html($settings['button_text']),
        ]);
    }
}
