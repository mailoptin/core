<?php

namespace MailOptin\Core\Admin\SettingsPage\Flows\Fields;


class Text extends AbstractField
{
    public function render()
    {
        ?>
        <# value = ''; disabled = ''; #>
        <# try {  if(data.isDisable === true) {disabled =" disabled"; value = data<?= $this->accessor_key() ?>; } #>
        <# } catch(e) {} #>
        <input class="automatewoo-field mo-flow-field-text" name="mo_flow_data<?= $this->name ?>" type="text" value="{{value}}" {{disabled}}>
        <?php
    }
}