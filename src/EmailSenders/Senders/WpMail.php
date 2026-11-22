<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Senders;

use MailOptin\Core\EmailSenders\ConnectionResult;
use MailOptin\Core\EmailSenders\Message;
use MailOptin\Core\EmailSenders\SendResult;
use MailOptin\Core\EmailSenders\SenderInterface;

/**
 * WordPress's own mailer. An SMTP plugin, when the site has one, takes over from here.
 */
class WpMail implements SenderInterface
{
    /** @var array */
    protected $config;

    /** @var string */
    protected $last_error = '';

    /**
     * @param array $config max_per_second (optional)
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function id()
    {
        return 'wp_mail';
    }

    public function batch_size()
    {
        return 1;
    }

    public function max_per_second()
    {
        return empty($this->config['max_per_second']) ? null : max(1, (int)$this->config['max_per_second']);
    }

    public function test_connection()
    {
        return new ConnectionResult(true, 'Emails go out through WordPress\'s mailer, or the SMTP plugin that replaces it.');
    }

    public function send(array $messages)
    {
        $results = [];

        foreach ($messages as $key => $message) {
            $results[$key] = $this->send_one($message);
        }

        return $results;
    }

    protected function send_one(Message $message)
    {
        $to = array_map(function ($a) {
            return $a->format();
        }, $message->to);

        $problem = $message->problem();
        if ('' !== $problem) return SendResult::failed($problem);

        $headers = [];

        if ('' !== $message->from->email) $headers[] = 'From: ' . $message->from->format();
        if ($message->reply_to) $headers[] = 'Reply-To: ' . $message->reply_to->format();
        foreach ($message->cc as $a) $headers[] = 'Cc: ' . $a->format();
        foreach ($message->bcc as $a) $headers[] = 'Bcc: ' . $a->format();
        foreach ($message->safe_headers() as $name => $value) $headers[] = $name . ': ' . $value;

        $is_html   = '' !== $message->html;
        $headers[] = 'Content-Type: ' . ($is_html ? 'text/html' : 'text/plain') . '; charset=UTF-8';

        // The plain-text version rides along as PHPMailer's AltBody, for this one email only.
        $alt_body = function ($phpmailer) use ($message, $is_html) {
            if ($is_html && '' !== $message->text) $phpmailer->AltBody = $message->text;
        };

        $this->last_error = '';
        $capture          = function ($error) {
            if (is_object($error) && method_exists($error, 'get_error_message')) $this->last_error = $error->get_error_message();
        };

        add_action('phpmailer_init', $alt_body, 999999);
        add_action('wp_mail_failed', $capture);

        try {
            $sent = wp_mail($to, $message->subject, $is_html ? $message->html : $message->text, $headers, $message->attachments);
        } finally {
            remove_action('phpmailer_init', $alt_body, 999999);
            remove_action('wp_mail_failed', $capture);
        }

        // wp_mail() doesn't say why it failed in a way that tells a bad address from a down mail
        // server, so a failure is worth another try.
        if ($sent) return SendResult::sent();

        // PHPMailer refusing an address won't change on a retry.
        if (false !== stripos($this->last_error, 'invalid address')) return SendResult::failed($this->last_error);

        return SendResult::retry($this->last_error ?: 'WordPress couldn\'t send the email.');
    }
}
