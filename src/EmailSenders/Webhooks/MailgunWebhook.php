<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Webhooks;

/**
 * Mailgun's webhooks, checked against the account's HTTP webhook signing key.
 *
 * Mailgun's signature covers only a timestamp and a token, not the event, so a signed pair could be
 * reattached to another event: old timestamps are refused, and with the cache callables, so is a token
 * seen before.
 *
 * Config: signing_key; secret (optional); max_age (seconds, default 900); confirm (bool, see Result::confirm()); cache_get / cache_set
 * (optional callables, keyed by token, to remember tokens already used).
 */
class MailgunWebhook extends AbstractWebhook
{
    public function handle(Request $request)
    {
        $data      = $request->json();
        $signature = isset($data['signature']) && is_array($data['signature']) ? $data['signature'] : [];
        $key       = $this->config('signing_key');
        $timestamp = (string)($signature['timestamp'] ?? '');
        $token     = (string)($signature['token'] ?? '');

        if ('' === $key || ! ctype_digit($timestamp) || '' === $token || ! is_string($signature['signature'] ?? null)) {
            return Result::rejected('Bad Mailgun signature.');
        }

        if ( ! hash_equals(hash_hmac('sha256', $timestamp . $token, $key), $signature['signature'])) {
            return Result::rejected('Bad Mailgun signature.');
        }

        $max_age = (int)($this->config['max_age'] ?? 900);

        if (abs(time() - (int)$timestamp) > $max_age) return Result::rejected('Mailgun signature too old.');

        $seen_key = 'mailgun_token_' . $token;

        if ($this->seen($seen_key)) return Result::rejected('Mailgun token already used.');

        if ( ! $this->secret_matches($request)) return Result::rejected('Wrong webhook secret.');

        $event = (array)($data['event-data'] ?? []);
        $name  = (string)($event['event'] ?? '');

        if ('complained' === $name) {
            $type = DeliveryEvent::COMPLAINT;
        } elseif ('failed' === $name) {
            $type = 'permanent' === ($event['severity'] ?? '') ? DeliveryEvent::HARD_BOUNCE : DeliveryEvent::SOFT_BOUNCE;

            // Mailgun's own suppressions ("Not delivering to previously bounced address") are hard too.
        } else {
            return $this->remember(new Result(true), [$seen_key]);
        }

        $email = (string)($event['recipient'] ?? '');

        if ('' === $email) return $this->remember(new Result(true), [$seen_key]);

        $status = (array)($event['delivery-status'] ?? []);

        return $this->remember(new Result(true, [
            new DeliveryEvent(
                $type,
                $email,
                'mailgun',
                (string)($event['message']['headers']['message-id'] ?? ''),
                (array)($event['user-variables'] ?? []),
                trim((string)($status['description'] ?? '') ?: (string)($status['message'] ?? '')),
                DeliveryEvent::time($event['timestamp'] ?? null)
            ),
        ]), [$seen_key]);
    }
}
