<?php

namespace MailOptin\Core\Automations\Stores;

/**
 * What automation emails need from a shop: the products in a cart or an order, prices, links,
 * and discount codes. WooCommerce is the one built in; another shop plugin (Easy Digital Downloads,
 * SureCart...) adds its own on mailoptin_automation_stores, and its triggers name it in store().
 *
 * Items are arrays: name, quantity, total (a number), image (URL or ''), url (product page or '').
 */
abstract class Store
{
    /** Coupon options a store may support (coupon_features()). */
    const COUPON_PERCENT = 'percent';
    const COUPON_FIXED = 'fixed';
    const COUPON_FREE_SHIPPING = 'free_shipping';
    const COUPON_EXPIRY = 'expiry';
    const COUPON_MINIMUM = 'minimum';
    const COUPON_INDIVIDUAL = 'individual_use';
    const COUPON_EXCLUDE_SALE = 'exclude_sale';
    /** A code only the person it was made for can use. */
    const COUPON_PER_PERSON = 'per_person';

    abstract public function id();

    abstract public function name();

    /** Whether the shop plugin is installed and running. */
    abstract public function active();

    /** An amount as the shop shows it ("£32.50"). */
    abstract public function money($amount, $currency = '');

    /**
     * The cart an automation run is about.
     *
     * @return array|null items, total, currency, url (back to the cart, restored)
     */
    public function cart($context)
    {
        return null;
    }

    /**
     * The order an automation run is about.
     *
     * @return array|null items, total, currency, url (the order's page)
     */
    public function order($context)
    {
        return null;
    }

    /**
     * Products to show in previews and tests that have no real person yet.
     *
     * @return array items, total, currency, url
     */
    public function sample($what)
    {
        $items = [
            ['name' => __('Paperwhite tulip bulbs', 'mailoptin'), 'quantity' => 2, 'total' => 18, 'image' => '', 'url' => ''],
            ['name' => __('Five-variety daffodil mix', 'mailoptin'), 'quantity' => 1, 'total' => 14.5, 'image' => '', 'url' => ''],
        ];

        return ['items' => $items, 'total' => 32.5, 'currency' => '', 'url' => home_url('/')];
    }

    /** @return string[] the COUPON_ options this store supports */
    public function coupon_features()
    {
        return [];
    }

    public function supports_coupon($feature)
    {
        return in_array($feature, $this->coupon_features(), true);
    }

    /**
     * Make a single-use discount code. Made once per $key: asked again (a retried send), the
     * same code comes back.
     *
     * @param array $args type (percent|fixed|free_shipping), amount, expiry_days, minimum,
     *                    individual_use, exclude_sale, prefix, email, automation_id, key
     *
     * @return array|\WP_Error code, expires (Y-m-d, or '')
     */
    public function create_coupon($args)
    {
        return new \WP_Error('mo_coupon_unsupported', __('This shop doesn\'t make discount codes.', 'mailoptin'));
    }

    /** Whether a code exists in the shop. */
    public function coupon_exists($code)
    {
        return false;
    }

    /** A link that opens the shop with a code applied. */
    public function apply_coupon_url($code)
    {
        return home_url('/');
    }

    /** Remove codes the automations made that were never used, a while after they expired. */
    public function purge_coupons()
    {
    }
}
