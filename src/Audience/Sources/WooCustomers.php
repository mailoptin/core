<?php

namespace MailOptin\Core\Audience\Sources;

/**
 * WooCommerce customers: everyone with a paid order, read from WooCommerce's own customer and
 * order lookup tables (the ones WooCommerce › Customers uses).
 */
class WooCustomers extends AbstractSource
{
    /** The checkbox's id in WooCommerce's block checkout; saved on the order as _wc_other/<id>. */
    const FIELD_ID = 'mailoptin/marketing-consent';

    const CLASSIC_FIELD = 'mo_marketing_consent';

    public function id()
    {
        return 'woo';
    }

    public function label()
    {
        return __('WooCommerce customers', 'mailoptin');
    }

    public function is_active()
    {
        global $wpdb;

        return class_exists('WooCommerce') && self::table_exists($wpdb->prefix . 'wc_customer_lookup');
    }

    public function admin_url()
    {
        return admin_url('admin.php?page=wc-admin&path=/customers');
    }

    public function admin_link_label()
    {
        return __('View in WooCommerce', 'mailoptin');
    }

    public function checkbox_place()
    {
        return __('at checkout', 'mailoptin');
    }

    public function setting_defaults()
    {
        return ['months' => 24, 'guests' => 1];
    }

    public function settings_fields_html($settings, $name_prefix)
    {
        ob_start();
        ?>
        <p>
            <label><?php printf(
                    esc_html__('Counts as a customer: ordered in the last %s months', 'mailoptin'),
                    '<input type="number" min="0" max="600" class="small-text" name="' . esc_attr($name_prefix) . '[months]" value="' . absint($settings['months']) . '">'
                ); ?></label>
            <span class="description"><?php esc_html_e('0 for any time.', 'mailoptin'); ?></span>
        </p>
        <p><label><input type="checkbox" name="<?php echo esc_attr($name_prefix); ?>[guests]" value="1" <?php checked( ! empty($settings['guests'])); ?>> <?php esc_html_e('Guest orders too (customers without an account)', 'mailoptin'); ?></label></p>
        <?php
        return ob_get_clean();
    }

    public function sanitize_settings($posted)
    {
        return [
            'months' => min(600, absint($posted['months'] ?? 24)),
            'guests' => empty($posted['guests']) ? 0 : 1,
        ];
    }

    /** Orders that make someone a customer: paid ones. */
    public static function paid_statuses()
    {
        return apply_filters('mailoptin_audience_woo_paid_statuses', ['wc-completed', 'wc-processing', 'wc-on-hold']);
    }

    public function people_sql($option, $settings)
    {
        global $wpdb;

        $customers = $wpdb->prefix . 'wc_customer_lookup';
        $orders    = $wpdb->prefix . 'wc_order_stats';
        $statuses  = self::paid_statuses();

        $where  = ["c.email <> ''", 'o.parent_id = 0', 'o.status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')'];
        $params = $statuses;

        $cutoff = self::cutoff($settings['months'] ?? 24);

        if ($cutoff) {
            $where[]  = 'o.date_created_gmt >= %s';
            $params[] = $cutoff;
        }

        if (empty($settings['guests'])) $where[] = 'c.user_id IS NOT NULL';

        return [
            "SELECT LOWER(c.email) AS email, MAX(c.first_name) AS first_name, MAX(c.last_name) AS last_name
            FROM $customers c INNER JOIN $orders o ON o.customer_id = c.customer_id
            WHERE " . implode(' AND ', $where) . ' GROUP BY LOWER(c.email)',
            $params,
        ];
    }

    public function register_checkbox($checkbox_text)
    {
        // Block checkout (WooCommerce 8.9+): an additional checkout field, saved on the order.
        $register = function () use ($checkbox_text) {
            if ( ! function_exists('woocommerce_register_additional_checkout_field')) return;

            try {
                woocommerce_register_additional_checkout_field([
                    'id'       => self::FIELD_ID,
                    'label'    => $checkbox_text,
                    'location' => 'order',
                    'type'     => 'checkbox',
                ]);
            } catch (\Throwable $e) {
                // An older WooCommerce with a different API: the classic checkbox still works.
            }
        };

        did_action('woocommerce_init') ? $register() : add_action('woocommerce_init', $register);

        add_action('woocommerce_store_api_checkout_order_processed', function ($order) use ($checkbox_text) {
            if ( ! is_object($order) || ! method_exists($order, 'get_meta')) return;

            if ($order->get_meta('_wc_other/' . self::FIELD_ID)) $this->agree_from_order($order, $checkbox_text);
        });

        // Classic checkout.
        add_action('woocommerce_review_order_before_submit', function () use ($checkbox_text) {
            printf(
                '<p class="form-row mo-marketing-consent"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="%s" value="1"> <span>%s</span></label></p>',
                esc_attr(self::CLASSIC_FIELD),
                esc_html($checkbox_text)
            );
        });

        add_action('woocommerce_checkout_order_processed', function ($order_id, $posted = [], $order = null) use ($checkbox_text) {
            // Nonce checked by WooCommerce's checkout.
            if (empty($_POST[self::CLASSIC_FIELD])) return;

            $order = is_object($order) ? $order : wc_get_order($order_id);

            if ($order) $this->agree_from_order($order, $checkbox_text);
        }, 10, 3);
    }

    protected function agree_from_order($order, $checkbox_text)
    {
        Sources::agree($this->id(), $order->get_billing_email(), $checkbox_text, sprintf(__('Order #%s', 'mailoptin'), $order->get_order_number()));
    }

    public function profile_summary($email)
    {
        global $wpdb;

        if ( ! $this->is_active()) return [];

        $customers = $wpdb->prefix . 'wc_customer_lookup';
        $orders    = $wpdb->prefix . 'wc_order_stats';
        $statuses  = self::paid_statuses();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(o.order_id) AS orders, SUM(o.total_sales) AS spent, MIN(o.date_created) AS first_order, MAX(o.date_created) AS last_order
            FROM $customers c INNER JOIN $orders o ON o.customer_id = c.customer_id
            WHERE c.email = %s AND o.parent_id = 0 AND o.status IN (" . implode(',', array_fill(0, count($statuses), '%s')) . ')',
            array_merge([strtolower(trim($email))], $statuses)
        ), 'ARRAY_A');

        if (empty($row['orders'])) return [];

        $date = function ($value) {
            return $value ? date_i18n(get_option('date_format'), strtotime($value)) : '';
        };

        return [
            __('Orders', 'mailoptin')         => number_format_i18n((int)$row['orders']),
            __('Total spent', 'mailoptin')    => function_exists('wc_price') ? wp_strip_all_tags(wc_price((float)$row['spent'])) : number_format_i18n((float)$row['spent'], 2),
            __('Customer since', 'mailoptin') => $date($row['first_order']),
            __('Last order', 'mailoptin')     => $date($row['last_order']),
        ];
    }
}
