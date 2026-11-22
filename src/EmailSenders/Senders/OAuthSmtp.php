<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Senders;

use MailOptin\Core\EmailSenders\Http\ClientInterface;
use MailOptin\Core\EmailSenders\OAuth\Provider;
use MailOptin\Core\EmailSenders\OAuth\Token;
use MailOptin\Core\EmailSenders\OAuth\TokenSource;

/**
 * SMTP through a Google or Microsoft account the site owner signed in with: no password stored.
 */
abstract class OAuthSmtp extends Smtp
{
    /** @var TokenSource */
    protected $tokens;

    /**
     * @param array $config client_id, client_secret, token (Token::to_array()), on_token (receives
     *                      each refreshed Token, for the host to store), timeout, max_per_second, and
     *                      smtp (host, port, encryption) to use another server than the provider's
     */
    public function __construct(array $config = [], ?ClientInterface $http = null, ?callable $factory = null)
    {
        // A visitor may be waiting on this (a password reset): don't wait long for the token.
        $provider = Provider::make($this->provider_id(), $http ?: new \MailOptin\Core\EmailSenders\Http\WpClient(10));
        $token    = Token::from_array($config['token'] ?? []);

        $this->tokens = new TokenSource(
            $provider,
            $config['client_id'] ?? '',
            $config['client_secret'] ?? '',
            $token,
            isset($config['on_token']) && is_callable($config['on_token']) ? $config['on_token'] : null
        );

        // 'smtp' overrides the provider's server (host, port, encryption): for a relay, or tests.
        $server = array_intersect_key((array)($config['smtp'] ?? []), array_flip(['host', 'port', 'encryption'])) + $provider->smtp();

        parent::__construct($server + [
            'username'       => $token->email,
            'envelope_from'  => $token->email,
            'auth'           => true,
            'oauth_token'    => $this->tokens,
            'timeout'        => $config['timeout'] ?? 15,
            'max_per_second' => $config['max_per_second'] ?? null,
        ], $factory);
    }

    /** @return string google or microsoft */
    abstract protected function provider_id();

    public function test_connection()
    {
        if ( ! $this->tokens->token()->is_connected()) {
            return new \MailOptin\Core\EmailSenders\ConnectionResult(false, 'Sign in with the account to send from.');
        }

        return parent::test_connection();
    }

    /** The token, refreshed if sending needed a new one. */
    public function token()
    {
        return $this->tokens->token();
    }
}
