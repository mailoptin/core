<?php

namespace MailOptin\Core\Sending;

use MailOptin\Core\Core;

/**
 * Settings › Email log: every email the site sent through MailOptin's sending service while it sends
 * the site's emails. Who it went to, the subject, which plugin sent it and what happened, for 30 days.
 * Never the content.
 */
class EmailLog
{
    const KEEP_DAYS = 30;

    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . Core::email_log_table_name;
    }

    /**
     * @param array $entry to (string[]), subject, source, sender, status (sent, failed or fallback),
     *                     error, message_id, as SiteMailer reports them
     */
    public static function add(array $entry)
    {
        global $wpdb;

        $wpdb->insert(self::table(), [
            'sent_at'    => current_time('mysql', true),
            'recipients' => implode(', ', array_map('sanitize_email', (array)($entry['to'] ?? []))),
            'subject'    => mb_substr(wp_strip_all_tags((string)($entry['subject'] ?? '')), 0, 255),
            'source'     => mb_substr(sanitize_text_field((string)($entry['source'] ?? '')), 0, 100),
            'sender'     => sanitize_key((string)($entry['sender'] ?? '')),
            'status'     => in_array($entry['status'] ?? '', ['sent', 'failed', 'fallback'], true) ? $entry['status'] : 'failed',
            'error'      => mb_substr((string)($entry['error'] ?? ''), 0, 2000),
            'message_id' => mb_substr((string)($entry['message_id'] ?? ''), 0, 255),
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
    }

    /** Entries older than KEEP_DAYS go, in batches so a big log can't hold the table long. */
    public static function purge()
    {
        global $wpdb;

        $before = gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS);

        for ($i = 0; $i < 20; $i++) {
            $deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE sent_at < %s LIMIT 5000', $before));
            if ( ! $deleted || $deleted < 5000) break;
        }
    }

    public static function delete_all()
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . self::table());
    }

    /**
     * @param string $view all or failed
     *
     * @return array rows, newest first
     */
    public static function rows($view = 'all', $search = '', $page = 1, $per_page = 30)
    {
        global $wpdb;

        list($where, $args) = self::where($view, $search);
        $args[] = $per_page;
        $args[] = max(0, ($page - 1) * $per_page);

        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", $args), ARRAY_A);
    }

    public static function count($view = 'all', $search = '')
    {
        global $wpdb;

        list($where, $args) = self::where($view, $search);

        $sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE $where";

        return (int)$wpdb->get_var($args ? $wpdb->prepare($sql, $args) : $sql);
    }

    protected static function where($view, $search)
    {
        global $wpdb;

        $where = ['1=1'];
        $args  = [];

        // Failed includes "fallback": the service refused, though WordPress may have sent it.
        if ('failed' === $view) $where[] = "status IN ('failed','fallback')";

        $search = trim((string)$search);
        if ('' !== $search) {
            $like    = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(recipients LIKE %s OR subject LIKE %s)';
            $args[]  = $like;
            $args[]  = $like;
        }

        return [implode(' AND ', $where), $args];
    }

    /**
     * What a log entry's source looks like on screen: the plugin's or theme's name.
     *
     * @param string $source e.g. woocommerce, theme:astra, mu:custom-mailer or wordpress
     */
    public static function source_label($source)
    {
        static $plugins = null;

        $source = (string)$source;

        if ('' === $source || 'wordpress' === $source) return __('WordPress', 'mailoptin');

        if (0 === strpos($source, 'theme:')) {
            $theme = wp_get_theme(substr($source, 6));

            return $theme->exists() ? sprintf(__('%s (theme)', 'mailoptin'), $theme->get('Name')) : $source;
        }

        if (0 === strpos($source, 'mu:')) return substr($source, 3);

        if (null === $plugins) {
            if ( ! function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $plugins = [];
            foreach (get_plugins() as $file => $data) {
                $plugins[explode('/', $file)[0]] = $data['Name'];
            }
        }

        return $plugins[$source] ?? $source;
    }
}
