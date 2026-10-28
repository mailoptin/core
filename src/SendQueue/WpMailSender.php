<?php

namespace MailOptin\Core\SendQueue;

use MailOptin\Core\Logging\CampaignLogRepository;
use MailOptin\Core\PluginSettings\Settings;

/**
 * Sends through wp_mail(), so any SMTP plugin installed on the site is used.
 */
class WpMailSender implements SenderInterface
{
    const ONE_CLICK_UNAVAILABLE_OPTION = 'mo_one_click_unsubscribe_unavailable';

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
        // Last, so an SMTP plugin has already chosen the transport.
        add_action('phpmailer_init', [__CLASS__, 'drop_headers_mail_would_corrupt'], PHP_INT_MAX);

        $sent = wp_mail($row['email'], $content['subject'], $content['html'], $this->headers($row, $campaign_log_id));

        remove_action('wp_mail_failed', $capture_error);
        remove_action('phpmailer_init', $add_plain_text);
        remove_action('phpmailer_init', [__CLASS__, 'drop_headers_mail_would_corrupt'], PHP_INT_MAX);

        if ($sent) return true;

        $message = $mail_error instanceof \WP_Error ? $mail_error->get_error_message() : __('wp_mail() could not send the email.', 'mailoptin');

        // wp_mail failures are usually SMTP connection or rate problems, so they are worth retrying.
        return new \WP_Error('mo_wp_mail_failed', $message, ['retryable' => true]);
    }

    /**
     * With PHP mail(), PHPMailer RFC 2047-encodes any custom header longer than about 47 characters.
     * An encoded List-Unsubscribe header is invalid, so drop the one-click headers instead of sending
     * a broken one, and remember it so the settings screen can recommend an SMTP plugin. The
     * unsubscribe link in the email body still works.
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
     */
    public static function drop_headers_mail_would_corrupt($phpmailer)
    {
        if ('mail' !== $phpmailer->Mailer) return;

        // Same limit PHPMailer::encodeHeader() uses for the mail transport.
        $max_length = $phpmailer::MAIL_MAX_LINE_LENGTH - 8 - strlen($phpmailer::CHARSET_ASCII);

        $headers = $phpmailer->getCustomHeaders();
        $dropped = false;

        foreach ($headers as $header) {
            if ('list-unsubscribe' === strtolower($header[0]) && strlen(trim($header[1])) > $max_length) {
                $dropped = true;
            }
        }

        if ( ! $dropped) return;

        $phpmailer->clearCustomHeaders();

        foreach ($headers as $header) {
            if (in_array(strtolower($header[0]), ['list-unsubscribe', 'list-unsubscribe-post'], true)) continue;

            $phpmailer->addCustomHeader($header[0], $header[1]);
        }

        update_option(self::ONE_CLICK_UNAVAILABLE_OPTION, time(), false);
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
        return apply_filters('mo_send_queue_email_headers', self::default_headers(), $row, $campaign_log_id);
    }

    /**
     * HTML content type plus the From and Reply-To set in MailOptin's email settings.
     *
     * @return array
     */
    public static function default_headers()
    {
        $headers   = ['Content-Type: text/html; charset=UTF-8'];
        $from_name = Settings::instance()->from_name();
        $from      = Settings::instance()->from_email();
        $reply_to  = Settings::instance()->reply_to();

        if (is_email($from)) {
            $headers[] = sprintf('From: %s <%s>', $from_name ?: get_bloginfo('name'), $from);
        }

        if (is_email($reply_to)) {
            $headers[] = sprintf('Reply-To: %s', $reply_to);
        }

        return $headers;
    }
}
