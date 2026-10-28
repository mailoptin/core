<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\Logging\CampaignLogRepository;
use MailOptin\Core\Repositories\AbstractCampaignLogMeta;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Personalizes queued campaign emails for built-in list subscribers: unsubscribe and web version
 * links, merge tags and the one-click unsubscribe headers.
 */
class CampaignRenderer
{
    const RECIPIENT_TYPE = 'subscriber';

    const META_HTML = 'mo_audience_content_html';
    const META_TEXT = 'mo_audience_content_text';
    const META_LIST_ID = 'mo_audience_list_id';

    protected static $content_cache = [];

    public function __construct()
    {
        add_filter('mo_send_queue_email_content', [$this, 'personalize'], 10, 3);
        add_filter('mo_send_queue_email_headers', [$this, 'headers'], 10, 3);
        add_action('mo_send_queue_email_sent', [$this, 'log_sent'], 10, 2);
    }

    /**
     * Store the campaign content after the connection's placeholder replacement, before queueing.
     */
    public static function save_campaign_content($campaign_log_id, $list_id, $content_html, $content_text)
    {
        AbstractCampaignLogMeta::update_campaignlog_meta($campaign_log_id, self::META_HTML, $content_html);
        AbstractCampaignLogMeta::update_campaignlog_meta($campaign_log_id, self::META_TEXT, $content_text);
        AbstractCampaignLogMeta::update_campaignlog_meta($campaign_log_id, self::META_LIST_ID, absint($list_id));

        unset(self::$content_cache[$campaign_log_id]);
    }

    /**
     * @return array subject, html, text and list_id
     */
    public static function campaign_content($campaign_log_id)
    {
        if ( ! isset(self::$content_cache[$campaign_log_id])) {
            $repository = CampaignLogRepository::instance();

            $html = AbstractCampaignLogMeta::get_campaignlog_meta($campaign_log_id, self::META_HTML);
            $text = AbstractCampaignLogMeta::get_campaignlog_meta($campaign_log_id, self::META_TEXT);

            self::$content_cache[$campaign_log_id] = [
                'subject' => $repository->retrieveTitle($campaign_log_id),
                'html'    => $html ?: $repository->retrieveContentHtml($campaign_log_id),
                'text'    => $text ?: $repository->retrieveContentText($campaign_log_id),
                'list_id' => absint(AbstractCampaignLogMeta::get_campaignlog_meta($campaign_log_id, self::META_LIST_ID)),
            ];
        }

        return self::$content_cache[$campaign_log_id];
    }

    public static function replace_tags($content, $replacements, $escape_html)
    {
        if ($escape_html) {
            $replacements = array_map('esc_html', $replacements);
        }

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    protected function subscriber_for($row)
    {
        if (self::RECIPIENT_TYPE !== ($row['recipient_type'] ?? '')) return null;

        return Subscribers::get($row['recipient_id']);
    }

    public function personalize($content, $row, $campaign_log_id)
    {
        $subscriber = $this->subscriber_for($row);

        if ( ! $subscriber) return $content;

        $campaign = self::campaign_content($campaign_log_id);

        $tags = [
            '{{unsubscribe}}'           => Tokens::unsubscribe_url($subscriber, $campaign['list_id'], $campaign_log_id),
            '{{webversion}}'            => Tokens::web_version_url($campaign_log_id),
            '{{subscriber.email}}'      => $subscriber['email'],
            '{{subscriber.first_name}}' => $subscriber['first_name'],
            '{{subscriber.last_name}}'  => $subscriber['last_name'],
        ];

        return [
            'subject' => self::replace_tags($campaign['subject'], $tags, false),
            'html'    => self::replace_tags($campaign['html'], $tags, true),
            'text'    => self::replace_tags($campaign['text'], $tags, false),
        ];
    }

    public function headers($headers, $row, $campaign_log_id)
    {
        $subscriber = $this->subscriber_for($row);

        if ( ! $subscriber) return $headers;

        $campaign = self::campaign_content($campaign_log_id);

        $headers[] = sprintf('List-Unsubscribe: <%s>', Tokens::unsubscribe_url($subscriber, $campaign['list_id'], $campaign_log_id));
        $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';

        return $headers;
    }

    public function log_sent($row, $campaign_log_id)
    {
        if (self::RECIPIENT_TYPE !== ($row['recipient_type'] ?? '') || empty($row['recipient_id'])) return;

        Subscribers::log_event($row['recipient_id'], Subscribers::EVENT_SENT, [], $campaign_log_id);
    }

    /**
     * @return CampaignRenderer
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
