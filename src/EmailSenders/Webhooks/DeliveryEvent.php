<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Webhooks;

/**
 * A bounce or spam complaint, the same shape whichever provider reported it.
 */
class DeliveryEvent
{
    /** The address doesn't exist or will never accept mail. Stop emailing it. */
    const HARD_BOUNCE = 'hard_bounce';

    /** A temporary failure, such as a full mailbox. Several in a row are worth treating as hard. */
    const SOFT_BOUNCE = 'soft_bounce';

    /** The person marked the email as spam. Stop emailing them. */
    const COMPLAINT = 'complaint';

    /** @var string one of the constants */
    public $type;

    /** @var string lower-case */
    public $email;

    /** @var string */
    public $provider;

    /** @var string the provider's message id, as SendResult::$message_id gave it */
    public $message_id;

    /** @var array the Message::$metadata the email was sent with, when the provider returns it */
    public $metadata;

    /** @var string the provider's explanation, such as an SMTP diagnostic */
    public $detail;

    /** @var int|null Unix time */
    public $occurred_at;

    public function __construct($type, $email, $provider, $message_id = '', array $metadata = [], $detail = '', $occurred_at = null)
    {
        $this->type        = $type;
        $this->email       = strtolower(trim((string)$email));
        $this->provider    = (string)$provider;
        $this->message_id  = trim((string)$message_id, '<> ');
        $this->metadata    = \MailOptin\Core\EmailSenders\Message::clean_metadata($metadata);
        $detail            = trim((string)$detail);
        $this->detail      = function_exists('mb_substr') ? mb_substr($detail, 0, 500) : substr($detail, 0, 500);
        $this->occurred_at = $occurred_at;
    }

    public static function time($value)
    {
        if (is_numeric($value)) return (int)$value;

        $time = is_string($value) ? strtotime($value) : false;

        return false === $time ? null : $time;
    }
}
