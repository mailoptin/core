<?php

namespace MailOptin\Core\Audience\Sources;

use MailOptin\Core\Audience\CampaignRenderer;
use MailOptin\Core\Audience\PreferenceCentre;
use MailOptin\Core\Audience\Tokens;
use MailOptin\Core\Audience\Tracking;
use MailOptin\Core\Repositories\AudienceConsentsRepository as Consents;
use MailOptin\Core\Repositories\CampaignActivityRepository;
use MailOptin\Core\Repositories\CustomFieldsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\Repositories\TagsRepository;

/**
 * Broadcast emails to people found through a source (customers, users, members). Their queue row
 * is recipient_type "source:<id>", with recipient_id their subscriber id, or 0 when they aren't one.
 *
 * Subscribers get everything a list email has. Others get their name from the source, and an
 * unsubscribe link that puts them on the suppression list; there's no tracking for them.
 */
class ContactRenderer
{
    const TYPE_PREFIX = 'source:';

    public function __construct()
    {
        add_filter('mo_send_queue_email_content', [$this, 'personalize'], 10, 3);
        add_filter('mo_send_queue_email_headers', [$this, 'headers'], 10, 3);
        add_filter('mo_send_queue_skip_reason', [$this, 'skip_reason'], 10, 3);
        add_filter('mo_send_queue_row_subscriber_id', [$this, 'row_subscriber_id'], 10, 2);
        add_action('mo_send_queue_email_sent', [$this, 'log_sent'], 10, 2);
        add_action('init', [$this, 'unsubscribe_request']);
    }

    public static function type($source_id)
    {
        return self::TYPE_PREFIX . $source_id;
    }

    /**
     * @return string the source id, or '' when the row isn't one of these
     */
    public static function source_of($row)
    {
        $type = (string)($row['recipient_type'] ?? '');

        return 0 === strpos($type, self::TYPE_PREFIX) ? substr($type, strlen(self::TYPE_PREFIX)) : '';
    }

    protected static function subscriber($row)
    {
        return empty($row['recipient_id']) ? null : Subscribers::get($row['recipient_id']);
    }

    public function row_subscriber_id($subscriber_id, $row)
    {
        return '' !== self::source_of($row) ? absint($row['recipient_id'] ?? 0) : $subscriber_id;
    }

    /**
     * Since it was queued they may have withdrawn, unsubscribed or been deleted as a subscriber.
     * (Suppressed addresses are skipped by the queue itself.)
     */
    public function skip_reason($reason, $row, $campaign_log_id)
    {
        $source_id = self::source_of($row);

        if ('' !== (string)$reason || '' === $source_id) return $reason;

        $consent = Consents::get($source_id, $row['email']);

        if ($consent && Consents::STATUS_WITHDRAWN === $consent['status']) {
            return __('They withdrew their consent to marketing.', 'mailoptin');
        }

        if ( ! empty($row['recipient_id'])) {
            $subscriber = self::subscriber($row);

            // A subscriber deleted since is still a customer: they just aren't a subscriber any more.
            if ($subscriber && in_array($subscriber['status'], [Subscribers::STATUS_UNSUBSCRIBED, Subscribers::STATUS_BOUNCED, Subscribers::STATUS_COMPLAINED], true)) {
                return __('The subscriber is no longer subscribed.', 'mailoptin');
            }
        }

        return $reason;
    }

    public static function unsubscribe_url($row, $subscriber, $campaign_log_id)
    {
        if ($subscriber) return Tokens::unsubscribe_url($subscriber, 0, $campaign_log_id);

        $email = strtolower(trim($row['email']));

        return add_query_arg([
            'mo_src_unsub' => rawurlencode($email),
            'mo_token'     => Tokens::make('source_unsubscribe', [$email]),
        ], home_url('/'));
    }

