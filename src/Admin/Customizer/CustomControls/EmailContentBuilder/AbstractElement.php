<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder;


abstract class AbstractElement implements ElementInterface
{
    public function __construct()
    {
        add_filter('mo_email_content_elements', [$this, 'define_element']);
        add_action('customize_controls_print_footer_scripts', [$this, 'js_template']);
    }

    public function define_element($elements)
    {
        $elements[] = [
            'id'          => $this->id(),
            'title'       => $this->title(),
            'icon'        => $this->icon(),
            'description' => $this->description(),
            'tabs'        => $this->tabs(),
            'settings'    => $this->settings()
        ];

        return $elements;
    }

    public function js_template()
    {
        printf('<script type="text/html" id="tmpl-mo-email-content-element-%s">', $this->id());

        echo '</script>';
    }
}