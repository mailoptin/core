<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\OAuth;

use MailOptin\Core\EmailSenders\Http\ClientInterface;
use MailOptin\Core\EmailSenders\Http\Response;
use MailOptin\Core\EmailSenders\Http\WpClient;

/**
 * Signing in to Google or Microsoft to send email over SMTP with OAuth (XOAUTH2).
 *
 * The site uses its own OAuth app (client ID and secret). The host plugin owns the redirect URI,
 * keeps $state and the PKCE verifier between the two requests, and stores the Token:
 *
 *     $google = Provider::make('google');
 *     $verifier = Provider::verifier();
 *     wp_redirect($google->authorize_url($client_id, $redirect_uri, $state, $verifier));
 *     // ...back at $redirect_uri, after checking $_GET['state']:
 *     $token = $google->exchange($client_id, $client_secret, $redirect_uri, $_GET['code'], $verifier);
 */
abstract class Provider
{
    const PROVIDERS = ['google' => Google::class, 'microsoft' => Microsoft::class];

    /** @var ClientInterface */
    protected $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?: new WpClient();
    }

    /**
     * @param string $id google or microsoft
     *
     * @return Provider|null
     */
    public static function make($id, ?ClientInterface $http = null)
    {
        $class = self::PROVIDERS[$id] ?? null;

        return $class ? new $class($http) : null;
    }

    /** @return string google or microsoft */
    abstract public function id();

    /** @return string e.g. Google */
    abstract public function name();

    abstract protected function authorize_endpoint();

    abstract protected function token_endpoint();

    /** @return string[] */
    abstract protected function scopes();

    /** @return array extra query arguments for the sign-in page */
    protected function authorize_extras()
    {
        return [];
    }

    /** @return array host, port and encryption of the provider's SMTP server */
    abstract public function smtp();

    /**
     * A random PKCE code verifier, to keep with $state until the person comes back.
     */
    public static function verifier()
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public static function challenge($verifier)
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * The provider's sign-in page.
     *
     * @param string $login_hint the address to sign in with, when known
     */
    public function authorize_url($client_id, $redirect_uri, $state, $verifier, $login_hint = '')
    {
        if ('' === (string)$state || '' === (string)$verifier) {
            throw new \InvalidArgumentException('A state and a PKCE verifier are required: they protect the sign-in from being forged.');
        }

        $args = [
            'client_id'             => $client_id,
            'redirect_uri'          => $redirect_uri,
            'response_type'         => 'code',
            'scope'                 => implode(' ', $this->scopes()),
            'state'                 => $state,
            'code_challenge'        => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ] + $this->authorize_extras();

        if ('' !== $login_hint) $args['login_hint'] = $login_hint;

        return $this->authorize_endpoint() . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Swap the code from the redirect for tokens.
     *
     * @throws OAuthException
     */
    public function exchange($client_id, $client_secret, $redirect_uri, $code, $verifier)
    {
        $token = $this->token_request([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirect_uri,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'code_verifier' => $verifier,
        ], new Token());

        if ('' === $token->refresh_token) {
            throw new OAuthException(sprintf('%s didn\'t give lasting access. Remove the app from your account\'s connected apps and connect again.', $this->name()), true);
        }

        if ('' === $token->email) {
            throw new OAuthException(sprintf('%s didn\'t say which email address signed in.', $this->name()), true);
        }

        return $token;
    }

    /**
     * A fresh access token for a stored one. Keeps the refresh token unless the provider sends a new one.
     *
     * @throws OAuthException
     */
    public function refresh($client_id, $client_secret, Token $token)
    {
        if ('' === $token->refresh_token) {
            throw new OAuthException(sprintf('The %s account isn\'t connected.', $this->name()), true);
        }

        return $this->token_request([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $token->refresh_token,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ] + $this->refresh_extras(), clone $token);
    }

    protected function refresh_extras()
    {
        return [];
    }

    protected function token_request(array $form, Token $token)
    {
        $response = $this->http->request('POST', $this->token_endpoint(), ['Accept' => 'application/json'], $form);
        $data     = $response->json();

        if ($response->status < 200 || $response->status >= 300 || empty($data['access_token'])) {
            throw $this->failure($response, is_array($data) ? $data : []);
        }

        $token->access_token = (string)$data['access_token'];
        $token->expires_at   = time() + max(60, (int)($data['expires_in'] ?? 3600));
        if ( ! empty($data['refresh_token'])) $token->refresh_token = (string)$data['refresh_token'];

        $email = $this->email_from_id_token((string)($data['id_token'] ?? ''));
        if ('' !== $email) $token->email = $email;

        return $token;
    }

    protected function failure(Response $response, array $data)
    {
        $code = (string)($data['error'] ?? '');
        $why  = trim((string)($data['error_description'] ?? '')) ?: $code;

        // Only these mean the sign-in itself is gone or the app is wrong. Anything else (an outage, a
        // firewall's error page, a proxy answering in place of the provider) is worth another try,
        // and must not make the site owner reconnect a working account.
        if (in_array($code, ['invalid_client', 'unauthorized_client'], true)) {
            return new OAuthException(sprintf('%s didn\'t accept the app\'s client ID or secret (%s). Check them, then connect again.', $this->name(), $why), true);
        }

        if (in_array($code, ['invalid_grant', 'interaction_required', 'consent_required'], true)) {
            return new OAuthException(sprintf('%s refused the saved sign-in (%s). Connect the account again.', $this->name(), $why), true);
        }

        return new OAuthException(sprintf('Couldn\'t get a sign-in from %s: %s', $this->name(), $response->error ?: ($why ?: 'HTTP ' . $response->status)), false);
    }

    /**
     * The email address in the ID token's claims. It comes straight from the provider's token
     * endpoint over TLS, so its signature needn't be checked here.
     */
    protected function email_from_id_token($jwt)
    {
        $parts = explode('.', $jwt);
        if (3 !== count($parts)) return '';

        $claims = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true);
        if ( ! is_array($claims)) return '';

        foreach (['email', 'preferred_username', 'upn'] as $claim) {
            if ( ! empty($claims[$claim]) && filter_var($claims[$claim], FILTER_VALIDATE_EMAIL)) return strtolower((string)$claims[$claim]);
        }

        return '';
    }
}
