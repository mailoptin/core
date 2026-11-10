<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Style;

/**
 * Posts are drawn by the template, which knows how to show a post (title, meta, featured image,
 * excerpt). A template without a posts_block() method shows nothing for it.
 */
class Posts extends ElementBlock
{
    protected function element_class()
    {
        return 'MailOptin\\Core\\Admin\\Customizer\\CustomControls\\EmailContentBuilder\\Elements\Posts';
    }

    public function render($settings, $context, Style $style, $id)
    {
        return $style->template && method_exists($style->template, 'posts_block') ? (string)$style->template->posts_block($id, $settings) : '';
    }
}
