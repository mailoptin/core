<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Core;
use MailOptin\Core\EmailCampaigns\Automations;
use MailOptin\Core\Repositories\CampaignActivityRepository;
use MailOptin\Core\Repositories\ConnectionsRepository;
use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

if ( ! class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class Email_Campaign_List extends \WP_List_Table
{
    private $table;

    /** @var array email campaign ID => its newest campaign log, for the rows shown */
    protected $last_logs = [];

    /** @var \wpdb */
    private $wpdb;

    /**
     * Class constructor
     */
    public function __construct($wpdb)
    {
        $this->wpdb  = $wpdb;
        $this->table = $this->wpdb->prefix . Core::email_campaigns_table_name;
        parent::__construct(array(
                'singular' => __('email_campaign', 'mailoptin'), //singular name of the listed records
                'plural'   => __('email_campaigns', 'mailoptin'), //plural name of the listed records
                'ajax'     => false //does this table support ajax?
            )
        );
    }

    /**
     * Retrieve campaigns data from the database
     *
     * @param int $per_page
     * @param int $current_page
     * @param string $campaign_type
     *
     * @return mixed
     */
    public function get_email_campaigns($per_page, $current_page = 1, $campaign_type = '')
    {
        $per_page     = absint($per_page);
        $current_page = absint($current_page);

        $offset = ($current_page - 1) * $per_page;
        $sql    = "SELECT * FROM {$this->table}";
        $args   = [];

        if ( ! empty($campaign_type)) {
            $sql    .= "  WHERE campaign_type = %s";
            $args[] = sanitize_text_field($campaign_type);

        } else {
            $sql .= sprintf("  WHERE campaign_type IN ('%s', '%s')",
                ER::NEW_PUBLISH_POST,
                ER::POSTS_EMAIL_DIGEST
            );
        }

        $sql .= "  ORDER BY id DESC";

        $sql .= " LIMIT %d";

        $args[] = $per_page;

        if ($current_page > 1) {
            $sql    .= "  OFFSET %d";
            $args[] = $offset;
        }

        return $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $args),
            'ARRAY_A'
        );
    }


    /**
     * Delete a campaign record.
     *
     * @param int $email_campaign_id campaign ID
     */
    public function delete_email_campaign($email_campaign_id)
    {
        ER::delete_campaign_by_id($email_campaign_id);

        // remove the campaign meta data.
        ER::delete_settings_by_id($email_campaign_id);

        EmailCampaignMeta::delete_all_meta_data($email_campaign_id);
    }

    /**
     * Returns the count of records in the database.
     *
     * @return null|string
     */
    public function record_count($campaign_type)
    {
        global $wpdb;
        $sql = "SELECT COUNT(*) FROM $this->table";
        if ( ! empty($campaign_type)) {
            $campaign_type = esc_sql($campaign_type);
            $sql           .= "  WHERE campaign_type = '$campaign_type'";
        } else {
            $campaign_type = esc_sql($campaign_type);
            $sql           .= sprintf("  WHERE campaign_type IN ('%s', '%s')",
                ER::NEW_PUBLISH_POST,
                ER::POSTS_EMAIL_DIGEST
            );
        }

        return $wpdb->get_var($sql);
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
    public static function _campaign_customize_url($item_id)
    {
        return add_query_arg(
            apply_filters('mo_email_campaign_customize_url', array(
                    'mailoptin_email_campaign_id' => $item_id,
                )
            ),
            admin_url('customize.php')
        );
    }

    /** Text displayed when no email campaign is available */
    public function no_items()
    {
        printf(
            __('No email automation is currently available. %sConsider creating one%s', 'mailoptin'),
            '<a href="' . add_query_arg('view', 'add-new-email-automation', MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE) . '">',
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
            '<input type="checkbox" name="email_campaign_ids[]" value="%s" />', esc_attr($item['id'])
        );
    }

    /**
     * Method for name column
     *
     * @param array $item an array of DB data
     *
     * @return string
     */
    function column_name($item)
    {
        $email_campaign_id = absint($item['id']);
        $page_url          = Automation_Page::url($email_campaign_id);
        $subject           = Automations::subject($email_campaign_id);

        $actions = [
            'edit'   => sprintf('<a href="%s">%s</a>', esc_url($page_url), esc_html__('Edit', 'mailoptin')),
            'design' => sprintf('<a href="%s">%s</a>', esc_url(Automation_Page::design_url($email_campaign_id)), esc_html__('Design email', 'mailoptin')),
            'clone'  => sprintf('<a href="%s">%s</a>', esc_url(self::_campaign_clone_url($email_campaign_id)), esc_html__('Duplicate', 'mailoptin')),
            'delete' => sprintf('<a class="mo-delete-prompt" href="%s">%s</a>', esc_url(self::_campaign_delete_url($email_campaign_id)), esc_html__('Delete', 'mailoptin')),
        ];

        return sprintf('<strong><a href="%s">%s</a></strong>', esc_url($page_url), esc_html($item['name']))
               . ('' !== $subject ? '<div class="description">' . esc_html(sprintf(__('Subject: %s', 'mailoptin'), $subject)) . '</div>' : '')
               . $this->row_actions($actions);
    }

    public function column_when($item)
    {
        $email_campaign_id = absint($item['id']);
        $names             = Automations::filter_names($email_campaign_id);
        $what              = Automations::post_type_label(Automations::post_type($email_campaign_id));

        if ( ! empty($names)) {
            $what .= ': ' . (count($names) > 2 ? sprintf(__('%1$s and %2$s more', 'mailoptin'), implode(', ', array_slice($names, 0, 2)), number_format_i18n(count($names) - 2)) : implode(', ', $names));
        }

        if (Automations::is_digest($email_campaign_id)) {
            $items = absint(Automations::get($email_campaign_id, 'item_number'));
            $lines = [
                '<strong>' . esc_html__('Digest', 'mailoptin') . '</strong>',
                esc_html(Automations::digest_schedule_label($email_campaign_id)),
                esc_html(sprintf(_n('%1$s, up to %2$s post', '%1$s, up to %2$s posts', $items, 'mailoptin'), $what, number_format_i18n($items))),
            ];
        } else {
            $lines = [
                '<strong>' . esc_html__('New post', 'mailoptin') . '</strong>',
                esc_html($what),
                esc_html(Automations::delay_label($email_campaign_id)),
            ];
        }

        return implode('<br>', $lines);
    }

    public function column_audience($item)
    {
        list($label, $detail) = Automations::audience_labels(absint($item['id']));

        if ('' === $label) return '<span class="description">' . esc_html__('Not chosen yet', 'mailoptin') . '</span>';

        return esc_html($label) . ('' !== $detail ? '<br><span class="description">' . esc_html($detail) . '</span>' : '');
    }

    public function column_last_sent($item)
    {
        $log = $this->last_logs[absint($item['id'])] ?? null;

        if ( ! $log) return '<span class="description">' . esc_html__('Never', 'mailoptin') . '</span>';

        $html = esc_html(($log['status_time'] ? mysql2date(get_option('date_format'), $log['status_time']) : '—'));

        if ('failed' === $log['status']) {
            $html .= ' <span style="color:#b32d2e;font-weight:600">' . esc_html__('failed', 'mailoptin') . '</span>';
        }

        return $html . '<br><span class="description">' . esc_html($log['title']) . '</span>';
    }

    public function column_engagement($item)
    {
        $email_campaign_id = absint($item['id']);
        $log               = $this->last_logs[$email_campaign_id] ?? null;
        $connection        = (string)Automations::get($email_campaign_id, 'connection_service');

        if ( ! $log) return '&mdash;';

        // Other services keep their own reports.
        if ('' !== $connection && 'MailOptinConnect' !== $connection) {
            return '<span class="description">' . esc_html(sprintf(__('In %s', 'mailoptin'), ConnectionsRepository::get_connections('email_campaign')[$connection] ?? $connection)) . '</span>';
        }

        if ( ! CampaignActivityRepository::is_ready()) return '&mdash;';

        $stats = CampaignActivityRepository::stats($log['id']);

        if (empty($stats['sent'])) return '&mdash;';

        return sprintf(
            '<a href="%s"><strong>%s%%</strong> %s · <strong>%s%%</strong> %s</a><br><span class="description">%s</span>',
            esc_url(Campaign_Report::url($log['id'])),
            esc_html(number_format_i18n($stats['openers'] / $stats['sent'] * 100, 1)),
            esc_html__('opened', 'mailoptin'),
            esc_html(number_format_i18n($stats['clickers'] / $stats['sent'] * 100, 1)),
            esc_html__('clicked', 'mailoptin'),
            esc_html__('last send', 'mailoptin')
        );
    }

    public function column_action($item)
    {
        $email_campaign_id = absint($item['id']);

        $delete_url    = self::_campaign_delete_url($email_campaign_id);
        $clone_url     = self::_campaign_clone_url($email_campaign_id);
        $customize_url = self::_campaign_customize_url($email_campaign_id);

        $action = sprintf(
            '<a class="mo-tooltipster button action mailoptin-btn-blue" href="%s" title="%s">%s</a> &nbsp;',
            esc_url_raw($customize_url),
            __('Customize', 'mailoptin'),
            '<span class="dashicons dashicons-edit mo-action-icon"></span>'
        );
        $action .= sprintf(
            '<a class="mo-tooltipster button action" href="%s" title="%s">%s</a> &nbsp;',
            $clone_url,
            __('Clone', 'mailoptin'),
            '<span class="dashicons dashicons-admin-page mo-action-icon"></span>'
        );
        $action .= sprintf(
            '<a class="mo-tooltipster button action mailoptin-btn-red mo-delete-prompt" href="%s" title="%s">%s</a> &nbsp;',
            $delete_url,
            __('Delete', 'mailoptin'),
            '<span class="dashicons dashicons-trash mo-action-icon"></span>'
        );

        return $action;
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
        $email_campaign_id = absint($item['id']);

        $input_value = ER::is_campaign_active($email_campaign_id) ? 'yes' : 'no';
        $checked     = ($input_value == 'yes') ? 'checked="checked"' : null;

        $switch = sprintf(
            '<input data-mo-automation-id="%1$s" id="mo-automation-activate-switch-%1$s" type="checkbox" class="mo-automation-activate-switch tgl tgl-light" value="%%3$s" %3$s />',
            $email_campaign_id,
            $input_value,
            $checked
        );

        $switch .= sprintf(
            '<label for="mo-automation-activate-switch-%1$s" style="margin:auto;" class="tgl-btn"></label>',
            $email_campaign_id
        );

        return $switch;
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
     *  Associative array of columns
     *
     * @return array
     */
    public function get_columns()
    {
        $columns = array(
            'cb'         => '<input type="checkbox">',
            'name'       => __('Automation', 'mailoptin'),
            'when'       => __('When it sends', 'mailoptin'),
            'audience'   => __('Audience', 'mailoptin'),
            'activated'  => __('Active', 'mailoptin'),
            'last_sent'  => __('Last sent', 'mailoptin'),
            'engagement' => __('Engagement', 'mailoptin'),
        );

        return $columns;
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
    /**
     * All automations, or one type, or the active ones.
     */
    protected function current_view()
    {
        $view = sanitize_key($_GET['mo_view'] ?? '');

        return in_array($view, [ER::NEW_PUBLISH_POST, ER::POSTS_EMAIL_DIGEST, 'active'], true) ? $view : '';
    }

    /**
     * @return array[] the automations of a view, newest first
     */
    protected function automations_in_view($view)
    {
        $type = in_array($view, [ER::NEW_PUBLISH_POST, ER::POSTS_EMAIL_DIGEST], true) ? $view : '';
        $rows = $this->get_email_campaigns(PHP_INT_MAX, 1, $type);

        if ('active' === $view) {
            $rows = array_values(array_filter($rows, function ($row) {
                return ER::is_campaign_active(absint($row['id']));
            }));
        }

        return $rows;
    }

    protected function get_views()
    {
        $current = $this->current_view();
        $counts  = ['' => 0, ER::NEW_PUBLISH_POST => 0, ER::POSTS_EMAIL_DIGEST => 0, 'active' => 0];

        foreach ($this->automations_in_view('') as $row) {
            $counts['']++;
            if (isset($counts[$row['campaign_type']])) $counts[$row['campaign_type']]++;
            if (ER::is_campaign_active(absint($row['id']))) $counts['active']++;
        }

        $labels = ['' => __('All', 'mailoptin'), ER::NEW_PUBLISH_POST => __('New post', 'mailoptin'), ER::POSTS_EMAIL_DIGEST => __('Digest', 'mailoptin'), 'active' => __('Active', 'mailoptin')];
        $views  = [];

        foreach ($labels as $view => $label) {
            $url = '' === $view ? MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE : add_query_arg('mo_view', $view, MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE);

            $views['' === $view ? 'all' : $view] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
                esc_url($url),
                $view === $current ? ' class="current" aria-current="page"' : '',
                esc_html($label),
                number_format_i18n($counts[$view])
            );
        }

        return $views;
    }

    public function prepare_items()
    {
        $this->process_actions();

        $this->_column_headers = $this->get_column_info();
        $per_page              = $this->get_items_per_page('email_campaign_per_page', 15);
        $current_page          = $this->get_pagenum();
        $rows                  = $this->automations_in_view($this->current_view());

        $this->set_pagination_args(array(
                'total_items' => count($rows),
                'per_page'    => $per_page
            )
        );

        $this->items     = array_slice($rows, ($current_page - 1) * $per_page, $per_page);
        $this->last_logs = Automations::last_logs(wp_list_pluck($this->items, 'id'));
    }

    public function process_actions($email_type = '')
    {
        // bail if user is not an admin or without admin privileges.
        if ( ! \MailOptin\Core\current_user_has_privilege()) {
            return;
        }

        $redirect_url = MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE;

        $email_campaign_id   = @absint($_GET['email-campaign-id']);
        $email_campaign_type = ER::get_email_campaign_type($email_campaign_id);

        if ($email_campaign_type == ER::NEWSLETTER) {
            $redirect_url = add_query_arg('view', MAILOPTIN_EMAIL_NEWSLETTERS_SETTINGS_SLUG, $redirect_url);
        }

        if ('delete' === $this->current_action()) {
            // In our file that handles the request, verify the nonce.
            $nonce = esc_attr($_REQUEST['_wpnonce']);
            if ( ! wp_verify_nonce($nonce, 'mailoptin_delete_email_campaign')) {
                wp_nonce_ays('mailoptin_delete_email_campaign');
            } else {
                self::delete_email_campaign($email_campaign_id);
                // esc_url_raw() is used to prevent converting ampersand in url to "#038;"
                // add_query_arg() return the current url
                \MailOptin\Core\do_admin_redirect(esc_url_raw($redirect_url));
            }
        }

        if ('clone' === $this->current_action()) {
            if (apply_filters('mailoptin_add_new_email_campaign_limit', true) && ER::campaign_count() >= 1) return;

            // In our file that handles the request, verify the nonce.
            $nonce = esc_attr($_REQUEST['_wpnonce']);
            if ( ! wp_verify_nonce($nonce, 'mailoptin_clone_email_campaign')) {
                wp_nonce_ays('mailoptin_clone_email_campaign');
            } else {
                (new CloneEmailCampaign($email_campaign_id))->forge();
                \MailOptin\Core\do_admin_redirect(esc_url_raw($redirect_url));
            }
        }

        if ('bulk-delete' === $this->current_action()) {
            $action = 'email_campaigns';
            if ($email_type == ER::NEWSLETTER) {
                $action = 'newsletters';
            }
            check_admin_referer('bulk-' . $action);
            $delete_ids = array_map('absint', $_POST['email_campaign_ids']);
            // loop over the array of record IDs and delete them
            foreach ($delete_ids as $id) {
                self::delete_email_campaign($id);
            }
            \MailOptin\Core\do_admin_redirect(esc_url_raw(add_query_arg()));
        }
    }

    /**
     * @return Email_Campaign_List
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