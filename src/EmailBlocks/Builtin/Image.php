<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Style;

class Image extends ElementBlock
{
    protected function element_class()
    {
        return 'MailOptin\\Core\\Admin\\Customizer\\CustomControls\\EmailContentBuilder\\Elements\Image';
    }

    public function render($settings, $context, Style $style, $id)
    {
        $padding   = Fields::padding($settings['block_padding']);
        $url       = esc_url($settings['image_url']);
        $width     = absint($settings['image_width']);
        $alignment = in_array($settings['image_alignment'], ['left', 'center', 'right'], true) ? $settings['image_alignment'] : 'center';
        $alt       = esc_attr($settings['image_alt_text']);
        $link      = (string)$settings['image_link'];
        $id        = esc_attr($id);

        $img = "<img class=\"mo-email-builder-element\" id=\"$id\" alt=\"$alt\" height=\"auto\" src=\"$url\" style=\"border:0;display:block;outline:none;text-decoration:none;height:auto;width:100%;max-width:{$width}px\" width=\"$width\"/>";

        // A link, or a placeholder filled in when sending.
        if ('' !== trim($link)) $img = '<a href="' . Fields::link($link) . '" target="_blank">' . $img . '</a>';

        return <<<HTML
<tr>
    <td align="$alignment" style="font-size:0px;padding:$padding;word-break:break-word;">
        <table border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;border-spacing:0px;">
            <tbody><tr><td style="width:{$width}px;">$img</td></tr></tbody>
        </table>
    </td>
</tr>
HTML;
    }
}
