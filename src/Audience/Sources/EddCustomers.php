<?php

namespace MailOptin\Core\Audience\Sources;

/**
 * Easy Digital Downloads customers (EDD 3): everyone with a completed sale.
 */
class EddCustomers extends AbstractSource
{
    const FIELD = 'mo_marketing_consent';

    public function id()
    {
        return 'edd';
    }

    public function label()
    {
        return __('Easy Digital Downloads customers', 'mailoptin');
    }

    public function is_active()
    {
        global $wpdb;

        return class_exists('Easy_Digital_Downloads') && self::table_exists($wpdb->prefix . 'edd_orders');
    }

    public function admin_url()
    {
        return admin_url('edit.php?post_type=download&page=edd-customers');
    }

    public function admin_link_label()
    {
        return __('View in Downloads', 'mailoptin');
    }

    public function checkbox_place()
    {
        return __('at checkout', 'mailoptin');
    }

    public function setting_defaults()
    {
        return ['months' => 24];
    }

    public function settings_fields_html($settings, $name_prefix)
    {
        return '<p><label>' . sprintf(
                esc_html__('Counts as a customer: bought in the last %s months', 'mailoptin'),
                '<input type="number" min="0" max="600" class="small-text" name="' . esc_attr($name_prefix) . '[months]" value="' . absint($settings['months']) . '">'
            ) . '</label> <span class="description">' . esc_html__('0 for any time.', 'mailoptin') . '</span></p>';
    }

    public function sanitize_settings($posted)
    {
        return ['months' => min(600, absint($posted['months'] ?? 24))];
    }

    public static function paid_statuses()
    {
        // edd_subscription: EDD Recurring's renewal payments.
        return apply_filters('mailoptin_audience_edd_paid_statuses', ['complete', 'partially_refunded', 'edd_subscription']);
    }

    public function people_sql($option, $settings, $email = null)
    {
        global $wpdb;

        $customers = $wpdb->prefix . 'edd_customers';
        $orders    = $wpdb->prefix . 'edd_orders';

        list($in, $params) = self::in_list(self::paid_statuses());

        // Test-mode purchases aren't customers.
        $where = ["o.email <> ''", "o.type = 'sale'", "o.mode = 'live'", "o.status $in"];

        if (null !== $email) {
            $where[]  = 'o.email = %s';
            $params[] = $email;
        }

        $cutoff = self::cutoff($settings['months'] ?? 24);

        if ($cutoff) {
            $where[]  = 'o.date_created >= %s';
            $params[] = $cutoff;
        }

        // EDD keeps one name; the first word is the first name.
        return [
            "SELECT LOWER(o.email) AS email, MAX(SUBSTRING_INDEX(c.name, ' ', 1)) AS first_name, MAX(TRIM(SUBSTRING(c.name, LENGTH(SUBSTRING_INDEX(c.name, ' ', 1)) + 1))) AS last_name
            FROM $orders o LEFT JOIN $customers c ON c.id = o.customer_id
            WHERE " . implode(' AND ', $where) . ' GROUP BY LOWER(o.email)',
            $params,
        ];
    }

    public function register_checkbox($checkbox_text)
    {
        add_action('edd_purchase_form_before_submit', function () use ($checkbox_text) {
            printf(
                '<fieldset class="mo-marketing-consent"><p><label><input type="checkbox" name="%s" value="1"> %s</label></p></fieldset>',
                esc_attr(self::FIELD),
                esc_html($checkbox_text)
            );
        }, 5);

        add_action('edd_built_order', function ($order_id) use ($checkbox_text) {
            // Nonce checked by EDD's checkout. Recorded once paid: anyone can type any address.
            if (empty($_POST[self::FIELD]) || ! function_exists('edd_add_order_meta')) return;

            edd_add_order_meta($order_id, '_mo_marketing_consent', $checkbox_text, true);
        });

        add_action('edd_complete_purchase', function ($order_id) {
            if ( ! function_exists('edd_get_order') || ! function_exists('edd_get_order_meta')) return;

            $text  = (string)edd_get_order_meta($order_id, '_mo_marketing_consent', true);
            $order = '' !== $text ? edd_get_order($order_id) : null;

            if ($order && ! empty($order->email)) {
                Sources::agree($this->id(), $order->email, $text, sprintf(__('Order #%s', 'mailoptin'), $order->get_number()));
            }
        });
    }
}
