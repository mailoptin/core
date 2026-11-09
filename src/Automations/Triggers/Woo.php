<?php

namespace MailOptin\Core\Automations\Triggers;

/**
 * WooCommerce lookups shared by the store triggers. Every function copes with WooCommerce being
 * switched off or an order having been deleted.
 */
class Woo
{
    /** Order statuses that count as a purchase. */
    const PAID_STATUSES = ['processing', 'completed'];

    public static function active()
    {
        return class_exists('WooCommerce') && function_exists('wc_get_order');
    }

    /**
     * @return \WC_Order|null
     */
    public static function order($order_id)
    {
        if ( ! self::active() || ! $order_id) return null;

        $order = wc_get_order($order_id);

        return $order instanceof \WC_Order ? $order : null;
    }

    public static function order_email($order)
    {
        return strtolower(trim((string)$order->get_billing_email()));
    }

    /**
     * @return int[] product IDs in the order (parents for variations)
     */
    public static function product_ids($order)
    {
        $ids = [];

        foreach ($order->get_items() as $item) {
            if ( ! $item instanceof \WC_Order_Item_Product) continue;
            $ids[] = (int)($item->get_product_id() ?: $item->get_variation_id());
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @return int[] product category IDs of everything in the order
     */
    public static function category_ids($order)
    {
        $ids = [];

        foreach (self::product_ids($order) as $product_id) {
            $ids = array_merge($ids, (array)wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']));
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @return array id => name, for a multiselect
     */
    public static function category_options()
    {
        if ( ! self::active()) return [];

        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);

        return is_wp_error($terms) ? [] : wp_list_pluck($terms, 'name', 'term_id');
    }

    /**
     * @return array status slug without "wc-" => label
     */
    public static function status_options()
    {
        if ( ! self::active()) return [];

        $options = [];

        foreach (wc_get_order_statuses() as $slug => $label) $options[substr($slug, 0, 3) === 'wc-' ? substr($slug, 3) : $slug] = $label;

        return $options;
    }

    /**
     * Whether this address has placed a paid order after the given UTC time.
     */
    public static function ordered_since($email, $utc_datetime, $except_order_id = 0)
    {
        if ( ! self::active()) return false;

        $orders = wc_get_orders([
            'billing_email' => $email,
            'status'        => array_map(function ($s) { return 'wc-' . $s; }, self::PAID_STATUSES),
            'date_created'  => '>' . strtotime($utc_datetime . ' UTC'),
            'exclude'       => $except_order_id ? [$except_order_id] : [],
            'limit'         => 1,
            'return'        => 'ids',
        ]);

        return ! empty($orders);
    }

    /**
     * Whether the customer has reviewed every product in the order, matched by their email.
     */
    public static function reviewed_all($order)
    {
        $email = self::order_email($order);

        foreach (self::product_ids($order) as $product_id) {
            $reviews = get_comments(['post_id' => $product_id, 'author_email' => $email, 'type' => 'review', 'count' => true, 'status' => 'all']);

            if ( ! $reviews) return false;
        }

        return true;
    }

    public static function money($amount, $order = null)
    {
        $args = $order ? ['currency' => $order->get_currency()] : [];

        return html_entity_decode(wp_strip_all_tags(wc_price($amount, $args)), ENT_QUOTES, 'UTF-8');
    }
}
