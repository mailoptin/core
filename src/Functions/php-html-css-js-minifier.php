<?php


/**
 * ----------------------------------------------------------------------------------------
 * Based on `https://github.com/mecha-cms/mecha-cms/blob/master/engine/plug/converter.php`
 * ----------------------------------------------------------------------------------------
 */


// Helper function(s) ...

define('X', "\x1A"); // a placeholder character

/**
 * Regex pieces for strings, CSS/JS comments and HTML comments.
 *
 * These used to be file-level variables read with `global`. Composer loads this file from inside a function,
 * so they were never globals and every minifier silently got empty patterns. A function works however the
 * file is loaded.
 *
 * @param string $name SS, CC or CH
 *
 * @return string
 */
function _mo_minify_pattern($name)
{
    static $patterns = [
        'SS' => '"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'',
        'CC' => '\/\*[\s\S]*?\*\/',
        'CH' => '<\!--[\s\S]*?-->',
    ];

    return $patterns[$name];
}

function __minify_x($input) {
    return str_replace(array("\n", "\t", ' '), array(X . '\n', X . '\t', X . '\s'), $input);
}

function __minify_v($input) {
    return str_replace(array(X . '\n', X . '\t', X . '\s'), array("\n", "\t", ' '), $input);
}


/**
 * =======================================================
 *  HTML MINIFIER
 * =======================================================
 * -- CODE: ----------------------------------------------
 *
 *    echo mo_minify_html(file_get_contents('test.html'));
 *
 * -------------------------------------------------------
 */

function mo_minify_html($input) {
    if( ! $input = trim($input)) return $input;
    // Keep these whole: pre, code, script, style and textarea, and IE conditional comments.
    $kept = array(_mo_minify_pattern('CH'));
    foreach (array('pre', 'code', 'script', 'style', 'textarea') as $tag) {
        $kept[] = '<' . $tag . '(?:>|\s[^<>]*?>)[\s\S]*?<\/' . $tag . '>';
    }
    // Odd indexes are the kept chunks, even ones the text and tags between them.
    $chunks = preg_split('#(' . implode('|', $kept) . ')#i', $input, -1, PREG_SPLIT_DELIM_CAPTURE);
    $output = '';
    foreach ($chunks as $i => $v) {
        if ($i % 2 === 1) {
            // Plain comments go; IE conditional comments stay.
            if (strpos($v, '<!--') === 0 && substr($v, -12) !== '<![endif]-->') continue;
            $output .= $v;
            continue;
        }
        // Collapse runs of white-space in text the way a browser renders them; tags and their attributes
        // stay as written. A run with a line break keeps one line break, so text styled with
        // `white-space: pre-line` still breaks where it did.
        $output .= preg_replace_callback('#(<[^<>]*>)|\s+#', function ($m) {
            if ( ! empty($m[1])) return $m[1];

            return strpbrk($m[0], "\r\n") !== false ? "\n" : ' ';
        }, $v);
    }

    return $output;
}


/**
 * =======================================================
 *  CSS MINIFIER
 * =======================================================
 * -- CODE: ----------------------------------------------
 *
 *    echo mo_minify_css(file_get_contents('test.css'));
 *
 * -------------------------------------------------------
 */

/**
 * Minify CSS outside strings and comments.
 *
 * Only white-space that can never matter is removed, and nothing is rewritten. The original rules also
 * shortened values, which broke real CSS: `screen and (max-width: 480px)` became `and(` (an invalid media
 * query), `0%` keyframes became `0`, `#0ff` became `#0`, `0s` became an invalid `0`,
 * `:not(.a) .b` lost its space and changed meaning, and `transform-origin: 0 0` became `0`.
 *
 * @param string $input
 *
 * @return string
 */
function _mo_minify_css($input) {
    return preg_replace(
        array(
            // Runs of white-space become one space.
            '#\s+#',
            // No space around `{`, `}`, `;` and `,`, or after `:`. A space before `:` is kept: in a selector,
            // `a :hover` and `a:hover` differ.
            '#\s*([{};,])\s*#',
            '#:\s+#',
            // No `;` before `}`.
            '#;+}#',
        ),
        array(' ', '$1', ':', '}'),
        $input);
}

function mo_minify_css($input) {
    if( ! $input = trim($input)) return $input;
    $SS = _mo_minify_pattern('SS');
    $CC = _mo_minify_pattern('CC');
    // Create chunk(s) of string(s), comment(s) and text
    $input = preg_split('#(' . $SS . '|' . $CC . ')#', $input, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
    $output = "";
    foreach($input as $v) {
        if(
            ($v[0] === '"' && substr($v, -1) === '"') ||
            ($v[0] === "'" && substr($v, -1) === "'") ||
            (strpos($v, '/*') === 0 && substr($v, -2) === '*/')
        ) {
            // Remove if not detected as important comment ...
            if($v[0] === '/' && strpos($v, '/*!') !== 0) continue;
            $output .= $v; // String or comment ...
        } else {
            $output .= _mo_minify_css($v);
        }
    }

    return trim($output);
}


/**
 * =======================================================
 *  JAVASCRIPT MINIFIER
 * =======================================================
 * -- CODE: ----------------------------------------------
 *
 *    echo mo_minify_js(file_get_contents('test.js'));
 *
 * -------------------------------------------------------
 */

function _minify_js($input) {
    return preg_replace(
        array(
            // Remove inline comment(s) [^1]
            '#\s*\/\/.*$#m',
            // Remove white-space(s) around punctuation(s) [^2]
            '#\s*([!%&*\(\)\-=+\[\]\{\}|;:,.<>?\/])\s*#',
            // Remove the last semi-colon and comma [^3]
            '#[;,]([\]\}])#',
            // Replace `true` with `!0` and `false` with `!1` [^4]
            '#\btrue\b#', '#\bfalse\b#', '#\breturn\s+#'
        ),
        array(
            // [^1]
            "",
            // [^2]
            '$1',
            // [^3]
            '$1',
            // [^4]
            '!0', '!1', 'return '
        ),
        $input);
}

function mo_minify_js($input) {
    if( ! $input = trim($input)) return $input;
    // Create chunk(s) of string(s), comment(s), regex(es) and text
    $SS = _mo_minify_pattern('SS');
    $CC = _mo_minify_pattern('CC');
    $input = preg_split('#(' . $SS . '|' . $CC . '|\/[^\n]+?\/(?=[.,;]|[gimuy]|$))#', $input, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
    $output = "";
    foreach($input as $v) {
        if(trim($v) === "") continue;
        if(
            ($v[0] === '"' && substr($v, -1) === '"') ||
            ($v[0] === "'" && substr($v, -1) === "'") ||
            ($v[0] === '/' && substr($v, -1) === '/')
        ) {
            // Remove if not detected as important comment ...
            if(strpos($v, '//') === 0 || (strpos($v, '/*') === 0 && strpos($v, '/*!') !== 0 && strpos($v, '/*@cc_on') !== 0)) continue;
            $output .= $v; // String, comment or regex ...
        } else {
            $output .= _minify_js($v);
        }
    }
    return preg_replace(
        array(
            // Minify object attribute(s) except JSON attribute(s). From `{'foo':'bar'}` to `{foo:'bar'}` [^1]
            '#(' . $CC . ')|([\{,])([\'])(\d+|[a-z_]\w*)\3(?=:)#i',
            // From `foo['bar']` to `foo.bar` [^2]
            '#([\w\)\]])\[([\'"])([a-z_]\w*)\2\]#i'
        ),
        array(
            // [^1]
            '$1$2$4',
            // [^2]
            '$1.$3'
        ),
        $output);
}