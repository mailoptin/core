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
 * Also restores a cart from its email link, and marks a cart recovered when its order is paid.
 */
class Carts
{
    /** Single event that looks for carts whose wait has passed. */
    const CHECK_HOOK = 'mo_carts_check';

    /** Autoloaded: whether a cart automation is live, so other sites pay nothing per page view. */
    const LIVE_OPTION = 'mo_cart_automations_live';

    const SESSION_EMAIL = 'mo_cart_email';
    const SESSION_HASH = 'mo_cart_hash';
    const SESSION_EMAILS_TRIED = 'mo_cart_emails_tried';

    const RESTORE_ARG = 'mo_cart_restore';

    /** Guest emails one IP address can attach to carts per hour, and one session at all. */
    const IP_HOURLY_LIMIT = 5;
    const SESSION_EMAIL_LIMIT = 3;

    public function __construct()
    {
        add_action('mailoptin_automations_changed', [__CLASS__, 'refresh_live_flag']);

        // Carts already kept stay usable after an automation stops: links in sent emails, orders
        // recovering them, the check, and personal data requests.
        add_action('woocommerce_order_status_changed', [$this, 'order_status_changed'], 10, 3);
        add_action('template_redirect', [$this, 'restore']);
        add_action(self::CHECK_HOOK, [$this, 'check']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter']);

        // Recording is hooked only while a cart automation is live: other stores pay nothing per page view.
        if ( ! get_option(self::LIVE_OPTION)) return;

        add_action('woocommerce_cart_updated', [$this, 'record']);
        add_action('woocommerce_cart_emptied', [$this, 'emptied']);
        add_action('wp_ajax_mo_cart_email', [$this, 'ajax_email']);
        add_action('wp_ajax_nopriv_mo_cart_email', [$this, 'ajax_email']);
        add_action('wp_enqueue_scripts', [$this, 'checkout_script']);
        add_action('woocommerce_checkout_order_processed', [$this, 'checked_out'], 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'checked_out'], 10, 1);
    }

    /* ---------------------------------------------------------------------------------------------
     * Which carts are kept
     * ------------------------------------------------------------------------------------------ */

    public static function refresh_live_flag()
    {
        $live = Repo::is_ready() && ! empty(Repo::live_for_trigger(WooCartAbandoned::ID));

        if ((bool)get_option(self::LIVE_OPTION) !== $live) update_option(self::LIVE_OPTION, $live ? 1 : 0, true);
    }

