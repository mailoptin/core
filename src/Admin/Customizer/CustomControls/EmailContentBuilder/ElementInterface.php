<?php
/**
 * Copyright (C) 2016  Agbonghama Collins <me@w3guy.com>
 */

namespace MailOptin\Admin\Customizer\CustomControls\EmailContentBuilder;


interface ElementInterface
{
    public function title();

    public function description();

    public function icon();

    public function tabs();

    public function settings();
}