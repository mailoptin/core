<?php

namespace MailOptin\Core\OptinForms\Wheel;

use MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_Custom_Content;
use MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_Toggle_Control;
use MailOptin\Core\OptinForms\AbstractWheelTheme;
use MailOptin\Core\OptinForms\Messages;
use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;

/**
 * Spin to win: a prize wheel. The visitor enters their email; once the subscription has gone through, the
 * site (never the browser) draws a prize by the chances set for each slice, records the spin, and sends the
 * prize back in the same response. mailoptin.js then turns the wheel to that slice and shows the prize.
 *
 * Prizes work on any site: a code typed in (any shop or platform), text with a link (a guide, a download),
 * or no prize; on WooCommerce sites also a coupon from the shop (a discount or free shipping) or a free
 * product, which can go straight into the cart. The prize is emailed too.
 *
 * Free: the wheel, every prize type, the Split and Stacked pop-ups and the in-post wheel. Premium adds the
 * Edge pop-up and the Corner slide-in, a single-use code of their own for each winner (the
 * "mailoptin_wheel_prize_code" filter), and the spins and prizes on the Statistics page.
 */
class Wheel
{
    const OBJECTIVE = 'spin_to_win';
    const SECTION = 'mo_wheel_section';

    /** What a wheel has no use for: its "after conversion" is the prize. */
    const HIDDEN_SECTIONS = ['mo_success_section'];

    const TYPES = ['coupon', 'product', 'code', 'link', 'none'];

    const MIN_SLICES = 2;
    const MAX_SLICES = 12;

    public function __construct()
    {
        add_action('admin_init', [Spins::class, 'maybe_install']);
        add_filter('mo_drop_database_tables', function ($sql) {
            $sql[] = 'DROP TABLE IF EXISTS ' . Spins::table();

            return $sql;
        });
        add_filter('mo_drop_mu_database_tables', function ($tables) {
            $tables[] = Spins::table();

            return $tables;
        });

        add_filter('mo_optin_customizer_sections_ids', [$this, 'sections'], 20);
        add_action('mo_optin_before_design_customizer_section', [$this, 'section'], 10, 2);
        add_action('mo_optin_customizer_settings', [$this, 'settings'], 10, 3);
        add_action('mo_optin_after_customizer_controls', [$this, 'controls'], 10, 4);
        add_action('customize_controls_enqueue_scripts', [$this, 'customizer_scripts']);

        add_filter('mo_optin_js_config', [$this, 'js_config'], 10, 2);
        add_filter('mailoptin_optin_subscription_response', [$this, 'respond'], 10, 3);
        add_action('mailoptin_ajax_wheel_apply', [$this, 'apply_to_cart']);

        add_filter('mo_optin_table_column', [$this, 'list_value'], 10, 3);
        add_filter('mailoptin_twostep_supported', [$this, 'no_two_steps'], 10, 2);

        // A won code used in a paid order: for "used" and revenue on Premium's Statistics page.
        add_action('woocommerce_order_status_processing', [$this, 'record_use']);
        add_action('woocommerce_order_status_completed', [$this, 'record_use']);
    }

    // -- Which campaigns are wheels -- //

    public static function is_campaign($optin_campaign_id)
    {
        static $cache = [];

        $id = absint($optin_campaign_id);
        if ( ! $id) return false;

        if ( ! isset($cache[$id])) {
            $class      = Messages::template_class($id);
            $cache[$id] = $class && class_exists($class) && is_subclass_of($class, AbstractWheelTheme::class);
        }

        return $cache[$id];
    }

    public static function on($value)
    {
        return Messages::on($value);
    }

    /** A wheel_* value: saved, else the template's default. */
    public static function value($optin_campaign_id, $key)
    {
        $class    = Messages::template_class($optin_campaign_id);
        $defaults = $class && class_exists($class) && is_subclass_of($class, AbstractWheelTheme::class) ? $class::wheel_defaults() : self::defaults();

        return OCR::get_customizer_value($optin_campaign_id, $key, $defaults[$key] ?? '');
    }

    public static function woocommerce()
    {
        return class_exists('WooCommerce') && function_exists('wc_get_coupon_code_by_id');
    }

    public static function premium()
    {
        return defined('MAILOPTIN_DETACH_LIBSODIUM');
    }

    // -- Slices -- //

