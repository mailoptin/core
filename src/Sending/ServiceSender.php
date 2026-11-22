<?php

namespace MailOptin\Core\Sending;

use MailOptin\Core\Audience\DeliveryEvents;
use MailOptin\Core\EmailSenders\Message;
use MailOptin\Core\EmailSenders\SendResult;
use MailOptin\Core\EmailSenders\SenderInterface;
use MailOptin\Core\SendQueue\BatchSenderInterface;
use MailOptin\Core\SendQueue\WpMailSender;

/**
 * Sends the send queue's emails through a sending service. The emails are put together exactly as
 * wp_mail() would get them (WpMailSender::compose()), with merge tags, tracking and unsubscribe headers.
 */
class ServiceSender implements BatchSenderInterface
{
    /** @var SenderInterface */
    protected $sender;

    /** @var WpMailSender */
    protected $composer;

    public function __construct(SenderInterface $sender)
    {
        $this->sender   = $sender;
        $this->composer = new WpMailSender();
    }

    public function batch_size()
    {
        return $this->sender->batch_size();
    }

    public function max_per_second()
    {
        return $this->sender->max_per_second();
    }

    public function send($row, $campaign_log_id)
    {
        return current($this->send_batch([$row['id'] => $row], $campaign_log_id));
    }

    public function send_batch(array $rows, $campaign_log_id)
    {
        $messages = [];

        foreach ($rows as $id => $row) {
            $content = $this->composer->compose($row, $campaign_log_id);

            $messages[$id] = self::message($row['email'], $content['subject'], $content['html'], (string)($content['text'] ?? ''), (array)$content['headers'], '', [DeliveryEvents::QUEUE_KEY => (string)$id]);
        }

        $results = [];

        foreach ($this->sender->send($messages) as $id => $result) {
            $results[$id] = self::to_queue_result($result);
        }

        return $results;
    }

    /**
     * A Message from wp_mail-style arguments: From and Reply-To come out of the headers, Content-Type is
     * dropped (the service gets HTML and text separately), the rest are sent as they are.
     */
    public static function message($to, $subject, $html, $text, array $headers, $stream = '', array $metadata = [])
    {
        $settings = get_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, []);
        $settings = is_array($settings) ? $settings : [];

        $args = [
            // Usually replaced by the From header MailOptin adds.
            'from'     => ['email' => (string)($settings['from_email'] ?? get_option('admin_email')), 'name' => (string)($settings['from_name'] ?? '') ?: get_bloginfo('name')],
            'to'       => $to,
            'subject'  => $subject,
            'html'     => $html,
            'text'     => '' !== $text ? $text : self::text_version($html),
            'stream'   => $stream,
            'metadata' => $metadata,
            'headers'  => [],
        ];

        foreach ($headers as $name => $line) {
            if (is_int($name)) {
                $parts = explode(':', (string)$line, 2);
                if (2 !== count($parts)) continue;
                list($name, $value) = $parts;
            } else {
                $value = $line;
            }

            $name  = trim($name);
            $value = trim((string)$value);

            switch (strtolower($name)) {
                case 'from':
                    $args['from'] = $value;
                    break;
                case 'reply-to':
                    $args['reply_to'] = $value;
                    break;
                case 'cc':
                case 'bcc':
                    $args[strtolower($name)][] = $value;
                    break;
                case 'content-type':
                case 'mime-version':
                    break;
                default:
                    $args['headers'][$name] = $value;
            }
        }

        return Message::make($args);
    }

    protected static function text_version($html)
    {
        if ('' === $html) return '';

        if (class_exists('\Soundasleep\Html2Text')) {
            try {
                return \Soundasleep\Html2Text::convert($html, ['ignore_errors' => true]);
            } catch (\Exception $e) {
            }
        }

        return trim(wp_strip_all_tags($html));
    }

    /**
     * @return true|\WP_Error as the send queue expects
     */
    public static function to_queue_result(SendResult $result)
    {
        switch ($result->status) {
            case SendResult::SENT:
                return true;
            case SendResult::RETRY:
                return new \WP_Error('mo_sending_service_retry', $result->error, array_filter(['retryable' => true, 'retry_after' => $result->retry_after, 'throttled' => $result->throttled]));
            case SendResult::AUTH:
                return new \WP_Error('mo_sending_service_auth', $result->error ?: __('The sending service refused the credentials.', 'mailoptin'), ['retryable' => true, 'stop' => true]);
            default:
                return new \WP_Error('mo_sending_service_failed', $result->error ?: __('The sending service refused this email.', 'mailoptin'));
        }
    }
}
