<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Mjml;
use MailOptin\Core\EmailBlocks\Style;

class Spacer extends ElementBlock
{
    protected function element_class()
    {
        return 'MailOptin\\Core\\Admin\\Customizer\\CustomControls\\EmailContentBuilder\\Elements\Spacer';
    }

    public function render($settings, $context, Style $style, $id)
    {
        return Mjml::fill('spacer', Mjml::padding($settings['block_padding']) + ['height' => absint($settings['spacer_height'])]);
    }

    public function group()
    {
        return 'basics';
    }

    public function palette_icon()
    {
        return 'spacer';
    }
}