    /**
     * The wheel every new campaign starts with: prizes that work on any site (typed codes and a link),
     * for the owner to change to their own.
     */
    public static function default_slices()
    {
        $p = self::presets()['garden']['slices'];

        return [
            ['id' => 's1', 'label' => __('10% off', 'mailoptin'), 'type' => 'code', 'code' => 'SPIN10', 'chance' => 25, 'color' => $p[0][0], 'ink' => $p[0][1]],
            ['id' => 's2', 'label' => __('Free shipping', 'mailoptin'), 'type' => 'code', 'code' => 'FREESHIP', 'chance' => 20, 'color' => $p[1][0], 'ink' => $p[1][1]],
            ['id' => 's3', 'label' => __('5% off', 'mailoptin'), 'type' => 'code', 'code' => 'SPIN5', 'chance' => 30, 'color' => $p[2][0], 'ink' => $p[2][1]],
            ['id' => 's4', 'label' => __('No luck', 'mailoptin'), 'type' => 'none', 'chance' => 10, 'color' => $p[3][0], 'ink' => $p[3][1]],
            ['id' => 's5', 'label' => __('15% off', 'mailoptin'), 'type' => 'code', 'code' => 'SPIN15', 'chance' => 10, 'color' => $p[4][0], 'ink' => $p[4][1]],
            ['id' => 's6', 'label' => __('Free guide', 'mailoptin'), 'type' => 'link', 'title' => __('Our free guide', 'mailoptin'), 'url' => home_url('/'), 'button' => __('Download', 'mailoptin'), 'chance' => 5, 'color' => $p[5][0], 'ink' => $p[5][1]],
        ];
    }

    /** The five colour presets: the template's colours and the slices' (repeated past six slices). */
    public static function presets()
    {
        return [
            'garden'  => [
                'label' => __('Garden', 'mailoptin'),
                'colors' => ['wheel_side_bg' => '#f3ead8', 'form_background_color' => '#fbf8f2', 'headline_font_color' => '#1f2a24', 'description_font_color' => '#3d4741', 'submit_button_background' => '#2f6b4f', 'submit_button_color' => '#ffffff', 'wheel_eyebrow_color' => '#c8643b', 'wheel_ring_color' => '#1f2a24', 'wheel_heading_font' => 'serif'],
                'slices' => [['#2f6b4f', '#ffffff'], ['#f3ead8', '#1f2a24'], ['#c8643b', '#ffffff'], ['#fbf8f2', '#6b756f'], ['#21503a', '#ffffff'], ['#f6e3d9', '#1f2a24']],
            ],
            'bold'    => [
                'label' => __('Bold', 'mailoptin'),
                'colors' => ['wheel_side_bg' => '#fde68a', 'form_background_color' => '#ffffff', 'headline_font_color' => '#111827', 'description_font_color' => '#374151', 'submit_button_background' => '#111827', 'submit_button_color' => '#ffffff', 'wheel_eyebrow_color' => '#dc2626', 'wheel_ring_color' => '#111827', 'wheel_heading_font' => 'sans'],
                'slices' => [['#1d4ed8', '#ffffff'], ['#facc15', '#111827'], ['#dc2626', '#ffffff'], ['#ffffff', '#6b7280'], ['#16a34a', '#ffffff'], ['#f97316', '#ffffff']],
            ],
            'minimal' => [
                'label' => __('Minimal', 'mailoptin'),
                'colors' => ['wheel_side_bg' => '#f4f4f5', 'form_background_color' => '#ffffff', 'headline_font_color' => '#18181b', 'description_font_color' => '#52525b', 'submit_button_background' => '#18181b', 'submit_button_color' => '#ffffff', 'wheel_eyebrow_color' => '#0f766e', 'wheel_ring_color' => '#18181b', 'wheel_heading_font' => 'sans'],
                'slices' => [['#0f766e', '#ffffff'], ['#ffffff', '#27272a'], ['#e4e4e7', '#27272a'], ['#fafafa', '#71717a'], ['#27272a', '#ffffff'], ['#f4f4f5', '#27272a']],
            ],
            'night'   => [
                'label' => __('Night', 'mailoptin'),
                'colors' => ['wheel_side_bg' => '#0b1220', 'form_background_color' => '#111827', 'headline_font_color' => '#f9fafb', 'description_font_color' => '#d1d5db', 'submit_button_background' => '#f59e0b', 'submit_button_color' => '#1f1300', 'wheel_eyebrow_color' => '#2dd4bf', 'wheel_ring_color' => '#f9fafb', 'wheel_heading_font' => 'sans'],
                'slices' => [['#14b8a6', '#04201d'], ['#1f2937', '#e5e7eb'], ['#f59e0b', '#1f1300'], ['#111827', '#9ca3af'], ['#38bdf8', '#04131c'], ['#374151', '#e5e7eb']],
            ],
            'holiday' => [
                'label' => __('Holiday', 'mailoptin'),
                'colors' => ['wheel_side_bg' => '#fef2f2', 'form_background_color' => '#fffbeb', 'headline_font_color' => '#450a0a', 'description_font_color' => '#57534e', 'submit_button_background' => '#b91c1c', 'submit_button_color' => '#ffffff', 'wheel_eyebrow_color' => '#166534', 'wheel_ring_color' => '#450a0a', 'wheel_heading_font' => 'serif'],
                'slices' => [['#b91c1c', '#ffffff'], ['#fef2f2', '#7f1d1d'], ['#166534', '#ffffff'], ['#fffbeb', '#78716c'], ['#7f1d1d', '#ffffff'], ['#ecfccb', '#14532d']],
            ],
        ];
    }

