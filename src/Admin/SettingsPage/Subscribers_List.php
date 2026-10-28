<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

if ( ! class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class Subscribers_List extends \WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'subscriber',
            'plural'   => 'subscribers',
            'ajax'     => false,
        ]);
    }

    public function get_columns()
    {
        return [
            'cb'         => '<input type="checkbox" />',
            'email'      => __('Email', 'mailoptin'),
            'name'       => __('Name', 'mailoptin'),
            'status'     => __('Status', 'mailoptin'),
            'lists'      => __('Lists', 'mailoptin'),
            'source'     => __('Source', 'mailoptin'),
            'created_at' => __('Subscribed', 'mailoptin'),
        ];
    }

    protected function get_sortable_columns()
    {
        return [
            'email'      => ['email', false],
            'created_at' => ['created_at', true],
        ];
    }

    public function no_items()
    {
        if ( ! empty($_GET['s']) || ! empty($_GET['status']) || ! empty($_GET['list'])) {
            esc_html_e('No subscribers match these filters.', 'mailoptin');

            return;
        }

        esc_html_e('No subscribers yet. Connect a form to "MailOptin (built-in)" or add someone with Add New.', 'mailoptin');
    }

    public function prepare_items()
    {
        $per_page = $this->get_items_per_page('mo_subscribers_per_page', 20);

        $result = Subscribers::query([
            'status'   => sanitize_key($_GET['status'] ?? ''),
            'list_id'  => absint($_GET['list'] ?? 0),
            'search'   => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
            'orderby'  => sanitize_key($_GET['orderby'] ?? ''),
            'order'    => sanitize_key($_GET['order'] ?? ''),
            'per_page' => $per_page,
            'page'     => $this->get_pagenum(),
        ]);

        $this->items = $result['items'];

        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page'    => $per_page,
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }

    protected function get_views()
    {
        $counts  = Subscribers::count_by_status();
        $current = sanitize_key($_GET['status'] ?? '');
        $base    = remove_query_arg(['status', 'paged', 'mo_notice']);

        $labels = [
            ''                               => __('All', 'mailoptin'),
            Subscribers::STATUS_SUBSCRIBED   => __('Subscribed', 'mailoptin'),
            Subscribers::STATUS_PENDING      => __('Pending confirmation', 'mailoptin'),
            Subscribers::STATUS_UNSUBSCRIBED => __('Unsubscribed', 'mailoptin'),
            Subscribers::STATUS_BOUNCED      => __('Bounced', 'mailoptin'),
            Subscribers::STATUS_COMPLAINED   => __('Complained', 'mailoptin'),
        ];

        $views = [];

        foreach ($labels as $status => $label) {
            $count = '' === $status ? array_sum($counts) : $counts[$status];

            // Hide empty problem statuses so the row stays short.
            if (in_array($status, [Subscribers::STATUS_BOUNCED, Subscribers::STATUS_COMPLAINED], true) && 0 === $count) continue;

            $views[$status ?: 'all'] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
                esc_url('' === $status ? $base : add_query_arg('status', $status, $base)),
                $current === $status ? ' class="current" aria-current="page"' : '',
                esc_html($label),
                number_format_i18n($count)
            );
        }

        return $views;
    }

    protected function get_bulk_actions()
    {
        return [
            'add_to_list'         => __('Add to list', 'mailoptin'),
            'remove_from_list'    => __('Remove from list', 'mailoptin'),
            'resend_confirmation' => __('Resend confirmation', 'mailoptin'),
            'unsubscribe'         => __('Unsubscribe', 'mailoptin'),
            'delete'              => __('Delete', 'mailoptin'),
        ];
    }

    protected function extra_tablenav($which)
    {
        if ('top' !== $which) return;

        $lists   = ListsRepository::get_all();
        $current = absint($_GET['list'] ?? 0);
        ?>
        <div class="alignleft actions">
            <label class="screen-reader-text" for="mo-bulk-list"><?php esc_html_e('List for "Add to list" and "Remove from list"', 'mailoptin'); ?></label>
            <select name="mo_bulk_list" id="mo-bulk-list">
                <option value=""><?php esc_html_e('List for bulk action…', 'mailoptin'); ?></option>
                <?php foreach ($lists as $list) : ?>
                    <option value="<?php echo absint($list['id']); ?>"><?php echo esc_html($list['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="alignleft actions">
            <label class="screen-reader-text" for="mo-filter-list"><?php esc_html_e('Filter by list', 'mailoptin'); ?></label>
            <select name="list" id="mo-filter-list">
                <option value=""><?php esc_html_e('All lists', 'mailoptin'); ?></option>
                <?php foreach ($lists as $list) : ?>
                    <option value="<?php echo absint($list['id']); ?>" <?php selected($current, (int)$list['id']); ?>><?php echo esc_html($list['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Filter', 'mailoptin'), '', 'mo_filter', false); ?>
        </div>
        <?php
    }

    protected function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="subscriber_ids[]" value="%d" />', absint($item['id']));
    }

    protected function column_email($item)
    {
        $profile_url = add_query_arg('subscriber', absint($item['id']), Subscribers_Page::page_url());

        $actions = [
            'view' => sprintf('<a href="%s">%s</a>', esc_url($profile_url), esc_html__('View', 'mailoptin')),
        ];

        if ( ! in_array($item['status'], [Subscribers::STATUS_UNSUBSCRIBED], true)) {
            $actions['unsubscribe'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(Subscribers_Page::action_url('unsubscribe', $item['id'])),
                esc_html__('Unsubscribe', 'mailoptin')
            );
        }

        $actions['delete'] = sprintf(
            '<a class="submitdelete mo-delete-prompt" href="%s">%s</a>',
            esc_url(Subscribers_Page::action_url('delete', $item['id'])),
            esc_html__('Delete', 'mailoptin')
        );

        return sprintf('<strong><a class="row-title" href="%s">%s</a></strong>', esc_url($profile_url), esc_html($item['email'])) . $this->row_actions($actions);
    }

    protected function column_name($item)
    {
        return esc_html(trim($item['first_name'] . ' ' . $item['last_name']));
    }

    protected function column_status($item)
    {
        return Subscribers_Page::status_pill($item['status']);
    }

    protected function column_lists($item)
    {
        if (empty($item['lists'])) return '<span class="mo-muted">' . esc_html__('none', 'mailoptin') . '</span>';

        return esc_html(implode(', ', $item['lists']));
    }

    protected function column_source($item)
    {
        return '<span class="mo-muted">' . esc_html(Subscribers_Page::source_label($item)) . '</span>';
    }

    protected function column_created_at($item)
    {
        return esc_html(get_date_from_gmt($item['created_at'], get_option('date_format')));
    }
}
