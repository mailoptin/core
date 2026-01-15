<?php

namespace MailOptin\Core\AsyncHandler;

class AsyncHandler
{
    public $bg_process;

    public function __construct()
    {
        add_action('plugins_loaded', function () {
            $this->bg_process = new BGProcess();
        });
    }

    public static function push_to_queue($action, $bag = [], $trigger = true)
    {
        $args = array_merge(['action' => $action], $bag);

        self::get_instance()->bg_process->push_to_queue($args);

        if ($trigger === true) self::trigger();

        return true;
    }

    public static function trigger()
    {
        self::get_instance()->bg_process->save()->dispatch();
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