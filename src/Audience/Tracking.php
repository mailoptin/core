<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\Repositories\CampaignActivityRepository as Activity;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Open and click tracking for campaigns sent to built-in lists.
 *
 * Each email gets an invisible image and its links go through this site first. Both carry the
 * send queue row's ID and a signature, so they can't be guessed or pointed anywhere else: a click
 * link only leads to an address saved for that campaign. Links keep working when tracking is
 * turned off later; they just stop counting.
 */
class Tracking
{
    const OPENS_SETTING = 'tracking_opens';
    const CLICKS_SETTING = 'tracking_clicks';
    const RETENTION_SETTING = 'tracking_retention_days';

    const DEFAULT_RETENTION_DAYS = 365;

    const PRUNE_HOOK = 'mo_tracking_prune';

    public function __construct()
    {
        add_action('init', [$this, 'handle_request'], 5);
        add_action('init', [$this, 'schedule_prune']);
        add_action(self::PRUNE_HOOK, [$this, 'prune']);
    }

    protected static function setting($key, $default)
    {
        $settings = (array)get_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, []);

        return $settings[$key] ?? $default;
    }

    public static function tracks_opens()
    {
        return Activity::is_ready() && 'off' !== self::setting(self::OPENS_SETTING, 'on');
    }

    public static function tracks_clicks()
    {
        return Activity::is_ready() && 'off' !== self::setting(self::CLICKS_SETTING, 'on');
    }

    /**
     * @return int days; 0 keeps activity forever
     */
    public static function retention_days()
    {
        return absint(self::setting(self::RETENTION_SETTING, self::DEFAULT_RETENTION_DAYS));
    }

    /* ---------------------------------------------------------------------------------------------
     * Links in emails
     * ------------------------------------------------------------------------------------------ */

    protected static function signature($queue_id, $link_id)
    {
        // Short, since it goes in every link; 64 bits is plenty to stop guessing.
        return substr(Tokens::make('track', [absint($queue_id), absint($link_id)]), 0, 16);
    }

    public static function open_url($queue_id)
    {
        return add_query_arg('mo_open', absint($queue_id) . '.' . self::signature($queue_id, 0), home_url('/'));
    }

    public static function click_url($queue_id, $link_id)
    {
        return add_query_arg('mo_click', absint($queue_id) . '.' . absint($link_id) . '.' . self::signature($queue_id, $link_id), home_url('/'));
    }

    /**
     * Whether a link should go through click tracking. Merge tag links, like {{unsubscribe}}, are
     * replaced later and never tracked; neither are links back to our own subscriber pages.
     */
    public static function is_trackable($url)
    {
        $url = trim($url);

        if ('' === $url || false !== strpos($url, '{{') || false !== strpos($url, '{@')) return false;

        if ( ! preg_match('#^https?://#i', $url)) return false;

        $query = (string)wp_parse_url($url, PHP_URL_QUERY);

        return ! preg_match('/(^|&)(mo_unsub|mo_prefs|mo_web|mo_confirm|mo_click|mo_open)=/', $query);
    }

    /**
     * Rewrite an email's links to click links and add the open image, for one recipient.
     *
     * @param string $html campaign HTML, before merge tags are replaced
     * @param array $row the send queue row
     */
    public static function prepare_html($html, $row, $campaign_log_id)
    {
        if (empty($row['id'])) return $html;

        if (self::tracks_clicks()) {
            $html = preg_replace_callback('#<a\s[^>]*>.*?</a>#is', function ($match) use ($row, $campaign_log_id) {
                return preg_replace_callback('#(\bhref\s*=\s*)(["\'])(.*?)\2#is', function ($href) use ($match, $row, $campaign_log_id) {
                    $url = html_entity_decode($href[3], ENT_QUOTES, 'UTF-8');

                    if ( ! self::is_trackable($url)) return $href[0];

                    $label = trim(wp_strip_all_tags($match[0]));

                    if ('' === $label && preg_match('#\balt\s*=\s*(["\'])(.*?)\1#is', $match[0], $alt)) $label = html_entity_decode($alt[2], ENT_QUOTES, 'UTF-8');

                    $link_id = Activity::link_id($campaign_log_id, $url, $label);

                    if ( ! $link_id) return $href[0];

                    return $href[1] . $href[2] . esc_url(self::click_url($row['id'], $link_id)) . $href[2];
                }, $match[0], 1);
            }, $html);
        }

        if (self::tracks_opens()) {
            $pixel = sprintf('<img src="%s" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;margin:0;padding:0" />', esc_url(self::open_url($row['id'])));

            $html = false !== stripos($html, '</body>') ? preg_replace('#</body>#i', $pixel . '</body>', $html, 1) : $html . $pixel;
        }

        return $html;
    }

    /* ---------------------------------------------------------------------------------------------
     * Endpoints
     * ------------------------------------------------------------------------------------------ */

    /**
     * The sent queue row a tracking link belongs to, if its signature is right.
     */
    protected static function queue_row($queue_id, $link_id, $signature)
    {
        if ( ! is_string($signature) || ! hash_equals(self::signature($queue_id, $link_id), $signature)) return null;

        $row = SendQueueRepository::get(absint($queue_id));

        if ( ! $row || CampaignRenderer::RECIPIENT_TYPE !== $row['recipient_type'] || empty($row['recipient_id'])) return null;

        return $row;
    }

    public function handle_request()
    {
        if (isset($_GET['mo_open']) && is_string($_GET['mo_open'])) $this->open(sanitize_text_field(wp_unslash($_GET['mo_open'])));

        if (isset($_GET['mo_click']) && is_string($_GET['mo_click'])) $this->click(sanitize_text_field(wp_unslash($_GET['mo_click'])));
    }

    /**
     * Whether to count a hit. Subscribers deleted since keep working links but aren't counted.
     */
    protected static function should_count($row)
    {
        return Activity::is_ready() && 'HEAD' !== strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') && Subscribers::get($row['recipient_id']);
    }

    /**
     * Count an open from an image request.
     *
     * @return bool whether the token was valid
     */
    public static function open_token($token)
    {
        $parts = explode('.', (string)$token);
        $row   = 2 === count($parts) ? self::queue_row($parts[0], 0, $parts[1]) : null;

        if ( ! $row) return false;

        if (self::tracks_opens() && self::should_count($row)) Activity::record_open($row['campaign_log_id'], $row['recipient_id']);

        return true;
    }

    /**
     * Count a click.
     *
     * @return array|null the link to send them on to, or null when the token isn't valid
     */
    public static function click_token($token)
    {
        $parts = explode('.', (string)$token);
        $row   = 3 === count($parts) ? self::queue_row($parts[0], $parts[1], $parts[2]) : null;
        $link  = $row ? Activity::get_link(absint($parts[1])) : null;

        if ( ! $link || (int)$link['campaign_log_id'] !== (int)$row['campaign_log_id']) return null;

        if (self::tracks_clicks() && self::should_count($row)) Activity::record_click($row['campaign_log_id'], $row['recipient_id'], $link);

        return $link;
    }

    protected function open($token)
    {
        self::open_token($token);

        // Always the image, so a bad link reveals nothing.
        nocache_headers();
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }

    protected function click($token)
    {
        $link = self::click_token($token);

        if ( ! $link) {
            wp_die(esc_html__('This link is not valid. It may have been copied incompletely.', 'mailoptin'), esc_html__('Invalid link', 'mailoptin'), ['response' => 404]);
        }

        nocache_headers();
        // The address was saved from the campaign, so this can't be used to send people elsewhere.
        wp_redirect($link['url'], 302);
        exit;
    }

    /* ---------------------------------------------------------------------------------------------
     * Retention
     * ------------------------------------------------------------------------------------------ */

    public function schedule_prune()
    {
        if (Activity::is_ready() && ! wp_next_scheduled(self::PRUNE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK);
        }
    }

    public function prune()
    {
        $days = self::retention_days();

        if ( ! $days || ! Activity::is_ready()) return;

        $before = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

        // In batches, so a first run on a big site doesn't lock the table for long.
        for ($i = 0; $i < 20 && Activity::prune($before) > 0; $i++) {
            continue;
        }
    }

    /**
     * @return Tracking
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}
