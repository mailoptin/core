<?php

namespace MailOptin\Core\Automations\Triggers;

use MailOptin\Core\Automations\Engine;
use MailOptin\Core\Automations\Stores\WooStore;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;

/**
 * A customer reaches a number of paid orders: thank and reward repeat customers.
 *
 * Orders are counted by billing email, so guest checkouts count too. An order counts once it is paid
 * (processing or completed); the order that reaches the number starts the run. "Once" is the engine's
 * one run per person ever; "every multiple" starts once per number reached (the reference is the
 * number). An order paid with a code from one of these automations counts, but doesn't start a reward
 * itself: rewards don't earn rewards.
 */
class WooOrderCount extends WooOrderStatus
{
    const MIN_ORDERS = 2;
    const MAX_ORDERS = 100;

    /** @var array email => total spent, for this request */
    protected static $spent = [];

    public function id()
    {
        return 'woo_order_count';
    }

    public function label()
    {
        return __('A customer reaches a number of orders', 'mailoptin');
    }

    public function description()
    {
        return __('For example their third paid order. Use it to thank and reward repeat customers.', 'mailoptin');
    }

    public function tip()
    {
        return __('A thank-you with a code for the next order works well a day after the order that reaches the number. It counts from when it goes live: customers already past the number aren\'t rewarded for orders in the past.', 'mailoptin');
    }

    public function default_runs()
    {
        return 'once';
    }

    public function settings_fields()
    {
        return [
            'orders'  => [
                'label'       => __('Number of orders', 'mailoptin'),
                'type'        => 'number',
                'min'         => self::MIN_ORDERS,
                'max'         => self::MAX_ORDERS,
                'default'     => 3,
                'description' => __('Paid orders (processing or completed), counted by email address, guest orders included.', 'mailoptin'),
            ],
            // "runs" is the engine's own setting: "once" is one run per person, ever.
            'runs'    => [
                'label'   => __('Runs for each customer', 'mailoptin'),
                'type'    => 'radio',
                'options' => [
                    'once'  => __('Once, at that order', 'mailoptin'),
                    'every' => __('At every multiple (for 3: the 3rd, 6th, 9th order…)', 'mailoptin'),
                ],
                'default' => 'once',
            ],
            'reenter' => [
                'label'   => __('Already in it', 'mailoptin'),
                'type'    => 'checkbox',
                'text'    => __('Let the next number start while an earlier reward is still in progress', 'mailoptin'),
                'default' => true,
            ],
        ];
    }

    public function audience_fields()
    {
        return [
            'min_spent' => [
                'label'       => __('Only customers who have spent at least', 'mailoptin'),
                'type'        => 'number',
                'description' => __('In total, across their paid orders in the shop\'s currency. Leave empty for anyone.', 'mailoptin'),
            ],
        ];
    }

    /** The number of orders, kept between the field's limits. */
    protected static function orders($settings)
    {
        return min(self::MAX_ORDERS, max(self::MIN_ORDERS, absint($settings['orders'] ?? 3)));
    }

    protected static function every($settings)
    {
        return 'every' === ($settings['runs'] ?? 'once');
    }

    public function summary($settings)
    {
        $orders = self::orders($settings);

        if (self::every($settings)) {
            /* translators: 1: a number of orders, 2: twice it, 3: three times it */
            return sprintf(__('A customer reaches %1$d, %2$d, %3$d… paid orders', 'mailoptin'), $orders, $orders * 2, $orders * 3);
        }

        /* translators: %d: a number of orders */
        return sprintf(_n('A customer reaches %d paid order (once per customer)', 'A customer reaches %d paid orders (once per customer)', $orders, 'mailoptin'), $orders);
    }

    public function stops_when()
    {
        return __('The order that reached the number is refunded, cancelled, failed or deleted, earlier orders are cancelled so they no longer have that many, or they unsubscribe.', 'mailoptin');
    }

    public function listen()
    {
        add_action('woocommerce_order_status_changed', [$this, 'order_paid'], 20, 3);
    }

    /** Counts when an order becomes paid; moving between paid statuses doesn't count it twice. */
    public function order_paid($order_id, $from, $to)
    {
        $paid = (array)Woo::PAID_STATUSES;

        if ( ! in_array($to, $paid, true) || in_array($from, $paid, true)) return;

        // Most shops have no automation on this trigger: no order lookups for them, during checkout.
        if ( ! Engine::is_available() || empty(Repo::live_for_trigger($this->id()))) return;

        $order = Woo::order($order_id);
        $email = $order ? Woo::order_email($order) : '';

        if ( ! is_email($email)) return;

        $context = ['order_id' => absint($order_id), 'count' => self::paid_order_count($email)];

        if (self::paid_with_reward($order)) $context['reward_code'] = 1;

        $this->start($email, 'orders-' . $context['count'], $context);
    }

