<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders;

use MailOptin\Core\EmailSenders\Http\ClientInterface;
use MailOptin\Core\EmailSenders\Webhooks\WebhookInterface;

/**
 * Builds the webhook handler for a provider.
 */
class Webhooks
{
    const MAP = [
        'postmark' => Webhooks\PostmarkWebhook::class,
        'ses'      => Webhooks\SesWebhook::class,
        'sendgrid' => Webhooks\SendGridWebhook::class,
        'mailgun'  => Webhooks\MailgunWebhook::class,
        'brevo'    => Webhooks\BrevoWebhook::class,
    ];

    /**
     * @return WebhookInterface|null
     */
    public static function make($provider, array $config = [], ?ClientInterface $http = null)
    {
        $class = self::MAP[$provider] ?? null;

        return $class ? new $class($config, $http) : null;
    }
}
