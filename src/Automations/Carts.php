<?php

namespace MailOptin\Core\Automations;

use MailOptin\Core\Audience\Tokens;
use MailOptin\Core\Automations\Triggers\Woo;
use MailOptin\Core\Automations\Triggers\WooCartAbandoned;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;
use MailOptin\Core\Repositories\CartsRepository as Carts_Repo;

/**
 * Records WooCommerce carts for the "A cart is abandoned" automations, and nothing else: carts are
 * kept only while such an automation is live, and only once we know whose cart it is (a logged-in
 * customer, or a guest who typed their email at checkout).
 *
 * Also restores a cart from its email link, and marks carts recovered when their order is paid.
 */
class Carts
{
    /** Single event that looks for carts whose wait has passed. */
    const CHECK_HOOK = 'mo_carts_check';

    const SESSION_EMAIL = 'mo_cart_email';
    const SESSION_HASH = 'mo_cart_hash';

    const RESTORE_ARG = 'mo_cart_restore';

    public function __construct()
    {
        add_action('woocommerce_cart_updated', [$this, 'record']);
        add_action('woocommerce_cart_emptied', [$this, 'emptied']);
        add_action('wp_ajax_mo_cart_email', [$this, 'ajax_email']);
        add_action('wp_ajax_nopriv_mo_cart_email', [$this, 'ajax_email']);
        add_action('wp_enqueue_scripts', [$this, 'checkout_script']);

        add_action('woocommerce_checkout_order_processed', [$this, 'checked_out'], 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'checked_out'], 10, 1);
        add_action('woocommerce_order_status_changed', [$this, 'order_status_changed'], 10, 3);

