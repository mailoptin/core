<?php

namespace MailOptin\Core\Admin\SettingsPage\Flows\Fields;


class Select2 extends AbstractField
{
    public function render()
    {
        printf('<select name="mo_flow_data%s[]" class="mo-flow-field-select2" multiple>', $this->name);
        if (isset($this->args['options']) && is_array($this->args['options'])) {
            foreach ($this->args['options'] as $key => $label) :
                // using try catch to prevent any undefined variable error from breaking the app
                ?>
                <option value="<?= $key ?>" <# try { if(_.contains(data<?= $this->accessor_key() ?>, "<?= $key ?>")) { #>selected<# } } catch(e) {} #>>
                <?= $label ?>
                </option>
            <?php endforeach;
        }
        echo '</select>';
    }
}