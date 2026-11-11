<?php

namespace MailOptin\Core\Automations\Stores;

use MailOptin\Core\Automations\Carts;
use MailOptin\Core\Automations\Triggers\Woo;
use MailOptin\Core\Repositories\CartsRepository as Carts_Repo;

class WooStore extends Store
{
    /** On a code an automation made: what made it, and the key that keeps it to one per email. */
    const META_AUTOMATION = '_mo_automation_id';
    const META_KEY = '_mo_automation_key';

    /** Unused codes are deleted this long after they expire. */
    const PURGE_AFTER_DAYS = 30;

    /** ?mo_coupon=CODE: applied to the cart, then on to it. */
    const APPLY_ARG = 'mo_coupon';

    public function id()
    {
        return 'woocommerce';
    }

    public function name()
    {
        return 'WooCommerce';
    }

    public function active()
    {
        return Woo::active();
    }

    /** A code from a link, kept until there's something in the cart to apply it to. */
    const SESSION_PENDING = 'mo_pending_coupon';

    public function init()
    {
        add_action('template_redirect', [$this, 'apply_from_link']);
        add_action('woocommerce_add_to_cart', [$this, 'apply_pending'], 20);
    }

    public function money($amount, $currency = '')
    {
        if ( ! $this->active()) return (string)$amount;

        return html_entity_decode(wp_strip_all_tags(wc_price((float)$amount, $currency ? ['currency' => $currency] : [])), ENT_QUOTES, 'UTF-8');
    }

    protected function product_bits($product_id, $variation_id = 0)
    {
        $product = $this->active() ? wc_get_product($variation_id ?: $product_id) : null;

        if ( ! $product) return ['image' => '', 'url' => ''];

        $image = $product->get_image_id() ?: (($parent = wc_get_product($product->get_parent_id())) ? $parent->get_image_id() : 0);

        return [
            'image' => $image ? (string)wp_get_attachment_image_url($image, 'woocommerce_thumbnail') : '',
            'url'   => (string)get_permalink($product->get_parent_id() ?: $product->get_id()),
        ];
    }

    public function cart($context)
    {
        $cart = Carts_Repo::get($context['cart_id'] ?? 0);

        if ( ! $cart) return null;

        $items = [];
        foreach ((array)$cart['items'] as $item) {
            $items[] = [
                'name'     => (string)($item['name'] ?? ''),
                'quantity' => max(1, (int)($item['quantity'] ?? 1)),
                'total'    => (float)($item['line_total'] ?? 0),
            ] + $this->product_bits(absint($item['product_id'] ?? 0), absint($item['variation_id'] ?? 0));
        }

        return ['items' => $items, 'total' => (float)$cart['total'], 'currency' => (string)$cart['currency'], 'url' => Carts::restore_url($cart)];
    }

    public function order($context)
    {
        $order = Woo::order($context['order_id'] ?? 0);

        if ( ! $order) return null;

        $items = [];
        foreach ($order->get_items() as $item) {
            if ( ! $item instanceof \WC_Order_Item_Product) continue;

            $items[] = [
                'name'     => $item->get_name(),
                'quantity' => max(1, (int)$item->get_quantity()),
                'total'    => (float)$item->get_total() + (float)$item->get_total_tax(),
            ] + $this->product_bits($item->get_product_id(), $item->get_variation_id());
        }

        return ['items' => $items, 'total' => (float)$order->get_total(), 'currency' => $order->get_currency(), 'url' => $order->get_view_order_url()];
    }

    public function sample($what)
    {
        $sample = parent::sample($what);
        if ($this->active()) $sample['url'] = 'order' === $what ? wc_get_page_permalink('myaccount') : wc_get_cart_url();

        return $sample;
    }

    public function coupon_features()
    {
        return [self::COUPON_PERCENT, self::COUPON_FIXED, self::COUPON_FREE_SHIPPING, self::COUPON_EXPIRY, self::COUPON_MINIMUM, self::COUPON_INDIVIDUAL, self::COUPON_EXCLUDE_SALE, self::COUPON_PER_PERSON];
    }

    /**
     * @return int the coupon's post id, 0 if none
     */
    protected function coupon_by_key($key)
    {
        $ids = get_posts(['post_type' => 'shop_coupon', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 1, 'meta_key' => self::META_KEY, 'meta_value' => $key, 'no_found_rows' => true]);

        return $ids ? (int)$ids[0] : 0;
    }

    protected function details($coupon)
    {
        $expires = $coupon->get_date_expires();

        return ['code' => strtoupper($coupon->get_code()), 'expires' => $expires ? $expires->date('Y-m-d') : ''];
    }

