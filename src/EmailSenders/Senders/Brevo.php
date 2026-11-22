<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Senders;

use MailOptin\Core\EmailSenders\ConnectionResult;
use MailOptin\Core\EmailSenders\Message;
use MailOptin\Core\EmailSenders\SendResult;
use MailOptin\Core\EmailSenders\WebhookSetupInterface;

/**
 * Brevo's transactional email API.
 *
 * Config: api_key.
 */
class Brevo extends AbstractHttpSender implements WebhookSetupInterface
{
    const API = 'https://api.brevo.com/v3';

    public function id()
    {
        return 'brevo';
    }

    protected function headers()
    {
        return ['api-key' => $this->config('api_key'), 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
    }

    protected function send_one(Message $message)
    {
        $people = function (array $addresses) {
            return array_map(function ($a) {
                return array_filter(['email' => $a->email, 'name' => $a->name]);
            }, $addresses);
        };

        $data = [
            'sender'  => array_filter(['email' => $message->from->email, 'name' => $message->from->name]),
            'to'      => $people($message->to),
            'subject' => $message->subject,
        ];

        if ($message->cc) $data['cc'] = $people($message->cc);
        if ($message->bcc) $data['bcc'] = $people($message->bcc);
        if ($message->reply_to) $data['replyTo'] = array_filter(['email' => $message->reply_to->email, 'name' => $message->reply_to->name]);
        if ('' !== $message->html) $data['htmlContent'] = $message->html;
        if ('' !== $message->text) $data['textContent'] = $message->text;
        if ('' !== $message->stream) $data['tags'] = [$message->stream];

        $headers = $message->safe_headers();
        // Brevo returns this header's value in its webhook events.
        if ($message->metadata) $headers['X-Mailin-custom'] = self::json($message->metadata);
        if ($headers) $data['headers'] = $headers;

        foreach (self::attachments($message) as $file) {
            $data['attachment'][] = ['content' => $file['content'], 'name' => $file['name']];
        }

        $response = $this->http->request('POST', self::API . '/smtp/email', $this->headers(), self::json($data));

        if (201 === $response->status || 200 === $response->status) {
            return SendResult::sent(trim((string)($response->json()['messageId'] ?? ''), '<>'));
        }

        $body  = $response->json();
        $error = (string)($body['message'] ?? '');

        if (429 === $response->status) {
            $reset = $response->header('x-sib-ratelimit-reset');

            return SendResult::throttled($error ?: 'Brevo\'s rate limit was reached.', '' === $reset ? null : (int)$reset);
        }

        // An unvalidated sender or a suspended account stops every email.
        if (in_array($body['code'] ?? '', ['unauthorized', 'permission_denied', 'account_under_validation', 'not_enough_credits'], true)) {
            return SendResult::auth($error);
        }

        return $this->result_for_failure($response, $error);
    }

    public function test_connection()
    {
        $response = $this->http->request('GET', self::API . '/account', $this->headers());

        if (200 !== $response->status) {
            return $this->connection_failure($response, $response->json()['message'] ?? '');
        }

        $account = $response->json();

        return new ConnectionResult(true, sprintf('Connected to Brevo account %s.', (string)($account['email'] ?? '')), [
            'company' => (string)($account['companyName'] ?? ''),
        ]);
    }

    /**
     * A transactional webhook for hard and soft bounces, blocked and invalid addresses and spam
     * complaints. Each event comes back with an X-Webhook-Secret header holding $secret.
     */
    public function install_webhook($url, $secret)
    {
        $data = [
            'url'         => $url,
            'description' => 'MailOptin bounces and complaints',
            'events'      => ['hardBounce', 'softBounce', 'blocked', 'invalid', 'spam'],
            'type'        => 'transactional',
            'headers'     => [['key' => 'X-Webhook-Secret', 'value' => (string)$secret]],
        ];

        $existing = 0;
        $list     = $this->http->request('GET', self::API . '/webhooks?type=transactional', $this->headers());

        foreach ((array)($list->json()['webhooks'] ?? []) as $hook) {
            if (Postmark::same_endpoint($hook['url'] ?? '', $url)) $existing = (int)($hook['id'] ?? 0);
        }

        if ($existing) {
            unset($data['type']);
            $response = $this->http->request('PUT', self::API . '/webhooks/' . $existing, $this->headers(), self::json($data));
            $ok       = 204 === $response->status || 200 === $response->status;
        } else {
            $response = $this->http->request('POST', self::API . '/webhooks', $this->headers(), self::json($data));
            $ok       = 201 === $response->status || 200 === $response->status;
        }

        if ( ! $ok) {
            return $this->connection_failure($response, (string)($response->json()['message'] ?? ''));
        }

        return new ConnectionResult(true, 'Brevo will send bounces and spam complaints to this site.', ['webhook_id' => $existing ?: (int)($response->json()['id'] ?? 0)]);
    }
}
