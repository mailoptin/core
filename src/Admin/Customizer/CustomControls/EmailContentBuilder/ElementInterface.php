<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder;


interface ElementInterface
{
    public function id();

    public function title();

    public function description();

    public function icon();

    public function tabs();

    public function settings();
}