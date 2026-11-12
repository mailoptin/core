<?php

namespace MailOptin\Core\Admin\EmailEditor;

/**
 * Things worth a look before an email goes: warnings, never a block on saving or sending. Shown in
 * the editor (with a link to the element) and again on a broadcast's page before it's sent.
 */
class Checks
{
    /** Gmail clips emails over about 102 KB ("[Message clipped] View entire message"). */
    const SIZE_LIMIT = 102 * 1024;

    /** Placeholders every email fills in, whatever sends it. */
    const ALWAYS = ['unsubscribe', 'webversion', 'preferences', 'list.name', 'company_name', 'company_address', 'company_address_2', 'company_city', 'company_state', 'company_zip', 'company_country'];

    /**
     * @param array $state the email as the editor holds it (content, settings, code)
     * @param string $html the email drawn (before placeholders are filled in)
     * @param string[] $known placeholder keys this email fills in
     *
     * @return array [['ok' => bool, 'kind' => element|placeholder|subject|unsubscribe|size, 'text' => string, 'block' => id|null], ...] warnings first
     */
    public static function run($state, $html, $known)
    {
        $warn = [];
        $ok   = [];
        $known = array_merge(self::ALWAYS, (array)$known);

        foreach (self::blocks((array)($state['content'] ?? [])) as $block) {
            foreach (self::block_warnings($block) as $text) $warn[] = ['ok' => false, 'kind' => 'element', 'text' => $text, 'block' => (string)($block['id'] ?? '')];
        }

        if (isset($state['code'])) {
            foreach (self::html_warnings((string)$state['code']) as $text) $warn[] = ['ok' => false, 'kind' => 'element', 'text' => $text, 'block' => null];
        }

        // Placeholders this email can't fill in: they'd go out as written.
        $unknown = [];
        foreach (self::texts($state) as $where => $text) {
            if ( ! preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*(?:\|[^}]*)?\}\}/i', $text, $m)) continue;
            foreach ($m[1] as $key) {
                if ( ! in_array($key, $known, true) && ! isset($unknown[$key])) $unknown[$key] = $where;
            }
        }
        foreach ($unknown as $key => $where) {
            $warn[] = ['ok' => false, 'kind' => 'placeholder', 'text' => sprintf(
                /* translators: %s: a placeholder such as {{cart.url}} */
                __('%s isn\'t filled in for this email: it would show as written.', 'mailoptin'),
                '{{' . $key . '}}'
            ), 'block' => is_string($where) && 0 === strpos($where, 'block:') ? substr($where, 6) : null];
        }

        $subject = trim((string)($state['settings']['email_campaign_title'] ?? ''));
        if ('' === $subject) {
            $warn[] = ['ok' => false, 'kind' => 'subject', 'text' => __('There\'s no subject.', 'mailoptin'), 'block' => null];
        } else {
            $ok[] = ['ok' => true, 'kind' => 'subject', 'text' => __('Subject', 'mailoptin'), 'block' => null];
        }

        if (false !== strpos($html, '{{unsubscribe}}') || false !== stripos($html, '[unsubscribe]')) {
            $ok[] = ['ok' => true, 'kind' => 'unsubscribe', 'text' => __('Unsubscribe link', 'mailoptin'), 'block' => null];
        } else {
            $warn[] = ['ok' => false, 'kind' => 'unsubscribe', 'text' => __('There\'s no unsubscribe link. People must be able to leave the list.', 'mailoptin'), 'block' => null];
        }

        $kb = (int)ceil(strlen($html) / 1024);
        if (strlen($html) > self::SIZE_LIMIT) {
            /* translators: %d: size in KB */
            $warn[] = ['ok' => false, 'kind' => 'size', 'text' => sprintf(__('The email is %d KB. Gmail clips emails over about 100 KB: readers must click to see the rest, and opens aren\'t counted.', 'mailoptin'), $kb), 'block' => null];
        } else {
            /* translators: %d: size in KB */
            $ok[] = ['ok' => true, 'kind' => 'size', 'text' => sprintf(__('Size %d KB of about 100 KB', 'mailoptin'), $kb), 'block' => null];
        }

        return array_merge($warn, $ok);
    }

    /** Every block, those in columns included. */
    protected static function blocks($content)
    {
        $all = [];
        foreach ($content as $block) {
            if ( ! is_array($block)) continue;
            $all[] = $block;
            foreach ((array)($block['columns'] ?? []) as $column) {
                foreach ((array)$column as $child) if (is_array($child)) $all[] = $child;
            }
        }

        return $all;
    }

    protected static function block_warnings($block)
    {
        $s    = (array)($block['settings'] ?? []);
        $warn = [];

        // A starter layout's prompt not replaced yet: "[Your headline]" (not a shortcode: no "=").
        $prompt = null;
        array_walk_recursive($s, function ($value) use (&$prompt) {
            if (null === $prompt && is_string($value) && preg_match('/\[(?:[A-Z]|e\.g\.)[^\[\]=]{1,120}\]/u', wp_strip_all_tags($value), $m)) $prompt = $m[0];
        });
        /* translators: %s: the text in brackets, e.g. "[Your headline]" */
        if (null !== $prompt) $warn[] = sprintf(__('Replace "%s": it\'s a prompt from the starter layout.', 'mailoptin'), $prompt);

        switch ($block['type'] ?? '') {
            case 'image':
                if ('' !== trim((string)($s['image_url'] ?? '')) && '' === trim((string)($s['image_alt_text'] ?? ''))) {
                    $warn[] = __('An image has no alt text. People who don\'t load images see nothing there.', 'mailoptin');
                }
                if ('#' === trim((string)($s['image_link'] ?? ''))) $warn[] = __('An image links to "#".', 'mailoptin');
                break;
            case 'button':
                $link = trim((string)($s['button_link'] ?? ''));
                if ('' === $link || '#' === $link) {
                    /* translators: %s: the button's text */
                    $warn[] = sprintf(__('The "%s" button doesn\'t link anywhere.', 'mailoptin'), wp_strip_all_tags((string)($s['button_text'] ?? '')));
                }
                break;
            case 'text':
                $warn = array_merge($warn, self::html_warnings((string)($s['text_content'] ?? '')));
                break;
            case 'discount_code':
                if ('existing' === ($s['code_mode'] ?? '') && '' === trim((string)($s['existing_code'] ?? ''))) {
                    $warn[] = __('The discount code element has no coupon code.', 'mailoptin');
                }
                break;
        }

        return $warn;
    }

    /** Images without alt text and links to nowhere, in HTML written by hand. */
    protected static function html_warnings($html)
    {
        $warn = [];

        // An <img> without alt text that says something (quoted or not; data-alt isn't alt).
        if (preg_match('/<img(?![^>]*(?<![\w-])alt\s*=\s*(?:(["\'])\s*[^"\'\s][^"\']*\1|[^\s"\'>]+))[^>]*>/i', $html)) {
            $warn[] = __('An image has no alt text. People who don\'t load images see nothing there.', 'mailoptin');
        }

        if (preg_match('/<a\b[^>]*\bhref\s*=\s*["\']\s*#?\s*["\']/i', $html)) {
            $warn[] = __('A link goes nowhere ("#" or empty).', 'mailoptin');
        }

        return $warn;
    }

    /**
     * The words an email is made of, by where they are: "subject", "preheader", "code", "block:<id>".
     */
    protected static function texts($state)
    {
        $texts = [
            'subject'   => (string)($state['settings']['email_campaign_title'] ?? ''),
            'preheader' => (string)($state['settings']['email_campaign_preheader'] ?? ''),
        ];

        if (isset($state['code'])) $texts['code'] = (string)$state['code'];

        foreach (self::blocks((array)($state['content'] ?? [])) as $block) {
            $words = [];
            array_walk_recursive($block['settings'], function ($value) use (&$words) {
                if (is_string($value)) $words[] = $value;
            });
            $texts['block:' . ($block['id'] ?? '')] = implode("\n", $words);
        }

        return $texts;
    }
}
