<?php

namespace MailOptin\Admin\Customizer\CustomControls\EmailContentBuilder;


abstract class AbstractElement implements ElementInterface
{
    public function __construct()
    {
        add_filter('mo_email_content_elements', [$this, 'define_element']);
    }

    public function define_element($elements)
    {
        $elements[] = [
            'title'       => $this->title(),
            'icon'        => $this->icon(),
            'description' => $this->description(),
            'tabs'        => $this->tabs(),
            'settings'    => $this->settings()
        ];

        return $elements;
    }
}