<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Webhooks;

/**
 * Brevo's transactional webhook. Brevo doesn't sign it: the secret (sent as the X-Webhook-Secret header
 * by Brevo::install_webhook()) proves where it came from, so it's required.
 */
class BrevoWebhook extends AbstractWebhook
{
    const TYPES = [
        'hard_bounce'   => DeliveryEvent::HARD_BOUNCE,
        'invalid_email' => DeliveryEvent::HARD_BOUNCE,
        // Brevo blocks addresses that bounced or complained before.
        'blocked'       => DeliveryEvent::HARD_BOUNCE,
        'soft_bounce'   => DeliveryEvent::SOFT_BOUNCE,
        'spam'          => DeliveryEvent::COMPLAINT,
        'complaint'     => DeliveryEvent::COMPLAINT,
    ];

    public function handle(Request $request)
    {
        if ('' === $this->config('secret') || ! $this->secret_matches($request)) {
            return Result::rejected('Missing or wrong webhook secret.');
        }

        $data  = $request->json();
        $items = isset($data[0]) ? $data : [$data];

        $events = [];

        foreach ($items as $item) {
            $type = self::TYPES[(string)($item['event'] ?? '')] ?? null;

            if ( ! $type || empty($item['email'])) continue;

            $metadata = json_decode((string)($item['X-Mailin-custom'] ?? ''), true);

            $events[] = new DeliveryEvent(
                $type,
                (string)$item['email'],
                'brevo',
                (string)($item['message-id'] ?? ''),
                is_array($metadata) ? $metadata : [],
                (string)($item['reason'] ?? ''),
                DeliveryEvent::time($item['ts_event'] ?? $item['date'] ?? null)
            );
        }

        return new Result(true, $events);
    }
}
