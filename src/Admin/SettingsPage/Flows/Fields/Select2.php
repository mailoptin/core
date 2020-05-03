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

    public function accessor_key()
    {
        return str_replace(['[', ']'], ['["', '"]'], $this->name);
    }

    public function render()
    {
        printf('<select name="mo_flow_data%s[]" class="mo-flow-field-select2" multiple>', $this->name);
        if (isset($this->args['options']) && is_array($this->args['options'])) {
            foreach ($this->args['options'] as $key => $label) :
                ?>
                <# console.log(data, "<?= $key ?>", data<?= $this->accessor_key() ?>) #>
                <option value="<?= $key ?>" <# if(_.indexOf(data<?= $this->accessor_key() ?>, "<?= $key ?>") != -1) { #>selected<# } #>><?= $label ?></option>
            <?php endforeach;
        }
        echo '</select>';
    }
}