    /** Settings every wheel has, before a template's own defaults. */
    public static function defaults()
    {
        return [
            'wheel_slices'       => wp_json_encode(self::default_slices()),
            'wheel_preset'       => 'garden',
            'wheel_eyebrow'      => __('Spin to win', 'mailoptin'),
            'wheel_show_odds'    => true,
            'wheel_once'         => true,
            'wheel_cart'         => true,
            'wheel_email'        => true,
            'wheel_win_label'    => __('You won', 'mailoptin'),
            'wheel_lose_title'   => __('No prize this time', 'mailoptin'),
            'wheel_lose_text'    => __('You\'re on the list, so you\'ll be first to hear about the next offer.', 'mailoptin'),
            'wheel_no_thanks'    => __('No thanks', 'mailoptin'),
            'wheel_terms_url'    => '',
            'wheel_side_bg'      => '#f3ead8',
            'wheel_eyebrow_color' => '#c8643b',
            'wheel_ring_color'   => '#1f2a24',
            'wheel_heading_font' => 'serif',
        ];
    }

    /** Clean slices from the Customizer: known types, numbers in range, no markup. */
    public static function sanitize_slices($value)
    {
        $list = is_array($value) ? $value : json_decode((string)$value, true);
        if ( ! is_array($list)) return wp_json_encode(self::default_slices());

        $text  = function ($v, $max = 100) { return mb_substr(trim(sanitize_text_field((string)$v)), 0, $max); };
        $out   = [];
        $seen  = [];

        foreach (array_slice(array_values($list), 0, self::MAX_SLICES) as $i => $s) {
            if ( ! is_array($s)) continue;

            $id = preg_replace('/[^a-z0-9]/', '', strtolower((string)($s['id'] ?? '')));
            if ('' === $id || isset($seen[$id])) $id = 's' . substr(md5(wp_json_encode($s) . $i), 0, 8);
            $seen[$id] = true;

            $type = in_array($s['type'] ?? '', self::TYPES, true) ? $s['type'] : 'none';

            $slice = [
                'id'     => substr($id, 0, 20),
                'label'  => $text($s['label'] ?? '', 40) ?: __('Prize', 'mailoptin'),
                'type'   => $type,
                'chance' => max(0, min(100, (int)($s['chance'] ?? 0))),
                'color'  => sanitize_hex_color((string)($s['color'] ?? '')) ?: '#2f6b4f',
                'ink'    => sanitize_hex_color((string)($s['ink'] ?? '')) ?: '#ffffff',
            ];

            if (in_array($type, ['coupon', 'product'], true)) $slice['coupon'] = absint($s['coupon'] ?? 0);
            if ('product' === $type) $slice['product'] = absint($s['product'] ?? 0);
            if (in_array($type, ['coupon', 'product'], true)) $slice['unique'] = self::on($s['unique'] ?? false);
            if ('code' === $type) $slice['code'] = $text($s['code'] ?? '', 60);
            if (in_array($type, ['code', 'link'], true)) $slice['url'] = esc_url_raw((string)($s['url'] ?? ''));
            if ('link' === $type) {
                $slice['title']  = $text($s['title'] ?? '', 120);
                $slice['text']   = $text($s['text'] ?? '', 300);
                $slice['button'] = $text($s['button'] ?? '', 40);
            }

            $out[] = $slice;
        }

        if (count($out) < self::MIN_SLICES) return wp_json_encode(self::default_slices());

        return wp_json_encode($out);
    }

    /** @return array[] The campaign's slices, clean. */
    public static function slices($optin_campaign_id)
    {
        return json_decode(self::sanitize_slices(self::value($optin_campaign_id, 'wheel_slices')), true);
    }

    /** Whether a slice's prize can be given on this site now (a shop coupon needs WooCommerce and the coupon). */
    public static function can_give($slice)
    {
        switch ($slice['type']) {
            case 'coupon':
            case 'product':
                if ( ! self::woocommerce() || ! ($slice['coupon'] ?? 0)) return false;
                if ('' === (string)wc_get_coupon_code_by_id($slice['coupon'])) return false;

                return 'coupon' === $slice['type'] || (($slice['product'] ?? 0) && function_exists('wc_get_product') && wc_get_product($slice['product']));
            case 'code':
                return '' !== (string)($slice['code'] ?? '');
            case 'link':
                return '' !== (string)($slice['url'] ?? '');
        }

        return true;
    }

