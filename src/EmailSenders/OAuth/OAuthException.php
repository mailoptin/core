<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\OAuth;

/**
 * A sign-in that didn't work. $revoked says whether trying again later could help: a revoked or
 * expired refresh token needs the account connected again.
 */
class OAuthException extends \RuntimeException
{
    /** @var bool */
    public $revoked;

    public function __construct($message, $revoked = false)
    {
        parent::__construct($message);
        $this->revoked = (bool)$revoked;
    }
}
