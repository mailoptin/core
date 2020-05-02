<?php

namespace MailOptin\Core\Flows\Triggers;


use MailOptin\Core\Admin\SettingsPage\Flows\Fields\FieldInterface;

abstract class AbstractTrigger implements TriggerInterface
{
    const WOOCOMERCE_CATEGORY = 'woocommerce';

    public function __construct()
    {
        add_filter('mo_automate_flows_triggers', [$this, 'add_flow']);
        add_filter('admin_footer', [$this, 'settings_template']);
    }

    public function add_flow($triggers)
    {
        $triggers[$this->category()][$this->id()] = [
            'id'          => $this->id(),
            'title'       => $this->title(),
            'description' => $this->description()
        ];

        return $triggers;
    }

    public function settings_template()
    {
        $settings = $this->settings();

        foreach ($settings as $key => $setting) :
            $field = ucwords($setting['field']);
            $field = (new $field($key, $setting))->render();
            ?>
            <script type="text/html" id="tmpl-mo-flows-trigger-<?= $this->id() ?>">
                <tr class="automatewoo-table__row aw-trigger-option" data-name="name" data-type="select" data-required="0 ">

                    <td class="automatewoo-table__col automatewoo-table__col--label">
                        <?= $setting['label'] ?>
                        <span title="<?= $setting['description'] ?>" class="mo-tooltipster dashicons dashicons-editor-help" style="font-size: 16px;cursor: help;"></span>
                    </td>
                    <td class="automatewoo-table__col automatewoo-table__col--field">
                        <?= $field ?>
                    </td>
                </tr>
            </script>
        <?php
        endforeach;
    }
}