<?php

namespace MailOptin\Core\EmailCampaigns\Newsletter;

use MailOptin\Core\SendQueue\QueueRunner;
use MailOptin\Core\Connections\ConnectionFactory;
use MailOptin\Core\EmailCampaigns\AbstractTriggers;
use MailOptin\Core\EmailCampaigns\Misc;
use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

class Newsletter extends AbstractTriggers
{
    public function __construct()
    {
        parent::__construct();

        add_action('admin_init', [$this, 'newsletter_sending_handler']);
    }

    public function newsletter_sending_handler()
    {
        if ( ! is_admin() || ! \MailOptin\Core\current_user_has_privilege()) return;

        if ( ! isset($_GET['action']) || $_GET['action'] != 'mailoptin_send_newsletter') return;

        if ( ! isset($_GET['id'])) return;

        check_admin_referer('mailoptin-send-newsletter');

        $email_campaign_id = absint($_GET['id']);

        $campaign_id = $this->create_log($email_campaign_id);

        $this->send_campaign($email_campaign_id, $campaign_id);
    }

    /**
     * Render the newsletter and save it to the campaign log, ready to send.
     *
     * @return int campaign log ID
     */
    protected function create_log($email_campaign_id)
    {
        $email_subject = Misc::parse_email_subject(ER::get_merged_customizer_value($email_campaign_id, 'email_campaign_title'));

        $content_html = (new Templatify($email_campaign_id))->forge();

        do_action('mo_newsletter_before_send', $email_campaign_id, $email_subject, $content_html);

        return $this->save_campaign_log(
            $email_campaign_id,
            apply_filters('mailoptin_newsletter_email_campaign_subject', $email_subject, $email_campaign_id),
            $content_html
        );
    }

    /**
     * Send a newsletter now, from the broadcast page or a scheduled send.
     *
     * @return array success, campaign_log_id, and error when it failed
     */
    public function dispatch($email_campaign_id)
    {
        $email_campaign_id = absint($email_campaign_id);

        if (empty($this->connection_service($email_campaign_id))) {
            return ['success' => false, 'campaign_log_id' => 0, 'error' => __('Choose who to send it to first.', 'mailoptin')];
        }

        $campaign_log_id = $this->create_log($email_campaign_id);

        $response = $this->deliver($email_campaign_id, $campaign_log_id);

        return ['success' => ! empty($response['success']), 'campaign_log_id' => $campaign_log_id, 'error' => ! empty($response['success']) ? '' : __('The email service didn\'t accept the newsletter. See the campaign log for details.', 'mailoptin')];
    }

    /**
     * Hand a logged newsletter to its connection and record the outcome.
     *
     * @return array the connection's response
     */
    protected function deliver($email_campaign_id, $campaign_log_id)
    {
        do_action('mo_newsletter_before_send_campaign', $email_campaign_id, $campaign_log_id);

        $campaign            = $this->CampaignLogRepository->getById($campaign_log_id);
        $connection_instance = ConnectionFactory::make($this->connection_service($email_campaign_id));

        $response = $connection_instance->send_newsletter(
            $email_campaign_id,
            $campaign_log_id,
            $campaign->title,
            $connection_instance->replace_placeholder_tags($campaign->content_html, 'html'),
            $connection_instance->replace_placeholder_tags($campaign->content_text, 'text')
        );

        if (isset($response['success']) && (true === $response['success'])) {
            // The send queue marks the campaign processed itself once every email has gone out.
            if (($response['status'] ?? '') !== QueueRunner::LOG_STATUS_SENDING) {
                $this->update_campaign_status($campaign_log_id, 'processed');
            }
            EmailCampaignMeta::update_meta_data($email_campaign_id, 'newsletter_date_sent', current_time('mysql'));
        } else {
            $this->update_campaign_status($campaign_log_id, 'failed');
            EmailCampaignMeta::update_meta_data($email_campaign_id, 'newsletter_date_sent', ER::NEWSLETTER_STATUS_FAILED);
        }

        return (array)$response;
    }

    /**
     * Does the actual campaign sending.
     *
     * @param int $email_campaign_id
     * @param int $campaign_log_id
     */
    public function send_campaign($email_campaign_id, $campaign_log_id)
    {
        if (empty($this->connection_service($email_campaign_id))) {
            wp_die(__('Error: no recipient has been defined for the newsletter. Please go back and do that now.', 'mailoptin'));
        }

        $this->deliver($email_campaign_id, $campaign_log_id);

        wp_safe_redirect(MAILOPTIN_EMAIL_NEWSLETTERS_SETTINGS_PAGE);
        exit;
    }

    /**
     * Singleton.
     *
     * @return self
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