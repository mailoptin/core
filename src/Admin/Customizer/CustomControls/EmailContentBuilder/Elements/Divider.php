<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\AbstractElement;

class Divider extends AbstractElement
{
    public function id()
    {
        return 'divider';
    }

    public function icon()
    {
        return '<span class="dashicons dashicons-minus"></span>';
    }

    public function title()
    {
        return esc_html__('Divider', 'mailoptin');
    }

    public function description()
    {
        return esc_html__('A line separator.', 'mailoptin');
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