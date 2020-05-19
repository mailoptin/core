<?php

namespace MailOptin\Core\Admin\SettingsPage\Flows;

// Exit if accessed directly
if ( ! defined('ABSPATH')) {
    exit;
}

use MailOptin\Core\Admin\SettingsPage\AbstractSettingsPage;
use MailOptin\Core\Flows\AbstractTriggerAction;
use MailOptin\Core\Flows\Triggers\AbstractTrigger;
use MailOptin\Core\Repositories\FlowsRepository;
use W3Guy\Custom_Settings_Page_Api;

class AddEditFlow extends AbstractSettingsPage
{
    public $flow_id;

    public function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'select2_enqueue']);
    }

    /**
     * Back to campaign overview button.
     */
    public function back_to_optin_overview()
    {
        $url = MAILOPTIN_FLOWS_SETTINGS_PAGE;
        echo "<a class=\"add-new-h2\" style='margin-left: 10px;' href=\"$url\">" . __('Back to Overview', 'mailoptin') . '</a>';
    }

    /**
     * Build the settings page structure. I.e tab, sidebar.
     */
    public function settings_admin_page()
    {
        $this->save_flow();

        if (isset($_GET['flowid'])) {
            $this->flow_id = absint($_GET['flowid']);
        }

        add_action('mailoptin_admin_notices', function () {
            add_action('admin_notices', array($this, 'admin_notice'));
        });

        add_action('admin_footer', [$this, 'flow_builder_globals']);
        add_action('admin_footer', [$this, 'js_templates']);

        add_action('wp_cspa_before_closing_header', [$this, 'back_to_optin_overview']);
        add_filter('wp_cspa_main_content_area', [$this, 'flow_builder_page']);
        add_filter('wp_cspa_setting_page_sidebar', [$this, 'flow_builder_page_sidebar']);

        $instance = Custom_Settings_Page_Api::instance();
        $instance->page_header(Flows::page_title());
        $this->register_core_settings($instance);
        $instance->build();
    }

    public function admin_notice()
    {
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] == 'true') {
            echo '<div id="message" class="updated notice is-dismissible">';
            echo '<p>' . esc_html__('Flow Updated', 'mailoptin') . '</p>';
            echo '</div>';
        }
    }

    public function select2_enqueue()
    {
        wp_enqueue_script('mailoptin-select2', MAILOPTIN_ASSETS_URL . 'js/customizer-controls/select2/select2.min.js', array('jquery'), false, false);
        wp_enqueue_style('mailoptin-select2', MAILOPTIN_ASSETS_URL . 'js/customizer-controls/select2/select2.min.css', null);
    }


    public static function registered_categories()
    {
        return apply_filters('mo_automate_flows_categories', [
            AbstractTriggerAction::WOOCOMMERCE_CATEGORY => 'WooCommerce'
        ]);
    }

    public static function registered_triggers()
    {
        return apply_filters('mo_automate_flows_triggers', []);
    }

    public static function registered_rules()
    {
        return apply_filters('mo_automate_flows_rules', []);
    }

    public static function registered_actions()
    {
        return apply_filters('mo_automate_flows_actions', []);
    }

    public function flow_builder_globals()
    {
        printf(
            '<script type="text/javascript">
                    var mo_automate_flows_triggers = %s;
                    var mo_automate_flows_actions = %s;
                    var mo_automate_flows_rules = %s;
                    var mo_automate_flows_db_data = %s;
                    </script>',
            json_encode(self::registered_triggers()),
            json_encode(self::registered_actions()),
            json_encode(self::registered_rules()),
            json_encode(FlowsRepository::get_flow_by_id($this->flow_id))
        );
    }

    public function get_triggers_by_category($category)
    {
        return array_filter(self::registered_triggers(), function ($item) use ($category) {
            return $item['category'] == $category;
        });
    }

    public function get_rules_by_category($category)
    {
        return array_filter(self::registered_rules(), function ($value) use ($category) {
            return $value['category'] == $category;
        });
    }

    public function get_actions_by_category($category)
    {
        return array_filter(self::registered_actions(), function ($value) use ($category) {
            return $value['category'] == $category;
        });
    }

    public function flow_builder_page()
    {
        $flow_id = $this->flow_id;

        require dirname(__FILE__) . '/view.tmpl.php';
    }

    public function flow_builder_page_sidebar()
    {
        $flow_id = $this->flow_id;
        ob_start();
        require dirname(__FILE__) . '/sidebar-view.tmpl.php';

        return ob_get_clean();
    }

    public function save_flow()
    {
        if ( ! isset($_POST['save_flow'])) return;

        if (isset($_GET['view']) && in_array($_GET['view'], ['add', 'edit'])) {
            check_admin_referer('mo_save_automate_flows', 'security');
        }

        $view = $_GET['view'];

        if ($view == 'edit') {
            $flow_id = absint($_GET['flowid']);

            $response = FlowsRepository::update_flow(
                $flow_id,
                sanitize_text_field($_POST['flow_title']),
                sanitize_text_field($_POST['flow_status']),
                $_POST['mo_flow_data']
            );

            if ($response) {
                wp_safe_redirect(
                    add_query_arg(
                        ['view' => $view, 'flowid' => $flow_id, 'settings-updated' => 'true'],
                        MAILOPTIN_FLOWS_SETTINGS_PAGE
                    )
                );
                exit;
            }
        }

        if ($view == 'add') {

            $response = FlowsRepository::add_flow(
                sanitize_text_field($_POST['flow_title']),
                sanitize_text_field($_POST['flow_status']),
                $_POST['mo_flow_data']
            );

            if (is_int($response)) {
                wp_safe_redirect(add_query_arg(['view' => $view, 'flowid' => $response], MAILOPTIN_FLOWS_SETTINGS_PAGE));
                exit;
            }
        }
    }

    public function js_templates()
    {
        ?>
        <script type="text/html" id="tmpl-mo-flows-field-select2">
            <# isDisabled = typeof data.isDisabled != "undefined" && data.isDisabled == true ? "disabled" : ''; #>
            <select name="{{data.fieldName}}[]" class="mo-flow-field-select2" multiple {{isDisabled}}>
                <# if (typeof data.fieldOptions != "undefined" && !_.isEmpty(data.fieldOptions)) { #>
                <# _.each(data.fieldOptions, function(label, key) { #>
                <# selected = typeof data.dbValue != "undefined" && _.contains(data.dbValue, key) ? 'selected': ''; #>
                <option value="{{key}}" {{selected}}>{{label}}</option>
                <# }); #>
                <# } #>
            </select>
        </script>

        <script type="text/html" id="tmpl-mo-flows-field-text">
            <# isDisabled = typeof data.isDisabled != "undefined" && data.isDisabled == true ? "disabled" : ''; #>
            <input class="automatewoo-field" type="text" name="{{data.fieldName}}" {{isDisabled}}>
        </script>

        <script type="text/html" id="tmpl-mo-flows-trigger-settings">
            <# if(typeof data.triggerSettings !== "undefined") { #>
            <# _.each(data.triggerSettings, function(args, key) { #>
            <tr class="automatewoo-table__row mo-trigger-settings">
                <td class="automatewoo-table__col automatewoo-table__col--label">
                    {{args.label}}
                    <span title="{{args.description}}" class="mo-tooltipster dashicons dashicons-editor-help" style="font-size: 16px;cursor:help"></span>
                </td>

                <td class="automatewoo-table__col automatewoo-table__col--field">

                    <# field_tmpl = wp.template('mo-flows-field-' + args.field); #>
                    <# fieldOptions = typeof args.options != "undefined" ? args.options : []; #>
                    <# fieldName = "mo_flow_data[trigger_settings]["+key+"]"; #>
                    <# dbValue = typeof mo_automate_flows_db_data.trigger_settings != 'undefined' ? mo_automate_flows_db_data.trigger_settings[key] : ''; #>
                    <# field = field_tmpl({fieldName:fieldName, fieldOptions: fieldOptions, dbValue: dbValue}); #>
                    {{{field}}}

                </td>
            </tr>
            <# }); #>
            <# } #>
        </script>

        <script type="text/html" id="tmpl-mo-flows-rules-default">
            <p class="mo-flows-rules-default-msg">
                <?= sprintf(
                    esc_html__('Rules can be used to add conditional logic to flows. Click the %s+ Add Rule Group%s button to create a rule.', 'mailoptin'),
                    '<strong>', '</strong>'
                ); ?>
            </p>
        </script>

        <script type="text/html" id="tmpl-mo-flows-actions-default">
            <p class="mo-flows-actions-default-msg">
                <?= sprintf(
                    esc_html__('No actions. Click the %s+ Add Action%s button to create an action.', 'mailoptin'),
                    '<strong>', '</strong>'
                ); ?>
            </p>
        </script>

        <script type="text/html" id="tmpl-mo-flows-rule-row">

            <div class="automatewoo-rule">

                <div class="automatewoo-rule__fields">

                    <div class="aw-rule-select-container automatewoo-rule__field-container">
                        <select name="{{data.fieldName}}" class="mo-flow-rule-select automatewoo-field" required="">

                            <option value=""><?= esc_html__('Select...', 'mailoptin') ?></option>

                            <?php foreach (self::registered_categories() as $categoryId => $categoryLabel) : ?>

                                <?php $category_rules = $this->get_rules_by_category($categoryId); ?>

                                <?php if ( ! empty($category_rules)) : ?>
                                    <optgroup label="<?= $categoryLabel ?>">
                                        <?php foreach ($category_rules as $ruleId => $rule) : ?>
                                            <# selected = typeof data.dbValue != "undefined" && "<?= $ruleId ?>" == data.dbValue ? 'selected' : ''; #>
                                            <option value="<?= $ruleId ?>" {{selected}}><?= $rule['label'] ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>

                                <?php endif; ?>

                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="aw-rule-field-compare automatewoo-rule__field-container">
                        <!--  compare select goes here    -->
                    </div>

                    <div class="aw-rule-field-value automatewoo-rule__field-container">
                        <!--  value field goes here    -->
                    </div>
                </div>

                <div class="automatewoo-rule__buttons">
                    <button type="button" class="mo-flow-add-rule automatewoo-rule__add button"><?= esc_html__('and', 'mailoptin') ?></button>
                    <button type="button" class="mo-flow-remove-rule automatewoo-rule__remove"></button>
                </div>
            </div>
        </script>

        <script type="text/html" id="tmpl-mo-flows-rules-grouping">
            <# groupId = typeof data != 'undefined' && typeof data.groupId != 'undefined' ? data.groupId : ''; #>
            <div class="aw-rule-group" data-group-id="{{groupId}}">
                <div class="rules mo-flows-rules-group">
                    <!--      child view goes here               -->
                </div>
                <div class="aw-rule-group__or"><span><?= esc_html__('or', 'mailoptin') ?></span></div>
            </div>
        </script>

        <script type="text/html" id="tmpl-mo-flows-rule-compare">
            <# if (typeof data.compareOptions !== "undefined" && _.isEmpty(data.compareOptions) === false) { #>

            <select name="{{data.fieldName}}" class="automatewoo-field js-rule-compare-field">
                <# _.each(data.compareOptions, function(label, key) { #>
                <# selected = typeof data.dbValue != "undefined" && key == data.dbValue ? 'selected' : ''; #>
                <option value="{{key}}" {{selected}}>{{label}}</option>
                <# }); #>
            </select>

            <# } else { #>
            <select name="{{data.fieldName}}" class="automatewoo-field js-rule-compare-field" disabled></select>
            <# } #>
        </script>

        <script type="text/html" id="tmpl-mo-flows-rule-value">

            <# if (typeof data.valueField == "undefined") { data.valueField = "<?= AbstractTrigger::TEXT_FIELD ?>"; } #>
            <# field_tmpl = wp.template('mo-flows-field-' + data.valueField); #>
            <# isDisabled = typeof data.isDisabled != "undefined" ? data.isDisabled : false; #>
            <# fieldOptions = typeof data.fieldOptions != "undefined" ? data.fieldOptions : []; #>
            <# dbValue = typeof data.dbValue != "undefined" ? data.dbValue : []; #>

            <# field = field_tmpl({fieldName:data.fieldName, fieldOptions: fieldOptions, dbValue: dbValue, isDisabled: isDisabled}); #>

            {{{field}}}

        </script>

        <script type="text/html" id="tmpl-mo-flows-action-settings">
            <div class="automatewoo-action__header">
                <div class="row-options">
                    <a class="js-edit-action" href="#"><?= esc_html__('Edit', 'mailoptin') ?></a>
                    <a class="js-delete-action" href="#"><?= esc_html__('Delete', 'mailoptin') ?></a>
                </div>

                <# title = typeof data.title != 'undefined' && data.title != '' ? data.title : '<?= esc_html__('New Action', 'mailoptin') ?>'; #>

                <h4 class="action-title">{{title}}</h4>
            </div>

            <div class="automatewoo-action__fields" style="display: none;">
                <table class="automatewoo-table">

                    <tbody>
                    <tr class="automatewoo-table__row">
                        <td class="automatewoo-table__col automatewoo-table__col--label">
                            <label><?= esc_html__('Action', 'mailoptin'); ?> <span class="required">*</span></label>
                        </td>
                        <td class="automatewoo-table__col automatewoo-table__col--field">

                            <select name="{{data.fieldName}}" class="mo-flow-action-select automatewoo-field" required="">

                                <option value=""><?= esc_html__('Select...', 'mailoptin') ?></option>

                                <?php foreach (self::registered_categories() as $categoryId => $categoryLabel) : ?>

                                    <?php $category_actions = $this->get_actions_by_category($categoryId); ?>

                                    <?php if ( ! empty($category_actions)) : ?>
                                        <optgroup label="<?= $categoryLabel ?>">

                                            <?php foreach ($category_actions as $actionId => $action) : ?>
                                                <# selected = typeof data.dbValue != "undefined" && "<?= $actionId ?>" == data.dbValue ? 'selected' : ''; #>
                                                <option value="<?= $actionId ?>" {{selected}}><?= $action['title'] ?></option>
                                            <?php endforeach; ?>

                                        </optgroup>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                            </select>


                            <p class="aw-field-description">
                                <!--     description goes here            -->
                            </p>
                        </td>
                    </tr>

                    <tr class="automatewoo-table__row" data-name="user_tags" data-type="select" data-required="0 ">

                        <td class="automatewoo-table__col automatewoo-table__col--label">
                            <label>Tags </label>
                        </td>

                        <td class="automatewoo-table__col automatewoo-table__col--field automatewoo-field-wrap">
                            <select name="aw_workflow_data[actions][2][user_tags][]">

                            </select>
                        </td>
                    </tr>

                    </tbody>
                </table>
            </div>
        </script>
        <?php
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