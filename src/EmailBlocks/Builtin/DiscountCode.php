<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\Automations\Stores\Store;
use MailOptin\Core\EmailBlocks\Mjml;
use MailOptin\Core\EmailBlocks\Style;

/**
 * A discount code: a new single-use one for each person (made as their email is sent, tied to
 * their address), or a coupon the shop already has. Previews and tests show a sample code; no
 * coupon is made for them. Options the shop can't do are left out when the code is made.
 */
class DiscountCode extends ShopBlock
{
    const MODE_NEW = 'new';
    const MODE_EXISTING = 'existing';

    public function id()
    {
        return 'discount_code';
    }

    public function title()
    {
        return __('Discount code', 'mailoptin');
    }

    public function description()
    {
        return __('A single-use code for each person, or a coupon you already have.', 'mailoptin');
    }

    protected function needs()
    {
        return '';
    }

    public function fields()
    {
        return [
                'code_mode'     => ['type' => 'select', 'label' => __('Code', 'mailoptin'), 'choices' => [
                    self::MODE_NEW      => __('A new code for each person (single use, tied to their email address)', 'mailoptin'),
                    self::MODE_EXISTING => __('A coupon you already have (everyone gets the same code)', 'mailoptin'),
                ], 'default' => self::MODE_NEW, 'tab' => 'content'],
                'existing_code' => ['type' => 'text', 'label' => __('Coupon code', 'mailoptin'), 'description' => __('For "a coupon you already have".', 'mailoptin'), 'default' => '', 'tab' => 'content'],
                'discount_type' => ['type' => 'select', 'label' => __('Discount', 'mailoptin'), 'choices' => [
                    'percent'       => __('Percentage off', 'mailoptin'),
                    'fixed'         => __('Fixed amount off', 'mailoptin'),
                    'free_shipping' => __('Free shipping', 'mailoptin'),
                ], 'default' => 'percent', 'tab' => 'content'],
                'amount'        => ['type' => 'number', 'label' => __('Amount', 'mailoptin'), 'min' => 0, 'max' => 100000, 'default' => '10', 'tab' => 'content'],
                'expiry_days'   => ['type' => 'number', 'label' => __('Expires after (days)', 'mailoptin'), 'description' => __('0: never.', 'mailoptin'), 'min' => 0, 'max' => 365, 'default' => '7', 'tab' => 'content'],
                'minimum'       => ['type' => 'number', 'label' => __('Minimum spend', 'mailoptin'), 'description' => __('0: none.', 'mailoptin'), 'min' => 0, 'max' => 1000000, 'default' => '0', 'tab' => 'content'],
                'individual'    => ['type' => 'checkbox', 'label' => __('Not with other coupons', 'mailoptin'), 'default' => true, 'tab' => 'content'],
                'exclude_sale'  => ['type' => 'checkbox', 'label' => __('Exclude sale items', 'mailoptin'), 'default' => false, 'tab' => 'content'],
                'prefix'        => ['type' => 'text', 'label' => __('Code starts with', 'mailoptin'), 'description' => __('Then 6 random letters and digits.', 'mailoptin'), 'default' => 'SAVE', 'tab' => 'content'],
            ] + $this->button_fields(__('Use it now', 'mailoptin'));
    }

