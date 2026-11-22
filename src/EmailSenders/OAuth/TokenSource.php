<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\OAuth;

/**
 * Hands the SMTP sender a current access token, refreshing it only when it's about to expire, and
 * gives each refreshed token to the host plugin to store.
 */
class TokenSource
{
    /** @var Provider */
    protected $provider;

    /** @var string */
    protected $client_id;

    /** @var string */
    protected $client_secret;

    /** @var Token */
    protected $token;

    /** @var callable|null receives the refreshed Token */
    protected $on_refresh;

    public function __construct(Provider $provider, $client_id, $client_secret, Token $token, ?callable $on_refresh = null)
    {
        $this->provider      = $provider;
        $this->client_id     = (string)$client_id;
        $this->client_secret = (string)$client_secret;
        $this->token         = $token;
        $this->on_refresh    = $on_refresh;
    }

    /**
     * @return string
     *
     * @throws OAuthException
     */
    public function __invoke()
    {
        if ($this->token->expires_soon()) {
            $this->token = $this->provider->refresh($this->client_id, $this->client_secret, $this->token);
            if ($this->on_refresh) call_user_func($this->on_refresh, $this->token);
        }

        return $this->token->access_token;
    }

    public function token()
    {
        return $this->token;
    }
}
