<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\PluginSettings\Settings;

/**
 * Logo and colours for the emails built-in lists send to subscribers: the confirmation email and
 * the welcome email. Both use frame() so a new subscriber's first emails look the same.
 */
class EmailBranding
{
    const LOGO_ID = 'email_brand_logo_id';
    const LOGO_WIDTH = 'email_brand_logo_width';
    const LOGO_ALIGN = 'email_brand_logo_align';
    const ACCENT = 'email_brand_accent';
    const BACKGROUND = 'email_brand_background';

    const DEFAULT_ACCENT = '#2271b1';
    const DEFAULT_BACKGROUND = '#f0f0f1';
    const DEFAULT_LOGO_WIDTH = 140;

    /**
     * Read fresh, not from Settings::instance(), so a save earlier in the same request is seen.
     *
     * @return array logo_url, logo_alt, logo_width, logo_align, accent, background
     */
    public static function settings()
    {
        $settings = new Settings();

        $logo_id = absint($settings->{self::LOGO_ID}());
        $width   = absint($settings->{self::LOGO_WIDTH}());

        return [
            'logo_url'   => $logo_id ? (string)wp_get_attachment_image_url($logo_id, 'full') : '',
            'logo_alt'   => $settings->company_name() ?: get_bloginfo('name'),
            'logo_width' => $width ? min(560, max(40, $width)) : self::DEFAULT_LOGO_WIDTH,
            'logo_align' => 'center' === $settings->{self::LOGO_ALIGN}() ? 'center' : 'left',
            'accent'     => sanitize_hex_color($settings->{self::ACCENT}()) ?: self::DEFAULT_ACCENT,
            'background' => sanitize_hex_color($settings->{self::BACKGROUND}()) ?: self::DEFAULT_BACKGROUND,
        ];
    }

    /**
     * A complete email: background, white card, optional logo, the content and a footer.
     * Styles are inline because most email apps ignore <style> blocks.
     *
     * @param string $content_html
     * @param string $footer_html
     * @param array|null $branding defaults to the saved settings
     *
     * @return string
     */
    public static function frame($content_html, $footer_html, $branding = null)
    {
        $b = $branding ?: self::settings();

        $logo = '';
        if ( ! empty($b['logo_url'])) {
            $logo = sprintf(
                '<div style="text-align:%s;margin:0 0 20px"><img src="%s" alt="%s" width="%d" style="width:%dpx;max-width:100%%;height:auto;border:0;display:inline-block"></div>',
                esc_attr($b['logo_align']),
                esc_url($b['logo_url']),
                esc_attr($b['logo_alt']),
                $b['logo_width'],
                $b['logo_width']
            );
        }

        return sprintf(
            '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>' .
            '<body style="margin:0;padding:24px 12px;background:%1$s">' .
            '<div style="max-width:600px;margin:0 auto;background:#ffffff;padding:28px 32px;font:15px/1.6 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1d2327">' .
            '%2$s%3$s<div style="border-top:1px solid #eeeeee;margin-top:24px;padding-top:12px;color:#646970;font-size:12px;line-height:1.5">%4$s</div>' .
            '</div></body></html>',
            esc_attr($b['background']),
            $logo,
            self::color_links($content_html, $b['accent']),
            self::color_links($footer_html, $b['accent'])
        );
    }

    /**
     * Give links without their own style the accent colour.
     */
    public static function color_links($html, $accent)
    {
        return preg_replace('/<a\s(?![^>]*\bstyle=)/i', '<a style="color:' . esc_attr($accent) . '" ', (string)$html);
    }

    public static function button($url, $label, $branding = null)
    {
        $b = $branding ?: self::settings();

        return sprintf(
            '<p><a href="%s" style="display:inline-block;padding:10px 18px;background:%s;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:600">%s</a></p>',
            esc_url($url),
            esc_attr($b['accent']),
            esc_html($label)
        );
    }

    /**
     * A sample welcome email for the settings preview and test send.
     */
    public static function sample_html($branding = null)
    {
        $name = (new Settings())->company_name() ?: get_bloginfo('name');

        $content = sprintf(
            '<p>%s</p><p>%s</p><p>%s <a href="#">%s</a></p>',
            esc_html__('Hi there,', 'mailoptin'),
            esc_html(sprintf(__('Thanks for joining %s. This is how your welcome and confirmation emails will look.', 'mailoptin'), $name)),
            esc_html__('Links use your accent colour:', 'mailoptin'),
            esc_html__('an example link', 'mailoptin')
        );

        $content .= self::button('#', __('Yes, subscribe me', 'mailoptin'), $branding);

        $address = Subscription::postal_footer();
        $footer  = ('' !== $address ? esc_html($address) . '<br>' : '') . esc_html__("You're receiving this because you subscribed.", 'mailoptin') . ' <a href="#">' . esc_html__('Unsubscribe', 'mailoptin') . '</a>';

        return self::frame($content, $footer, $branding);
    }
}
