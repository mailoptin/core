<?php

namespace MailOptin\Core\Admin;

/**
 * The first screen of a list that has nothing in it yet: what the screen is for and how to start,
 * instead of one line inside an empty table.
 */
class EmptyState
{
    /**
     * @param array $args
     *   - icon:    optin, email, broadcast or '' (none)
     *   - title:   heading
     *   - text:    one or two sentences
     *   - cards:   [['label', 'sub', 'url', 'pro' => bool, 'shape' => lightbox|slidein|bar|inpost|sidebar]]
     *   - buttons: [['label', 'url', 'primary' => bool, 'external' => bool]]
     *   - note:    extra line under the buttons, already-escaped HTML
     *
     * @return string
     */
    public static function render($args)
    {
        $args = wp_parse_args($args, ['icon' => '', 'title' => '', 'text' => '', 'cards' => [], 'buttons' => [], 'note' => '']);

        $html = '<div class="mo-ui-empty">';
        $html .= self::icon($args['icon']);
        $html .= '<h2>' . esc_html($args['title']) . '</h2>';
        if ('' !== $args['text']) $html .= '<p>' . esc_html($args['text']) . '</p>';

        if ( ! empty($args['cards'])) {
            $html .= '<div class="mo-ui-empty-cards">';
            foreach ($args['cards'] as $card) {
                $shape = sanitize_html_class($card['shape'] ?? '');
                $html  .= sprintf(
                    '<a class="mo-ui-empty-card" href="%s">%s<span class="mo-ui-empty-card-label">%s%s</span>%s</a>',
                    esc_url($card['url']),
                    '' !== $shape ? '<span class="mo-ui-empty-shape mo-ui-shape-' . $shape . '" aria-hidden="true"></span>' : '',
                    esc_html($card['label']),
                    ! empty($card['pro']) ? ' ' . UI::pro_pill() : '',
                    ! empty($card['sub']) ? '<span class="mo-ui-empty-card-sub">' . esc_html($card['sub']) . '</span>' : ''
                );
            }
            $html .= '</div>';
        }

        if ( ! empty($args['buttons'])) {
            $html .= '<p class="mo-ui-empty-actions">';
            foreach ($args['buttons'] as $button) {
                $attrs = ! empty($button['external']) ? ['target' => '_blank'] : [];
                $html  .= UI::button($button['label'], $button['url'], ! empty($button['primary']) ? 'primary' : '', $attrs) . ' ';
            }
            $html .= '</p>';
        }

        if ('' !== $args['note']) $html .= '<p class="mo-ui-empty-note">' . wp_kses_post($args['note']) . '</p>';

        return $html . '</div>';
    }

    protected static function icon($name)
    {
        $icons = [
            'optin'     => '<rect x="6" y="6" width="84" height="60" rx="6" fill="none" stroke="currentColor" opacity=".35" stroke-width="2"/><rect x="26" y="20" width="44" height="32" rx="4" fill="#2271b1" opacity=".15" stroke="#2271b1" stroke-width="2"/><rect x="33" y="38" width="30" height="7" rx="2" fill="#2271b1"/>',
            'email'     => '<rect x="10" y="14" width="76" height="48" rx="5" fill="none" stroke="currentColor" opacity=".35" stroke-width="2"/><path d="M10 18l38 26 38-26" fill="none" stroke="#2271b1" stroke-width="2"/><circle cx="76" cy="16" r="9" fill="#2271b1"/><path d="M72 16h8M76 12v8" stroke="#fff" stroke-width="2"/>',
            'broadcast' => '<path d="M14 30v12l44 16V14z" fill="#2271b1" opacity=".15" stroke="#2271b1" stroke-width="2"/><path d="M58 22c8 3 8 25 0 28" fill="none" stroke="currentColor" opacity=".35" stroke-width="2"/><path d="M66 16c14 6 14 34 0 40" fill="none" stroke="currentColor" opacity=".25" stroke-width="2"/>',
        ];

        if ( ! isset($icons[$name])) return '';

        return '<svg class="mo-ui-empty-icon" viewBox="0 0 96 72" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';
    }
}
