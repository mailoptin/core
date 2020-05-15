<?php

namespace MailOptin\Core\Admin\SettingsPage\Flows;

// Exit if accessed directly
if ( ! defined('ABSPATH')) {
    exit;
}

use MailOptin\Core\Admin\SettingsPage\AbstractSettingsPage;
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
            AbstractTrigger::WOOCOMMERCE_CATEGORY => 'WooCommerce'
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

    public function flow_builder_globals()
    {
        printf(
            '<script type="text/javascript">
                    var mo_automate_flows_triggers = %s;
                    var mo_automate_flows_rules = %s;
                    var mo_automate_flows_db_data = %s;
                    </script>',
            json_encode(self::registered_triggers()),
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
            <select name="mo_flow_data{{data.fieldName}}[]" class="mo-flow-field-select2" multiple {{isDisabled}}>
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
            <input class="automatewoo-field" type="text" name="mo_flow_data{{data.fieldName}}" {{isDisabled}}>
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
                    <# fieldName = "[trigger_settings]["+key+"]"; #>
                    <# dbValue = mo_automate_flows_db_data.trigger_settings[key]; #>
                    <# field = field_tmpl({fieldName:fieldName, fieldOptions: fieldOptions, dbValue: dbValue}); #>
                    {{{field}}}

                </td>
            </tr>
            <# }); #>
            <# } #>
        </script>

        <script type="text/html" id="tmpl-mo-flows-rules-default">
            <p class="aw-rules-empty-message">
                <?= sprintf(
                    esc_html__('Rules can be used to add conditional logic to flows. Click the %s+ Add Rule Group%s button to create a rule.', 'mailoptin'),
                    '<strong>', '</strong>'
                ); ?>
            </p>
        </script>

        <script type="text/html" id="tmpl-mo-flows-rule-row">

            <div class="automatewoo-rule automatewoo-rule--type-new automatewoo-rule--compare-false">

                <div class="automatewoo-rule__fields">

                    <div class="aw-rule-select-container automatewoo-rule__field-container">
                        <select name="mo_flow_data[rule_options][rule_group_94][rule_96][name]" class="mo-flow-rule-select automatewoo-field" required="">

                            <option value=""><?= esc_html__('Select rule', 'mailoptin') ?></option>

                            <?php foreach (self::registered_categories() as $categoryId => $categoryLabel) : ?>

                                <optgroup label="<?= $categoryLabel ?>">
                                    <?php foreach ($this->get_rules_by_category($categoryId) as $ruleId => $rule) : ?>
                                        <option value="<?= $ruleId ?>"><?= $rule['label'] ?></option>
                                    <?php endforeach; ?>
                                </optgroup>

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
            <div class="aw-rule-group">
                <div class="rules mo-flows-rules-group">
                    <!--      child view goe here               -->
                </div>
                <div class="aw-rule-group__or"><span><?= esc_html__('or', 'mailoptin') ?></span></div>
            </div>
        </script>

        <script type="text/html" id="tmpl-mo-flows-rule-compare">
            <# if (typeof data.compareOptions !== "undefined" && _.isEmpty(data.compareOptions) === false) { #>

            <select name="mo_flow_data[rule_options][rule_group_94][rule_96][compare]" class="automatewoo-field js-rule-compare-field">
                <# _.each(data.compareOptions, function(label, key) { #>
                <option value="{{key}}">{{label}}</option>
                <# }); #>
            </select>

            <# } else { #>

            <select name="mo_flow_data[rule_options][rule_group_94][rule_96][compare]" class="automatewoo-field js-rule-compare-field" disabled></select>

            <# } #>
        </script>

        <script type="text/html" id="tmpl-mo-flows-rule-value">

            <# if (typeof data.valueField == "undefined") { data.valueField = "<?= AbstractTrigger::TEXT_FIELD ?>"; } #>
            <# field_tmpl = wp.template('mo-flows-field-' + data.valueField); #>
            <# isDisabled = typeof data.isDisabled != "undefined" ? data.isDisabled : false; #>
            <# fieldOptions = typeof data.fieldOptions != "undefined" ? data.fieldOptions : []; #>
            <# fieldName = "[rule_options][rule_group_94][rule_96][value]"; #>

            <# dbValue = []; #>
            <# try { dbValue = mo_automate_flows_db_data.trigger_rules[key]; #>
            <# } catch (e) {} #>

            <# field = field_tmpl({fieldName:fieldName, fieldOptions: fieldOptions, dbValue: dbValue, isDisabled: isDisabled}); #>

            {{{field}}}

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