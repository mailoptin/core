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
     * message stream, SendGrid's category and Mailgun's tag. SES ignores it (its configuration set
     * comes from the sender's settings). Empty uses the sender's default; Message::TRANSACTIONAL
     * asks for the sender's transactional stream.
     *
     * @var string
     */
    public $stream = '';

    /** Password resets, orders, confirmations: Postmark sends these on its transactional stream. */
    const TRANSACTIONAL = 'transactional';

    /** Headers each sender sets itself from the message's own fields; a copy in headers is dropped. */
    const RESERVED_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id', 'sender', 'return-path',
        'mime-version', 'content-type', 'content-transfer-encoding', 'x-mailer',
    ];

    /** @var string[] recipients given that aren't valid addresses, so the send can fail saying which */
    public $invalid = [];

    /**
     * @param array $args from, reply_to, to, cc, bcc, subject, html, text, headers, attachments,
     *                    metadata, stream
     */
    public static function make(array $args)
    {
        $message = new self();

        $message->from     = Address::from($args['from'] ?? '');
        $message->reply_to = empty($args['reply_to']) ? null : Address::from($args['reply_to']);

        // A string may list several ("a@x.com, Name <b@y.com>"), as wp_mail() takes them.
        foreach (['to', 'cc', 'bcc'] as $key) {
            $message->$key = [];
            foreach (Address::split($args[$key] ?? []) as $value) {
                $address = Address::from($value);
                if ($address->is_valid()) {
                    $message->{$key}[] = $address;
                } else {
                    $message->invalid[] = is_string($value) ? $value : $address->email;
                }
            }
        }

        foreach (['subject', 'html', 'text', 'stream'] as $key) {
            $message->$key = (string)($args[$key] ?? '');
        }

        // Name => value, or "Name: value" lines as wp_mail() takes them.
        $message->headers = [];
        foreach ((array)($args['headers'] ?? []) as $name => $value) {
            if (is_int($name)) {
                $parts = explode(':', (string)$value, 2);
                if (2 !== count($parts)) continue;
                list($name, $value) = $parts;
            }
            $message->headers[trim((string)$name)] = trim((string)$value);
        }
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
     * Why this message can't be sent at all, or '' when it can.
     */
    public function problem()
    {
        if ($this->invalid) {
            return sprintf('Not a valid email address: %s.', implode(', ', array_map(function ($a) {
                return '"' . str_replace(["\r", "\n"], ' ', (string)$a) . '"';
            }, $this->invalid)));
        }

        if ( ! $this->to && ! $this->cc && ! $this->bcc) return 'No recipient.';

        return '';
    }

    /**
     * Header values without line breaks, so none can add a header of its own, and without the
     * headers a sender sets itself (From, To, Subject, Content-Type...).
     */
    public function safe_headers()
    {
        $headers = [];

        foreach ($this->headers as $name => $value) {
            $name = preg_replace('/[^A-Za-z0-9\-]/', '', (string)$name);
            if ('' === $name || in_array(strtolower($name), self::RESERVED_HEADERS, true)) continue;
            $headers[$name] = str_replace(["\r", "\n"], ' ', (string)$value);
        }

        return $headers;
    }
}
