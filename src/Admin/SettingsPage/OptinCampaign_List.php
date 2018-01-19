<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Core;
use MailOptin\Core\Repositories\OptinCampaignMeta;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use MailOptin\Core\Repositories\OptinCampaignStat;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class OptinCampaign_List extends \WP_List_Table
{
    private $table;

    /** @var \wpdb */
    private $wpdb;

    /** @var array */
    private $lite_optin_types_support;

    /** @var array */
    private $lite_themes;

    /** @var array */
    private $fqn_lite_themes;

    /**
     * Class constructor
     */
    public function __construct($wpdb)
    {
        $this->lite_themes = [
            'lightbox' => 'BareMetal',
            'inpost' => 'Columbine',
            'sidebar' => 'Lupin',
        ];

        $this->lite_optin_types_support = array_keys($this->lite_themes);

        foreach ($this->lite_themes as $type => $class) {
            $this->fqn_lite_themes[] = $type . '/' . $class;
        }

        $this->wpdb = $wpdb;
        $this->table = $this->wpdb->prefix . Core::optin_campaigns_table_name;
        parent::__construct(array(
                'singular' => __('optin_form', 'mailoptin'), //singular name of the listed records
                'plural' => __('optin_forms', 'mailoptin'), //plural name of the listed records
                'ajax' => false //does this table support ajax?
            )
        );
    }

    /**
     * Retrieve optin forms data from the database
     *
     * @param int $per_page
     * @param int $current_page
     * @param string $optin_type
     *
     * @return mixed
     */
    public function get_optin_campaign($per_page, $current_page = 1, $optin_type = '')
    {
        if (!defined('MAILOPTIN_DETACH_LIBSODIUM') && !empty($optin_type) && !in_array($optin_type, $this->lite_optin_types_support)) {
            return [];
        }

        $per_page = absint($per_page);
        $current_page = absint($current_page);
        $optin_type = sanitize_text_field($optin_type);

        $offset = ($current_page - 1) * $per_page;
        $sql = "SELECT * FROM $this->table";
        $args = [];

        if (!empty($optin_type)) {
            $sql .= " WHERE optin_type = %s";
            $args[] = $optin_type;

            // if this is lite and ofcourse $optin_type is specified.
            if (!defined('MAILOPTIN_DETACH_LIBSODIUM')) {
                $sql .= " AND optin_class = %s";
                $args[] = $this->lite_themes[$optin_type];
            }
        }

        // if this is lite and $optin_type is not specified. That is "All" optin needed to be fetched regardless of type.
        if (!defined('MAILOPTIN_DETACH_LIBSODIUM') && empty($optin_type)) {
            $_lite_themes = "('" . implode("','", $this->fqn_lite_themes) . "')";

            $sql .= " WHERE CONCAT_WS('/', optin_type,optin_class) IN $_lite_themes";
        }

        $sql .= " ORDER BY id DESC";

        $args[] = $per_page;

        $sql .= " LIMIT %d";
        if ($current_page > 1) {
            $args[] = $offset;
            $sql .= "  OFFSET %d";
        }

        return $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $args),
            'ARRAY_A'
        );
    }


    /**
     * Delete a optin form record.
     *
     * @param int $optin_campaign_id optin_form ID
     */
    public function delete_optin_campaign($optin_campaign_id)
    {
        $this->wpdb->delete(
            $this->table,
            array('id' => $optin_campaign_id),
            array('%d')
        );

        OptinCampaignsRepository::delete_settings_by_id($optin_campaign_id);

        OptinCampaignMeta::delete_campaign_meta($optin_campaign_id, 'split_test_parent');
    }

    /**
     * Returns the count of records in the database.
     *
     * @param string $optin_type
     *
     * @return null|string
     */
    public function record_count($optin_type = '')
    {
        global $wpdb;

        $optin_type = sanitize_text_field($optin_type);

        $sql = "SELECT COUNT(*) FROM $this->table";
        if (!empty($optin_type)) {
            $optin_type = esc_sql($optin_type);
            $sql .= "  WHERE optin_type = '$optin_type'";
        }

        return $wpdb->get_var($sql);
    }

    /**
     * Clear browser cookie of an optin campaign.
     *
     * @param $optin_campaign_id
     */
    public static function clear_cookie($optin_campaign_id)
    {
        $optin_campaign_uuid = OptinCampaignsRepository::get_optin_campaign_uuid($optin_campaign_id);
        setcookie("mo_$optin_campaign_uuid", '', -1, COOKIEPATH, COOKIE_DOMAIN, false);
        setcookie("mo_success_$optin_campaign_uuid", '', -1, COOKIEPATH, COOKIE_DOMAIN, false);

        do_action('mo_optin_after_clear_cookie', $optin_campaign_id, $optin_campaign_uuid);
    }

    /**
     * Generate URL to delete optin campaign.
     *
     * @param int $optin_campaign_id
     *
     * @return string
     */
    public static function _optin_campaign_delete_url($optin_campaign_id)
    {
        $delete_nonce = wp_create_nonce('mailoptin_delete_optin_campaign');

        return sprintf(
            '?page=%s&action=%s&optin-form=%s&_wpnonce=%s',
            esc_attr($_REQUEST['page']), 'delete', absint($optin_campaign_id), $delete_nonce
        );
    }

    /**
     * Generate URL to activate optin campaign.
     *
     * @param int $optin_campaign_id
     *
     * @return string
     */
    public static function _optin_campaign_activate_url($optin_campaign_id)
    {
        $activate_nonce = wp_create_nonce('mailoptin_activate_optin_campaign');

        return sprintf(
            '?page=%s&action=%s&optin-campaign=%s&_wpnonce=%s',
            esc_attr($_REQUEST['page']), 'activate', absint($optin_campaign_id), $activate_nonce
        );
    }

    /**
     * Generate URL to deactivate optin campaign.
     *
     * @param int $optin_campaign_id
     *
     * @return string
     */
    public static function _optin_campaign_deactivate_url($optin_campaign_id)
    {
        $deactivate_nonce = wp_create_nonce('mailoptin_deactivate_optin_campaign');

        return sprintf(
            '?page=%s&action=%s&optin-campaign=%s&_wpnonce=%s',
            esc_attr($_REQUEST['page']), 'deactivate', absint($optin_campaign_id), $deactivate_nonce
        );
    }

    /**
     * Generate URL to enable optin campaign test mode.
     *
     * @param int $optin_campaign_id
     *
     * @return string
     */
    public static function _optin_campaign_enable_test_mode($optin_campaign_id)
    {
        $enable_test_mode_nonce = wp_create_nonce('mailoptin_enable_test_mode');

        return sprintf(
            '?page=%s&action=%s&optin-campaign=%s&_wpnonce=%s',
            esc_attr($_REQUEST['page']), 'enable_test_mode', absint($optin_campaign_id), $enable_test_mode_nonce
        );
    }

    /**
     * Generate URL to disable optin campaign test mode.
     *
     * @param int $optin_campaign_id
     *
     * @return string
     */
    public static function _optin_campaign_disable_test_mode($optin_campaign_id)
    {
        $disable_test_mode_nonce = wp_create_nonce('mailoptin_disable_test_mode');

        return sprintf(
            '?page=%s&action=%s&optin-campaign=%s&_wpnonce=%s',
            esc_attr($_REQUEST['page']), 'disable_test_mode', absint($optin_campaign_id), $disable_test_mode_nonce
        );
    }

    /**
     * Generate URL to clear cookies
     *
     * @param int $optin_campaign_id
     *
     * @return string
     */
    public static function _optin_campaign_clear_cookies_url($optin_campaign_id)
    {
        $disable_test_mode_nonce = wp_create_nonce('mailoptin_clear_cookies');

        return sprintf(
            '?page=%s&action=%s&optin-campaign=%s&_wpnonce=%s',
            esc_attr($_REQUEST['page']), 'clear_cookies', absint($optin_campaign_id), $disable_test_mode_nonce
        );
    }

    /**
     * Generate URL to optin campaign statistic.
     *
     * @param int $optin_campaign_id
     *
     * @return string
     */
    public static function _optin_campaign_reset_stat_url($optin_campaign_id)
    {
        $reset_stat_nonce = wp_create_nonce('mailoptin_reset_stat_campaign');

        return sprintf(
            '?page=%s&action=%s&optin-campaign=%s&_wpnonce=%s',
            esc_attr($_REQUEST['page']), 'reset_stat', absint($optin_campaign_id), $reset_stat_nonce
        );
    }

    /**
     * URL to clone optin campaign
     *
     * @param int $optin_campaign_id
     *
     * @return mixed
     */
    public static function _optin_campaign_clone_url($optin_campaign_id)
    {
        $clone_nonce = wp_create_nonce('mailoptin_clone_optin_campaign');

        return sprintf(
            '?page=%s&action=%s&optin-form=%s&_wpnonce=%s',
            esc_attr($_REQUEST['page']), 'clone', absint($optin_campaign_id), $clone_nonce
        );
    }

    /**
     * URL to customize optin campaign
     *
     * @param int $optin_campaign_id
     *
     * @return string
     */
    public static function _optin_campaign_customize_url($optin_campaign_id)
    {
        return add_query_arg(
            array(
                'url' => urlencode(
                    add_query_arg(
                        '_wpnonce',
                        wp_create_nonce('mailoptin-preview-optin-form'),
                        sprintf(home_url('/?mailoptin_optin_campaign_id=%d'), $optin_campaign_id)
                    )
                ),
                'return' => MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE,
                'mailoptin_optin_campaign_id' => $optin_campaign_id,
            ),
            admin_url('customize.php')
        );
    }


    /**
     * Text displayed when no email optin form is available
     */
    public function no_items()
    {
        $optin_type = isset($_GET['optin-type']) ? sanitize_text_field($_GET['optin-type']) : '';

        if (empty($optin_type)) {
            printf(
                __('No campaign is currently available. %sConsider creating one%s', 'mailoptin'),
                '<a href="' . add_query_arg('view', 'add-new-optin', MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE) . '">',
                '</a>'
            );

            return;
        }

        if (defined('MAILOPTIN_DETACH_LIBSODIUM') || in_array($optin_type, $this->lite_optin_types_support)) {
            printf(
                __('No campaign is currently available for this optin type. %sConsider creating one%s', 'mailoptin'),
                '<a href="' . add_query_arg('view', 'add-new-optin', MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE) . '">',
                '</a>'
            );

            return;
        }

        if ($optin_type == 'bar') $optin_type = 'Notification Bar';

        if ($optin_type == 'slidein') $optin_type = 'Slide In';

        printf(
            __('Upgrade to %s for "%s optin" support', 'mailoptin'),
            '<a href="https://mailoptin.io/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=optin_themes_not_found" target="_blank">MailOptin Premium</a>',
            $optin_type
        );
    }

    /**
     * Generates content for a single row of the table
     *
     * @param object $item The current item
     */
    public function single_row($item)
    {
        $optin_campaign_id = absint($item['id']);

        OptinCampaignsRepository::is_test_mode($optin_campaign_id) ? $class = 'mo-test-mode' : null;

        // if this optin has a child optin as ab split test variant, add them beneath the parent.
        $variant_ids = OptinCampaignsRepository::get_split_test_variant_ids($optin_campaign_id);

        $class = '';

        echo "<tr class='$class'>";
        $this->single_row_columns($item);
        echo '</tr>';

        if (!empty($variant_ids)) {
            foreach ($variant_ids as $variant_id) {
                $item = OptinCampaignsRepository::get_optin_campaign_by_id($variant_id);
                echo "<tr class='$class mo-is-split-test'>";
                $this->single_row_columns($item);
                echo '</tr>';
            }

            $this->split_test_actions_row($optin_campaign_id);
        }
    }

    public function split_test_actions_row($optin_campaign_id)
    {
        if (OptinCampaignsRepository::is_split_test_active($optin_campaign_id)) {
            $pause_start_label = __('Pause Test', 'mailoptin');
            $pause_start_action = 'pause';
        } else {
            $pause_start_label = __('Start Test', 'mailoptin');
            $pause_start_action = 'start';
        }
        ?>
        <tr class="mo-split-test-actions">
            <td></td>
            <td>
                <a href="#" class="mo-split-test-add-variant mo-split-test-action-button" data-parent-optin-id="<?php echo $optin_campaign_id; ?>"><?php _e('Add Variant', 'mailoptin'); ?></a>
            </td>
            <td>
                <a href="#" class="mo-split-test-pause mo-split-test-action-button" data-split-test-action="<?php echo $pause_start_action; ?>" data-parent-id="<?php echo $optin_campaign_id; ?>"><?php echo $pause_start_label; ?></a>
                <img class="mo-spinner" id="mo-split-pause-spinner" style="margin-left:10px;display:none" src="<?php echo admin_url('images/spinner.gif'); ?>"/>
            </td>
            <td>
                <a href="#" class="mo-split-test-end mo-split-test-action-button" data-parent-id="<?php echo $optin_campaign_id; ?>"><?php _e('End & Pick Winner', 'mailoptin'); ?></a>
            </td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
        </tr>
        <?php
    }

    /**
     *  Associative array of columns
     *
     * @return array
     */
    public function get_columns()
    {
        $columns = array(
            'cb' => '<input type="checkbox" />',
            'name' => __('Name', 'mailoptin'),
            'uuid' => __('Unique ID', 'mailoptin'),
            'actions' => __('Actions', 'mailoptin'),
            'activated' => __('Activated', 'mailoptin'),
            'impression' => __('Impression', 'mailoptin'),
            'conversion' => __('Subscribers', 'mailoptin'),
            'percent' => __('% Conversion', 'mailoptin'),
        );

        return $columns;
    }

    /**
     * Render the bulk edit checkbox
     *
     * @param array $item
     *
     * @return string
     */
    function column_cb($item)
    {
        return sprintf(
            '<input type="checkbox" name="optin_campaign_id[]" value="%s" />', $item['id']
        );
    }

    /**
     * Method for name column
     *
     * @param array $item an array of DB data
     *
     * @return string
     */
    public function column_name($item)
    {
        $optin_campaign_id = absint($item['id']);
        $customize_url = $this->_optin_campaign_customize_url($optin_campaign_id);
        $delete_url = $this->_optin_campaign_delete_url($optin_campaign_id);

        if (OptinCampaignsRepository::is_test_mode($optin_campaign_id)) {
            $url = $this->_optin_campaign_disable_test_mode($optin_campaign_id);
            $label = esc_attr__('Disable Test Mode', 'mailoptin');
        } else {
            $url = $this->_optin_campaign_enable_test_mode($optin_campaign_id);
            $label = esc_attr__('Enable Test Mode', 'mailoptin');
        }

        $actions = array(
            'delete' => sprintf("<a href='%s'>%s</a>", $delete_url, esc_attr__('Delete', 'mailoptin')),
            'test_mode' => "<a href=\"$url\">$label</a>"
        );

        $name = '<a href="' . $customize_url . '"><strong>' . $item['name'] . '</strong></a>';

        if (OptinCampaignsRepository::is_split_test_parent($optin_campaign_id)) {
            $name .= '<div class="mo-has-split-test-variant">A/B</div>';
        }

        return $name . $this->row_actions($actions);
    }

    public function column_default($item, $column_name)
    {
        $optin_campaign_id = absint($item['id']);

        $stats = new OptinCampaignStat($optin_campaign_id);
        $impressions = $stats->get_impressions();
        $conversions = $stats->get_conversions();

        switch ($column_name) {
            case 'uuid' :
                $value = $item['uuid'];
                if (OptinCampaignsRepository::is_test_mode($optin_campaign_id)) {
                    $value .= '<br/><span class="mo-test-mode-title">' . __('Test Mode', 'mailoptin') . '</span>';
                }
                break;
            case 'impression' :
                $value = $impressions;
                break;
            case 'conversion' :
                $value = $conversions;
                break;
            case 'percent' :
                $value = (0 == $conversions || 0 == $impressions) ? '0' : number_format(($conversions / $impressions) * 100, 2) . '&#37;';
                break;
            default:
                $value = apply_filters('mo_optin_campaign_column_value', '', $item, $column_name);
                break;
        }

        return apply_filters('mo_optin_table_column', $value, $item, $column_name);
    }

    /**
     * Method for activated column
     *
     * @param array $item an array of DB data
     *
     * @return string
     */
    public function column_activated($item)
    {
        $optin_campaign_id = absint($item['id']);

        if (OptinCampaignsRepository::is_split_test_variant($optin_campaign_id)) return '';

        $input_value = OptinCampaignsRepository::is_activated($optin_campaign_id) ? 'yes' : 'no';
        $checked = ($input_value == 'yes') ? 'checked="checked"' : null;

        $switch = sprintf(
            '<input data-mo-optin-id="%1$s" id="mo-optin-activate-switch-%1$s" type="checkbox" class="mo-optin-activate-switch tgl tgl-light" value="%%3$s" %3$s />',
            $optin_campaign_id,
            $input_value,
            $checked
        );

        $switch .= sprintf(
            '<label for="mo-optin-activate-switch-%1$s" style="margin:auto;" class="tgl-btn"></label>',
            $optin_campaign_id
        );

        return $switch;
    }

    /**
     * Render a column when no column specific method exist.
     *
     * @param array $item
     *
     * @return mixed
     */
    public function column_actions($item)
    {
        $optin_campaign_id = absint($item['id']);

        $delete_url = $this->_optin_campaign_delete_url($optin_campaign_id);
        $customize_url = $this->_optin_campaign_customize_url($optin_campaign_id);

        $action = sprintf(
            '<a class="mo-tooltipster button action mailoptin-btn-blue" href="%s" title="%s">%s</a> &nbsp;',
            esc_url_raw($customize_url),
            __('Customize', 'mailoptin'),
            '<i class="fa fa-pencil" aria-hidden="true"></i>'
        );
        $action .= sprintf(
            '<a class="mo-tooltipster button action mailoptin-btn-red" href="%s" title="%s">%s</a> &nbsp;',
            $delete_url,
            __('Delete', 'mailoptin'),
            '<i class="fa fa-trash" aria-hidden="true"></i>'
        );

        $action .= '<span class="mo-ellipsis-action">';
        $action .= '<a class="mo-ellipsis-tooltipster button action"><i class="fa fa-ellipsis-h" aria-hidden="true"></i></a> &nbsp;';
        $action .= "<div style='display: none'>";
        $action .= "<div class='mo-popover-content'>";
        $action .= $this->popover_action_links($optin_campaign_id);
        $action .= '</div>';
        $action .= '</div>';
        $action .= '</span>';

        return $action;
    }

    public function popover_action_links($optin_campaign_id)
    {
        if (OptinCampaignsRepository::is_test_mode($optin_campaign_id)) {
            $test_mode_url = $this->_optin_campaign_disable_test_mode($optin_campaign_id);
            $test_mode_label = esc_attr__('Disable Test Mode', 'mailoptin');
        } else {
            $test_mode_url = $this->_optin_campaign_enable_test_mode($optin_campaign_id);
            $test_mode_label = esc_attr__('Enable Test Mode', 'mailoptin');
        }

        $actions = apply_filters('mo_optin_popover_actions', [
            'split_test' => [
                'title' => __('Create new split variation', 'mailoptin'),
                'href' => 'javascript:;',
                'label' => __('A/B Split Test', 'mailoptin'),
                'class' => 'mo-split-test'
            ],
            'clone' => [
                'title' => __('Duplicate optin', 'mailoptin'),
                'href' => self::_optin_campaign_clone_url($optin_campaign_id),
                'label' => __('Duplicate', 'mailoptin')
            ],
            'test_mode' => [
                'href' => $test_mode_url,
                'label' => $test_mode_label
            ],
            'reset_stat' => [
                'title' => __('Reset optin campaign statistics', 'mailoptin'),
                'href' => self::_optin_campaign_reset_stat_url($optin_campaign_id),
                'label' => __('Reset Analytics', 'mailoptin')
            ],
            'clear_cookies' => [
                'title' => __('Clear local cookies of this optin'),
                'href' => self::_optin_campaign_clear_cookies_url($optin_campaign_id),
                'label' => __('Clear Local Cookies', 'mailoptin')
            ],
        ], $optin_campaign_id);

        if (OptinCampaignsRepository::is_split_test_variant($optin_campaign_id)) {
            unset($actions['split_test']);
            unset($actions['clone']);
        }

        $structure = '<ul>';
        foreach ($actions as $action) {
            $action = wp_parse_args($action, [
                'href' => '',
                'title' => '',
                'label' => '',
                'class' => '',
            ]);

            $structure .= sprintf(
                '<li><a href="%s" class="%s" data-optin-id="%d" title="%s">%s</a></li>',
                esc_attr($action['href']),
                esc_attr($action['class']),
                $optin_campaign_id,
                esc_attr($action['title']),
                esc_attr($action['label'])
            );

        }
        $structure .= '</ul>';

        return $structure;
    }

    /**
     * Columns to make sortable.
     *
     * @return array
     */
    public function get_sortable_columns()
    {
        $sortable_columns = array(
            'name' => array('name', true),
        );

        return $sortable_columns;
    }

    /**
     * Returns an associative array containing the bulk action
     *
     * @return array
     */
    public function get_bulk_actions()
    {
        $actions = array(
            'bulk-delete' => __('Delete', 'mailoptin'),
            'bulk-clear-cookies' => __('Clear Local Cookies', 'mailoptin'),
        );

        return $actions;
    }

    /**
     * Handles data query and filter, sorting, and pagination.
     */
    public function prepare_items()
    {
        if (isset($_GET['page']) && $_GET['page'] == MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_SLUG && !empty($_GET['optin-type'])) {
            $optin_type = sanitize_text_field($_GET['optin-type']);
        } else {
            $optin_type = '';
        }

        $this->_column_headers = $this->get_column_info();
        /** Process bulk action */
        $this->process_actions();
        $per_page = defined('MAILOPTIN_DETACH_LIBSODIUM') ? $this->get_items_per_page('optin_form_per_page', 15) : 3;
        $current_page = $this->get_pagenum();
        $total_items = defined('MAILOPTIN_DETACH_LIBSODIUM') ? self::record_count($optin_type) : 3;
        $this->set_pagination_args(array(
                'total_items' => $total_items, //WE have to calculate the total number of items
                'per_page' => $per_page //WE have to determine how many items to show on a page
            )
        );

        $optin_campaigns = $this->get_optin_campaign($per_page, $current_page, $optin_type);

        foreach ($optin_campaigns as $key => $optin_campaign) {
            if (OptinCampaignsRepository::is_split_test_variant($optin_campaign['id'])) {
                unset($optin_campaigns[$key]);
            }
        }

        $this->items = $optin_campaigns;
    }

    public function process_actions()
    {
        // Bail if user is not an admin or without admin privileges.
        if (!current_user_can('administrator')) {
            return;
        }

        $optin_campaign_id = !empty($_GET['optin-form']) ? @absint($_GET['optin-form']) : @absint($_GET['optin-campaign']);

        // Detect when a bulk action is being triggered...
        if ('delete' === $this->current_action()) {
            // In our file that handles the request, verify the nonce.
            $nonce = sanitize_text_field($_REQUEST['_wpnonce']);
            if (!wp_verify_nonce($nonce, 'mailoptin_delete_optin_campaign')) {
                die('Go get a life script kiddies');
            } else {
                self::delete_optin_campaign($optin_campaign_id);
                // esc_url_raw() is used to prevent converting ampersand in url to "#038;"
                // add_query_arg() return the current url
                wp_redirect(esc_url_raw(MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE));
                exit;
            }
        }

        // Clone when the current action is clone.
        if ('clone' === $this->current_action()) {

            if (apply_filters('mailoptin_add_optin_email_campaign_limit', true) && OptinCampaignsRepository::campaign_count() >= MO_LITE_OPTIN_CAMPAIGN_LIMIT) return;

            // In our file that handles the request, verify the nonce.
            $nonce = sanitize_text_field($_REQUEST['_wpnonce']);
            if (!wp_verify_nonce($nonce, 'mailoptin_clone_optin_campaign')) {
                die('Go get a life script kiddies');
            } else {
                (new CloneOptinCampaign($optin_campaign_id))->forge();
                wp_redirect(esc_url_raw(MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE));
                exit;
            }
        }

        // Activate optin campaign.
        if ('activate' === $this->current_action()) {
            // In our file that handles the request, verify the nonce.
            $nonce = sanitize_text_field($_REQUEST['_wpnonce']);
            if (!wp_verify_nonce($nonce, 'mailoptin_activate_optin_campaign')) {
                die('Go get a life script kiddies');
            } else {
                OptinCampaignsRepository::activate_campaign($optin_campaign_id);
                wp_redirect(esc_url_raw(MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE));
                exit;
            }
        }

        // Deactivate optin campaign.
        if ('deactivate' === $this->current_action()) {
            // In our file that handles the request, verify the nonce.
            $nonce = sanitize_text_field($_REQUEST['_wpnonce']);
            if (!wp_verify_nonce($nonce, 'mailoptin_deactivate_optin_campaign')) {
                die('Go get a life script kiddies');
            } else {
                OptinCampaignsRepository::deactivate_campaign($optin_campaign_id);
                wp_redirect(esc_url_raw(MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE));
                exit;
            }
        }

        // Refresh optin campaign stat.
        if ('reset_stat' === $this->current_action()) {
            // In our file that handles the request, verify the nonce.
            $nonce = sanitize_text_field($_REQUEST['_wpnonce']);
            if (!wp_verify_nonce($nonce, 'mailoptin_reset_stat_campaign')) {
                die('Go get a life script kiddies');
            } else {
                (new OptinCampaignStat($optin_campaign_id))->reset_stat();
                wp_redirect(esc_url_raw(MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE));
                exit;
            }
        }

        // Enable test mode for optin campaign.
        if ('enable_test_mode' === $this->current_action()) {
            // In our file that handles the request, verify the nonce.
            $nonce = sanitize_text_field($_REQUEST['_wpnonce']);
            if (!wp_verify_nonce($nonce, 'mailoptin_enable_test_mode')) {
                die('Go get a life script kiddies');
            } else {
                OptinCampaignsRepository::enable_test_mode($optin_campaign_id);
                OptinCampaignsRepository::burst_cache($optin_campaign_id);
                wp_redirect(esc_url_raw(MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE));
                exit;
            }
        }

        // Disable test mode for optin campaign.
        if ('disable_test_mode' === $this->current_action()) {
            // In our file that handles the request, verify the nonce.
            $nonce = sanitize_text_field($_REQUEST['_wpnonce']);
            if (!wp_verify_nonce($nonce, 'mailoptin_disable_test_mode')) {
                die('Go get a life script kiddies');
            } else {
                OptinCampaignsRepository::disable_test_mode($optin_campaign_id);
                OptinCampaignsRepository::burst_cache($optin_campaign_id);
                wp_redirect(esc_url_raw(MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE));
                exit;
            }
        }

        if ('clear_cookies' === $this->current_action()) {
            // In our file that handles the request, verify the nonce.
            $nonce = sanitize_text_field($_REQUEST['_wpnonce']);
            if (!wp_verify_nonce($nonce, 'mailoptin_clear_cookies')) {
                die('Go get a life script kiddies');
            } else {
                self::clear_cookie($optin_campaign_id);
                wp_redirect(esc_url_raw(MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE));
                exit;
            }
        }

        // If the delete bulk action is triggered
        if ('bulk-delete' === $this->current_action()) {
            $delete_ids = array_map('absint', $_POST['optin_campaign_id']);
            // loop over the array of record IDs and delete them
            foreach ($delete_ids as $id) {
                self::delete_optin_campaign($id);
            }

            do_action('mo_optin_after_bulk_delete', $delete_ids);

            wp_redirect(esc_url_raw(add_query_arg()));
            exit;
        }

        // If the activate bulk action is triggered
        if ('bulk-activate' === $this->current_action()) {
            $ids = array_map('absint', $_POST['optin_campaign_id']);
            // loop over the array of campaign IDs and actvate them
            foreach ($ids as $id) {
                OptinCampaignsRepository::activate_campaign($id);
            }
            wp_redirect(esc_url_raw(add_query_arg()));
            exit;
        }

        // If the deactivate bulk action is triggered
        if ('bulk-deactivate' === $this->current_action()) {
            $ids = array_map('absint', $_POST['optin_campaign_id']);
            // loop over the array of campaign IDs and deactivate them
            foreach ($ids as $id) {
                OptinCampaignsRepository::deactivate_campaign($id);
            }
            wp_redirect(esc_url_raw(add_query_arg()));
            exit;
        }

        // If the deactivate bulk action is triggered
        if ('bulk-clear-cookies' === $this->current_action()) {
            $ids = array_map('absint', $_POST['optin_campaign_id']);
            // loop over the array of campaign IDs and deactivate them
            foreach ($ids as $id) {
                self::clear_cookie($id);
            }
            wp_redirect(esc_url_raw(add_query_arg()));
            exit;
        }
    }

    /**
     * @return OptinCampaign_List
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self($GLOBALS['wpdb']);
        }

        return $instance;
    }
}