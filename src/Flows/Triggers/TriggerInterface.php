<?php

namespace MailOptin\Core\Flows\Triggers;


interface TriggerInterface
{
    public function id();

    public function category();

    public function title();

    public function description();
}