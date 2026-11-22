<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Http;

/**
 * Requests through the WordPress HTTP API, so the site's proxy and SSL settings apply.
 */
class WpClient implements ClientInterface
{
    /** @var int */
    protected $timeout;

    public function __construct($timeout = 30)
    {
        $this->timeout = (int)$timeout;
    }

    public function request($method, $url, array $headers = [], $body = '')
    {
        $response = wp_remote_request($url, [
            'method'      => $method,
            'headers'     => $headers,
            'body'        => '' === $body ? null : $body,
            'timeout'     => $this->timeout,
            'redirection' => 0,
        ]);

        if (is_wp_error($response)) {
            return new Response(0, [], '', $response->get_error_message());
        }

        $headers = wp_remote_retrieve_headers($response);

        return new Response(
            (int)wp_remote_retrieve_response_code($response),
            is_object($headers) && method_exists($headers, 'getAll') ? $headers->getAll() : (array)$headers,
            wp_remote_retrieve_body($response)
        );
    }
}