    /**
     * @return array[] live "A cart is abandoned" automations
     */
    public static function automations()
    {
        if ( ! get_option(self::LIVE_OPTION) || ! Woo::active() || ! Engine::is_available()) return [];

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

    /**
     * On every cart change, and on page views (WooCommerce fires it when loading the cart): the cheap
     * checks come first, so a visitor without a cart costs nothing.
     */
    public function record()
    {
        $session = self::session();

        if ( ! $session || ! $session->has_session() || ! function_exists('WC') || ! WC()->cart) return;

        if (WC()->cart->is_empty()) {
            // Only a session whose cart we recorded has anything to let go of.
            if ($session->get(self::SESSION_HASH)) $this->emptied();

            return;
        }

        $user  = wp_get_current_user();
        $known = $user && $user->exists();
        $email = $known ? $user->user_email : (string)$session->get(self::SESSION_EMAIL);

        // Whose cart it is: nothing to keep for an anonymous visitor.
        if ( ! is_email($email)) return;

        $items = [];

        foreach (WC()->cart->get_cart() as $line) {
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

        $total = (float)WC()->cart->get_total('edit');
        $hash  = md5(wp_json_encode([$items, $total, strtolower($email)]));

        // Fired several times a request and on page views: only write changes.
        if ($hash === $session->get(self::SESSION_HASH)) return;

        if ( ! self::is_recording() || ( ! $known && ! self::records_guests())) return;

        $session->set(self::SESSION_HASH, $hash);

        $key  = self::session_key();
        $cart = Carts_Repo::get_by_session($key);

        // An abandoned cart whose emails are over: this is a new cart, not more of that one.
        if ($cart && Carts_Repo::STATUS_ACTIVE !== $cart['status'] && ! Carts_Repo::has_active_run($cart['id'])) {
            Carts_Repo::release_session($key, false);
        }

        Carts_Repo::save([
            'session_key' => $key,
            'user_id'     => $known ? $user->ID : 0,
            'email'       => $email,
            'first_name'  => $known ? $user->first_name : '',
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

        // Still being shopped: forgotten. Abandoned: its emails stop ("emptied their cart").
        Carts_Repo::release_session($key, true);

        if (self::session()) self::session()->set(self::SESSION_HASH, null);
    }

    /**
     * A guest typed their email at checkout. The nonce is the same for every guest, so it proves
     * little: what limits misuse (someone typing other people's addresses) is that it only counts for
     * a session with products in its cart, a few addresses per session, and a few per IP an hour.
     */
    public function ajax_email()
    {
        check_ajax_referer('mo_cart_email', 'nonce');

        $session = self::session();
        $email   = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));

        if ( ! $session || ! $session->has_session() || ! WC()->cart || WC()->cart->is_empty() || ! is_email($email) || ! self::records_guests()) {
            wp_send_json_success();
        }

        $tried = array_values(array_unique(array_merge((array)$session->get(self::SESSION_EMAILS_TRIED), [$email])));

        if (count($tried) > self::SESSION_EMAIL_LIMIT || ! self::ip_allows()) wp_send_json_success();

        $session->set(self::SESSION_EMAILS_TRIED, $tried);
        $session->set(self::SESSION_EMAIL, $email);
        $session->set(self::SESSION_HASH, null);

        $this->record();

        wp_send_json_success();
    }

    protected static function ip_allows()
    {
        $ip  = class_exists('\WC_Geolocation') ? \WC_Geolocation::get_ip_address() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $key = 'mo_cart_ip_' . md5((string)$ip);
        $n   = (int)get_transient($key);

        if ($n >= self::IP_HOURLY_LIMIT) return false;

        set_transient($key, $n + 1, HOUR_IN_SECONDS);

        return true;
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
     * Tie the cart to its order at checkout, while the shopper's session is still known. Whether it
     * was recovered is decided when the order is paid; an unpaid order stops nothing.
     */
    public function checked_out($order)
    {
        $order_id = is_object($order) && method_exists($order, 'get_id') ? $order->get_id() : absint($order);
        $key      = self::session_key();
        $cart     = '' !== $key && Carts_Repo::is_ready() ? Carts_Repo::get_by_session($key) : null;

        if ( ! $cart || ! $order_id) return;

        Carts_Repo::update($cart['id'], ['order_id' => $order_id]);
    }

    public function order_status_changed($order_id, $from, $to)
    {
        if ( ! in_array($to, Woo::PAID_STATUSES, true) || in_array($from, Woo::PAID_STATUSES, true) || ! Carts_Repo::is_ready()) return;

        $order = Woo::order($order_id);

        if ( ! $order) return;

        // Only orders placed by the shopper count; not ones made in the admin or renewals.
        $from_checkout = in_array($order->get_created_via(), ['checkout', 'store-api'], true);
        $cart          = Carts_Repo::for_order($order_id, Woo::order_email($order), $from_checkout);

        if ( ! $cart) return;

        if (Carts_Repo::STATUS_ABANDONED === $cart['status']) {
            Carts_Repo::update($cart['id'], [
                'status'          => Carts_Repo::STATUS_RECOVERED,
                'order_id'        => $order_id,
                'recovered_total' => (float)$order->get_total(),
                'recovered_at'    => Carts_Repo::now(),
                'session_key'     => 'done-' . $cart['id'],
            ]);

            // Its emails stop now rather than at their next step.
            foreach (Carts_Repo::run_ids($cart['id']) as $run_id) {
                $run = Repo::get_run($run_id);
                if ($run && Repo::RUN_ACTIVE === $run['status']) Repo::finish_run($run_id, Repo::RUN_STOPPED, 'ordered');
            }
        } else {
            // Ordered before it was ever abandoned: nothing to recover or to remember.
            Carts_Repo::delete($cart['id']);
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
        if (empty($_GET[self::RESTORE_ARG]) || ! Woo::active() || ! function_exists('WC') || ! WC()->cart || ! Carts_Repo::is_ready()) return;

        $cart = Carts_Repo::get(absint($_GET[self::RESTORE_ARG]));

        // A link only ever adds to an empty cart: it never replaces what a visitor is buying, and
        // someone sending their own link to another person can't take their cart over.
        if ($cart && Carts_Repo::STATUS_RECOVERED !== $cart['status'] && WC()->cart->is_empty()
            && Tokens::check('cart_restore', [$cart['id'], $cart['email']], (string)wp_unslash($_GET['mo_token'] ?? ''))) {

            foreach ($cart['items'] as $item) {
                $product = wc_get_product($item['variation_id'] ?: $item['product_id']);

                if ( ! $product || ! $product->is_purchasable()) continue;

                WC()->cart->add_to_cart($item['product_id'], max(1, (int)$item['quantity']), $item['variation_id'], (array)$item['variation']);
            }

            $session = self::session();
            $key     = self::session_key();

            // This browser now holds the cart, so its order recovers it; unless it already has one.
            if ($session && '' !== $key && $key !== $cart['session_key'] && ! Carts_Repo::get_by_session($key)) {
                Carts_Repo::update($cart['id'], ['session_key' => $key]);
                if ( ! $session->get(self::SESSION_EMAIL)) $session->set(self::SESSION_EMAIL, $cart['email']);
                $session->set(self::SESSION_HASH, null);
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
                $removed = Carts_Repo::is_ready() ? Carts_Repo::erase_email($email) : 0;

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

                foreach (Carts_Repo::is_ready() ? Carts_Repo::for_email($email) : [] as $cart) {
                    $names = [];
                    foreach ($cart['items'] as $item) $names[] = $item['name'] . ' × ' . (int)$item['quantity'];

                    $data[] = [
                        'group_id'    => 'mailoptin-carts',
                        'group_label' => __('Abandoned carts', 'mailoptin'),
                        'item_id'     => 'mo-cart-' . $cart['id'],
                        'data'        => [
                            ['name' => __('Email', 'mailoptin'), 'value' => $cart['email']],
                            ['name' => __('First name', 'mailoptin'), 'value' => $cart['first_name']],
                            ['name' => __('Products', 'mailoptin'), 'value' => implode(', ', $names)],
                            ['name' => __('Total', 'mailoptin'), 'value' => $cart['total'] . ' ' . $cart['currency']],
                            ['name' => __('Status', 'mailoptin'), 'value' => $cart['status']],
                            ['name' => __('Order', 'mailoptin'), 'value' => $cart['order_id'] ?: ''],
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
