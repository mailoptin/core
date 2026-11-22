<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Senders;

// WordPress ships PHPMailer without the one-method OAuth interface its setOAuth() expects; load
// ours if no one else has.
// Autoload on: a full PHPMailer on the site (with its own copy of the interface) wins.
if ( ! interface_exists('\PHPMailer\PHPMailer\OAuthTokenProvider')) {
    require_once __DIR__ . '/../PHPMailer/OAuthTokenProvider.php';
}

/**
 * Signs in to an SMTP server with an OAuth access token (XOAUTH2), as Gmail and Microsoft 365 expect.
 * The token comes from a callable, so it's refreshed by whoever stores it, only when needed.
 */
class SmtpOAuthToken implements \PHPMailer\PHPMailer\OAuthTokenProvider
{
    /** @var string */
    protected $email;

    /** @var callable */
    protected $token;

    public function __construct($email, callable $token)
    {
        $this->email = (string)$email;
        $this->token = $token;
    }

    public function getOauth64()
    {
        $token = (string)call_user_func($this->token);

        return base64_encode('user=' . $this->email . "\001auth=Bearer " . $token . "\001\001");
    }
}
