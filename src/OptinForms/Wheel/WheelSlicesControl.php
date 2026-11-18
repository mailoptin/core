<?php

namespace MailOptin\Core\OptinForms\Wheel;

/**
 * The slice editor in the Customizer's "Prize wheel" section. The slices are one setting (JSON); the editor
 * itself is drawn by assets/js/admin/wheel-customizer.js from what this control prints: the shop's coupons
 * and products (on WooCommerce sites), how often each slice has come up, and whether Premium is active.
 */
class WheelSlicesControl extends \WP_Customize_Control
{
    public $type = 'mo_wheel_slices';

    public $campaign_id = 0;

    protected function coupons()
    {
        if ( ! Wheel::woocommerce()) return [];

        $ids = get_posts(['post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => true]);
        $out = [];
        foreach ($ids as $id) {
            $code = (string)wc_get_coupon_code_by_id($id);
            if ('' === $code) continue;

            $coupon = new \WC_Coupon($id);
            $what   = 'percent' === $coupon->get_discount_type() ? $coupon->get_amount() . '%' : wp_strip_all_tags(wc_price($coupon->get_amount()));
            if ($coupon->get_free_shipping()) $what = $coupon->get_amount() > 0 ? $what . ' + ' . __('free shipping', 'mailoptin') : __('free shipping', 'mailoptin');

            $out[] = ['id' => $id, 'label' => $code . ' · ' . html_entity_decode($what, ENT_QUOTES)];
        }

        return $out;
    }

    protected function products()
    {
        if ( ! Wheel::woocommerce() || ! function_exists('wc_get_products')) return [];

        $out = [];
        foreach (wc_get_products(['status' => 'publish', 'limit' => 200, 'orderby' => 'title', 'order' => 'ASC', 'type' => ['simple', 'variation']]) as $p) {
            $out[] = ['id' => $p->get_id(), 'label' => wp_strip_all_tags($p->get_name())];
        }

        return $out;
    }

    public function render_content()
    {
        $counts = Spins::counts($this->campaign_id)['slices'];

        $data = [
            'woocommerce' => Wheel::woocommerce(),
            'premium'     => Wheel::premium(),
            'upgrade'     => Wheel::upgrade_url('wheel_unique_codes'),
            'coupons'     => $this->coupons(),
            'products'    => $this->products(),
            'counts'      => (object)$counts,
            'min'         => Wheel::MIN_SLICES,
            'max'         => Wheel::MAX_SLICES,
        ];

        if ( ! empty($this->label)) echo '<span class="customize-control-title">' . esc_html($this->label) . '</span>';
        if ( ! empty($this->description)) echo '<span class="description customize-control-description">' . esc_html($this->description) . '</span>';

        echo '<div class="mo-wheel-editor" data-config="' . esc_attr(wp_json_encode($data)) . '"></div>';
        echo '<input type="hidden" class="mo-wheel-slices-value" ' . $this->get_link() . ' value="' . esc_attr($this->value()) . '">';
    }
}
