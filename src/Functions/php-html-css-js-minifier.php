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

/**
 * Minify HTML: drop plain comments and collapse runs of white-space in text.
 *
 * Tags and their attributes, pre, code, script, style and textarea, and IE conditional comments stay as written.
 * Markup that asks for preserved white-space (`white-space: pre…` in its styles, or `xml:space`) is returned
 * unchanged. So is anything a pattern fails on, such as an input past PCRE's backtracking limit.
 *
 * @param string $input
 *
 * @return string
 */
function mo_minify_html($input) {
    if( ! $input = trim($input)) return $input;
    if (preg_match('#white-space\s*:\s*(?:pre|break-spaces)|xml:space#i', $input)) return $input;

    $kept = array();
    foreach (array('pre', 'code', 'script', 'style', 'textarea') as $tag) {
        $kept[] = '<' . $tag . '\b[^>]*+>(?:[^<]++|<(?!\/' . $tag . '\s*>))*+<\/' . $tag . '\s*>';
    }
    $pattern = '#(' . implode('|', $kept) . ')' . // 1: kept whole
               '|(<\/?[a-z][^\s>"\'\/]*+(?:"[^"]*+"|\'[^\']*+\'|[^>"\']++)*+>)' . // 2: a tag, quote-aware
               '|(<!--(?:-?>|(?:[^-]++|-(?!->))*+-->))' . // 3: a comment, including <!--> and <!--->
               '|(\s+)#i'; // 4: white-space in text

    $output = preg_replace_callback($pattern, function ($m) {
        if (isset($m[4]) && $m[4] !== '') {
            // A run with a line break keeps one line break; any other run becomes one space.
            return strpbrk($m[4], "\r\n") !== false ? "\n" : ' ';
        }
        if (isset($m[3]) && $m[3] !== '') {
            // IE conditional comments stay, both their openers and closers.
            return preg_match('#\[if\b|\[endif\]#i', $m[3]) ? $m[3] : '';
        }

        return $m[0];
    }, $input);

    return is_string($output) ? $output : $input;
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
 * Minify CSS between strings, comments, escapes and unquoted URLs.
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
            // `a :hover` and `a:hover` differ. A value that is only a space (`--x: ;`) keeps it.
            '#(?<!:)\s*([{};,])\s*#',
            '#:\s+(?![;}])#',
            // No `;` before `}`.
            '#;+}#',
        ),
        array(' ', '$1', ':', '}'),
        $input);
}

function mo_minify_css($input) {
    if( ! $input = trim($input)) return $input;
    // Left as written: strings (a string ends at a line break, as in CSS), a string left open at a line
    // break, comments, escapes such as `\,` or `\31 `, and unquoted url(), which may contain `/*`.
    $pattern = '#("(?:[^"\\\\\n\r\f]++|\\\\[\s\S])*+"|\'(?:[^\'\\\\\n\r\f]++|\\\\[\s\S])*+\')' . // 1: string
               '|((?:"(?:[^"\\\\\n\r\f]++|\\\\[\s\S])*+|\'(?:[^\'\\\\\n\r\f]++|\\\\[\s\S])*+)(?=[\n\r\f]|$))' . // 2: open string
               '|(\/\*(?:[^*]++|\*(?!\/))*+\*\/)' . // 3: comment
               '|(\\\\[0-9a-f]{1,6}\s?|\\\\[\s\S])' . // 4: escape
               '|(\burl\(\s*+[^\'"()\s]*+\s*+\))#i'; // 5: unquoted url()

    $chunks = preg_split($pattern, $input, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    if ( ! is_array($chunks)) return $input;

    $output      = '';
    $keep_break  = false;
    foreach ($chunks as $v) {
        if (preg_match('#^(?:"|\'|\/\*|\\\\|url\()#i', $v) && preg_match($pattern, $v, $m) && $m[0] === $v) {
            if ( ! empty($m[3]) && strpos($v, '/*!') !== 0) continue; // a plain comment goes
            $output     .= $v;
            // The line break that ends an open string has to stay, or the string runs on into what follows.
            $keep_break = ! empty($m[2]);
            continue;
        }
        $min = _mo_minify_css($v);
        if ( ! is_string($min)) return $input;
        if ($keep_break) {
            $min        = "\n" . ltrim($min);
            $keep_break = false;
        }
        $output .= $min;
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