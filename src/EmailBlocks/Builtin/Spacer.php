<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Style;

class Spacer extends ElementBlock
{
    protected function element_class()
    {
        return 'MailOptin\\Core\\Admin\\Customizer\\CustomControls\\EmailContentBuilder\\Elements\Spacer';
    }

    public function render($settings, $context, Style $style, $id)
    {
        $padding = Fields::padding($settings['block_padding']);
        $height  = absint($settings['spacer_height']);
        $id      = esc_attr($id);

        return <<<HTML
<tr>
    <td class="mo-email-builder-element" id="$id" style="background:transparent;font-size:0px;padding:$padding;word-break:break-word;">
        <!--[if mso | IE]><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td height="{$height}px" style="vertical-align:top;height:{$height}px;"><![endif]-->
        <div style="height:{$height}px;"> &nbsp; </div>
        <!--[if mso | IE]></td></tr></table><![endif]-->
    </td>
</tr>
HTML;
    }
}
