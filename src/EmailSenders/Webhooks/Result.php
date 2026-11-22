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

    /** @var callable[] what confirm() does: remember these events as handled */
    protected $on_confirm = [];

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
     * Run once the events have been handled, when the webhook was made with 'confirm' => true.
     *
     * Until then, nothing is remembered as handled: if handling fails (an error, a timeout) and the
     * provider sends the same request again, it is handled again instead of being taken for a repeat.
     * Safe to call more than once, and on any result.
     */
    public function confirm()
    {
        $callbacks        = $this->on_confirm;
        $this->on_confirm = [];

        foreach ($callbacks as $callback) call_user_func($callback);
    }

    /**
     * @internal For webhooks: remember something on confirm().
     */
    public function on_confirm(callable $callback)
    {
        $this->on_confirm[] = $callback;

        return $this;
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
