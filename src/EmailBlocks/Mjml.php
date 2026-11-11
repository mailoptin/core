<?php

namespace MailOptin\Core\EmailBlocks;

/**
 * The blocks' HTML, compiled from MJML (bin/email-blocks/build.js) into templates in mjml/ with
 * __MO_name__ slots. Filling one in is all that happens when an email is drawn: MJML's tables,
 * Outlook conditionals and widths come as MJML wrote them. Values MJML works out from others (an
 * Outlook width: the container less the padding) are worked out here the same way; the build checks
 * each template against MJML's own output for real values.
 */
class Mjml
{
    protected static $cache = [];

    public static function template($name)
    {
        $name = preg_replace('/[^a-z0-9-]/', '', (string)$name);

        if ( ! isset(self::$cache[$name])) {
            $file = __DIR__ . '/mjml/' . $name . '.html';
            $html = is_readable($file) ? (string)file_get_contents($file) : '';
            // Less the "generated" note.
            self::$cache[$name] = rtrim(preg_replace('/^<!--.*?-->\n/', '', $html), "\n");
        }

        return self::$cache[$name];
    }

    /**
     * A template with its slots filled. Values go in as given: escape them first. strtr() makes one
     * pass, so a value holding "__MO_x__" (or a {{placeholder}}) is not filled again.
     *
     * @param array $values slot name => value
     */
    public static function fill($name, $values)
    {
        $pairs = [];
        foreach ($values as $key => $value) $pairs['__MO_' . $key . '__'] = (string)$value;

        return strtr(self::template($name), $pairs);
    }

    /**
     * Padding as the four slots every template has.
     */
    public static function padding($value)
    {
        $value = is_array($value) ? $value : [];

        return [
            'pad_t' => absint($value['top'] ?? 0),
            'pad_r' => absint($value['right'] ?? 0),
            'pad_b' => absint($value['bottom'] ?? 0),
            'pad_l' => absint($value['left'] ?? 0),
        ];
    }

    /**
     * A width as MJML writes it: no trailing zeros.
     */
    public static function number($value)
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }

    /**
     * What goes in an email's <head> when it has columns: their widths on wider screens. Added after
     * the CSS is inlined, as the inliner drops rules for classes it can't find.
     */
    public static function columns_head()
    {
        return self::template('columns-head');
    }
}
