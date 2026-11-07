<?php

namespace MailOptin\Core\Admin;

/**
 * Markup helpers for the shared admin design system (src/assets/css/admin/mo-design.css).
 *
 * Every helper returns escaped HTML, so screens can echo the result directly.
 */
class UI
{
    const TONES = ['ok', 'warn', 'bad', 'info'];

    const BUTTON_VARIANTS = ['primary', 'ghost', 'danger'];

    /**
     * A status badge with a coloured dot, e.g. "Subscribed".
     *
     * @param string $label
     * @param string $tone ok, warn, bad, info or empty for neutral grey.
     */
    public static function badge($label, $tone = '')
    {
        return sprintf('<span class="%s">%s</span>', esc_attr(self::classes('mo-ui-badge', $tone, self::TONES)), esc_html($label));
    }

    /**
     * The small purple "Premium" pill shown next to a locked feature.
     */
    public static function pro_pill($label = '')
    {
        return sprintf('<span class="mo-ui-pro">%s</span>', esc_html('' === $label ? __('Premium', 'mailoptin') : $label));
    }

    /**
     * A link styled as a button.
     *
     * @param string $label
     * @param string $url
     * @param string $variant primary, ghost, danger or empty for the plain white button.
     * @param array $attrs Extra attributes, e.g. ['target' => '_blank'].
     */
    public static function button($label, $url, $variant = '', $attrs = [])
    {
        $attrs['href']  = esc_url($url);
        $attrs['class'] = trim(self::classes('mo-ui-btn', $variant, self::BUTTON_VARIANTS) . ' ' . ($attrs['class'] ?? ''));

        if (isset($attrs['target']) && '_blank' === $attrs['target'] && ! isset($attrs['rel'])) {
            $attrs['rel'] = 'noopener';
        }

        return sprintf('<a%s>%s</a>', self::attributes($attrs), esc_html($label));
    }

    /**
     * A filter chip, e.g. "List: Newsletter ×". When $remove_url is given the × removes the filter.
     */
    public static function chip($name, $value, $remove_url = '')
    {
        $html = '<span class="mo-ui-chip"><span>' . esc_html($name) . '</span> <b>' . esc_html($value) . '</b>';

        if ('' !== $remove_url) {
            $html .= sprintf(
                ' <a class="mo-ui-chip-x" href="%s" aria-label="%s">&times;</a>',
                esc_url($remove_url),
                /* translators: %s: filter name, e.g. "List" */
                esc_attr(sprintf(__('Remove %s filter', 'mailoptin'), $name))
            );
        }

        return $html . '</span>';
    }

    /**
     * A notice box inside a page (not a WordPress admin notice).
     *
     * @param string $html Already-escaped HTML content.
     * @param string $tone ok, warn, bad or empty for blue info.
     */
    public static function note($html, $tone = '')
    {
        return sprintf('<div class="%s">%s</div>', esc_attr(self::classes('mo-ui-note', $tone, self::TONES)), wp_kses_post($html));
    }

    private static function classes($base, $modifier, $allowed)
    {
        return in_array($modifier, $allowed, true) ? $base . ' mo-ui-' . $modifier : $base;
    }

    private static function attributes($attrs)
    {
        $out = '';
        foreach ($attrs as $name => $value) {
            $name = preg_replace('/[^a-z0-9_\-:]/i', '', (string)$name);
            if ('' === $name) continue;
            $out .= sprintf(' %s="%s"', $name, 'href' === $name ? $value : esc_attr($value));
        }

        return $out;
    }
}
