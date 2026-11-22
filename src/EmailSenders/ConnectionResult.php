<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders;

/**
 * The outcome of checking a sender's credentials.
 */
class ConnectionResult
{
    /** @var bool */
    public $ok;

    /** @var string a sentence for the settings screen */
    public $message;

    /**
     * @var array facts worth showing, for example ['domain_verified' => true, 'max_per_second' => 14,
     *            'max_per_day' => 50000, 'sent_last_day' => 1204, 'sandbox' => false]
     */
    public $details;

    public function __construct($ok, $message, array $details = [])
    {
        $this->ok      = (bool)$ok;
        $this->message = (string)$message;
        $this->details = $details;
    }
}
