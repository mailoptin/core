<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Senders;

use MailOptin\Core\EmailSenders\ConnectionResult;
use MailOptin\Core\EmailSenders\Message;
use MailOptin\Core\EmailSenders\SendResult;
use MailOptin\Core\EmailSenders\WebhookSetupInterface;

/**
 * Mailgun's messages API.
 *
 * Config: api_key, domain (the sending domain in Mailgun), region ("us" or "eu").
 */
class Mailgun extends AbstractHttpSender implements WebhookSetupInterface
{
    public function id()
    {
        return 'mailgun';
    }

    protected function api()
    {
        return 'eu' === $this->config('region') ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
    }

    protected function domain()
    {
        return rawurlencode(strtolower($this->config('domain')));
    }

    protected function headers(array $extra = [])
    {
        return ['Authorization' => 'Basic ' . base64_encode('api:' . $this->config('api_key'))] + $extra;
    }

    protected function send_one(Message $message)
    {
        $list = function (array $addresses) {
            return implode(', ', array_map(function ($a) {
                return $a->format();
            }, $addresses));
        };

        $fields = [
            ['from', $message->from->format()],
            ['to', $list($message->to)],
            ['subject', $message->subject],
            ['o:tracking', 'no'],
        ];

        if ($message->cc) $fields[] = ['cc', $list($message->cc)];
        if ($message->bcc) $fields[] = ['bcc', $list($message->bcc)];
        if ($message->reply_to) $fields[] = ['h:Reply-To', $message->reply_to->format()];
        if ('' !== $message->text) $fields[] = ['text', $message->text];
        if ('' !== $message->html) $fields[] = ['html', $message->html];
        if ('' !== $message->stream) $fields[] = ['o:tag', $message->stream];

        foreach ($message->safe_headers() as $name => $value) $fields[] = ['h:' . $name, $value];
        foreach ($message->metadata as $name => $value) $fields[] = ['v:' . $name, $value];

        $boundary = 'mo' . bin2hex(random_bytes(12));
        $body     = self::multipart($fields, self::attachments($message), $boundary);

        $response = $this->http->request(
            'POST',
            $this->api() . '/v3/' . $this->domain() . '/messages',
            $this->headers(['Content-Type' => 'multipart/form-data; boundary=' . $boundary]),
            $body
        );

        if (200 === $response->status) {
            return SendResult::sent(trim((string)($response->json()['id'] ?? ''), '<>'));
        }

        $error = (string)($response->json()['message'] ?? '');

        // A domain that isn't set up, or isn't allowed to send, stops every email.
        if (404 === $response->status) return SendResult::auth($error ?: 'Mailgun doesn\'t know this sending domain.');

        return $this->result_for_failure($response, $error);
    }

    protected static function multipart(array $fields, array $files, $boundary)
    {
        $body = '';

        foreach ($fields as list($name, $value)) {
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . str_replace(['"', "\r", "\n"], '', $name) . "\"\r\n\r\n";
            $body .= $value . "\r\n";
        }

        foreach ($files as $file) {
            $name  = str_replace(['"', "\r", "\n"], '', $file['name']);
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="attachment"; filename="' . $name . "\"\r\n";
            $body .= 'Content-Type: ' . $file['type'] . "\r\n\r\n";
            $body .= base64_decode($file['content']) . "\r\n";
        }

        return $body . '--' . $boundary . "--\r\n";
    }

    public function test_connection()
    {
        $response = $this->http->request('GET', $this->api() . '/v3/domains/' . $this->domain(), $this->headers());

        if (404 === $response->status) {
            return new ConnectionResult(false, sprintf('Mailgun has no domain called "%s" in this region.', $this->config('domain')));
        }

        if (200 !== $response->status) {
            return $this->connection_failure($response, $response->json()['message'] ?? '');
        }

        $domain = (array)($response->json()['domain'] ?? []);
        $state  = (string)($domain['state'] ?? '');

        $details = ['domain' => (string)($domain['name'] ?? ''), 'state' => $state, 'domain_verified' => 'active' === $state];

        if ('active' !== $state) {
            return new ConnectionResult(false, sprintf('Connected, but Mailgun hasn\'t verified %s yet (its state is "%s").', $details['domain'], $state), $details);
        }

        return new ConnectionResult(true, sprintf('Connected to Mailgun domain %s.', $details['domain']), $details);
    }

    /**
     * Point the permanent_fail, temporary_fail and complained webhooks at $url. The result's details
     * hold the account's 'signing_key', which verifies the events.
     */
    public function install_webhook($url, $secret)
    {
        $base = $this->api() . '/v3/domains/' . $this->domain() . '/webhooks';
        $form = ['Content-Type' => 'application/x-www-form-urlencoded'];

        foreach (['permanent_fail', 'temporary_fail', 'complained'] as $event) {
            $existing = $this->http->request('GET', $base . '/' . $event, $this->headers());
            $urls     = 200 === $existing->status ? (array)($existing->json()['webhook']['urls'] ?? []) : [];

            // Keep the URLs other tools set for this event (Mailgun allows three), replacing an older
            // one of ours.
            $urls = array_values(array_filter($urls, function ($u) use ($url) {
                return is_string($u) && ! Postmark::same_endpoint($u, $url);
            }));

            if (empty($urls) && 200 !== $existing->status) {
                $response = $this->http->request('POST', $base, $this->headers($form), http_build_query(['id' => $event, 'url' => $url]));
            } else {
                $urls[]   = $url;
                $body     = implode('&', array_map(function ($u) {
                    return 'url=' . rawurlencode($u);
                }, array_slice($urls, -3)));
                $response = $this->http->request('PUT', $base . '/' . $event, $this->headers($form), $body);
            }

            if (200 !== $response->status) {
                return $this->connection_failure($response, (string)($response->json()['message'] ?? ''));
            }
        }

        $key = $this->http->request('GET', $this->api() . '/v5/accounts/http_signing_key', $this->headers());

        $signing_key = (string)($key->json()['http_signing_key'] ?? '');

        if ('' === $signing_key) {
            return new ConnectionResult(false, 'The webhooks are set up, but Mailgun didn\'t return the webhook signing key. Copy it from Mailgun\'s Webhooks page.');
        }

        return new ConnectionResult(true, 'Mailgun will send bounces and spam complaints to this site.', ['signing_key' => $signing_key]);
    }
}
