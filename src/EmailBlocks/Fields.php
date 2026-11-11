<?php

namespace MailOptin\Core\EmailBlocks;

/**
 * Cleaning a block setting by its field type, on save.
 */
class Fields
{
    public static function sanitize($field, $value)
    {
        switch ($field['type'] ?? 'text') {
            case 'tinymce':
            case 'textarea':
                // Authors who may post unfiltered HTML keep it, as in posts.
                return current_user_can('unfiltered_html') ? (string)$value : wp_kses_post((string)$value);

            case 'checkbox':
                return true === $value || 'true' === $value || 'yes' === $value || '1' === $value || 1 === $value;

            case 'range':
            case 'number':
                if ( ! is_numeric($value)) return '';
                $value = 0 + $value;
                if (isset($field['min'])) $value = max($field['min'], $value);
                if (isset($field['max'])) $value = min($field['max'], $value);

                return (string)$value;

            case 'color_picker':
                return self::color($value);

            case 'dimension':
                $value = is_array($value) ? $value : [];

                return [
                    'top'    => (string)absint($value['top'] ?? 0),
                    'bottom' => (string)absint($value['bottom'] ?? 0),
                    'right'  => (string)absint($value['right'] ?? 0),
                    'left'   => (string)absint($value['left'] ?? 0),
                ];

            case 'select_image':
            case 'url':
                return esc_url_raw((string)$value);

            case 'select':
            case 'font_family':
                $choices = self::flatten_choices($field['choices'] ?? []);

                // No fixed choices (a list searched for, such as the Posts block's posts): kept as
                // given, one value or several.
                if (empty($choices)) {
                    if ( ! empty($field['multiple'])) {
                        return array_values(array_map('sanitize_text_field', array_filter((array)$value, 'is_scalar')));
                    }

                    return is_scalar($value) ? sanitize_text_field((string)$value) : '';
                }

                if ( ! empty($field['multiple']) || is_array($value)) {
                    return array_values(array_intersect(array_map('strval', (array)$value), $choices));
                }

                return in_array((string)$value, $choices, true) ? (string)$value : '';

            default:
                return is_array($value) ? array_map('sanitize_text_field', array_filter($value, 'is_scalar')) : sanitize_text_field((string)$value);
        }
    }

    /**
     * #fff, #ffffff, rgb(), rgba() or transparent, else ''.
     */
    public static function color($value)
    {
        $value = trim((string)$value);

        if ('' === $value) return '';

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value)) return $value;

        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(\d+(\.\d+)?|\.\d+)\s*)?\)$/i', $value)) return $value;

        if ('transparent' === strtolower($value)) return 'transparent';

        return '';
    }

    /**
     * A link for an href, escaped: a whole {{placeholder}} (filled in when sending) is kept as it is;
     * anything else goes through esc_url, which drops unsafe protocols and adds http:// to "www.…".
     */
    public static function link($value)
    {
        $value = trim((string)$value);

        if (preg_match('/^\{\{\s*[a-z][a-z0-9_.]*\s*(\|[^}]*)?\}\}$/i', $value)) return esc_attr($value);

        return esc_url($value);
    }

    /**
     * "10px 0px 10px 0px" from a dimension setting.
     */
    public static function padding($value)
    {
        $value = is_array($value) ? $value : [];

        return sprintf('%dpx %dpx %dpx %dpx', absint($value['top'] ?? 0), absint($value['right'] ?? 0), absint($value['bottom'] ?? 0), absint($value['left'] ?? 0));
    }

    protected static function flatten_choices($choices)
    {
        $keys = [];

        foreach ((array)$choices as $key => $label) {
            if (is_array($label)) {
                $keys = array_merge($keys, array_map('strval', array_keys($label)));
            } else {
                $keys[] = (string)$key;
            }
        }

        return $keys;
    }
}
