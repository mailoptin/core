<?php

namespace MailOptin\Core\Automations\Triggers;

use MailOptin\Core\Automations\AbstractTrigger;
use MailOptin\Core\Automations\Carts;
use MailOptin\Core\Repositories\CartsRepository as Carts_Repo;

/**
 * A shopper adds products and leaves without ordering. Carts are recorded by Carts; this looks for
 * the ones whose wait has passed, and starts the automation for them.
 */
class WooCartAbandoned extends AbstractTrigger
{
    const ID = 'woo_cart_abandoned';

    const DEFAULT_DELAY = 60;

    /** Carts left longer ago than this are not started: too late to remind anyone. */
    const MAX_AGE_DAYS = 7;

    const BATCH = 200;

    public function id()
    {
        return self::ID;
    }

    public function group()
    {
        return 'WooCommerce';
    }

    public function label()
    {
        return __('A cart is abandoned', 'mailoptin');
    }

    public function description()
    {
        return __('A shopper adds products and leaves without ordering. Use it to recover lost sales.', 'mailoptin');
    }

    public function is_available()
    {
        return Woo::active();
    }

    public static function delay_minutes($settings)
    {
        return max(15, min(7 * 24 * 60, (int)($settings['delay'] ?? self::DEFAULT_DELAY)));
    }

    public function settings_fields()
    {
        return [
            'delay'  => [
                'label'       => __('Counts as abandoned after', 'mailoptin'),
                'type'        => 'number',
                'default'     => self::DEFAULT_DELAY,
                'suffix'      => __('minutes without an order', 'mailoptin'),
                'description' => __('At least 15 minutes.', 'mailoptin'),
            ],
            'guests' => [
                'label'       => __('Guests', 'mailoptin'),
                'type'        => 'checkbox',
                'text'        => __('Also record guests\' carts, once they type their email at checkout', 'mailoptin'),
                'default'     => true,
                'description' => __('Logged-in customers\' carts are recorded as soon as they add a product. Carts are kept 30 days. Mention it in your privacy policy.', 'mailoptin'),
            ],
            'runs'   => [
                'label'   => __('Runs for each customer', 'mailoptin'),
                'type'    => 'radio',
                'options' => ['once' => __('Once', 'mailoptin'), 'every' => __('For every abandoned cart', 'mailoptin')],
                'default' => 'every',
            ],
        ];
    }

    public function audience_fields()
    {
        return [
            'category_ids' => [
                'label'       => __('Only carts with products in', 'mailoptin'),
                'type'        => 'multiselect',
                'options'     => [Woo::class, 'category_options'],
                'description' => __('Leave empty for any product.', 'mailoptin'),
            ],
            'min_total'    => [
                'label'       => __('Minimum cart total', 'mailoptin'),
                'type'        => 'number',
                'description' => __('Leave empty for any total.', 'mailoptin'),
            ],
        ];
    }

    public function stops_when()
    {
        return __('They place an order (from the email or not), empty their cart, or unsubscribe.', 'mailoptin');
    }

    public function listen()
    {
        // Started by scan(), from carts recorded by Carts.
    }

    public function scan($automation)
    {
        $delay  = self::delay_minutes($automation['trigger_settings']);
        $newest = Carts_Repo::now(-$delay * MINUTE_IN_SECONDS);
        $oldest = Carts_Repo::now(-self::MAX_AGE_DAYS * DAY_IN_SECONDS);
        $last   = 0;

        do {
            $carts = Carts_Repo::due_for($automation['id'], $newest, $oldest, $last, self::BATCH);

            foreach ($carts as $cart) {
                $last = $cart['id'];

                if (Carts_Repo::STATUS_ACTIVE === $cart['status']) {
                    Carts_Repo::update($cart['id'], ['status' => Carts_Repo::STATUS_ABANDONED, 'abandoned_at' => Carts_Repo::now()]);
                }

                $this->start($cart['email'], 'cart-' . $cart['id'], ['cart_id' => $cart['id']], $automation['id']);
            }
        } while (count($carts) === self::BATCH);
    }

    public function matches($context, $settings, $audience)
    {
        $cart = Carts_Repo::get($context['cart_id'] ?? 0);

        if ( ! $cart || empty($cart['items'])) return false;

        $categories = array_map('intval', (array)($audience['category_ids'] ?? []));

        if ( ! empty($categories)) {
            $in_cart = [];

            foreach ($cart['items'] as $item) {
                $in_cart = array_merge($in_cart, (array)wp_get_post_terms(absint($item['product_id']), 'product_cat', ['fields' => 'ids']));
            }

            if ( ! array_intersect($categories, array_map('intval', $in_cart))) return false;
        }

        $min = $audience['min_total'] ?? '';

        return '' === $min || ! is_numeric($min) || $cart['total'] >= (float)$min;
    }

    public function stop_reason($run)
    {
        $cart = Carts_Repo::get($run['context']['cart_id'] ?? 0);

        if ( ! $cart) return 'cart_deleted';

        if (Carts_Repo::STATUS_RECOVERED === $cart['status'] || $cart['order_id']) return 'ordered';

        if (empty($cart['items'])) return 'cart_emptied';

        return '';
    }

    public function checks()
    {
        return [
            'not_recovered' => [
                'label'    => __("their cart hasn't been recovered", 'mailoptin'),
                'callback' => function ($run) {
                    return '' === $this->stop_reason($run);
                },
            ],
        ];
    }

    public function placeholders()
    {
        $cart = function ($context) {
            return Carts_Repo::get($context['cart_id'] ?? 0);
        };

        return [
            'customer.first_name' => ['label' => __('First name', 'mailoptin'), 'value' => function ($c) use ($cart) { $x = $cart($c); return $x ? $x['first_name'] : ''; }, 'sample' => 'Maya'],
            'cart.items'          => [
                'label'  => __('Products in the cart', 'mailoptin'),
                'value'  => function ($c) use ($cart) {
                    $x = $cart($c);
                    if ( ! $x) return '';
                    $names = [];
                    foreach ($x['items'] as $item) $names[] = $item['name'] . ($item['quantity'] > 1 ? ' × ' . $item['quantity'] : '');

                    return implode(', ', array_filter($names));
                },
                'sample' => 'Paperwhite tulip bulbs × 2, Five-variety daffodil mix',
            ],
            'cart.total'          => [
                'label'  => __('Cart total', 'mailoptin'),
                'value'  => function ($c) use ($cart) {
                    $x = $cart($c);

                    return $x && Woo::active() ? html_entity_decode(wp_strip_all_tags(wc_price($x['total'], ['currency' => $x['currency']])), ENT_QUOTES, 'UTF-8') : '';
                },
                'sample' => '£32.50',
            ],
            'cart.url'            => ['label' => __('Link back to their cart', 'mailoptin'), 'value' => function ($c) use ($cart) { $x = $cart($c); return $x ? Carts::restore_url($x) : ''; }, 'sample' => home_url('/cart/')],
        ];
    }

    public function count_recent($automation)
    {
        $carts = Carts_Repo::abandoned_since(Carts_Repo::now(-30 * DAY_IN_SECONDS));
        $match = 0;

        foreach ($carts as $cart) {
            if ($this->matches(['cart_id' => $cart['id']], $automation['trigger_settings'], $automation['audience'])) $match++;
        }

        return ['matched' => $match, 'total' => count($carts), 'label' => __('carts abandoned in the last 30 days', 'mailoptin')];
    }
}