    /**
     * "10% off · until 18 November 2026"
     */
    protected function terms($store, $settings, $expires)
    {
        $type   = (string)($settings['discount_type'] ?? 'percent');
        $amount = (float)($settings['amount'] ?? 0);

        if ('free_shipping' === $type) {
            $what = __('Free shipping', 'mailoptin');
        } elseif ('fixed' === $type) {
            /* translators: %s: an amount of money */
            $what = sprintf(__('%s off', 'mailoptin'), $store->money($amount));
        } else {
            /* translators: %s: a percentage */
            $what = sprintf(__('%s%% off', 'mailoptin'), rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.'));
        }

        if ((float)($settings['minimum'] ?? 0) > 0) {
            /* translators: %s: an amount of money */
            $what .= ' · ' . sprintf(__('on orders over %s', 'mailoptin'), $store->money((float)$settings['minimum']));
        }

        /* translators: %s: a date */
        return '' !== $expires ? $what . ' · ' . sprintf(__('until %s', 'mailoptin'), date_i18n(get_option('date_format'), strtotime($expires))) : $what;
    }

    public function render_for($data, $args, Style $style)
    {
        $store    = $this->store($args);
        $settings = array_replace($this->defaults($style->email_campaign_id), (array)($data['settings'] ?? []));
        $mode     = (string)($args['mode'] ?? 'sample');
        $days     = absint($settings['expiry_days'] ?? 0);

        if ( ! $store) return '';

        if (self::MODE_EXISTING === ($settings['code_mode'] ?? '')) {
            $code    = strtoupper(trim((string)($settings['existing_code'] ?? '')));
            $expires = '';
            // An existing coupon's own terms apply: say only what the code is.
            $terms   = '';
            if ('' === $code || ('send' === $mode && ! $store->coupon_exists($code))) return '';
        } elseif ('send' !== $mode) {
            // A preview or a test: a sample code, no coupon made.
            $code    = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($settings['prefix'] ?? ''))) . ('' !== (string)($settings['prefix'] ?? '') ? '-' : '') . 'SAMPLE';
            $expires = $days ? gmdate('Y-m-d', time() + $days * DAY_IN_SECONDS) : '';
            $terms   = $this->terms($store, $settings, $expires);
        } else {
            $run    = (array)($args['run'] ?? []);
            $result = $store->create_coupon([
                'type'           => in_array($settings['discount_type'] ?? '', ['percent', 'fixed', 'free_shipping'], true) ? $settings['discount_type'] : 'percent',
                'amount'         => (float)($settings['amount'] ?? 0),
                'expiry_days'    => $store->supports_coupon(Store::COUPON_EXPIRY) ? $days : 0,
                'minimum'        => $store->supports_coupon(Store::COUPON_MINIMUM) ? (float)($settings['minimum'] ?? 0) : 0,
                'individual_use' => $store->supports_coupon(Store::COUPON_INDIVIDUAL) && ! empty($settings['individual']),
                'exclude_sale'   => $store->supports_coupon(Store::COUPON_EXCLUDE_SALE) && ! empty($settings['exclude_sale']),
                'prefix'         => (string)($settings['prefix'] ?? ''),
                'email'          => $store->supports_coupon(Store::COUPON_PER_PERSON) ? (string)($run['email'] ?? '') : '',
                'automation_id'  => absint($run['automation_id'] ?? 0),
                // One code per person per block: a retried send gives the same one.
                'key'            => 'run' . absint($run['id'] ?? 0) . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($data['id'] ?? '')),
            ]);

            if (is_wp_error($result)) {
                error_log('MailOptin discount code: ' . $result->get_error_message());

                return '';
            }

            $code    = $result['code'];
            $expires = $result['expires'];
            $terms   = $this->terms($store, $settings, $expires);
        }

        $pad = Mjml::padding($settings['block_padding'] ?? []);
        $box = '<div style="text-align:center;"><div style="display:inline-block;border:2px dashed ' . esc_attr($settings['button_background_color'] ?: '#dc4d2f') . ';border-radius:6px;padding:14px 26px;">'
               . '<div style="font-family:Menlo,Consolas,monospace;font-size:24px;letter-spacing:2px;font-weight:bold;">' . esc_html($code) . '</div>'
               . ('' !== $terms ? '<div style="font-size:13px;color:#666666;margin-top:4px;">' . esc_html($terms) . '</div>' : '')
               . '</div></div>';

        return $this->text_row($style, $settings, $box, 15, $pad['pad_t'], 4) . $this->button_row($style, $settings, $store->apply_coupon_url($code));
    }
}