    /**
     * "9 in 10 spins win a prize", from the chances, so it's always true. '' when nothing can be won.
     */
    public static function odds_line($slices)
    {
        $total = array_sum(array_column($slices, 'chance'));
        if ($total <= 0) return '';

        $win = 0;
        foreach ($slices as $s) {
            if ('none' !== $s['type']) $win += $s['chance'];
        }

        if ($win <= 0) return '';
        if ($win >= $total) return __('Every spin wins a prize.', 'mailoptin');

        $in_ten = (int)floor(10 * $win / $total);
        if ($in_ten >= 1) {
            return sprintf(_n('%d in 10 spins wins a prize.', '%d in 10 spins win a prize.', $in_ten, 'mailoptin'), $in_ten);
        }

        return sprintf(__('%s of spins win a prize.', 'mailoptin'), number_format_i18n(100 * $win / $total, 0) . '%');
    }

    /**
     * Draws a slice by the chances: a slice with 25 of a total of 100 comes up a quarter of the time.
     * Slices whose prize can't be given on this site (a coupon that was deleted) are left out.
     *
     * @return int|null The slice's index, or null when no slice can come up.
     */
    public static function draw($slices)
    {
        $pool = [];
        foreach ($slices as $i => $s) {
            if ($s['chance'] > 0 && self::can_give($s)) $pool[$i] = (int)$s['chance'];
        }

        $total = array_sum($pool);
        if ($total <= 0) return null;

        $roll = random_int(1, $total);
        foreach ($pool as $i => $chance) {
            if (($roll -= $chance) <= 0) return $i;
        }

        return array_key_last($pool);
    }

    // -- The spin -- //

    /**
     * After a successful subscription on a wheel: the draw, the prize and the spin's record, sent back with
     * the response for mailoptin.js to show.
     */
    public function respond($response, $optin_campaign_id, $payload)
    {
        if ( ! is_array($response) || true !== ($response['success'] ?? false) || ! self::is_campaign($optin_campaign_id)) return $response;

        $email = isset($payload['email']) && is_string($payload['email']) ? sanitize_email($payload['email']) : '';
        if ('' === $email) return $response;

        $response['wheel'] = self::spin($optin_campaign_id, $email);

        return $response;
    }

    /** @return array What mailoptin.js needs to show the result. */
    public static function spin($optin_campaign_id, $email)
    {
        $slices = self::slices($optin_campaign_id);

        // One spin per address: the same address again gets its prize emailed again, not shown here, so
        // typing someone else's address reveals nothing.
        if (self::on(self::value($optin_campaign_id, 'wheel_once'))) {
            $previous = Spins::find($optin_campaign_id, $email);
            if ($previous) {
                $resent = false;
                if ('none' !== $previous->prize_type && (empty($previous->emailed_at) || strtotime($previous->emailed_at . ' UTC') < time() - DAY_IN_SECONDS)) {
                    $index = self::index_of($slices, $previous->slice_id);
                    $slice = null !== $index ? $slices[$index] : ['label' => $previous->slice_label, 'type' => $previous->prize_type];
                    $resent = self::email_prize($optin_campaign_id, $email, $slice, $previous->code);
                    if ($resent) Spins::update($previous->id, ['emailed_at' => current_time('mysql', true)]);
                }

                return [
                    'already' => true,
                    'message' => 'none' === $previous->prize_type
                        ? __('This email address has already had its spin.', 'mailoptin')
                        : ($resent ? __('This email address has already spun the wheel. We\'ve sent its prize to it again.', 'mailoptin') : __('This email address has already spun the wheel, and its prize was sent to it.', 'mailoptin')),
                ];
            }
        }

        $index = self::draw($slices);
        if (null === $index) {
            return ['already' => false, 'slice' => null, 'type' => 'none', 'title' => (string)self::value($optin_campaign_id, 'wheel_lose_title'), 'text' => (string)self::value($optin_campaign_id, 'wheel_lose_text')];
        }

        $slice = $slices[$index];
        $code  = self::prize_code($optin_campaign_id, $slice, $email);
        $token = bin2hex(random_bytes(16));
        $id    = Spins::add($optin_campaign_id, $email, $slice, $code, $token);

        do_action('mailoptin_wheel_spun', $optin_campaign_id, $slice, $id);

        $result = [
            'already' => false,
            'slice'   => $index,
            'type'    => $slice['type'],
            'label'   => $slice['label'],
        ];

        if ('none' === $slice['type']) {
            $result['title'] = (string)self::value($optin_campaign_id, 'wheel_lose_title');
            $result['text']  = (string)self::value($optin_campaign_id, 'wheel_lose_text');

            return $result;
        }

        $result['win_label'] = (string)self::value($optin_campaign_id, 'wheel_win_label');

        switch ($slice['type']) {
            case 'coupon':
            case 'product':
                $result['code'] = $code;
                if ('product' === $slice['type'] && function_exists('wc_get_product') && ($product = wc_get_product($slice['product']))) {
                    $result['text'] = sprintf(__('%s, free with your code.', 'mailoptin'), wp_strip_all_tags($product->get_name()));
                }
                // The cart is filled by a second, short request from the shop's page (wheel_apply), where
                // WooCommerce's cart and session are loaded.
                if (self::on(self::value($optin_campaign_id, 'wheel_cart'))) $result['cart_token'] = $token;
                break;
            case 'code':
                $result['code'] = $code;
                if ( ! empty($slice['url'])) {
                    $result['url']    = $slice['url'];
                    $result['button'] = __('Use it now', 'mailoptin');
                }
                break;
            case 'link':
                $result['title']  = $slice['title'] ?: $slice['label'];
                $result['text']   = $slice['text'] ?? '';
                $result['url']    = $slice['url'];
                $result['button'] = $slice['button'] ?: __('Open', 'mailoptin');
                break;
        }

        if (self::on(self::value($optin_campaign_id, 'wheel_email')) && $id) {
            $result['emailed'] = self::email_prize($optin_campaign_id, $email, $slice, $code);
            if ($result['emailed']) Spins::update($id, ['emailed_at' => current_time('mysql', true)]);
        }

        return $result;
    }

