<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\AbstractElement;

class Button extends AbstractElement
{
    public function id()
    {
        return 'button';
    }

    public function icon()
    {
        return '<span class="dashicons dashicons-editor-removeformatting"></span>';
    }

    public function title()
    {
        return esc_html__('Button', 'mailoptin');
    }

    public function description()
    {
        return esc_html__('A simple button.', 'mailoptin');
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