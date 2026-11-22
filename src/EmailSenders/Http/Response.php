<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Http;

class Response
{
    /** @var int 0 when the request never got a response */
    public $status;

    /** @var array lower-case header name => value */
    public $headers;

    /** @var string */
    public $body;

    /** @var string why the request failed to complete, for status 0 */
    public $error;

    public function __construct($status, array $headers = [], $body = '', $error = '')
    {
        $this->status  = (int)$status;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->body    = (string)$body;
        $this->error   = (string)$error;
    }

    public function json()
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : [];
    }

    public function header($name)
    {
        $value = $this->headers[strtolower($name)] ?? '';

        return is_array($value) ? (string)reset($value) : (string)$value;
    }
}