    public function personalize($content, $row, $campaign_log_id)
    {
        $source_id = self::source_of($row);

        if ('' === $source_id) return $content;

        $campaign   = CampaignRenderer::campaign_content($campaign_log_id);
        $subscriber = self::subscriber($row);
        $person     = $subscriber ?: array_merge(['id' => 0, 'email' => $row['email']], Sources::person($source_id, $row['email']));
        $fields     = $subscriber && TagsRepository::is_ready() ? CustomFieldsRepository::merge_values($subscriber['id']) : [];

        $tags = [
            '{{list.name}}'             => '',
            '{{unsubscribe}}'           => self::unsubscribe_url($row, $subscriber, $campaign_log_id),
            '{{preferences}}'           => $subscriber ? PreferenceCentre::url($subscriber) : '',
            '{{webversion}}'            => Tokens::web_version_url($campaign_log_id),
            '{{subscriber.email}}'      => $person['email'],
            '{{subscriber.first_name}}' => $person['first_name'],
            '{{subscriber.last_name}}'  => $person['last_name'],
        ];

        $html = $campaign['html'];
        $text = $campaign['text'];

        // Opens and clicks are counted per subscriber, so only subscribers are tracked.
        if ($subscriber) {
            $html = Tracking::prepare_html(PreferenceCentre::add_link($html, true), $row, $campaign_log_id);
            $text = PreferenceCentre::add_link($text, false);
        }

        return [
            'subject' => CampaignRenderer::replace_tags(CampaignRenderer::replace_fallback_tags($campaign['subject'], $person, false, $fields), $tags, false),
            'html'    => CampaignRenderer::replace_tags(CampaignRenderer::replace_fallback_tags($html, $person, true, $fields), $tags, true),
            'text'    => CampaignRenderer::replace_tags(CampaignRenderer::replace_fallback_tags($text, $person, false, $fields), $tags, false),
        ];
    }

    public function headers($headers, $row, $campaign_log_id)
    {
        if ('' === self::source_of($row)) return $headers;

        $headers[] = sprintf('List-Unsubscribe: <%s>', self::unsubscribe_url($row, self::subscriber($row), $campaign_log_id));
        $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';

        return $headers;
    }

    public function log_sent($row, $campaign_log_id)
    {
        if ('' === self::source_of($row) || empty($row['recipient_id'])) return;

        Subscribers::log_event($row['recipient_id'], Subscribers::EVENT_SENT, [], $campaign_log_id);

        if (CampaignActivityRepository::is_ready()) CampaignActivityRepository::record_sent($campaign_log_id);
    }

    /**
     * The unsubscribe link for people who aren't subscribers: a confirm page, then the
     * suppression list. Mail clients' one-click unsubscribe POSTs straight to it.
     */
    public function unsubscribe_request()
    {
        if ( ! isset($_GET['mo_src_unsub'], $_GET['mo_token']) || ! is_string($_GET['mo_src_unsub']) || ! is_string($_GET['mo_token'])) return;

        $email = strtolower(trim(rawurldecode(sanitize_text_field(wp_unslash($_GET['mo_src_unsub'])))));
        $token = sanitize_text_field(wp_unslash($_GET['mo_token']));

        if ( ! is_email($email) || ! Tokens::check('source_unsubscribe', [$email], $token)) {
            wp_die(esc_html__('This link is not valid. It may have been copied incompletely.', 'mailoptin'), esc_html__('Invalid link', 'mailoptin'), ['response' => 404]);
        }

        $masked = PreferenceCentre::masked_email($email);

        if ('POST' === strtoupper($_SERVER['REQUEST_METHOD'] ?? '')) {
            SuppressionRepository::add($email, SuppressionRepository::REASON_UNSUBSCRIBED, Sources::SUPPRESSION_SOURCE);

            wp_die(
                sprintf(esc_html__('%s will no longer receive these emails.', 'mailoptin'), '<strong>' . esc_html($masked) . '</strong>'),
                esc_html__("You've been unsubscribed", 'mailoptin'),
                ['response' => 200]
            );
        }

        wp_die(
            sprintf(esc_html__('Click the button below to stop emails to %s.', 'mailoptin'), '<strong>' . esc_html($masked) . '</strong>')
            . sprintf('<form method="post" action="%s" style="margin-top:16px"><button type="submit" class="button">%s</button></form>', esc_url(add_query_arg([])), esc_html__('Unsubscribe', 'mailoptin')),
            esc_html__('Unsubscribe', 'mailoptin'),
            ['response' => 200]
        );
    }

    /**
     * @return ContactRenderer
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
