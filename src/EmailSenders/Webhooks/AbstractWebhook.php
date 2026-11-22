<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Webhooks;

use MailOptin\Core\EmailSenders\Http\ClientInterface;
use MailOptin\Core\EmailSenders\Http\WpClient;

abstract class AbstractWebhook implements WebhookInterface
{
    /** @var array */
    protected $config;

    /** @var ClientInterface */
    protected $http;

    /**
     * @param array $config secret: when set, the request must carry it, in the X-Webhook-Secret
     *                      header or the "key" query argument; plus each provider's own keys
     */
    public function __construct(array $config = [], ?ClientInterface $http = null)
    {
        $this->config = $config;
        $this->http   = $http ?: new WpClient(10);
    }

    protected function config($key)
    {
        return trim((string)($this->config[$key] ?? ''));
    }

    /**
     * @return bool true when no secret is configured or the request carries it
     */
    protected function secret_matches(Request $request)
    {
        $secret = $this->config('secret');

        if ('' === $secret) return true;

        $given = $request->header('x-webhook-secret');
        if ('' === $given) $given = (string)($request->query['key'] ?? '');

        return '' !== $given && hash_equals($secret, $given);
    }

    /**
     * Whether a key (an SNS message, a Mailgun token, a SendGrid event) was handled before.
     */
    protected function seen($key)
    {
        $get = $this->config['cache_get'] ?? null;

        return is_callable($get) && (bool)call_user_func($get, $key);
    }

    /**
     * Remember keys as handled: straight away, or on $result->confirm() when the webhook was made
     * with 'confirm' => true (the host confirms once it has handled the events).
     *
     * @param string[] $keys
     */
    protected function remember(Result $result, array $keys)
    {
        $set = $this->config['cache_set'] ?? null;
        if ( ! is_callable($set) || ! $keys) return $result;

        $store = function () use ($set, $keys) {
            foreach ($keys as $key) call_user_func($set, $key, '1');
        };

        if ( ! empty($this->config['confirm'])) {
            $result->on_confirm($store);
        } else {
            $store();
        }

        return $result;
    }
}
