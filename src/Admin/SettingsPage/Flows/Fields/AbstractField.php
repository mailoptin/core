<?php

namespace MailOptin\Core\Admin\SettingsPage\Flows\Fields;


abstract class AbstractField implements FieldInterface
{
    protected $name;

    protected $args;

    public function __construct($name, $args)
    {
        $this->name = $name;

        $this->args = $args;
    }

    public function accessor_key()
    {
        return str_replace(['[', ']'], ['["', '"]'], $this->name);
    }
}