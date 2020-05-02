<?php

namespace MailOptin\Core\Admin\SettingsPage\Flows\Fields;


class Select2 implements FieldInterface
{
    protected $name;

    protected $args;

    public function __construct($name, $args)
    {
        $this->name = $name;

        $this->args = $args;
    }

    public function render()
    {
        printf('<select name="%s">', $this->name);
        if (isset($this->args['options']) && is_array($this->args['options'])) {
            foreach ($this->args['options'] as $key => $label) {
                printf('<option value="%s">%s</option>', $key, $label);
            }
        }
        echo '</select>';
    }
}