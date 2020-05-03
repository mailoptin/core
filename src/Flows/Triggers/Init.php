<?php

namespace MailOptin\Core\Flows\Triggers;

class Init
{
    public function __construct()
    {
        new WooCommerce\OrderCreated();
        new WooCommerce\OrderRefunded();

        do_action('mo_automate_trigger_init');
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