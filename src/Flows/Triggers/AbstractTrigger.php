<?php

namespace MailOptin\Core\Flows\Triggers;

abstract class AbstractTrigger implements TriggerInterface
{
    const WOOCOMMERCE_CATEGORY = 'woocommerce';

    const SELECT2_FIELD = 'select2';

    public function __construct()
    {
        add_filter('mo_automate_flows_triggers', [$this, 'add_flow']);
        add_action('admin_footer', [$this, 'settings_template']);
    }

    public function add_flow($triggers)
    {
        $triggers[] = [
            'id'          => $this->id(),
            'title'       => $this->title(),
            'description' => $this->description(),
            'category'    => $this->category()
        ];

        return $triggers;
    }

    public function settings_template()
    {
        if ( ! isset($_GET['page'], $_GET['view']) || $_GET['page'] != MAILOPTIN_FLOWS_SETTINGS_SLUG) return;

        $settings  = $this->settings();

        printf('<script type="text/html" id="tmpl-mo-flows-trigger-%s">', $this->id());
        foreach ($settings as $key => $setting) :
            $field_name = "[trigger_settings][$key]";
            $field = "MailOptin\\Core\\Admin\\SettingsPage\\Flows\\Fields\\" . ucwords($setting['field']);
            ?>
            <# data = data.flows_db_data; #>
            <tr class="automatewoo-table__row mo-trigger-settings">

                <td class="automatewoo-table__col automatewoo-table__col--label">
                    <?= $setting['label'] ?>
                    <span title="<?= $setting['description'] ?>" class="mo-tooltipster dashicons dashicons-editor-help" style="font-size: 16px;cursor: help;"></span>
                </td>

                <td class="automatewoo-table__col automatewoo-table__col--field">
                    <?= (new $field($field_name, $setting))->render(); ?>
                </td>
            </tr>
        <?php
        endforeach;

        echo '</script>';
    }
}