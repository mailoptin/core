<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Webhooks;

/**
 * An incoming webhook request, as the host plugin received it.
 */
class Request
{
    /** @var string the raw body, exactly as sent: signatures are over these bytes */
    public $body;

    /** @var array lower-case header name => value */
    public $headers;

    /** @var array */
    public $query;

    public function __construct($body, array $headers = [], array $query = [])
    {
        $this->body    = (string)$body;
        $this->query   = $query;
        $this->headers = [];

        foreach ($headers as $name => $value) {
            $this->headers[strtolower(str_replace('_', '-', (string)$name))] = is_array($value) ? (string)reset($value) : (string)$value;
        }
    }

    public function header($name)
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function json()
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : [];
    }
}
