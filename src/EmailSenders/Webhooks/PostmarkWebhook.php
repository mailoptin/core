<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Webhooks;

/**
 * Postmark's Bounce and SpamComplaint webhooks. Postmark doesn't sign them: the secret (sent as the
 * X-Webhook-Secret header by Postmark::install_webhook()) proves where they came from, so it's required.
 */
class PostmarkWebhook extends AbstractWebhook
{
    const HARD = ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated'];

    const SOFT = ['SoftBounce', 'Transient', 'DnsError', 'Blocked', 'SMTPApiError'];

    public function handle(Request $request)
    {
        if ('' === $this->config('secret') || ! $this->secret_matches($request)) {
            return Result::rejected('Missing or wrong webhook secret.');
        }

        $data = $request->json();
        $type = (string)($data['RecordType'] ?? '');
        $kind = (string)($data['Type'] ?? '');

        if ('SpamComplaint' === $type || 'SpamComplaint' === $kind) {
            $event = DeliveryEvent::COMPLAINT;
        } elseif ('Bounce' === $type && in_array($kind, self::HARD, true)) {
            $event = DeliveryEvent::HARD_BOUNCE;
        } elseif ('Bounce' === $type && in_array($kind, self::SOFT, true)) {
            $event = DeliveryEvent::SOFT_BOUNCE;
        } else {
            return new Result(true);
        }

        $email = (string)($data['Email'] ?? '');

        if ('' === $email) return new Result(true);

        return new Result(true, [
            new DeliveryEvent(
                $event,
                $email,
                'postmark',
                (string)($data['MessageID'] ?? ''),
                (array)($data['Metadata'] ?? []),
                // The SMTP reply ("smtp;550 5.1.1 ...") first: it says more than Postmark's general description.
                trim(($data['Details'] ?? '') . ' ' . ($data['Description'] ?? '')),
                DeliveryEvent::time($data['BouncedAt'] ?? $data['ReceivedAt'] ?? null)
            ),
        ]);
    }
}