    protected static function index_of($slices, $slice_id)
    {
        foreach ($slices as $i => $s) {
            if ($s['id'] === $slice_id) return $i;
        }

        return null;
    }

    /**
     * The code a winner gets: a typed code as typed; a shop coupon's code. Premium can give each winner
     * one of their own (a single-use copy of the coupon, only for their address).
     */
    public static function prize_code($optin_campaign_id, $slice, $email)
    {
        $code = '';
        if ('code' === $slice['type']) $code = (string)$slice['code'];
        if (in_array($slice['type'], ['coupon', 'product'], true) && self::woocommerce()) $code = (string)wc_get_coupon_code_by_id($slice['coupon']);

        return (string)apply_filters('mailoptin_wheel_prize_code', $code, $slice, $email, $optin_campaign_id);
    }

    /** The prize by email, in WooCommerce's email design where the shop has it. */
    public static function email_prize($optin_campaign_id, $email, $slice, $code)
    {
        if ('none' === ($slice['type'] ?? 'none')) return false;

        $site    = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf(__('Your prize from %1$s: %2$s', 'mailoptin'), $site, $slice['label']);
        $heading = sprintf(__('You won: %s', 'mailoptin'), $slice['label']);

        $body = '';
        if ('link' === $slice['type']) {
            $title = ! empty($slice['title']) ? $slice['title'] : $slice['label'];
            $body  .= '<p><strong>' . esc_html($title) . '</strong></p>';
            if ( ! empty($slice['text'])) $body .= '<p>' . esc_html($slice['text']) . '</p>';
            $body .= '<p><a href="' . esc_url($slice['url']) . '">' . esc_html(($slice['button'] ?? '') ?: __('Open', 'mailoptin')) . '</a></p>';
        } elseif ('' !== (string)$code) {
            $body .= '<p>' . esc_html__('Your code:', 'mailoptin') . '</p>';
            $body .= '<p style="font-size:22px;font-weight:700;letter-spacing:.05em;border:2px dashed #999;display:inline-block;padding:8px 14px">' . esc_html($code) . '</p>';
            if ('code' === $slice['type'] && ! empty($slice['url'])) {
                $body .= '<p><a href="' . esc_url($slice['url']) . '">' . esc_html__('Use it now', 'mailoptin') . '</a></p>';
            } elseif (in_array($slice['type'], ['coupon', 'product'], true)) {
                $body .= '<p>' . esc_html__('Enter it at checkout.', 'mailoptin') . ' <a href="' . esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/')) . '">' . esc_html__('Go to the shop', 'mailoptin') . '</a></p>';
            }
        }

        $body = (string)apply_filters('mailoptin_wheel_email_body', $body, $slice, $code, $optin_campaign_id);

        if (self::woocommerce() && function_exists('WC') && WC()->mailer()) {
            $message = WC()->mailer()->wrap_message($heading, $body);
            $message = (new \WC_Email())->style_inline($message);
        } else {
            $message = '<html><body style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;font-size:15px;line-height:1.5;color:#1f2933;max-width:560px;margin:0 auto;padding:24px">'
                       . '<h2 style="margin:0 0 16px">' . esc_html($heading) . '</h2>' . $body . '</body></html>';
        }

        return (bool)wp_mail($email, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
    }

    /**
     * Puts a won shop coupon (and a free product) in the cart: asked for by mailoptin.js, from the shop's
     * own page, right after the spin. The token is the spin's, good once, for an hour.
     */
    public function apply_to_cart()
    {
        $token = isset($_REQUEST['token']) ? sanitize_key(wp_unslash($_REQUEST['token'])) : '';
        $spin  = Spins::by_token($token);

        if ( ! $spin || $spin->applied || ! in_array($spin->prize_type, ['coupon', 'product'], true) || strtotime($spin->created_at . ' UTC') < time() - HOUR_IN_SECONDS) {
            wp_send_json_error(null, 400);
        }

        if ( ! self::woocommerce() || ! function_exists('WC')) wp_send_json_error(null, 400);

        if (function_exists('wc_load_cart')) wc_load_cart();
        if ( ! WC()->cart) wp_send_json_error(null, 400);
        if (WC()->session && ! WC()->session->has_session()) WC()->session->set_customer_session_cookie(true);

        Spins::update($spin->id, ['applied' => 1]);

        if ('product' === $spin->prize_type) {
            $slices = self::slices($spin->optin_id);
            $index  = self::index_of($slices, $spin->slice_id);
            if (null !== $index && ! empty($slices[$index]['product'])) WC()->cart->add_to_cart($slices[$index]['product'], 1);
        }

        $applied = WC()->cart->has_discount($spin->code) || WC()->cart->apply_coupon($spin->code);
        // WooCommerce's own notices would show on the next page as well.
        wc_clear_notices();
        WC()->cart->calculate_totals();

        wp_send_json_success(['applied' => (bool)$applied]);
    }

    /** A paid order using a won code, by the winner's address: the spin's "used", and the order's total. */
    public function record_use($order_id)
    {
        if ((int)get_option(Spins::DB_OPTION, 0) < 1 || ! function_exists('wc_get_order')) return;

        $order = wc_get_order($order_id);
        if ( ! $order || ! method_exists($order, 'get_coupon_codes')) return;

        $codes = array_filter(array_map('strtolower', (array)$order->get_coupon_codes()));
        $email = (string)$order->get_billing_email();
        if ( ! $codes || '' === $email) return;

        global $wpdb;
        $hash = Spins::email_hash($email);

        foreach ($codes as $code) {
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . Spins::table() . ' SET used_order = %d, used_total = %f WHERE used_order = 0 AND email_hash = %s AND LOWER(code) = %s',
                $order_id,
                (float)$order->get_total(),
                $hash,
                $code
            ));
        }
    }

    // -- Customizer -- //

    protected static function customizer_campaign_id()
    {
        $id = absint($_REQUEST['mailoptin_optin_campaign_id'] ?? 0);

        return $id && self::is_campaign($id) ? $id : 0;
    }

    public function sections($sections)
    {
        if ( ! self::customizer_campaign_id()) return $sections;

        $sections[] = self::SECTION;

        return array_values(array_diff($sections, (array)apply_filters('mailoptin_wheel_hidden_sections', self::HIDDEN_SECTIONS)));
    }

    public function section($wp_customize, $customizer)
    {
        if ( ! isset($customizer->optin_campaign_id) || ! self::is_campaign($customizer->optin_campaign_id)) return;

        $wp_customize->add_section(self::SECTION, [
            'title'    => __('Prize wheel', 'mailoptin'),
            'priority' => 1,
        ]);
    }

    protected static function setting_args()
    {
        $text  = function ($v) { return sanitize_text_field((string)$v); };
        $bool  = function ($v) { return self::on($v); };
        $color = function ($v) { return sanitize_hex_color((string)$v) ?: ''; };

        return [
            'wheel_slices'        => [self::class, 'sanitize_slices'],
            'wheel_preset'        => function ($v) { return array_key_exists($v, self::presets()) ? $v : 'garden'; },
            'wheel_eyebrow'       => $text,
            'wheel_show_odds'     => $bool,
            'wheel_once'          => $bool,
            'wheel_cart'          => $bool,
            'wheel_email'         => $bool,
            'wheel_win_label'     => $text,
            'wheel_lose_title'    => $text,
            'wheel_lose_text'     => $text,
            'wheel_no_thanks'     => $text,
            'wheel_terms_url'     => 'esc_url_raw',
            'wheel_side_bg'       => $color,
            'wheel_eyebrow_color' => $color,
            'wheel_ring_color'    => $color,
            'wheel_heading_font'  => function ($v) { return 'sans' === $v ? 'sans' : 'serif'; },
        ];
    }

    public function settings($wp_customize, $option_prefix, $customizer)
    {
        $id = isset($customizer->optin_campaign_id) ? $customizer->optin_campaign_id : 0;
        if ( ! self::is_campaign($id)) return;

        $class    = Messages::template_class($id);
        $defaults = $class::wheel_defaults();

        foreach (self::setting_args() as $key => $sanitize) {
            $wp_customize->add_setting($option_prefix . '[' . $key . ']', [
                'default'           => $defaults[$key] ?? '',
                'type'              => 'option',
                'transport'         => 'refresh',
                'sanitize_callback' => $sanitize,
            ]);
        }
    }

    public function controls($instance, $wp_customize, $option_prefix, $customizer)
    {
        $campaign_id = isset($customizer->optin_campaign_id) ? $customizer->optin_campaign_id : 0;
        if ( ! self::is_campaign($campaign_id)) return;

        $id       = function ($key) use ($option_prefix) { return $option_prefix . '[' . $key . ']'; };
        $priority = 0;
        $add      = function ($key, $args, $section = self::SECTION) use ($wp_customize, $id, &$priority) {
            $wp_customize->add_control($id('wheel_control_' . $key), $args + ['section' => $section, 'settings' => $id($key), 'priority' => $priority += 5]);
        };
        $toggle   = function ($key, $label, $description = '') use ($wp_customize, $id, &$priority) {
            $wp_customize->add_control(new WP_Customize_Toggle_Control($wp_customize, $id('wheel_control_' . $key), [
                'label'       => $label,
                'description' => $description,
                'section'     => self::SECTION,
                'settings'    => $id($key),
                'type'        => 'light',
                'priority'    => $priority += 5,
            ]));
        };

        $wp_customize->add_control(new WheelSlicesControl($wp_customize, $id('wheel_control_slices'), [
            'label'       => __('Slices', 'mailoptin'),
            'description' => __('Each slice is a prize and its chance of coming up. The site draws the prize, and the wheel lands on it, so these chances are what visitors get.', 'mailoptin'),
            'section'     => self::SECTION,
            'settings'    => $id('wheel_slices'),
            'priority'    => $priority += 5,
            'campaign_id' => $campaign_id,
        ]));

        $toggle('wheel_once', __('One spin per email address', 'mailoptin'), __('Someone spinning again with the same address gets their prize emailed again, not shown, so nobody sees another person\'s prize.', 'mailoptin'));
        if (self::woocommerce()) $toggle('wheel_cart', __('Put a won coupon in their cart', 'mailoptin'));
        $toggle('wheel_email', __('Email them their prize', 'mailoptin'));
        $toggle('wheel_show_odds', __('Show the odds', 'mailoptin'), __('A line like "9 in 10 spins win a prize", worked out from the chances.', 'mailoptin'));

        $add('wheel_eyebrow', ['type' => 'text', 'label' => __('Small text above the title', 'mailoptin'), 'description' => __('Leave empty for none.', 'mailoptin')]);
        $add('wheel_win_label', ['type' => 'text', 'label' => __('Above a prize', 'mailoptin')]);
        $add('wheel_lose_title', ['type' => 'text', 'label' => __('Title when there\'s no prize', 'mailoptin')]);
        $add('wheel_lose_text', ['type' => 'text', 'label' => __('Text when there\'s no prize', 'mailoptin')]);
        $add('wheel_no_thanks', ['type' => 'text', 'label' => __('"No thanks" link', 'mailoptin'), 'description' => __('Closes it. Leave empty for none.', 'mailoptin')]);
        $add('wheel_terms_url', ['type' => 'url', 'label' => __('Prize terms (optional)', 'mailoptin'), 'description' => __('A page with the prize rules, linked under the form.', 'mailoptin')]);

        $wp_customize->add_control(new WP_Customize_Custom_Content($wp_customize, $id('wheel_control_results'), [
            'section'  => self::SECTION,
            'settings' => [],
            'priority' => 200,
            'content'  => self::results_html($campaign_id),
        ]));

        // Design: a preset, then the colours it sets.
        $wp_customize->add_control($id('wheel_control_preset'), [
            'type'        => 'select',
            'label'       => __('Start from a preset', 'mailoptin'),
            'description' => __('Sets the wheel\'s and the form\'s colours and the title font. Everything stays editable afterwards.', 'mailoptin'),
            'section'     => 'mo_design_section',
            'settings'    => $id('wheel_preset'),
            'choices'     => array_map(function ($p) { return $p['label']; }, self::presets()),
            'priority'    => 1,
        ]);

        $priority = 50;
        foreach (['wheel_side_bg' => __('Wheel background', 'mailoptin'), 'wheel_ring_color' => __('Wheel rim and pointer', 'mailoptin'), 'wheel_eyebrow_color' => __('Small text colour', 'mailoptin')] as $key => $label) {
            $wp_customize->add_control(new \WP_Customize_Color_Control($wp_customize, $id('wheel_design_' . $key), [
                'label'    => $label,
                'section'  => 'mo_design_section',
                'settings' => $id($key),
                'priority' => $priority += 5,
            ]));
        }
        $wp_customize->add_control($id('wheel_design_heading_font'), [
            'type'     => 'radio',
            'label'    => __('Title font', 'mailoptin'),
            'section'  => 'mo_design_section',
            'settings' => $id('wheel_heading_font'),
            'choices'  => ['serif' => __('Serif (Georgia)', 'mailoptin'), 'sans' => __('Sans-serif', 'mailoptin')],
            'priority' => $priority += 5,
        ]);
    }

    /** "So far" for the Customizer, and on free sites what Premium's Statistics page adds. */
    protected static function results_html($campaign_id)
    {
        $c    = Spins::counts($campaign_id);
        $line = $c['spins']
            ? sprintf(__('So far: %1$s spins, %2$s won a prize.', 'mailoptin'), number_format_i18n($c['spins']), number_format_i18n($c['won']))
            : __('No spins yet.', 'mailoptin');

        $html = '<div class="mo-wheel-box"><strong>' . esc_html($line) . '</strong>';

        if (self::premium()) {
            $url  = admin_url('admin.php?page=' . (defined('MAILOPTIN_ADVANCE_ANALYTICS_SETTINGS_SLUG') ? MAILOPTIN_ADVANCE_ANALYTICS_SETTINGS_SLUG : 'mailoptin-statistics') . '&optin=' . absint($campaign_id));
            $html .= '<p><a href="' . esc_url($url) . '" target="_blank">' . esc_html__('Which prizes were used, and what they brought in: Statistics', 'mailoptin') . '</a></p>';
        } else {
            $html .= '<p>' . esc_html__('See which prizes were used in an order and what each brought in, on the Statistics page.', 'mailoptin') . ' <span class="mo-wheel-pill">' . esc_html__('Premium', 'mailoptin') . '</span></p>'
                     . '<p><a href="' . esc_url(self::upgrade_url('wheel_stats')) . '" target="_blank">' . esc_html__('See what Premium adds', 'mailoptin') . '</a></p>';
        }

        return $html . '</div>';
    }

    public static function upgrade_url($campaign)
    {
        return 'https://mailoptin.io/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=' . rawurlencode($campaign);
    }

    public function customizer_scripts()
    {
        if ( ! self::customizer_campaign_id()) return;

        wp_enqueue_script('mailoptin-wheel-customizer', MAILOPTIN_ASSETS_URL . 'js/admin/wheel-customizer.js', ['jquery', 'customize-controls'], defined('MAILOPTIN_VERSION_NUMBER') ? MAILOPTIN_VERSION_NUMBER : false, true);
        wp_localize_script('mailoptin-wheel-customizer', 'moWheel', [
            'presets' => self::presets(),
            'prefix'  => 'mo_optin_campaign[' . self::customizer_campaign_id() . ']',
        ]);
    }

    // -- Front end -- //

    public function js_config($data, $form)
    {
        $id = isset($form->optin_campaign_id) ? $form->optin_campaign_id : 0;

        if ($id && self::is_campaign($id)) $data['is_wheel'] = true;

        return $data;
    }

    /** The Optin Campaigns list: a wheel's conversions, as spins and wins. */
    public function list_value($value, $item, $column)
    {
        $id = absint($item['id'] ?? 0);
        if ('conversion' !== $column || ! self::is_campaign($id)) return $value;

        static $totals = null;
        if (null === $totals) {
            $totals = Spins::totals(array_filter(array_map(function ($c) { return absint($c['id'] ?? 0); }, (array)OCR::get_optin_campaigns())));
        }

        $t = $totals[$id] ?? ['spins' => 0, 'won' => 0];

        return esc_html(sprintf(_n('%s spin', '%s spins', $t['spins'], 'mailoptin'), number_format_i18n($t['spins'])))
               . '<br><span class="description">' . esc_html(sprintf(__('%s won a prize', 'mailoptin'), number_format_i18n($t['won']))) . '</span>';
    }

    /** Two steps and a wheel don't mix: the wheel is its own first step. */
    public function no_two_steps($supported, $optin_campaign_id)
    {
        return $supported && ! self::is_campaign($optin_campaign_id);
    }

    public static function get_instance()
    {
        static $instance = null;

        if (null === $instance) $instance = new self();

        return $instance;
    }
}
