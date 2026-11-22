<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Senders;

use MailOptin\Core\EmailSenders\ConnectionResult;
use MailOptin\Core\EmailSenders\Message;
use MailOptin\Core\EmailSenders\SendResult;
use MailOptin\Core\EmailSenders\WebhookSetupInterface;

/**
 * SendGrid's v3 Mail Send API.
 *
 * Config: api_key, region ("global" or "eu").
 */
class SendGrid extends AbstractHttpSender implements WebhookSetupInterface
{
    public function id()
    {
        return 'sendgrid';
    }

    protected function api()
    {
        return 'eu' === $this->config('region') ? 'https://api.eu.sendgrid.com' : 'https://api.sendgrid.com';
    }

    protected function headers()
    {
        return ['Authorization' => 'Bearer ' . $this->config('api_key'), 'Content-Type' => 'application/json'];
    }

    protected function send_one(Message $message)
    {
        $people = function (array $addresses) {
            return array_map(function ($a) {
                return array_filter(['email' => $a->email, 'name' => $a->name]);
            }, $addresses);
        };

        $personalization = array_filter([
            'to'          => $people($message->to),
            'cc'          => $people($message->cc),
            'bcc'         => $people($message->bcc),
            'custom_args' => $message->metadata ?: null,
        ]);

        $content = [];
        // SendGrid wants text/plain before text/html.
        if ('' !== $message->text) $content[] = ['type' => 'text/plain', 'value' => $message->text];
        if ('' !== $message->html) $content[] = ['type' => 'text/html', 'value' => $message->html];

        $data = [
            'personalizations'  => [$personalization],
            'from'              => array_filter(['email' => $message->from->email, 'name' => $message->from->name]),
            'subject'           => $message->subject,
            'content'           => $content,
            'tracking_settings' => ['click_tracking' => ['enable' => false], 'open_tracking' => ['enable' => false]],
        ];

        if ($message->reply_to) $data['reply_to'] = array_filter(['email' => $message->reply_to->email, 'name' => $message->reply_to->name]);
        if ($message->safe_headers()) $data['headers'] = $message->safe_headers();
        if ('' !== $message->stream) $data['categories'] = [$message->stream];

        foreach (self::attachments($message) as $file) {
            $data['attachments'][] = ['content' => $file['content'], 'filename' => $file['name'], 'type' => $file['type'], 'disposition' => 'attachment'];
        }

        $response = $this->http->request('POST', $this->api() . '/v3/mail/send', $this->headers(), self::json($data));

        if (202 === $response->status || 200 === $response->status) {
            return SendResult::sent($response->header('x-message-id'));
        }

        return $this->result_for_failure($response, self::error($response));
    }

    protected static function error($response)
    {
        $messages = array_filter(array_map(function ($e) {
            return $e['message'] ?? '';
        }, (array)($response->json()['errors'] ?? [])));

        return implode(' ', $messages);
    }

    public function test_connection()
    {
        $response = $this->http->request('GET', $this->api() . '/v3/scopes', $this->headers());

        if (200 !== $response->status) {
            return $this->connection_failure($response, self::error($response));
        }

        $scopes = (array)($response->json()['scopes'] ?? []);

        if ( ! in_array('mail.send', $scopes, true)) {
            return new ConnectionResult(false, 'This API key can\'t send email. Give it the Mail Send permission.', ['scopes' => $scopes]);
        }

        return new ConnectionResult(true, 'Connected to SendGrid.', [
            'can_set_up_webhook' => in_array('user.webhooks.event.settings.create', $scopes, true) || in_array('user.webhooks.event.settings.update', $scopes, true),
        ]);
    }

    /**
     * Adds a signed event webhook for bounces, blocks and spam reports. The result's details hold the
     * 'public_key' that verifies its events.
     */
    public function install_webhook($url, $secret)
    {
        $data = [
            'enabled'     => true,
            'url'         => $url,
            'friendly_name' => 'MailOptin',
            'bounce'      => true,
            'spam_report' => true,
            'dropped'     => false,
            'deferred'    => false,
            'delivered'   => false,
            'processed'   => false,
            'open'        => false,
            'click'       => false,
            'unsubscribe' => false,
            'group_unsubscribe' => false,
            'group_resubscribe' => false,
        ];

        $existing = '';
        $list     = $this->http->request('GET', $this->api() . '/v3/user/webhooks/event/settings/all', $this->headers());

        foreach ((array)($list->json()['webhooks'] ?? []) as $hook) {
            if (Postmark::same_endpoint($hook['url'] ?? '', $url)) $existing = (string)($hook['id'] ?? '');
        }

        $response = '' !== $existing
            ? $this->http->request('PATCH', $this->api() . '/v3/user/webhooks/event/settings/' . rawurlencode($existing), $this->headers(), self::json($data))
            : $this->http->request('POST', $this->api() . '/v3/user/webhooks/event/settings', $this->headers(), self::json($data));

        if ( ! in_array($response->status, [200, 201], true)) {
            return $this->connection_failure($response, self::error($response) ?: 'Couldn\'t add the event webhook. The API key needs the Event Webhook permission.');
        }

        $id = (string)($response->json()['id'] ?? $existing);

        $signed = $this->http->request('PATCH', $this->api() . '/v3/user/webhooks/event/settings/signed/' . rawurlencode($id), $this->headers(), self::json(['enabled' => true]));

        $public_key = (string)($signed->json()['public_key'] ?? '');

        if (200 !== $signed->status || '' === $public_key) {
            return $this->connection_failure($signed, self::error($signed) ?: 'Couldn\'t turn on signing for the event webhook.');
        }

        return new ConnectionResult(true, 'SendGrid will send bounces and spam reports to this site.', ['webhook_id' => $id, 'public_key' => $public_key]);
    }
}
