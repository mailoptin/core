<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\AbstractElement;

class Image extends AbstractElement
{
    public function icon()
    {
        return '<span class="dashicons dashicons-format-image"></span>';
    }

    public function title()
    {
        return esc_html__('Image', 'mailoptin');
    }

    public function description()
    {
        return esc_html__('Adds an image.', 'mailoptin');
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