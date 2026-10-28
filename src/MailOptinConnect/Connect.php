<?php

namespace MailOptin\MailOptinConnect;

use MailOptin\Core\Audience\CampaignRenderer;
use MailOptin\Core\Audience\Consent;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Connections\AbstractConnect;
use MailOptin\Core\Connections\ConnectionInterface;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository;
use MailOptin\Core\SendQueue\QueueRunner;

/**
 * Built-in lists: subscribers are stored in this WordPress site and emailed through the send queue.
 */
class Connect extends AbstractConnect implements ConnectionInterface
{
    /**
     * @var string key of connection service. its important all connection name ends with "Connect"
     */
    public static $connectionName = 'MailOptinConnect';

    const ENQUEUE_BATCH_SIZE = 1000;

    public function __construct()
    {
        add_filter('mailoptin_registered_connections', [$this, 'register_connection']);

        parent::__construct();
    }

    public static function features_support()
    {
        return [
            self::OPTIN_CAMPAIGN_SUPPORT,
            self::EMAIL_CAMPAIGN_SUPPORT,
        ];
    }

    public static function is_connected()
    {
        return true;
    }

    public function register_connection($connections)
    {
        $connections[self::$connectionName] = __('MailOptin (built-in)', 'mailoptin');

        return $connections;
    }

    /**
     * {@unsubscribe}, {{webversion}} and subscriber merge tags are left for per-recipient rendering.
     *
     * {@inheritdoc}
     */
    public function replace_placeholder_tags($content, $type = 'html')
    {
        return $this->replace_footer_placeholder_tags($content);
    }

    public function get_email_list()
    {
        $lists = [];

        foreach (ListsRepository::get_all() as $list) {
            $lists[$list['id']] = $list['name'];
        }

        return $lists;
    }

    public function get_optin_fields($list_id = '')
    {
        return [];
    }

    public function subscribe($email, $name, $list_id, $extras = null)
    {
        $this->extras = is_array($extras) ? $extras : [];

        $names = self::get_first_last_names($name);

        $consent = Consent::from_form(absint($this->extras['optin_campaign_id'] ?? 0), $this->extras);

        $response = Subscription::subscribe($email, absint($list_id), [
            'consent_text'    => $consent['consent_text'],
            'consent_method'  => $consent['consent_method'],
            'first_name'      => $names[0] ?? '',
            'last_name'       => $names[1] ?? '',
            'source'          => 'form',
            'source_optin_id' => absint($this->extras['optin_campaign_id'] ?? 0),
            'ip_address'      => $this->extras['mo_ip_address'] ?? '',
            'meta'            => array_filter([
                'form'            => $this->extras['mo_campaign_name'] ?? '',
                'conversion_page' => esc_url_raw($this->extras['conversion_page'] ?? ''),
                'referrer'        => esc_url_raw($this->extras['referrer'] ?? ''),
            ]),
        ]);

        if (is_wp_error($response)) {
            self::save_optin_error_log(
                $response->get_error_message(),
                'mailoptin',
                $this->extras['optin_campaign_id'] ?? null,
                $this->extras['optin_campaign_type'] ?? null
            );

            return parent::ajax_failure();
        }

        return parent::ajax_success();
    }

    /**
     * Queues every subscribed member of the campaign's list. The queue marks the campaign log
     * processed when it has sent to everyone, so this returns status "sending".
     *
     * {@inheritdoc}
     */
    public function send_newsletter($email_campaign_id, $campaign_log_id, $subject, $content_html, $content_text)
    {
        $list_id = absint($this->get_email_campaign_list_id($email_campaign_id));

        if ( ! $list_id || ! ListsRepository::get($list_id)) {
            self::save_campaign_error_log(__('The selected list no longer exists.', 'mailoptin'), $campaign_log_id, $email_campaign_id);

            return ['success' => false];
        }

        CampaignRenderer::save_campaign_content($campaign_log_id, $list_id, $content_html, $content_text);

        // Resending a campaign retries its failed recipients; anyone already sent is skipped by the queue.
        SendQueueRepository::retry_failed($campaign_log_id);

        $after_id = 0;

        do {
            $recipients = SubscribersRepository::get_list_recipients($list_id, $after_id, self::ENQUEUE_BATCH_SIZE);

            if (empty($recipients)) break;

            SendQueueRepository::enqueue($campaign_log_id, $email_campaign_id, array_map(function ($recipient) {
                return [
                    'email'          => $recipient['email'],
                    'recipient_type' => CampaignRenderer::RECIPIENT_TYPE,
                    'recipient_id'   => $recipient['id'],
                ];
            }, $recipients));

            $after_id = (int)end($recipients)['id'];

        } while (count($recipients) === self::ENQUEUE_BATCH_SIZE);

        QueueRunner::get_instance()->start($campaign_log_id, $email_campaign_id, []);

        return ['success' => true, 'status' => QueueRunner::LOG_STATUS_SENDING];
    }

    /**
     * @return Connect
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
