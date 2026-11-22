<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Senders;

use MailOptin\Core\EmailSenders\ConnectionResult;
use MailOptin\Core\EmailSenders\Message;
use MailOptin\Core\EmailSenders\SendResult;
use MailOptin\Core\EmailSenders\WebhookSetupInterface;

/**
 * Postmark, through its batch endpoint: up to 500 emails per call.
 *
 * Config: server_token, message_stream (default "outbound"; Message::$stream overrides it), webhook_streams
 * (the streams install_webhook() covers; default message_stream: list every stream you send through).
 */
class Postmark extends AbstractHttpSender implements WebhookSetupInterface
{
    const API = 'https://api.postmarkapp.com';

    public function id()
    {
        return 'postmark';
    }

    public function batch_size()
    {
        return 500;
    }

    protected function headers()
    {
        return [
            'Accept'                  => 'application/json',
            'Content-Type'            => 'application/json',
            'X-Postmark-Server-Token' => $this->config('server_token'),
        ];
    }

    /** Postmark refuses a request over 50 MB; batches stay under this, attachments included. */
    const MAX_BATCH_BYTES = 40000000;

    public function send(array $messages)
    {
        $results = [];
        $batch   = [];
        $bytes   = 0;

        foreach ($messages as $key => $message) {
            $problem = $message->problem();
            if ('' !== $problem) {
                $results[$key] = SendResult::failed($problem);
                continue;
            }

            $payload = $this->payload($message);
            $size    = strlen(self::json($payload));

            // Up to 500 emails and MAX_BATCH_BYTES per request.
            if ($batch && (count($batch) >= $this->batch_size() || $bytes + $size > static::MAX_BATCH_BYTES)) {
                $results += $this->send_batch($batch);
                $batch = [];
                $bytes = 0;
            }

            $batch[$key] = $payload;
            $bytes      += $size;
        }

        if ($batch) $results += $this->send_batch($batch);

        // In the order given.
        $ordered = [];
        foreach (array_keys($messages) as $key) $ordered[$key] = $results[$key];

        return $ordered;
    }

    /**
     * @param array $payloads key => Postmark email
     */
    protected function send_batch(array $payloads)
    {
        $keys    = array_keys($payloads);
        $payload = array_values($payloads);

        $response = $this->http->request('POST', self::API . '/email/batch', $this->headers(), self::json($payload));
        $body     = $response->json();

        if (200 !== $response->status || ! isset($body[0])) {
            $error = $body['Message'] ?? '';

            // 422 with error code 10: the server token is wrong.
            $result = 422 === $response->status && 10 === (int)($body['ErrorCode'] ?? 0)
                ? SendResult::auth($error)
                : $this->result_for_failure($response, $error);

            // One result object per email, so a caller changing one doesn't change them all.
            $results = [];
            foreach ($keys as $key) $results[$key] = clone $result;

            return $results;
        }

        $results = [];

        foreach ($keys as $i => $key) {
            $item          = $body[$i] ?? ['ErrorCode' => -1, 'Message' => 'Missing from Postmark\'s response.'];
            $results[$key] = self::result_for_item($item);
        }

        return $results;
    }

    /**
     * @see https://postmarkapp.com/developer/api/overview#error-codes
     */
    protected static function result_for_item(array $item)
    {
        $code    = (int)($item['ErrorCode'] ?? -1);
        $message = (string)($item['Message'] ?? '');

        if (0 === $code) return SendResult::sent($item['MessageID'] ?? '');

        // Bad token, unknown or unconfirmed sender signature, account paused or out of credits.
        if (in_array($code, [10, 400, 401, 405], true)) return SendResult::auth($message);

        // An account pending approval can only send to its own domain: this recipient fails, the rest
        // of the queue doesn't stop.
        if (412 === $code) return SendResult::failed($message);

        // Rate limited, or Postmark's own trouble.
        if (429 === $code) return SendResult::throttled($message);

        if (in_array($code, [500, 501], true) || $code < 0) return SendResult::retry($message);

        return SendResult::failed($message);
    }

