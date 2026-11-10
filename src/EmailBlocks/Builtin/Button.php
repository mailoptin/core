<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Style;

class Button extends ElementBlock
{
    protected function element_class()
    {
        return 'MailOptin\\Core\\Admin\\Customizer\\CustomControls\\EmailContentBuilder\\Elements\Button';
    }

    public function render($settings, $context, Style $style, $id)
    {
        $block_padding  = Fields::padding($settings['block_padding']);
        $button_padding = Fields::padding($settings['button_padding']);
        $text           = esc_html($settings['button_text']);
        // A link, or a placeholder such as {{cart.url}} filled in when sending.
        $link           = Fields::link($settings['button_link']);
        $width          = min(100, absint($settings['button_width']) ?: 100);
        $background     = esc_attr(Fields::color($settings['button_background_color']));
        $color          = esc_attr(Fields::color($settings['button_color']));
        $font_size      = absint($settings['button_font_size']) ? 'font-size:' . absint($settings['button_font_size']) . 'px;' : '';
        $alignment      = in_array($settings['button_alignment'], ['left', 'center', 'right'], true) ? $settings['button_alignment'] : 'center';
        $radius         = absint($settings['button_border_radius']);
        $font_family    = esc_attr($style->font_stack($settings['button_font_family']));
        $font_weight    = esc_attr(preg_replace('/[^a-z0-9]/i', '', (string)$settings['button_font_weight']));
        $id             = esc_attr($id);

        return <<<HTML
<tr>
    <td align="$alignment" vertical-align="middle" style="font-size:0px;padding:$block_padding;word-break:break-word;">
        <table class="mo-email-builder-element" id="$id" border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:separate;width:$width%;line-height:100%;">
            <tr>
                <td align="center" bgcolor="$background" role="presentation" style="border:none;border-radius:15px;cursor:auto;mso-padding-alt:$button_padding;background:$background;" valign="middle">
                    <!--[if mso]>
                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="$link" style="height:45px;v-text-anchor:middle;width:200px;" arcsize="10%" stroke="f" fillcolor="$background">
                            <w:anchorlock/>
                            <center>
                            <![endif]-->
                              <a style="display:inline-block;width:100%;background:{$background};color:{$color};font-family:{$font_family};{$font_size}font-weight:{$font_weight};line-height:120%;margin:0;text-decoration:none;text-transform:none;padding:{$button_padding};mso-padding-alt:0px;border-radius:{$radius}px;" href="$link">$text</a>
                            <!--[if mso]>
                            </center>
                        </v:roundrect>
                    <![endif]-->
                </td>
            </tr>
        </table>
    </td>
</tr>
HTML;
    }
}
