<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Webhooks;

/**
 * SendGrid's signed event webhook (ECDSA over the timestamp and raw body).
 *
 * Config: public_key (base64, as SendGrid shows it); secret (optional); max_age (seconds, default 73 hours).
 */
class SendGridWebhook extends AbstractWebhook
{
    /** Event fields that aren't the custom_args a message was sent with. */
    const STANDARD = [
        'email', 'timestamp', 'event', 'sg_event_id', 'sg_message_id', 'smtp-id', 'category', 'reason', 'status',
        'type', 'bounce_classification', 'ip', 'tls', 'cert_err', 'useragent', 'url', 'url_offset', 'asm_group_id',
        'attempt', 'response', 'marketing_campaign_id', 'marketing_campaign_name', 'pool', 'sg_machine_open',
        'sg_template_id', 'sg_template_name', 'send_at', 'sg_content_type',
    ];

    public function handle(Request $request)
    {
        $key = $this->config('public_key');

        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $key), 64, "\n") . "-----END PUBLIC KEY-----\n";

        $signature = base64_decode($request->header('x-twilio-email-event-webhook-signature'), true);
        $timestamp = $request->header('x-twilio-email-event-webhook-timestamp');

        $public = '' === $key ? false : @openssl_pkey_get_public($pem);

        if ( ! $public || ! $signature || '' === $timestamp || 1 !== openssl_verify($timestamp . $request->body, $signature, $public, OPENSSL_ALGO_SHA256)) {
            return Result::rejected('Bad SendGrid signature.');
        }

        if ( ! $this->secret_matches($request)) return Result::rejected('Wrong webhook secret.');

        // A signed request can't be replayed long after: SendGrid retries failed deliveries for up to
        // 72 hours, so anything signed before that is refused.
        $max_age = (int)($this->config['max_age'] ?? 73 * 3600);

        if ( ! ctype_digit((string)$timestamp) || abs(time() - (int)$timestamp) > $max_age) return Result::rejected('SendGrid signature too old.');

        $events = [];

        foreach ($request->json() as $item) {
            if ( ! is_array($item) || empty($item['email'])) continue;

            $name = (string)($item['event'] ?? '');

            if ('spamreport' === $name) {
                $type = DeliveryEvent::COMPLAINT;
            } elseif ('bounce' === $name) {
                // "blocked" is the receiving server refusing for now, not a dead address.
                $type = 'blocked' === ($item['type'] ?? '') ? DeliveryEvent::SOFT_BOUNCE : DeliveryEvent::HARD_BOUNCE;
            } else {
                continue;
            }

            $metadata = [];
            foreach ($item as $field => $value) {
                if ( ! in_array($field, self::STANDARD, true) && is_scalar($value)) $metadata[$field] = $value;
            }

            $events[] = new DeliveryEvent(
                $type,
                (string)$item['email'],
                'sendgrid',
                // "abc123.filter0001p1las1-1234-5" starts with the X-Message-Id the send returned.
                strtok((string)($item['sg_message_id'] ?? ''), '.'),
                $metadata,
                (string)($item['reason'] ?? ''),
                DeliveryEvent::time($item['timestamp'] ?? null)
            );
        }

        return new Result(true, $events);
    }
}
