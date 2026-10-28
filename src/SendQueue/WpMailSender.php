<?php

namespace MailOptin\Core\SendQueue;

use MailOptin\Core\Logging\CampaignLogRepository;

/**
 * Sends through wp_mail(), so any SMTP plugin installed on the site is used.
 */
class WpMailSender implements SenderInterface
{
    protected $content_cache = [];

    public function send($row, $campaign_log_id)
    {
        $content = apply_filters('mo_send_queue_email_content', $this->campaign_content($campaign_log_id), $row, $campaign_log_id);

        $mail_error = null;

        $capture_error = function ($wp_error) use (&$mail_error) {
            $mail_error = $wp_error;
        };

        $add_plain_text = function ($phpmailer) use ($content) {
            $phpmailer->AltBody = $content['text'];
        };

        add_action('wp_mail_failed', $capture_error);
        add_action('phpmailer_init', $add_plain_text);

        $sent = wp_mail($row['email'], $content['subject'], $content['html'], $this->headers($row, $campaign_log_id));

        remove_action('wp_mail_failed', $capture_error);
        remove_action('phpmailer_init', $add_plain_text);

        if ($sent) return true;

        $message = $mail_error instanceof \WP_Error ? $mail_error->get_error_message() : __('wp_mail() could not send the email.', 'mailoptin');

        // wp_mail failures are usually SMTP connection or rate problems, so they are worth retrying.
        return new \WP_Error('mo_wp_mail_failed', $message, ['retryable' => true]);
    }

    protected function campaign_content($campaign_log_id)
    {
        if ( ! isset($this->content_cache[$campaign_log_id])) {
            $repository = CampaignLogRepository::instance();

            $this->content_cache[$campaign_log_id] = [
                'subject' => $repository->retrieveTitle($campaign_log_id),
                'html'    => $repository->retrieveContentHtml($campaign_log_id),
                'text'    => $repository->retrieveContentText($campaign_log_id),
            ];
        }

        return $this->content_cache[$campaign_log_id];
    }

    protected function headers($row, $campaign_log_id)
    {
        return apply_filters('mo_send_queue_email_headers', ['Content-Type: text/html; charset=UTF-8'], $row, $campaign_log_id);
    }
}
