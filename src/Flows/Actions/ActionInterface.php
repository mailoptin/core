<?php

namespace MailOptin\Core\Flows\Actions;


interface ActionInterface
{
    public function id();

    public function category();

    public function title();

    public function description();
}