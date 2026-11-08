<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Repositories\SuppressionRepository as Suppressions;

if ( ! class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Suppression list > the table. The list keeps a hash of each address, not the address, so a row
 * shows the address only when a subscriber still has it.
 */
class Suppression_List extends \WP_List_Table
{
    const PER_PAGE = 20;

    public function __construct()
    {
        parent::__construct([
            'singular' => 'suppression',
            'plural'   => 'suppressions',
            'ajax'     => false,
            'screen'   => 'mo-suppression-list',
        ]);
    }

    public function get_columns()
    {
        return [
            'address'    => __('Address', 'mailoptin'),
            'reason'     => __('Reason', 'mailoptin'),
            'source'     => __('From', 'mailoptin'),
            'created_at' => __('Since', 'mailoptin'),
        ];
    }

    public static function current_reason()
    {
        $reason = sanitize_key($_GET['reason'] ?? '');

        return isset(Subscribers_Suppressions::reason_labels()[$reason]) ? $reason : '';
    }

    public function prepare_items()
    {
        $reason = self::current_reason();
        $counts = Suppressions::counts();
        $total  = '' === $reason ? $counts['all'] : ($counts[$reason] ?? 0);

        $this->_column_headers = [$this->get_columns(), [], []];
        $this->items           = Suppressions::page($reason, self::PER_PAGE, ($this->get_pagenum() - 1) * self::PER_PAGE);

        $this->set_pagination_args(['total_items' => $total, 'per_page' => self::PER_PAGE]);
    }

    public function no_items()
    {
        '' === self::current_reason()
            ? esc_html_e('Nobody is on the suppression list. Addresses that unsubscribe, bounce or mark an email as spam show here.', 'mailoptin')
            : esc_html_e('No addresses for this reason.', 'mailoptin');
    }

    public function column_address($item)
    {
        if ('' === $item['email']) {
            return '<span class="mo-supp-not-stored">' . esc_html__('Address not stored', 'mailoptin') . '</span>'
                   . '<br><span class="description">' . esc_html__('Only a fingerprint is kept', 'mailoptin') . '</span>'
                   . $this->row_actions(['lift' => sprintf('<a href="%s">%s</a>', esc_url(Subscribers_Suppressions::lift_url($item['email_hash'])), esc_html__('Allow emails again', 'mailoptin'))]);
        }

        $actions = [
            'view' => sprintf('<a href="%s">%s</a>', esc_url(add_query_arg('subscriber', $item['subscriber_id'], Subscribers_Page::page_url())), esc_html__('View subscriber', 'mailoptin')),
            'lift' => sprintf('<a href="%s">%s</a>', esc_url(Subscribers_Suppressions::lift_url($item['email_hash'])), esc_html__('Allow emails again', 'mailoptin')),
        ];

        return '<strong>' . esc_html($item['email']) . '</strong>' . $this->row_actions($actions);
    }

    public function column_reason($item)
    {
        return Subscribers_Suppressions::reason_badge($item['reason']);
    }

    public function column_source($item)
    {
        return esc_html(Subscribers_Suppressions::source_label($item['source']));
    }

    public function column_created_at($item)
    {
        return esc_html(Subscribers_Suppressions::local_date($item['created_at']));
    }

    protected function get_table_classes()
    {
        return array_merge(parent::get_table_classes(), ['mo-suppression-table']);
    }
}
