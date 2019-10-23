<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\AbstractElement;

class Button extends AbstractElement
{
    public function icon()
    {
        return '<img src="https://us4.admin.mailchimp.com/images/campaigns/content-blocks/button.svg">';
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