        add_action('template_redirect', [$this, 'restore']);
        add_action(self::CHECK_HOOK, [$this, 'check']);

        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter']);
    }

    /* ---------------------------------------------------------------------------------------------
     * Which carts are kept
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return array[] live "A cart is abandoned" automations
     */
    public static function automations()
    {
        if ( ! Woo::active() || ! Engine::is_available()) return [];

        return Repo::live_for_trigger(WooCartAbandoned::ID);
    }

    public static function is_recording()
    {
        return ! empty(self::automations());
    }

    /**
     * Guests' carts are kept only if an automation asks for them.
     */
    public static function records_guests()
    {
        foreach (self::automations() as $automation) {
            if ( ! array_key_exists('guests', $automation['trigger_settings']) || ! empty($automation['trigger_settings']['guests'])) return true;
        }

        return false;
    }

    /**
     * The shortest wait before a cart counts as abandoned, in minutes.
     */
    public static function shortest_delay()
    {
        $delays = array_map(function ($automation) {
            return WooCartAbandoned::delay_minutes($automation['trigger_settings']);
        }, self::automations());

        return $delays ? min($delays) : WooCartAbandoned::DEFAULT_DELAY;
    }

    protected static function session()
    {
        return function_exists('WC') && WC()->session ? WC()->session : null;
    }

    protected static function session_key()
    {
        $session = self::session();

        return $session ? (string)$session->get_customer_id() : '';
    }

    /* ---------------------------------------------------------------------------------------------
     * Recording
     * ------------------------------------------------------------------------------------------ */

    public function record()
    {
        if ( ! self::is_recording() || ! function_exists('WC') || ! WC()->cart || '' === self::session_key()) return;

        $cart = WC()->cart;

        if ($cart->is_empty()) {
            $this->emptied();

            return;
        }

        $user    = wp_get_current_user();
        $email   = $user && $user->exists() ? $user->user_email : (string)self::session()->get(self::SESSION_EMAIL);
        $first   = $user && $user->exists() ? $user->first_name : '';

        // Whose cart it is: nothing to keep for an anonymous visitor.
        if ( ! is_email($email) || ( ! ($user && $user->exists()) && ! self::records_guests())) return;

        $items = [];

        foreach ($cart->get_cart() as $line) {
            $product = $line['data'] ?? null;

            $items[] = [
                'product_id'   => absint($line['product_id'] ?? 0),
                'variation_id' => absint($line['variation_id'] ?? 0),
                'variation'    => array_map('sanitize_text_field', (array)($line['variation'] ?? [])),
                'quantity'     => max(1, (int)($line['quantity'] ?? 1)),
                'name'         => $product instanceof \WC_Product ? $product->get_name() : '',
                'line_total'   => (float)($line['line_total'] ?? 0) + (float)($line['line_tax'] ?? 0),
            ];
        }

        $total = (float)$cart->get_total('edit');
        $hash  = md5(wp_json_encode([$items, $total, strtolower($email)]));

        // woocommerce_cart_updated fires on every cart change and on some page loads: only write changes.
        if ($hash === self::session()->get(self::SESSION_HASH)) return;

        self::session()->set(self::SESSION_HASH, $hash);

        Carts_Repo::save([
            'session_key' => self::session_key(),
            'user_id'     => $user ? $user->ID : 0,
            'email'       => $email,
            'first_name'  => $first,
            'items'       => $items,
            'total'       => $total,
            'currency'    => get_woocommerce_currency(),
        ]);

        self::schedule_check(time() + self::shortest_delay() * MINUTE_IN_SECONDS + MINUTE_IN_SECONDS);
    }

    public function emptied()
    {
        $key = self::session_key();

        if ('' === $key) return;

        Carts_Repo::delete_active($key);

        if (self::session()) self::session()->set(self::SESSION_HASH, null);
    }

    /**
     * A guest typed their email at checkout.
     */
    public function ajax_email()
    {
        check_ajax_referer('mo_cart_email', 'nonce');

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));

        if ( ! self::session() || ! is_email($email) || ! self::records_guests()) wp_send_json_success();

        self::session()->set(self::SESSION_EMAIL, strtolower($email));
        self::session()->set(self::SESSION_HASH, null);

        $this->record();

        wp_send_json_success();
    }

    public function checkout_script()
    {
        if ( ! function_exists('is_checkout') || ! is_checkout() || is_user_logged_in() || ! self::records_guests()) return;

        wp_register_script('mailoptin-cart-email', '', ['jquery'], MAILOPTIN_VERSION_NUMBER, true);
        wp_enqueue_script('mailoptin-cart-email');
        wp_add_inline_script('mailoptin-cart-email', sprintf(
            '(function($){var last="";$(document).on("change","#billing_email,#email",function(){var v=$.trim(this.value);if(!v||v===last||!/^\S+@\S+\.\S+$/.test(v))return;last=v;$.post(%s,{action:"mo_cart_email",nonce:%s,email:v});});})(jQuery);',
            wp_json_encode(admin_url('admin-ajax.php')),
            wp_json_encode(wp_create_nonce('mo_cart_email'))
        ));
    }

    /* ---------------------------------------------------------------------------------------------
     * Abandoning
     * ------------------------------------------------------------------------------------------ */

    /**
     * Make sure carts are looked at by $at, so a cart counts as abandoned close to its wait rather
     * than at the next hourly scan.
     */
    public static function schedule_check($at)
    {
        $next = wp_next_scheduled(self::CHECK_HOOK);

        if ($next && $next <= $at) return;

        if ($next) wp_clear_scheduled_hook(self::CHECK_HOOK);

        wp_schedule_single_event($at, self::CHECK_HOOK);
    }

    public function check()
    {
        $trigger = Triggers::get_instance()->get(WooCartAbandoned::ID);

        if ( ! $trigger || ! $trigger->is_available() || ! Engine::is_available()) return;

        foreach (self::automations() as $automation) $trigger->scan($automation);
    }

    /* ---------------------------------------------------------------------------------------------
     * Ordering and recovery
     * ------------------------------------------------------------------------------------------ */

    /**
     * Tie the cart to its order at checkout, while the shopper's session is still known; whether it
     * was recovered is decided when the order is paid.
     */
    public function checked_out($order)
    {
        $order_id = is_object($order) && method_exists($order, 'get_id') ? $order->get_id() : absint($order);
        $cart     = '' !== self::session_key() ? Carts_Repo::get_by_session(self::session_key()) : null;

        if ( ! $cart || ! $order_id) return;

        Carts_Repo::update($cart['id'], ['order_id' => $order_id]);

        // A new cart for the next order starts fresh.
        if (self::session()) self::session()->set(self::SESSION_HASH, null);
    }

    public function order_status_changed($order_id, $from, $to)
    {
        if ( ! in_array($to, Woo::PAID_STATUSES, true) || in_array($from, Woo::PAID_STATUSES, true)) return;

        $order = Woo::order($order_id);

        if ( ! $order) return;

        foreach (Carts_Repo::for_order($order_id, Woo::order_email($order)) as $cart) {
            if (Carts_Repo::STATUS_ABANDONED === $cart['status']) {
                Carts_Repo::update($cart['id'], [
                    'status'          => Carts_Repo::STATUS_RECOVERED,
                    'order_id'        => $order_id,
                    'recovered_total' => (float)$order->get_total(),
                    'recovered_at'    => Carts_Repo::now(),
                    'session_key'     => 'done-' . $cart['id'],
                ]);
            } else {
                // Ordered before it was ever abandoned: nothing to recover or to remember.
                Carts_Repo::delete($cart['id']);
            }

            // Its emails stop now rather than at their next step.
            Repo::stop_runs(['trigger_ref' => 'cart-' . $cart['id'], 'email' => $cart['email']], 'ordered');
        }
    }

    /* ---------------------------------------------------------------------------------------------
     * Restoring a cart from its email
     * ------------------------------------------------------------------------------------------ */

    public static function restore_url($cart)
    {
        return add_query_arg([
            self::RESTORE_ARG => absint($cart['id']),
            'mo_token'        => Tokens::make('cart_restore', [absint($cart['id']), $cart['email']]),
        ], home_url('/'));
    }

    public function restore()
    {
        if (empty($_GET[self::RESTORE_ARG]) || ! Woo::active() || ! function_exists('WC') || ! WC()->cart) return;

        $cart = Carts_Repo::get(absint($_GET[self::RESTORE_ARG]));

        if ( ! $cart || ! Tokens::check('cart_restore', [$cart['id'], $cart['email']], (string)wp_unslash($_GET['mo_token'] ?? ''))) {
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        // Already ordered: just the shop's cart page.
        if (Carts_Repo::STATUS_RECOVERED !== $cart['status']) {
            WC()->cart->empty_cart(false);

            foreach ($cart['items'] as $item) {
                $product = wc_get_product($item['variation_id'] ?: $item['product_id']);

                if ( ! $product || ! $product->is_purchasable()) continue;

                WC()->cart->add_to_cart($item['product_id'], max(1, (int)$item['quantity']), $item['variation_id'], (array)$item['variation']);
            }

            if (self::session()) {
                self::session()->set(self::SESSION_EMAIL, $cart['email']);
                self::session()->set(self::SESSION_HASH, null);

                // This browser now holds the cart: its order recovers it.
                $key = self::session_key();
                if ('' !== $key && $key !== $cart['session_key']) {
                    Carts_Repo::delete_active($key);
                    Carts_Repo::update($cart['id'], ['session_key' => $key]);
                }
            }
        }

        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    /* ---------------------------------------------------------------------------------------------
     * Privacy
     * ------------------------------------------------------------------------------------------ */

    public function register_eraser($erasers)
    {
        $erasers['mailoptin-carts'] = [
            'eraser_friendly_name' => __('MailOptin abandoned carts', 'mailoptin'),
            'callback'             => function ($email) {
                $removed = Carts_Repo::erase_email($email);

                return ['items_removed' => $removed > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
            },
        ];

        return $erasers;
    }

    public function register_exporter($exporters)
    {
        $exporters['mailoptin-carts'] = [
            'exporter_friendly_name' => __('MailOptin abandoned carts', 'mailoptin'),
            'callback'               => function ($email) {
                $data = [];

                foreach (Carts_Repo::for_email($email) as $cart) {
                    $data[] = [
                        'group_id'    => 'mailoptin-carts',
                        'group_label' => __('Abandoned carts', 'mailoptin'),
                        'item_id'     => 'mo-cart-' . $cart['id'],
                        'data'        => [
                            ['name' => __('Products', 'mailoptin'), 'value' => implode(', ', wp_list_pluck($cart['items'], 'name'))],
                            ['name' => __('Status', 'mailoptin'), 'value' => $cart['status']],
                            ['name' => __('Last changed', 'mailoptin'), 'value' => $cart['updated_at']],
                        ],
                    ];
                }

                return ['data' => $data, 'done' => true];
            },
        ];

        return $exporters;
    }

    public static function get_instance()
    {
        static $instance = null;

        if (null === $instance) $instance = new self();

        return $instance;
    }
}
