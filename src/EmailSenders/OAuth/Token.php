<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\OAuth;

/**
 * A signed-in account's tokens. The host plugin stores to_array() (encrypted) and rebuilds it with
 * from_array().
 */
class Token
{
    /** @var string */
    public $access_token = '';

    /** @var string */
    public $refresh_token = '';

    /** @var int unix time the access token stops working */
    public $expires_at = 0;

    /** @var string the account's email address, which is also the SMTP username */
    public $email = '';

    public static function from_array($data)
    {
        $token = new self();
        if ( ! is_array($data)) return $token;

        $token->access_token  = (string)($data['access_token'] ?? '');
        $token->refresh_token = (string)($data['refresh_token'] ?? '');
        $token->expires_at    = (int)($data['expires_at'] ?? 0);
        $token->email         = (string)($data['email'] ?? '');

        return $token;
    }

    public function to_array()
    {
        return [
            'access_token'  => $this->access_token,
            'refresh_token' => $this->refresh_token,
            'expires_at'    => $this->expires_at,
            'email'         => $this->email,
        ];
    }

    /**
     * Whether the access token needs refreshing before use: missing, or expiring within $margin seconds.
     */
    public function expires_soon($margin = 120, $now = null)
    {
        return '' === $this->access_token || $this->expires_at <= ($now ?? time()) + $margin;
    }

    public function is_connected()
    {
        return '' !== $this->refresh_token;
    }
}
