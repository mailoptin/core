<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders;

/**
 * One email. A sender sends a list of them, each with its own recipients and content.
 */
class Message
{
    /** @var Address */
    public $from;

    /** @var Address|null */
    public $reply_to;

    /** @var Address[] */
    public $to = [];

    /** @var Address[] */
    public $cc = [];

    /** @var Address[] */
    public $bcc = [];

    /** @var string */
    public $subject = '';

    /** @var string */
    public $html = '';

    /** @var string */
    public $text = '';

    /** @var array header name => value, for example List-Unsubscribe */
    public $headers = [];

    /** @var string[] absolute file paths, keyed by the file name to show when it isn't the path's own */
    public $attachments = [];

    /**
     * Short string values sent with the email and returned in its bounce and complaint events,
     * so they can be matched to the send. Keys and values: letters, digits, "_" and "-" only,
     * because Amazon SES message tags allow nothing else.
     *
     * @var array
     */
    public $metadata = [];

    /**
     * A category for providers that keep broadcasts apart from transactional email: Postmark's
     * message stream, SES's configuration set, SendGrid's and Mailgun's tag. Empty uses the
     * sender's default.
     *
     * @var string
     */
    public $stream = '';

    /**
     * @param array $args from, reply_to, to, cc, bcc, subject, html, text, headers, attachments,
     *                    metadata, stream
     */
    public static function make(array $args)
    {
        $message = new self();

        $message->from     = Address::from($args['from'] ?? '');
        $message->reply_to = empty($args['reply_to']) ? null : Address::from($args['reply_to']);

        foreach (['to', 'cc', 'bcc'] as $key) {
            $list = $args[$key] ?? [];
            if ( ! is_array($list) || isset($list['email'])) $list = [$list];
            $message->$key = array_values(array_filter(array_map([Address::class, 'from'], $list), function ($a) {
                return '' !== $a->email;
            }));
        }

        foreach (['subject', 'html', 'text', 'stream'] as $key) {
            $message->$key = (string)($args[$key] ?? '');
        }

        $message->headers     = (array)($args['headers'] ?? []);
        // Paths, optionally keyed by the name the recipient sees ('invoice.pdf' => '/tmp/x'), as wp_mail() takes them.
        $message->attachments = [];
        foreach ((array)($args['attachments'] ?? []) as $name => $path) {
            if ( ! is_string($path) || '' === trim($path)) continue;
            if (is_string($name) && '' !== trim($name)) {
                $message->attachments[trim($name)] = trim($path);
            } else {
                $message->attachments[] = trim($path);
            }
        }
        $message->metadata    = self::clean_metadata((array)($args['metadata'] ?? []));

        return $message;
    }

    public static function clean_metadata(array $metadata)
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            $key   = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$key);
            $value = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$value);
            if ('' !== $key) $clean[substr($key, 0, 64)] = substr($value, 0, 200);
        }

        return $clean;
    }

    /**
     * Header values without line breaks, so none can add a header of its own.
     */
    public function safe_headers()
    {
        $headers = [];

        foreach ($this->headers as $name => $value) {
            $name = preg_replace('/[^A-Za-z0-9\-]/', '', (string)$name);
            if ('' === $name) continue;
            $headers[$name] = str_replace(["\r", "\n"], ' ', (string)$value);
        }

        return $headers;
    }
}
