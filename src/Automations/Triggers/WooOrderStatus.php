<?php

namespace MailOptin\Core\Automations\Triggers;

use MailOptin\Core\Automations\AbstractTrigger;

/**
 * An order reaches a status, such as Completed: review requests, thank-yous, follow-ups.
 */
class WooOrderStatus extends AbstractTrigger
{
    public function id()
    {
        return 'woo_order_status';
    }

    public function group()
    {
        return 'WooCommerce';
    }

    public function label()
    {
        return __('An order changes status', 'mailoptin');
    }

    public function description()
    {
        return __('For example when an order is completed. Use it for review requests, thank-yous and follow-ups.', 'mailoptin');
    }

    public function is_available()
    {
        return Woo::active();
    }

    public function settings_fields()
    {
        return [
            'status' => [
                'label'   => __('Order status', 'mailoptin'),
                'type'    => 'select',
                'options' => [Woo::class, 'status_options'],
                'default' => 'completed',
            ],
            'runs'   => [
                'label'   => __('Runs for each customer', 'mailoptin'),
                'type'    => 'radio',
                'options' => ['once' => __('Once', 'mailoptin'), 'every' => __('For every order', 'mailoptin')],
                'default' => 'every',
            ],
            'reenter' => [
                'label'   => __('Already in it', 'mailoptin'),
                'type'    => 'checkbox',
                'text'    => __('Let them start again while an earlier order is still in progress', 'mailoptin'),
                'default' => false,
            ],
        ];
    }

    public function audience_fields()
    {
        return [
            'category_ids' => [
                'label'       => __('Only orders with products in', 'mailoptin'),
                'type'        => 'multiselect',
                'options'     => [Woo::class, 'category_options'],
                'description' => __('Leave empty for any product.', 'mailoptin'),
            ],
            'min_total'    => [
                'label'       => __('Minimum order total', 'mailoptin'),
                'type'        => 'number',
                'description' => __('Leave empty for any total.', 'mailoptin'),
            ],
        ];
    }

    public function stops_when()
    {
        return __('The order is refunded, cancelled, failed or deleted, or they unsubscribe.', 'mailoptin');
    }

    public function listen()
    {
        add_action('woocommerce_order_status_changed', [$this, 'status_changed'], 20, 3);
    }

    public function status_changed($order_id, $from, $to)
    {
        $order = Woo::order($order_id);

        if ( ! $order || ! is_email(Woo::order_email($order))) return;

        $this->start(Woo::order_email($order), 'order-' . absint($order_id), ['order_id' => absint($order_id), 'status' => sanitize_key($to)]);
    }

    public function matches($context, $settings, $audience)
    {
        if (sanitize_key($settings['status'] ?? 'completed') !== ($context['status'] ?? '')) return false;

        $order = Woo::order($context['order_id'] ?? 0);

        if ( ! $order) return false;

        $categories = array_map('intval', (array)($audience['category_ids'] ?? []));

        if ( ! empty($categories) && ! array_intersect($categories, Woo::category_ids($order))) return false;

        $min = $audience['min_total'] ?? '';

        if ('' !== $min && is_numeric($min) && (float)$order->get_total() < (float)$min) return false;

        return true;
    }

    public function stop_reason($run)
    {
        $order = Woo::order($run['context']['order_id'] ?? 0);

        if ( ! $order) return 'order_deleted';

        if (in_array($order->get_status(), ['refunded', 'cancelled', 'failed'], true)) return 'order_' . $order->get_status();

        return '';
    }

    public function checks()
    {
        return [
            'not_reviewed'      => [
                'label'    => __("they haven't reviewed every product in the order", 'mailoptin'),
                'callback' => function ($run) {
                    $order = Woo::order($run['context']['order_id'] ?? 0);

                    return $order && ! Woo::reviewed_all($order);
                },
            ],
            'not_ordered_again' => [
                'label'    => __("they haven't ordered again since", 'mailoptin'),
                'callback' => function ($run) {
                    return ! Woo::ordered_since($run['email'], $run['started_at'], $run['context']['order_id'] ?? 0);
                },
            ],
        ];
    }

    public function placeholders()
    {
        $order = function ($context) {
            return Woo::order($context['order_id'] ?? 0);
        };

        return [
            'customer.first_name' => ['label' => __('First name', 'mailoptin'), 'value' => function ($c) use ($order) { $o = $order($c); return $o ? $o->get_billing_first_name() : ''; }, 'sample' => 'Maya'],
            'customer.last_name'  => ['label' => __('Last name', 'mailoptin'), 'value' => function ($c) use ($order) { $o = $order($c); return $o ? $o->get_billing_last_name() : ''; }, 'sample' => 'Patel'],
            'order.number'        => ['label' => __('Order number', 'mailoptin'), 'value' => function ($c) use ($order) { $o = $order($c); return $o ? $o->get_order_number() : ''; }, 'sample' => '1048'],
            'order.date'          => ['label' => __('Order date', 'mailoptin'), 'value' => function ($c) use ($order) { $o = $order($c); return $o && $o->get_date_created() ? wc_format_datetime($o->get_date_created()) : ''; }, 'sample' => date_i18n(get_option('date_format'))],
            'order.total'         => ['label' => __('Order total', 'mailoptin'), 'value' => function ($c) use ($order) { $o = $order($c); return $o ? Woo::money($o->get_total(), $o) : ''; }, 'sample' => '£32.50'],
            'order.status'        => ['label' => __('Order status', 'mailoptin'), 'value' => function ($c) use ($order) { $o = $order($c); return $o ? wc_get_order_status_name($o->get_status()) : ''; }, 'sample' => __('Completed', 'mailoptin')],
            'order.items'         => [
                'label'  => __('Products ordered', 'mailoptin'),
                'value'  => function ($c) use ($order) {
                    $o = $order($c);
                    if ( ! $o) return '';
                    $names = [];
                    foreach ($o->get_items() as $item) $names[] = $item->get_name() . ($item->get_quantity() > 1 ? ' × ' . $item->get_quantity() : '');

                    return implode(', ', $names);
                },
                'sample' => 'Paperwhite tulip bulbs × 2, Five-variety daffodil mix',
            ],
            'order.url'           => ['label' => __('Link to the order', 'mailoptin'), 'value' => function ($c) use ($order) { $o = $order($c); return $o ? $o->get_view_order_url() : ''; }, 'sample' => home_url('/my-account/view-order/1048/')],
        ];
    }

    public function count_recent($automation)
    {
        if ( ! Woo::active()) return null;

        $status = sanitize_key($automation['trigger_settings']['status'] ?? 'completed');
        $ids    = wc_get_orders(['status' => 'wc-' . $status, 'date_created' => '>' . (time() - 30 * DAY_IN_SECONDS), 'limit' => 500, 'return' => 'ids']);
        $match  = 0;

        foreach ($ids as $order_id) {
            if ($this->matches(['order_id' => $order_id, 'status' => $status], $automation['trigger_settings'], $automation['audience'])) $match++;
        }

        return ['matched' => $match, 'total' => count($ids), 'label' => sprintf(__('orders with status "%s" in the last 30 days', 'mailoptin'), wc_get_order_status_name($status))];
    }
}
