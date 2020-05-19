<?php

namespace MailOptin\Core\Flows\Actions;

class Init
{
    public function __construct()
    {
        new Mailchimp\AddUpdateSubscriber();

        do_action('mo_automate_actions_init');
    }

    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}