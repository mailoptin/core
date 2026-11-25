<?php

namespace MailOptin\Core\EmailBlocks;

/**
 * The email editor's block icons: line icons on a 24px grid, drawn with currentColor.
 *
 * A block's palette_icon() may return one of the names below, a Dashicons class ("dashicons-cart"),
 * or SVG path data of its own (for the same 24px grid). Anything else gets the generic block icon.
 */
class Icons
{
    const PATHS = [
        'block'     => 'M4 4h16v16H4zM4 12h16M12 4v16',
        'text'      => 'M5 6h14M12 6v13',
        'image'     => 'M4 5h16v14H4zM20 15l-5-5-9 9M10.5 9.5a1.5 1.5 0 1 1-3 0a1.5 1.5 0 1 1 3 0',
        'button'    => 'M6 8h12a4 4 0 0 1 0 8H6a4 4 0 0 1 0-8zM9 12h6',
        'divider'   => 'M3 12h18M8 7h8M8 17h8',
        'spacer'    => 'M12 4v16M8 7l4-3 4 3M8 17l4 3 4-3',
        'posts'     => 'M5 4h14v16H5zM8 8h8M8 12h8M8 16h5',
        'columns'   => 'M3 5h8v14H3zM13 5h8v14h-8z',
        'imagetext' => 'M3 6h8v12H3zM14 8h7M14 12h7M14 16h5',
        'textimage' => 'M13 6h8v12h-8zM3 8h7M3 12h7M3 16h5',
        'products'  => 'M5 8h14l-1.2 12H6.2zM9 8a3 3 0 0 1 6 0',
        'discount'  => 'M3 12V4h8l10 10-8 8zM9 8.5a1.5 1.5 0 1 1-3 0a1.5 1.5 0 1 1 3 0',
        'cart'      => 'M3 4h2l2.4 11h11l2-8H6.2M10 20a1.4 1.4 0 1 1-2.8 0a1.4 1.4 0 1 1 2.8 0M18 20a1.4 1.4 0 1 1-2.8 0a1.4 1.4 0 1 1 2.8 0',
        'bought'    => 'M5 8h14l-1.2 12H6.2zM9 8a3 3 0 0 1 6 0M9 14l2 2 4-4',
    ];

    /**
     * The icon's markup (decorative: the tile's label says what it is).
     *
     * @param string $icon a name above, a "dashicons-*" class, or SVG path data
     */
    public static function html($icon)
    {
        $icon = trim((string)$icon);

        if (preg_match('/^dashicons-[a-z0-9-]+$/', $icon)) {
            return '<span class="mo-ee-tile-icon dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>';
        }

        if (isset(self::PATHS[$icon])) {
            $path = self::PATHS[$icon];
        } elseif ('' !== $icon && strlen($icon) <= 2000 && preg_match('/^[MmLlHhVvCcSsQqTtAaZz0-9\s.,\-]+$/', $icon)) {
            $path = $icon;
        } else {
            $path = self::PATHS['block'];
        }

        return '<svg class="mo-ee-tile-icon" aria-hidden="true" focusable="false" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="' . esc_attr($path) . '"/></svg>';
    }
}