    /** Whether the order used a code that an automation with this trigger made. */
    public static function paid_with_reward($order)
    {
        foreach ((array)$order->get_coupon_codes() as $code) {
            $coupon_id     = wc_get_coupon_id_by_code($code);
            $automation_id = $coupon_id ? absint(get_post_meta($coupon_id, WooStore::META_AUTOMATION, true)) : 0;
            if ( ! $automation_id) continue;

            $automation = Repo::get($automation_id);

            if ($automation && 'woo_order_count' === ($automation['trigger_id'] ?? '')) return true;
        }

        return false;
    }

    protected static function paid_statuses()
    {
        return array_map(function ($s) { return 'wc-' . $s; }, (array)Woo::PAID_STATUSES);
    }

    /** IDs of an address's paid orders (IDs only: cheap even for a customer with many orders). */
    public static function paid_order_ids($email)
    {
        return wc_get_orders(['billing_email' => $email, 'status' => self::paid_statuses(), 'limit' => -1, 'return' => 'ids']);
    }

    public static function paid_order_count($email)
    {
        return count(self::paid_order_ids($email));
    }

    /** What an address has spent, less refunds, on paid orders in the shop's currency. Once per request. */
    public static function total_spent($email)
    {
        $email = strtolower((string)$email);

        if ( ! isset(self::$spent[$email])) {
            $total = 0.0;

            foreach (wc_get_orders(['billing_email' => $email, 'status' => self::paid_statuses(), 'currency' => get_woocommerce_currency(), 'limit' => -1]) as $order) {
                $total += (float)$order->get_total() - (float)$order->get_total_refunded();
            }

            self::$spent[$email] = $total;
        }

        return self::$spent[$email];
    }

    public function matches($context, $settings, $audience)
    {
        $count  = absint($context['count'] ?? 0);
        $orders = self::orders($settings);

        if ( ! $count || ! empty($context['reward_code'])) return false;

        $hit = self::every($settings) ? 0 === $count % $orders : $count === $orders;

        if ( ! $hit) return false;

        $min = $audience['min_spent'] ?? '';

        if ('' !== $min && is_numeric($min)) {
            $order = Woo::order($context['order_id'] ?? 0);

            if ( ! $order || self::total_spent(Woo::order_email($order)) < (float)$min) return false;
        }

        return true;
    }

    /**
     * Before each email: the order that reached the number is still paid, and they still have that
     * many paid orders (orders cancelled while they wait for the reward take it away).
     */
    public function stop_reason($run)
    {
        $reason = parent::stop_reason($run);

        if ('' !== $reason) return $reason;

        $count = absint($run['context']['count'] ?? 0);

        if ($count && self::paid_order_count($run['email'] ?? '') < $count) return 'orders_cancelled';

        return '';
    }

    public function checks()
    {
        $checks = parent::checks();

        unset($checks['not_reviewed']);

        return $checks;
    }

    public function placeholders()
    {
        return parent::placeholders() + [
            'customer.order_count' => [
                'label'  => __('Number of orders', 'mailoptin'),
                'value'  => function ($c) { return (string)absint($c['count'] ?? 0); },
                'sample' => '3',
            ],
        ];
    }

    public function count_recent($automation)
    {
        if ( ! Woo::active()) return null;

        $ids      = wc_get_orders(['status' => self::paid_statuses(), 'date_created' => '>' . (time() - 30 * DAY_IN_SECONDS), 'limit' => 200, 'return' => 'ids']);
        $match    = 0;
        $by_email = [];

        foreach ($ids as $order_id) {
            $order = Woo::order($order_id);
            $email = $order ? Woo::order_email($order) : '';
            if ( ! is_email($email)) continue;

            // Which paid order this was for them: order IDs only go up, so rank by ID (two orders in
            // the same second would tie on their dates). One lookup per customer.
            if ( ! isset($by_email[$email])) $by_email[$email] = array_map('intval', self::paid_order_ids($email));

            $count = count(array_filter($by_email[$email], function ($id) use ($order_id) { return $id <= (int)$order_id; }));

            if ($this->matches(['order_id' => $order_id, 'count' => $count], $automation['trigger_settings'], $automation['audience'])) $match++;
        }

        return ['matched' => $match, 'total' => count($ids), 'label' => __('paid orders in the last 30 days', 'mailoptin')];
    }
}
