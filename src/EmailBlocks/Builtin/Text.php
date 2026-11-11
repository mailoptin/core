<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Mjml;
use MailOptin\Core\EmailBlocks\Style;

class Text extends ElementBlock
{
    protected function element_class()
    {
        return 'MailOptin\\Core\\Admin\\Customizer\\CustomControls\\EmailContentBuilder\\Elements\Text';
    }

    public function render($settings, $context, Style $style, $id)
    {
        return Mjml::fill('text', Mjml::padding($settings['block_padding']) + [
            'font_family' => esc_attr($style->font_stack($settings['text_font_family'])),
            // An unset size would be MJML's 13px: the template's 16px then.
            'font_size'   => absint($settings['text_font_size']) ?: 16,
            // Saved by an author; cleaned on save (wp_kses_post for those without unfiltered_html).
            'content'     => wpautop(stripslashes((string)$settings['text_content'])),
        ]);
    }
}
