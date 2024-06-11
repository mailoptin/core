<?php

namespace MailOptin\Core\BlockEditor;

class Init
{
    public function __construct()
    {
        add_action('init', [$this, 'register_blocks']);
    }

    public function register_blocks()
    {
        register_block_type(__DIR__ . '/build/email-optin');
    }

    /**
     * @return self
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}