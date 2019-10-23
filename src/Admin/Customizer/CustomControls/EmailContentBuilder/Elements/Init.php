<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


class Init
{
    public function __construct()
    {
        new Text();
        new Image();
        new Button();
    }
}