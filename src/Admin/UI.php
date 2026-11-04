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

    /**
     * The page or box shown in place of a Premium feature: what it does, a preview of it, and a way to
     * get it. Replaces the old blurred full-page screenshot.
     *
     * @param array $args {
     *     @type string   $title     Heading, e.g. "Send to exactly the right people with segments".
     *     @type string   $intro     One or two sentences.
     *     @type string[] $bullets   What you can do with it.
     *     @type string   $cta_url   Pricing link.
     *     @type string   $learn_url Optional docs link.
     *     @type string   $learn_label
     *     @type string   $preview   Optional HTML for the right-hand preview; built by the caller from escaped parts.
     *     @type string   $footnote  Defaults to the "everything else stays free" line. Empty string hides it.
     * }
     */
    public static function locked_feature($args)
    {
        $args = wp_parse_args($args, [
            'title'       => '',
            'intro'       => '',
            'bullets'     => [],
            'cta_url'     => 'https://mailoptin.io/pricing/',
            'learn_url'   => '',
            'learn_label' => __('Learn more', 'mailoptin'),
            'preview'     => '',
            'footnote'    => __('Everything else, including unlimited lists, subscribers, tags and custom fields, stays free.', 'mailoptin'),
        ]);

        $bullets = '';
        foreach ((array)$args['bullets'] as $bullet) {
            $bullets .= '<li>' . esc_html($bullet) . '</li>';
        }

        $html = '<div class="mo-ui-card mo-ui-locked' . ('' === $args['preview'] ? ' mo-ui-locked-solo' : '') . '">';
        $html .= '<div class="mo-ui-locked-text">';
        $html .= self::pro_pill(__('MailOptin Premium', 'mailoptin'));
        $html .= '<h2>' . esc_html($args['title']) . '</h2>';
        $html .= '' !== $args['intro'] ? '<p class="mo-ui-locked-intro">' . esc_html($args['intro']) . '</p>' : '';
        $html .= '' !== $bullets ? '<ul>' . $bullets . '</ul>' : '';
        $html .= '<div class="mo-ui-locked-actions">' . self::button(__('See Premium plans', 'mailoptin'), $args['cta_url'], 'primary', ['target' => '_blank']);
        $html .= '' !== $args['learn_url'] ? self::button($args['learn_label'], $args['learn_url'], 'ghost', ['target' => '_blank']) : '';
        $html .= '</div>';
        $html .= '' !== $args['footnote'] ? '<p class="mo-ui-locked-foot">' . esc_html($args['footnote']) . '</p>' : '';
        $html .= '</div>';

        if ('' !== $args['preview']) {
            $html .= '<div class="mo-ui-preview" aria-hidden="true"><div class="mo-ui-preview-bar"><i></i><i></i><i></i></div><div class="mo-ui-preview-in">' . $args['preview'] . '</div></div>';
        }

        return $html . '</div>';
    }

    /**
     * Rows for a locked-feature preview table. Every cell is escaped; pass a badge as ['badge' => label, 'tone' => tone].
     *
     * @param array $rows list of rows, each a list of cells
     */
    public static function preview_rows($rows)
    {
        $html = '<table class="mo-ui-preview-table">';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . (is_array($cell) ? self::badge($cell['badge'], $cell['tone'] ?? '') : esc_html($cell)) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</table>';
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
