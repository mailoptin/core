<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders;

/**
 * What happened to one message.
 */
class SendResult
{
    /** The provider accepted it. */
    const SENT = 'sent';

    /** Not sent this time, for example a timeout or a rate limit: try again later. */
    const RETRY = 'retry';

    /** Never going to send, for example an invalid or suppressed address. Don't retry. */
    const FAILED = 'failed';

    /** The credentials are wrong or lack permission. Nothing will send until they're fixed. */
    const AUTH = 'auth';

    /** @var string one of the constants */
    public $status;

    /** @var string the provider's id for the message, used to match its webhook events */
    public $message_id = '';

    /** @var string */
    public $error = '';

    /** @var int|null seconds to wait before retrying, when the provider said */
    public $retry_after;

    /**
     * The provider is rate limiting: this email isn't at fault, so a retry shouldn't count against it.
     *
     * @var bool
     */
    public $throttled = false;

    public function __construct($status, $message_id = '', $error = '', $retry_after = null)
    {
        $this->status      = $status;
        $this->message_id  = (string)$message_id;
        $this->error       = (string)$error;
        $this->retry_after = null === $retry_after ? null : max(0, (int)$retry_after);
    }

    public static function sent($message_id = '')
    {
        return new self(self::SENT, $message_id);
    }

    public static function retry($error, $retry_after = null)
    {
        return new self(self::RETRY, '', $error, $retry_after);
    }

    public static function throttled($error, $retry_after = null)
    {
        $result            = new self(self::RETRY, '', $error, $retry_after);
        $result->throttled = true;

        return $result;
    }

    public static function failed($error)
    {
        return new self(self::FAILED, '', $error);
    }

    public static function auth($error)
    {
        return new self(self::AUTH, '', $error);
    }

    public function is_sent()
    {
        return self::SENT === $this->status;
    }
}
