<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\Logging\CampaignLogRepository;
use MailOptin\Core\Repositories\AbstractCampaignLogMeta;
use MailOptin\Core\Repositories\CustomFieldsRepository;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository;

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
        add_filter('mo_send_queue_skip_reason', [$this, 'skip_reason'], 10, 3);
    }

    /**
     * Things change between queueing and sending: skip subscribers who were deleted, unsubscribed
     * or left the campaign's list in the meantime.
     */
    public function skip_reason($reason, $row, $campaign_log_id)
    {
        if ('' !== (string)$reason || self::RECIPIENT_TYPE !== ($row['recipient_type'] ?? '')) return $reason;

        $subscriber = Subscribers::get($row['recipient_id']);

        if ( ! $subscriber) return __('The subscriber was deleted.', 'mailoptin');

        if (Subscribers::STATUS_SUBSCRIBED !== $subscriber['status']) {
            return __('The subscriber is no longer subscribed.', 'mailoptin');
        }

        $list_id = self::campaign_content($campaign_log_id)['list_id'];

        if ($list_id) {
            $membership = Subscribers::get_membership($subscriber['id'], $list_id);

            if ( ! $membership || Subscribers::STATUS_SUBSCRIBED !== $membership['status']) {
                return __('The subscriber left this list.', 'mailoptin');
            }
        }

        return $reason;
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

        $list = $campaign['list_id'] ? ListsRepository::get($campaign['list_id']) : null;

        $tags = [
            '{{list.name}}'             => $list['name'] ?? '',
            '{{unsubscribe}}'           => Tokens::unsubscribe_url($subscriber, $campaign['list_id'], $campaign_log_id),
            '{{preferences}}'           => PreferenceCentre::url($subscriber),
            '{{webversion}}'            => Tokens::web_version_url($campaign_log_id),
            '{{subscriber.email}}'      => $subscriber['email'],
            '{{subscriber.first_name}}' => $subscriber['first_name'],
            '{{subscriber.last_name}}'  => $subscriber['last_name'],
        ];

        $fields = TagsRepository::is_ready() ? CustomFieldsRepository::merge_values($subscriber['id']) : [];

        return [
            'subject' => self::replace_tags(self::replace_fallback_tags($campaign['subject'], $subscriber, false, $fields), $tags, false),
            'html'    => self::replace_tags(self::replace_fallback_tags(PreferenceCentre::add_link($campaign['html'], true), $subscriber, true, $fields), $tags, true),
            'text'    => self::replace_tags(self::replace_fallback_tags(PreferenceCentre::add_link($campaign['text'], false), $subscriber, false, $fields), $tags, false),
        ];
    }

    /**
     * Subscriber merge tags: {{subscriber.first_name}}, {{subscriber.email}} and any custom field
     * by its merge key, like {{subscriber.company}}. Add a fallback for when the subscriber has no
     * value: {{subscriber.first_name|there}}. Unknown tags with a fallback show the fallback; without
     * one they're left as they are, for connections that add their own.
     *
     * @param string $content
     * @param array $subscriber subscriber row; custom field values are looked up by its "id"
     * @param bool $escape_html
     * @param array|null $field_values merge key => value, instead of looking them up
     */
    public static function replace_fallback_tags($content, $subscriber, $escape_html, $field_values = null)
    {
        $content = (string)$content;

        if (false === strpos($content, '{{subscriber.')) return $content;

        $builtin = ['email', 'first_name', 'last_name'];

        return preg_replace_callback('/\{\{subscriber\.([a-z][a-z0-9_]*)(?:\|([^}]*))?\}\}/', function ($match) use ($subscriber, $escape_html, $builtin, &$field_values) {
            $key = $match[1];

            if (in_array($key, $builtin, true)) {
                $value = (string)($subscriber[$key] ?? '');
            } else {
                if (null === $field_values) {
                    $field_values = TagsRepository::is_ready() ? CustomFieldsRepository::merge_values(absint($subscriber['id'] ?? 0)) : [];
                }

                // Not ours, unless it has a fallback: then it's a field that was deleted or misspelled.
                if ( ! array_key_exists($key, $field_values)) {
                    if ( ! isset($match[2])) return $match[0];

                    return $escape_html ? esc_html($match[2]) : $match[2];
                }

                $value = (string)$field_values[$key];
            }

            if ('' === trim($value)) $value = $match[2] ?? '';

            return $escape_html ? esc_html($value) : $value;
        }, $content);
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
