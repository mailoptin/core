<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Style;

class Divider extends ElementBlock
{
    protected function element_class()
    {
        return 'MailOptin\\Core\\Admin\\Customizer\\CustomControls\\EmailContentBuilder\\Elements\Divider';
    }

    public function render($settings, $context, Style $style, $id)
    {
        $padding = Fields::padding($settings['block_padding']);
        $width   = min(100, absint($settings['divider_width']));
        $type    = in_array($settings['divider_style'], ['solid', 'dashed', 'dotted', 'double'], true) ? $settings['divider_style'] : 'solid';
        $color   = esc_attr(Fields::color($settings['divider_color']));
        $height  = absint($settings['divider_height']);
        $id      = esc_attr($id);

        return <<<HTML
<tr>
    <td class="mo-email-builder-element" id="$id" style="font-size:0px;padding:$padding;word-break:break-word;">
        <p style="border-top:$type {$height}px $color;font-size:0;margin:0px auto;width:$width%;"></p>
        <!--[if mso | IE]>
        <table align="center" border="0" cellpadding="0" cellspacing="0" style="border-top:$type {$height}px $color;font-size:1;margin:0px auto;width:450px;" role="presentation" width="450px">
            <tr><td style="height:0;line-height:0;">&nbsp;</td></tr>
        </table>
        <![endif]-->
    </td>
</tr>
HTML;
    }
}
