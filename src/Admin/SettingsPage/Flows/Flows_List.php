<?php

namespace MailOptin\Core\Admin\SettingsPage\Flows;

use MailOptin\Core\Core;
use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use function MailOptin\Core\strtotime_utc;

if ( ! class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class Flows_List extends \WP_List_Table
{
    private $table;

    /** @var \wpdb */
    private $wpdb;

    /**
     * Class constructor
     */
    public function __construct($wpdb)
    {
        $this->wpdb  = $wpdb;
        $this->table = $this->wpdb->prefix . Core::flows_table_name;
        parent::__construct(array(
                'singular' => __('flow', 'mailoptin'), //singular name of the listed records
                'plural'   => __('flows', 'mailoptin'), //plural name of the listed records
                'ajax'     => false //does this table support ajax?
            )
        );
    }
    
    /**
     *  Associative array of columns
     *
     * @return array
     */
    public function get_columns()
    {
        $columns = array(
            'cb'         => '<input type="checkbox">',
            'title'      => __('Title', 'mailoptin'),
            'created_at' => __('Created', 'mailoptin'),
            'status'     => ''
        );

        return $columns;
    }

    /**
     * Retrieve flows data from the database
     *
     * @param int $per_page
     * @param int $current_page
     * @param string $campaign_type
     *
     * @return mixed
     */
    public function get_flows($per_page, $current_page = 1)
    {
        $offset = ($current_page - 1) * $per_page;
        $sql    = "SELECT * FROM {$this->table}";
        $sql    .= "  ORDER BY id DESC";
        $sql    .= " LIMIT $per_page";
        if ($current_page > 1) {
            $sql .= "  OFFSET $offset";
        }

        return $this->wpdb->get_results($sql, 'ARRAY_A');
    }


    /**
     * Delete a campaign record.
     *
     * @param int $flow_id campaign ID
     */
    public function delete_email_campaign($flow_id)
    {
        ER::delete_campaign_by_id($flow_id);

        // remove the campaign meta data.
        ER::delete_settings_by_id($flow_id);

        EmailCampaignMeta::delete_all_meta_data($flow_id);
    }

    /**
     * Returns the count of records in the database.
     *
     * @return null|string
     */
    public function record_count()
    {
        $sql = "SELECT COUNT(*) FROM $this->table";

        return $this->wpdb->get_var($sql);
    }

    /**
     * Generate URL to delete email campaign.
     *
     * @param int $item_id
     *
     * @return string
     */
    public static function _campaign_delete_url($item_id)
    {
        $delete_nonce = wp_create_nonce('mailoptin_delete_email_campaign');

        return add_query_arg(
            [
                'action'            => 'delete',
                'email-campaign-id' => absint($item_id),
                '_wpnonce'          => $delete_nonce
            ],
            MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE
        );
    }

    /**
     * Generate URL to clone email campaign.
     *
     * @param int $item_id
     *
     * @return string
     */
    public static function _campaign_clone_url($item_id)
    {
        $clone_nonce = wp_create_nonce('mailoptin_clone_email_campaign');

        return add_query_arg(
            [
                'action'            => 'clone',
                'email-campaign-id' => absint($item_id),
                '_wpnonce'          => $clone_nonce
            ],
            MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE
        );
    }

    /**
     * Generate URL to customize email campaign.
     *
     * @param int $item_id
     *
     * @return string
     */
    public static function _edit_flow_url($item_id)
    {
        return add_query_arg(
            apply_filters('mo_email_edit_flow_url', array(
                    'mailoptin_email_campaign_id' => $item_id,
                )
            ),
            admin_url('customize.php')
        );
    }

    /**
     * Text displayed when no email campaign is available
     */
    public function no_items()
    {
        printf(
            __('No flows is currently available. %sConsider creating one%s', 'mailoptin'),
            '<a href="' . add_query_arg('view', 'add-new', MAILOPTIN_FLOWS_SETTINGS_PAGE) . '">',
            '</a>'
        );
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
            '<input type="checkbox" name="flows[]" value="%s" />', $item['id']
        );
    }

    /**
     * Method for name column
     *
     * @param array $item an array of DB data
     *
     * @return string
     */
    function column_title($item)
    {
        $flow_id = absint($item['id']);

        $edit_url = self::_edit_flow_url($flow_id);

        $delete_url = self::_campaign_delete_url($flow_id);
        $name       = "<strong><a href=\"$edit_url\">" . $item['title'] . '</a></strong>';

        $actions = array(
            'delete' => sprintf("<a href=\"$delete_url\">%s</a>", __('Delete', 'mailoptin')),
        );

        return $name . $this->row_actions($actions);
    }

    /**
     * @param array $item an array of DB data
     *
     * @return string
     */
    public function column_status($item)
    {
        $flow_id = absint($item['id']);

        $input_value = ER::is_campaign_active($flow_id) ? 'yes' : 'no';
        $checked     = ($input_value == 'yes') ? 'checked="checked"' : null;

        $switch = sprintf(
            '<input data-mo-flow-id="%1$s" id="mo-flow-status-switch-%1$s" type="checkbox" class="mo-flow-status-switch tgl tgl-light" value="%%3$s" %3$s />',
            $flow_id,
            $input_value,
            $checked
        );

        $switch .= sprintf(
            '<label for="mo-flow-status-switch-%1$s" style="margin:auto;" class="tgl-btn"></label>',
            $flow_id
        );

        return $switch;
    }

    public function column_created_at($item)
    {
        return current_time(get_option( 'date_format' ), strtotime_utc($item['created_at']));
    }

    /**
     * Display campaign type data and any other data.
     *
     * @param object $item
     * @param string $column_name
     *
     * @return string
     */
    public function column_default($item, $column_name)
    {
        return ER::get_type_name(
            sanitize_text_field($item[$column_name])
        );
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
        );

        return $actions;
    }

    /**
     * Handles data query and filter, sorting, and pagination.
     */
    public function prepare_items()
    {
        /** Process bulk action */
        $this->process_actions();

        $this->_column_headers = $this->get_column_info();

        $per_page              = $this->get_items_per_page('flows_per_page', 10);
        $current_page          = $this->get_pagenum();
        $total_items           = $this->record_count();
        $this->set_pagination_args([
            'total_items' => $total_items, //WE have to calculate the total number of items
            'per_page'    => $per_page //WE have to determine how many items to show on a page
        ]);

        $this->items = $this->get_flows($per_page, $current_page);
    }

    public function process_actions($email_type = '')
    {
        // bail if user is not an admin or without admin privileges.
        if ( ! \MailOptin\Core\current_user_has_privilege()) {
            return;
        }

        $redirect_url = MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE;

        $flow_id   = @absint($_GET['email-campaign-id']);
        $email_campaign_type = ER::get_email_campaign_type($flow_id);

        if ($email_campaign_type == ER::NEWSLETTER) {
            $redirect_url = add_query_arg('view', MAILOPTIN_EMAIL_NEWSLETTERS_SETTINGS_SLUG, $redirect_url);
        }

        if ('delete' === $this->current_action()) {
            // In our file that handles the request, verify the nonce.
            $nonce = esc_attr($_REQUEST['_wpnonce']);
            if ( ! wp_verify_nonce($nonce, 'mailoptin_delete_email_campaign')) {
                wp_nonce_ays('mailoptin_delete_email_campaign');
            } else {
                self::delete_email_campaign($flow_id);
                // esc_url_raw() is used to prevent converting ampersand in url to "#038;"
                // add_query_arg() return the current url
                wp_redirect(esc_url_raw($redirect_url));
                exit;
            }
        }

        if ('clone' === $this->current_action()) {
            if (apply_filters('mailoptin_add_new_email_campaign_limit', true) && ER::campaign_count() >= 1) return;

            // In our file that handles the request, verify the nonce.
            $nonce = esc_attr($_REQUEST['_wpnonce']);
            if ( ! wp_verify_nonce($nonce, 'mailoptin_clone_email_campaign')) {
                wp_nonce_ays('mailoptin_clone_email_campaign');
            } else {
                (new CloneEmailCampaign($flow_id))->forge();
                wp_redirect(esc_url_raw($redirect_url));
                exit;
            }
        }

        if ('bulk-delete' === $this->current_action()) {
            $action = 'email_campaigns';
            if ($email_type == ER::NEWSLETTER) {
                $action = 'newsletters';
            }
            check_admin_referer('bulk-' . $action);
            $delete_ids = array_map('absint', $_POST['flows']);
            // loop over the array of record IDs and delete them
            foreach ($delete_ids as $id) {
                self::delete_email_campaign($id);
            }
            wp_redirect(esc_url_raw(add_query_arg()));
            exit;
        }
    }

    /**
     * @return self
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