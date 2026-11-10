<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Style;

class Text extends ElementBlock
{
    protected function element_class()
    {
        return 'MailOptin\\Core\\Admin\\Customizer\\CustomControls\\EmailContentBuilder\\Elements\Text';
    }

    public function render($settings, $context, Style $style, $id)
    {
        // Saved by an author; cleaned on save (wp_kses_post for those without unfiltered_html).
        $text        = wpautop(stripslashes((string)$settings['text_content']));
        $font_family = esc_attr($style->font_stack($settings['text_font_family']));
        // The cell around it has font-size:0, so an unset size would hide the text: 16px then.
        $font_size   = 'font-size:' . (absint($settings['text_font_size']) ?: 16) . 'px;';
        $padding     = Fields::padding($settings['block_padding']);
        $id          = esc_attr($id);

        return <<<HTML
<tr>
    <td align="left" style="font-size:0px;padding:$padding;word-break:break-word;">
        <div class="mo-email-builder-element mo-content-text-color" id="$id" style="font-family:$font_family;{$font_size}line-height:1;text-align:left;">$text</div>
    </td>
</tr>
HTML;
    }
}
