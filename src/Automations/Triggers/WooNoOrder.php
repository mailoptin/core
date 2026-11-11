<?php

namespace MailOptin\Core\Automations\Triggers;

use MailOptin\Core\Automations\AbstractTrigger;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;

/**
 * A customer hasn't ordered for a while: win-back emails. Checked hourly.
 *
 * Only customers who crossed the line in the last week are started, so switching it on doesn't
 * email everyone who ever bought; the next check picks up those crossing it later.
 */
class WooNoOrder extends AbstractTrigger
{
    const BATCH = 200;

    /** How far back past the line a last order still starts someone. */
    const WINDOW_DAYS = 7;

    public function id()
    {
        return 'woo_no_order';
    }

    public function group()
    {
        return 'WooCommerce';
    }

    public function label()
    {
        return __("A customer hasn't ordered for a while", 'mailoptin');
    }

    public function description()
    {
        return __('Checked every hour. Use it for win-back emails.', 'mailoptin');
    }

    public function is_available()
    {
        return Woo::active();
    }

    public function settings_fields()
    {
        return [
            'days' => [
                'label'   => __('No order for', 'mailoptin'),
                'type'    => 'number',
                'default' => 120,
                'suffix'  => __('days', 'mailoptin'),
            ],
            'runs' => [
                'label'   => __('Runs for each customer', 'mailoptin'),
                'type'    => 'radio',
                'options' => ['once' => __('Once', 'mailoptin'), 'every' => __('Every time they go quiet again', 'mailoptin')],
                'default' => 'every',
            ],
        ];
    }

    public function stops_when()
    {
        return __('They place an order, or they unsubscribe.', 'mailoptin');
    }

    public function listen()
    {
        // Started by scan(), not by an event.
    }

    public function scan($automation)
    {
        $days = max(1, (int)($automation['trigger_settings']['days'] ?? 120));
        $line = time() - $days * DAY_IN_SECONDS;

        // Only orders that crossed the line since the last scan; the first scan (or one after a long
        // gap) looks back a week. Without this every order in the week was checked again every hour.
        $cursor = (int)Repo::get_cursor($automation['id']);
        $from   = max($cursor, $line - self::WINDOW_DAYS * DAY_IN_SECONDS) + 1;

        if ($from > $line) return;

        // A page at a time: orders from people who bought again are skipped, and could fill a batch.
        $page = 1;

        do {
            $order_ids = wc_get_orders([
                'status'       => array_map(function ($s) { return 'wc-' . $s; }, Woo::PAID_STATUSES),
                'date_created' => $from . '...' . $line,
                'orderby'      => 'ID',
                'order'        => 'ASC',
                'limit'        => self::BATCH,
                'paged'        => $page++,
                'return'       => 'ids',
            ]);

            $this->start_for_orders($automation, (array)$order_ids);
        } while (count($order_ids) === self::BATCH);

        Repo::set_cursor($automation['id'], $line);
    }

    protected function start_for_orders($automation, $order_ids)
    {
        foreach ($order_ids as $order_id) {
            $order = Woo::order($order_id);

            if ( ! $order || ! is_email(Woo::order_email($order)) || ! $order->get_date_created()) continue;

            $email = Woo::order_email($order);

            // Not their last order: they bought again since.
            if (Woo::ordered_since($email, gmdate('Y-m-d H:i:s', $order->get_date_created()->getTimestamp()), $order_id)) continue;

            $this->start($email, 'last-order-' . $order_id, ['order_id' => (int)$order_id], $automation['id']);
        }
    }

    public function stop_reason($run)
    {
        return Woo::ordered_since($run['email'], $run['started_at']) ? 'ordered' : '';
    }

    public function checks()
    {
        return [
            'not_ordered_again' => [
                'label'    => __("they haven't ordered since", 'mailoptin'),
                'callback' => function ($run) {
                    return ! Woo::ordered_since($run['email'], $run['started_at']);
                },
            ],
        ];
    }

    public function store()
    {
        return 'woocommerce';
    }

    public function provides()
    {
        return [];
    }

    public function placeholders()
    {
        $order = function ($context) {
            return Woo::order($context['order_id'] ?? 0);
        };

        return [
            'customer.first_name'  => ['label' => __('First name', 'mailoptin'), 'value' => function ($c) use ($order) { $o = $order($c); return $o ? $o->get_billing_first_name() : ''; }, 'sample' => 'Maya'],
            'customer.last_order_date' => ['label' => __('Date of their last order', 'mailoptin'), 'value' => function ($c) use ($order) { $o = $order($c); return $o && $o->get_date_created() ? wc_format_datetime($o->get_date_created()) : ''; }, 'sample' => date_i18n(get_option('date_format'), time() - 120 * DAY_IN_SECONDS)],
            'customer.last_order_items' => [
                'label'  => __('What they last bought', 'mailoptin'),
                'value'  => function ($c) use ($order) {
                    $o = $order($c);

                    return $o ? implode(', ', array_map(function ($item) { return $item->get_name(); }, array_values($o->get_items()))) : '';
                },
                'sample' => 'Heirloom tomato seed collection',
            ],
        ];
    }
}