    public function create_coupon($args)
    {
        if ( ! $this->active()) return parent::create_coupon($args);

        $key = (string)($args['key'] ?? '');

        if ('' !== $key && ($id = $this->coupon_by_key($key))) return $this->details(new \WC_Coupon($id));

        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($args['prefix'] ?? '')));
        $code   = '';
        for ($i = 0; $i < 5 && ('' === $code || wc_get_coupon_id_by_code($code)); $i++) {
            $code = ('' !== $prefix ? $prefix . '-' : '') . strtoupper(wp_generate_password(6, false));
        }

        if (wc_get_coupon_id_by_code($code)) return new \WP_Error('mo_coupon_code', __('No free code could be found.', 'mailoptin'));

        $type   = (string)($args['type'] ?? 'percent');
        $coupon = new \WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_description(sprintf(
            /* translators: %d: automation id */
            __('Made by MailOptin automation #%d for one person. Deleted if unused 30 days after it expires.', 'mailoptin'),
            absint($args['automation_id'] ?? 0)
        ));

        if ('free_shipping' === $type) {
            $coupon->set_discount_type('fixed_cart');
            $coupon->set_amount(0);
            $coupon->set_free_shipping(true);
        } else {
            $coupon->set_discount_type('fixed' === $type ? 'fixed_cart' : 'percent');
            $coupon->set_amount(max(0, min('fixed' === $type ? PHP_INT_MAX : 100, (float)($args['amount'] ?? 0))));
        }

        $days = absint($args['expiry_days'] ?? 0);
        if ($days) $coupon->set_date_expires(time() + $days * DAY_IN_SECONDS);
        if ((float)($args['minimum'] ?? 0) > 0) $coupon->set_minimum_amount((float)$args['minimum']);

        $coupon->set_individual_use( ! empty($args['individual_use']));
        $coupon->set_exclude_sale_items( ! empty($args['exclude_sale']));
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);

        // Only the person it was sent to can use it.
        if (is_email($args['email'] ?? '')) $coupon->set_email_restrictions([strtolower($args['email'])]);

        $coupon->update_meta_data(self::META_AUTOMATION, absint($args['automation_id'] ?? 0));
        if ('' !== $key) $coupon->update_meta_data(self::META_KEY, $key);

        $coupon->save();

        return $coupon->get_id() ? $this->details($coupon) : new \WP_Error('mo_coupon_save', __('The discount code couldn\'t be saved.', 'mailoptin'));
    }

    public function coupon_exists($code)
    {
        return $this->active() && '' !== trim((string)$code) && (bool)wc_get_coupon_id_by_code((string)$code);
    }

    public function apply_coupon_url($code)
    {
        return add_query_arg(self::APPLY_ARG, rawurlencode((string)$code), $this->active() ? wc_get_cart_url() : home_url('/'));
    }

    /**
     * On template_redirect: ?mo_coupon=CODE applies the code to the cart, then drops the argument.
     * WooCommerce checks the code (who may use it, expiry, minimum) as for one typed in.
     */
    public function apply_from_link()
    {
        if (empty($_GET[self::APPLY_ARG]) || ! $this->active() || ! function_exists('WC') || ! WC()->cart) return;

        $code = wc_format_coupon_code(sanitize_text_field(wp_unslash($_GET[self::APPLY_ARG])));

        if ('' !== $code && wc_get_coupon_id_by_code($code) && WC()->session) {
            // A visitor with nothing in the cart has no session yet: start one, so the code is kept.
            if ( ! WC()->session->has_session()) WC()->session->set_customer_session_cookie(true);

            if (WC()->cart->is_empty()) {
                // Applied when they add something (or restore their cart).
                WC()->session->set(self::SESSION_PENDING, $code);
            } elseif ( ! WC()->cart->has_discount($code)) {
                WC()->cart->apply_coupon($code);
            }
        }

        wp_safe_redirect(remove_query_arg(self::APPLY_ARG));
        exit;
    }

    /**
     * On woocommerce_add_to_cart: a code from a link that came while the cart was empty.
     */
    public function apply_pending()
    {
        if ( ! function_exists('WC') || ! WC()->session || ! WC()->cart) return;

        $code = (string)WC()->session->get(self::SESSION_PENDING);
        if ('' === $code) return;

        WC()->session->set(self::SESSION_PENDING, null);
        if ( ! WC()->cart->has_discount($code)) WC()->cart->apply_coupon($code);
    }

    /**
     * Codes made here, never used, that expired more than 30 days ago: oldest first, in batches,
     * for up to 20 seconds a day. Used codes stay (they're on orders) and aren't looked at again.
     *
     * @return int how many were deleted
     */
    public function purge_coupons()
    {
        $deleted = 0;
        $until   = microtime(true) + 20;

        do {
            $ids = get_posts([
                'post_type'     => 'shop_coupon',
                'post_status'   => 'any',
                'fields'        => 'ids',
                'numberposts'   => 200,
                'orderby'       => 'ID',
                'order'         => 'ASC',
                'no_found_rows' => true,
                'meta_query'    => [
                    ['key' => self::META_KEY, 'compare' => 'EXISTS'],
                    ['key' => 'date_expires', 'value' => time() - self::PURGE_AFTER_DAYS * DAY_IN_SECONDS, 'compare' => '<', 'type' => 'NUMERIC'],
                    ['key' => 'date_expires', 'value' => '', 'compare' => '!='],
                    ['key' => 'usage_count', 'value' => 0, 'compare' => '=', 'type' => 'NUMERIC'],
                ],
            ]);

            foreach ($ids as $id) {
                wp_delete_post($id, true);
                $deleted++;
            }
        } while (count($ids) === 200 && microtime(true) < $until);

        return $deleted;
    }
}
