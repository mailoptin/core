<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\AbstractElement;

class Text extends AbstractElement
{
    public function id()
    {
        return 'text';
    }

    public function icon()
    {
        return '<span class="dashicons dashicons-text"></span>';
    }

    public function title()
    {
        return esc_html__('Text', 'mailoptin');
    }

    public function description()
    {
        return esc_html__('Text, HTML and multimedia content.', 'mailoptin');
    }

    public function tabs()
    {
        // TODO: Implement tabs() method.
    }

    public function settings()
    {
        // TODO: Implement settings() method.
    }
}