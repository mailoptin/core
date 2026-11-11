<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Mjml;
use MailOptin\Core\EmailBlocks\Style;

class Divider extends ElementBlock
{
    protected function element_class()
    {
        return 'MailOptin\\Core\\Admin\\Customizer\\CustomControls\\EmailContentBuilder\\Elements\Divider';
    }

    public function render($settings, $context, Style $style, $id)
    {
        $padding = Mjml::padding($settings['block_padding']);
        $width   = min(100, absint($settings['divider_width']) ?: 100);

        return Mjml::fill('divider', $padding + [
            'width'         => $width,
            'thickness'     => absint($settings['divider_height']),
            'style'         => in_array($settings['divider_style'], ['solid', 'dashed', 'dotted', 'double'], true) ? $settings['divider_style'] : 'solid',
            'color'         => esc_attr(Fields::color($settings['divider_color']) ?: '#cccccc'),
            // MJML's Outlook width: the room less the padding, times the width.
            'outlook_width' => Mjml::number(max(0, $style->width - $padding['pad_l'] - $padding['pad_r']) * $width / 100),
        ]);
    }
}