    protected function payload(Message $message)
    {
        $list = function (array $addresses) {
            return implode(', ', array_map(function ($a) {
                return $a->format();
            }, $addresses));
        };

        $data = [
            'From'          => $message->from->format(),
            'To'            => $list($message->to),
            'Subject'       => $message->subject,
            'MessageStream' => $this->stream($message),
            // The plugin tracks opens and clicks itself.
            'TrackOpens'    => false,
            'TrackLinks'    => 'None',
        ];

        if ($message->cc) $data['Cc'] = $list($message->cc);
        if ($message->bcc) $data['Bcc'] = $list($message->bcc);
        if ($message->reply_to) $data['ReplyTo'] = $message->reply_to->format();
        if ('' !== $message->html) $data['HtmlBody'] = $message->html;
        if ('' !== $message->text) $data['TextBody'] = $message->text;
        // Postmark allows 10 metadata fields, keys up to 20 characters and values up to 80.
        foreach (array_slice($message->metadata, 0, 10, true) as $key => $value) {
            $data['Metadata'][substr($key, 0, 20)] = substr($value, 0, 80);
        }

        foreach ($message->safe_headers() as $name => $value) {
            $data['Headers'][] = ['Name' => $name, 'Value' => $value];
        }

        foreach (self::attachments($message) as $file) {
            $data['Attachments'][] = ['Name' => $file['name'], 'Content' => $file['content'], 'ContentType' => $file['type']];
        }

        return $data;
    }

    /**
     * The message stream: the message's own, the transactional one for Message::TRANSACTIONAL, or
     * the configured broadcast stream.
     */
    protected function stream(Message $message)
    {
        if (Message::TRANSACTIONAL === $message->stream) return $this->config('transactional_stream') ?: 'outbound';

        return $message->stream ?: ($this->config('message_stream') ?: 'outbound');
    }

    public function test_connection()
    {
        $response = $this->http->request('GET', self::API . '/server', $this->headers());

        if (200 !== $response->status) {
            return $this->connection_failure($response, $response->json()['Message'] ?? '');
        }

        $server  = $response->json();
        $details = ['server' => (string)($server['Name'] ?? '')];

        $stream = $this->config('message_stream') ?: 'outbound';
        $check  = $this->http->request('GET', self::API . '/message-streams/' . rawurlencode($stream), $this->headers());

        if (200 !== $check->status) {
            return new ConnectionResult(false, sprintf('Connected, but this server has no message stream called "%s".', $stream), $details);
        }

        $details['stream_type'] = (string)($check->json()['MessageStreamType'] ?? '');

        return new ConnectionResult(true, sprintf('Connected to Postmark server "%s".', $details['server']), $details);
    }

    public function install_webhook($url, $secret)
    {
        // Bounces from campaigns and from transactional email (SiteMailer's) both come back.
        $streams = (array)($this->config['webhook_streams'] ?? []);
        if (empty($streams)) $streams = [$this->config('message_stream') ?: 'outbound', $this->config('transactional_stream') ?: 'outbound'];

        $ids = [];

        foreach (array_unique(array_filter(array_map('strval', $streams))) as $stream) {
            $data = [
                'Url'           => $url,
                'MessageStream' => $stream,
                'HttpHeaders'   => [['Name' => 'X-Webhook-Secret', 'Value' => (string)$secret]],
                'Triggers'      => [
                    'Bounce'        => ['Enabled' => true, 'IncludeContent' => false],
                    'SpamComplaint' => ['Enabled' => true, 'IncludeContent' => false],
                ],
            ];

            $existing = null;
            $list     = $this->http->request('GET', self::API . '/webhooks?MessageStream=' . rawurlencode($stream), $this->headers());

            foreach ((array)($list->json()['Webhooks'] ?? []) as $hook) {
                if (self::same_endpoint($hook['Url'] ?? '', $url)) $existing = $hook;
            }

            $response = $existing
                ? $this->http->request('PUT', self::API . '/webhooks/' . (int)$existing['ID'], $this->headers(), self::json($data))
                : $this->http->request('POST', self::API . '/webhooks', $this->headers(), self::json($data));

            if (200 !== $response->status) {
                return $this->connection_failure($response, $response->json()['Message'] ?? '');
            }

            $ids[$stream] = (int)($response->json()['ID'] ?? 0);
        }

        return new ConnectionResult(true, 'Postmark will send bounces and spam complaints to this site.', ['webhook_ids' => $ids]);
    }

    /**
     * The same webhook address, ignoring the query string, where a secret may have changed.
     */
    public static function same_endpoint($a, $b)
    {
        return strtok((string)$a, '?') === strtok((string)$b, '?');
    }
}
