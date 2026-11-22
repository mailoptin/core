<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Webhooks;

class Result
{
    /** @var bool whether the request really came from the provider */
    public $verified;

    /** @var DeliveryEvent[] */
    public $events;

    /** @var string why it wasn't verified */
    public $error;

    /** @var bool an SNS subscription was confirmed */
    public $confirmed = false;

    public function __construct($verified, array $events = [], $error = '')
    {
        $this->verified = (bool)$verified;
        $this->events   = $events;
        $this->error    = (string)$error;
    }

    public static function rejected($error)
    {
        return new self(false, [], $error);
    }

    /**
     * The HTTP status to answer with. Providers retry anything else than 2xx, so a verified request
     * gets 200 even when it held nothing of interest.
     */
    public function http_status()
    {
        return $this->verified ? 200 : 403;
    }
}
