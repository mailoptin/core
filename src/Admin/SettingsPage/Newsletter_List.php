<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Core;
use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\Core\Repositories\CampaignActivityRepository;
use MailOptin\Core\Repositories\ConnectionsRepository;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\SegmentsRepository;

if ( ! class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class Newsletter_List extends \WP_List_Table
{
    private $table;

    /** @var \wpdb */
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb  = $wpdb;
        $this->table = $this->wpdb->prefix . Core::email_campaigns_table_name;
        parent::__construct(array(
            'singular' => __('newsletter', 'mailoptin'), //singular name of the listed records
            'plural'   => __('newsletters', 'mailoptin'), //plural name of the listed records
            'ajax'     => false //does this table support ajax?
        ));
    }

    public function no_items()
    {
        printf(
            esc_html__('No broadcasts yet. %sCreate one%s.', 'mailoptin'),
            '<a href="' . esc_url(add_query_arg('view', 'create-broadcast', MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE)) . '">',
            '</a>'
        );
    }

    /**
     *  Associative array of columns
     *
     * @return array
     */
    public function get_columns()
    {
        return array(
            'cb'         => '<input type="checkbox" />',
            'name'       => __('Broadcast', 'mailoptin'),
            'audience'   => __('Audience', 'mailoptin'),
            'status'     => __('Status', 'mailoptin'),
            'engagement' => __('Engagement', 'mailoptin'),
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
        return Email_Campaign_List::get_instance()->column_cb($item);
    }

    function column_name($item)
    {
        $email_campaign_id = absint($item['id']);
        $status            = Broadcasts::status($email_campaign_id);
        $page_url          = Broadcast_Page::url($email_campaign_id);
        $editable          = in_array($status['status'], [Broadcasts::STATUS_DRAFT, Broadcasts::STATUS_SCHEDULED, Broadcasts::STATUS_FAILED], true);

        $actions = ['edit' => sprintf('<a href="%s">%s</a>', esc_url($page_url), $editable ? esc_html__('Edit', 'mailoptin') : esc_html__('View', 'mailoptin'))];

        if ($editable) {
            $actions['design'] = sprintf('<a href="%s">%s</a>', esc_url(Broadcast_Page::design_url($email_campaign_id)), esc_html__('Design email', 'mailoptin'));
        }

        if ($status['log'] && CampaignActivityRepository::is_ready() && CampaignActivityRepository::sent_count($status['log']['id'])) {
            $actions['report'] = sprintf('<a href="%s">%s</a>', esc_url(Campaign_Report::url($status['log']['id'])), esc_html__('Report', 'mailoptin'));
        }

        $actions['clone']  = sprintf('<a href="%s">%s</a>', esc_url(Email_Campaign_List::_campaign_clone_url($email_campaign_id)), esc_html__('Duplicate', 'mailoptin'));
        $actions['delete'] = sprintf('<a class="mo-delete-prompt" href="%s">%s</a>', Email_Campaign_List::_campaign_delete_url($email_campaign_id), esc_html__('Delete', 'mailoptin'));

        $subject = Broadcasts::subject($email_campaign_id);

        return sprintf('<strong><a href="%s">%s</a></strong>', esc_url($page_url), esc_html($item['name']))
               . ('' !== $subject ? '<div class="description">' . esc_html(sprintf(__('Subject: %s', 'mailoptin'), $subject)) . '</div>' : '')
               . $this->row_actions($actions);
    }

    public function column_audience($item)
    {
        $email_campaign_id = absint($item['id']);
        $connection        = (string)Broadcasts::get($email_campaign_id, 'connection_service');

        if ('' === $connection) return '<span class="description">' . esc_html__('Not chosen yet', 'mailoptin') . '</span>';

        $lists = ConnectionsRepository::connection_email_list($connection);
        $list  = (string)Broadcasts::get($email_campaign_id, 'connection_email_list');
        $label = $lists[$list] ?? (ConnectionsRepository::get_connections('email_campaign')[$connection] ?? $connection);

        $detail = '';

        if ('MailOptinConnect' === $connection) {
            $segment = absint(Broadcasts::get($email_campaign_id, SegmentsRepository::SEGMENT_SETTING));
            $name    = $segment ? (SegmentsRepository::get($segment)['name'] ?? '') : '';
            $detail  = '' !== $name ? sprintf(__('%s segment', 'mailoptin'), $name) : __('Everyone in the list', 'mailoptin');
        } else {
            $detail = ConnectionsRepository::get_connections('email_campaign')[$connection] ?? $connection;
        }

        return esc_html($label) . '<br><span class="description">' . esc_html($detail) . '</span>';
    }

    public function column_status($item)
    {
        $status = Broadcasts::status(absint($item['id']));

        $labels = [
            Broadcasts::STATUS_DRAFT     => __('Draft', 'mailoptin'),
            Broadcasts::STATUS_SCHEDULED => __('Scheduled', 'mailoptin'),
            Broadcasts::STATUS_SENDING   => __('Sending', 'mailoptin'),
            Broadcasts::STATUS_SENT      => __('Sent', 'mailoptin'),
            Broadcasts::STATUS_FAILED    => __('Failed', 'mailoptin'),
        ];

        $html = '<strong>' . esc_html($labels[$status['status']]) . '</strong>';

        if ($status['scheduled_at']) {
            $html .= '<br><span class="description">' . esc_html(get_date_from_gmt($status['scheduled_at'], get_option('date_format') . ' ' . get_option('time_format'))) . '</span>';
        } elseif ($status['log'] && ! empty($status['log']['status_time'])) {
            $html .= '<br><span class="description">' . esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $status['log']['status_time'])) . '</span>';
        }

        return $html;
    }

    public function column_engagement($item)
    {
        $status = Broadcasts::status(absint($item['id']));
        $log_id = $status['log'] ? absint($status['log']['id']) : 0;

        if ( ! $log_id || ! CampaignActivityRepository::is_ready()) return '&mdash;';

        $stats = CampaignActivityRepository::stats($log_id);

        if ( ! $stats['sent']) return '&mdash;';

        return sprintf(
            '<a href="%s"><strong>%s%%</strong> %s · <strong>%s%%</strong> %s</a>',
            esc_url(Campaign_Report::url($log_id)),
            esc_html(number_format_i18n($stats['openers'] / $stats['sent'] * 100, 1)),
            esc_html__('opened', 'mailoptin'),
            esc_html(number_format_i18n($stats['clickers'] / $stats['sent'] * 100, 1)),
            esc_html__('clicked', 'mailoptin')
        );
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
        Email_Campaign_List::get_instance()->process_actions(ER::NEWSLETTER);

        $this->_column_headers = $this->get_column_info();
        $per_page              = $this->get_items_per_page('newsletters_per_page', 15);
        $current_page          = $this->get_pagenum();
        $total_items           = Email_Campaign_List::get_instance()->record_count(ER::NEWSLETTER);
        $this->set_pagination_args(array(
                'total_items' => $total_items, //WE have to calculate the total number of items
                'per_page'    => $per_page //WE have to determine how many items to show on a page
            )
        );

        $this->items = Email_Campaign_List::get_instance()->get_email_campaigns($per_page, $current_page, ER::NEWSLETTER);
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