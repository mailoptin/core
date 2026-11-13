<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Mjml;
use MailOptin\Core\EmailBlocks\Style;

class Image extends ElementBlock
{
    protected function element_class()
    {
        return 'MailOptin\\Core\\Admin\\Customizer\\CustomControls\\EmailContentBuilder\\Elements\Image';
    }

    public function render($settings, $context, Style $style, $id)
    {
        $padding = Mjml::padding($settings['block_padding']);
        // As MJML does: no wider than the space it has (Outlook ignores max-width).
        $room    = max(1, $style->width - $padding['pad_l'] - $padding['pad_r']);
        $width   = absint($settings['image_width']);
        $link    = trim((string)$settings['image_link']);

        return Mjml::fill('' !== $link ? 'image-link' : 'image', $padding + [
            'width' => $width ? min($width, $room) : $room,
            'align' => in_array($settings['image_alignment'], ['left', 'center', 'right'], true) ? $settings['image_alignment'] : 'center',
            'src'   => esc_url($settings['image_url']),
            'alt'   => esc_attr($settings['image_alt_text']),
            // A link, or a placeholder filled in when sending.
            'href'  => '' !== $link ? Fields::link($link) : '',
        ]);
    }

    public function group()
    {
        return 'basics';
    }

    public function palette_icon()
    {
        return 'image';
    }
}
