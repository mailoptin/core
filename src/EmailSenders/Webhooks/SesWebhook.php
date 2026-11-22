<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Webhooks;

/**
 * Amazon SES bounces and complaints, delivered by SNS. Each SNS message is checked against Amazon's
 * signing certificate, and a subscription request is confirmed once its signature checks out.
 *
 * Config (at least one of topic_arn and secret is required):
 * - topic_arn: only accept messages from this topic.
 * - secret: require the "key" query argument.
 * - cache_get / cache_set (optional callables): keep downloaded certificates, keyed by URL, so each
 *   request doesn't fetch one, and remember notifications already handled. For example transients
 *   kept for at least max_age.
 * - confirm (bool): remember notifications as handled only on Result::confirm(), so a retry after a
 *   failed handling is handled again.
 * - max_age (seconds, default 86400): refuse notifications older than this. A signed message can
 *   otherwise be replayed at any time; SNS keeps the original timestamp when it retries.
 */
class SesWebhook extends AbstractWebhook
{
    public function handle(Request $request)
    {
        $data = $request->json();

        // Anyone can have Amazon sign a message from their own topic: without a topic or a secret
        // to check, a signature alone proves nothing.
        if ('' === $this->config('topic_arn') && '' === $this->config('secret')) {
            return Result::rejected('Set topic_arn or secret.');
        }

        if ( ! $this->secret_matches($request)) return Result::rejected('Wrong webhook secret.');

        if ( ! $this->verify($data)) return Result::rejected('Bad SNS signature.');

        $topic = $this->config('topic_arn');

        if ('' !== $topic && ($data['TopicArn'] ?? '') !== $topic) return Result::rejected('Message from another SNS topic.');

        $type = (string)($data['Type'] ?? '');

        if ('SubscriptionConfirmation' === $type) {
            $url = (string)($data['SubscribeURL'] ?? '');

            $result = new Result(true);

            if (self::is_aws_url($url, false)) {
                $confirm           = $this->http->request('GET', $url);
                $result->confirmed = 200 === $confirm->status;
                if ( ! $result->confirmed) $result->error = 'Couldn\'t confirm the SNS subscription.';
            }

            return $result;
        }

        if ('Notification' !== $type) return new Result(true);

        $sent_at = strtotime((string)($data['Timestamp'] ?? ''));
        $max_age = (int)($this->config['max_age'] ?? 86400);

        if (false === $sent_at || abs(time() - $sent_at) > $max_age) return Result::rejected('SNS message too old.');

        // A retry or a replay of a message already handled: accept it, so SNS stops retrying, but
        // report nothing.
        $key = 'ses_message_' . (string)($data['MessageId'] ?? '');

        if ($this->seen($key)) return new Result(true);

        $message = json_decode((string)($data['Message'] ?? ''), true);

        return $this->remember(new Result(true, is_array($message) ? self::events($message) : []), [$key]);
    }

    /**
     * @return DeliveryEvent[]
     */
    public static function events(array $message)
    {
        $kind = (string)($message['eventType'] ?? $message['notificationType'] ?? '');
        $mail = (array)($message['mail'] ?? []);

        $metadata = [];
        foreach ((array)($mail['tags'] ?? []) as $name => $values) {
            if (0 === strpos((string)$name, 'ses:') || 0 === strpos((string)$name, 'aws:')) continue;
            $metadata[$name] = is_array($values) ? (string)reset($values) : (string)$values;
        }

        $message_id = (string)($mail['messageId'] ?? '');
        $events     = [];

        if ('Bounce' === $kind) {
            $bounce = (array)($message['bounce'] ?? []);
            $type   = 'Permanent' === ($bounce['bounceType'] ?? '') ? DeliveryEvent::HARD_BOUNCE : DeliveryEvent::SOFT_BOUNCE;

            foreach ((array)($bounce['bouncedRecipients'] ?? []) as $recipient) {
                if (empty($recipient['emailAddress'])) continue;

                $events[] = new DeliveryEvent(
                    $type,
                    $recipient['emailAddress'],
                    'ses',
                    $message_id,
                    $metadata,
                    (string)($recipient['diagnosticCode'] ?? ($bounce['bounceSubType'] ?? '')),
                    DeliveryEvent::time($bounce['timestamp'] ?? null)
                );
            }
        }

        if ('Complaint' === $kind) {
            $complaint = (array)($message['complaint'] ?? []);

            // "not-spam" feedback is someone saying the opposite.
            if ('not-spam' === ($complaint['complaintFeedbackType'] ?? '')) return [];

            foreach ((array)($complaint['complainedRecipients'] ?? []) as $recipient) {
                if (empty($recipient['emailAddress'])) continue;

                $events[] = new DeliveryEvent(
                    DeliveryEvent::COMPLAINT,
                    $recipient['emailAddress'],
                    'ses',
                    $message_id,
                    $metadata,
                    (string)($complaint['complaintFeedbackType'] ?? ''),
                    DeliveryEvent::time($complaint['timestamp'] ?? null)
                );
            }
        }

        return $events;
    }

    protected function verify(array $data)
    {
        $cert_url = (string)($data['SigningCertURL'] ?? $data['SigningCertUrl'] ?? '');

        if ( ! self::is_aws_url($cert_url, true) || empty($data['Signature'])) return false;

        $fields = 'Notification' === ($data['Type'] ?? '')
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

        $string = '';
        foreach ($fields as $field) {
            if ( ! isset($data[$field])) {
                if ('Subject' === $field) continue;

                return false;
            }
            $string .= $field . "\n" . $data[$field] . "\n";
        }

        $certificate = $this->certificate($cert_url);

        if ('' === $certificate) return false;

        $public = @openssl_pkey_get_public($certificate);

        if ( ! $public) return false;

        $algorithm = '2' === (string)($data['SignatureVersion'] ?? '1') ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
        $signature = base64_decode((string)$data['Signature'], true);

        return false !== $signature && 1 === openssl_verify($string, $signature, $public, $algorithm);
    }

    /**
     * Only Amazon's own SNS hosts, over HTTPS. Certificates must also be .pem files.
     */
    public static function is_aws_url($url, $certificate)
    {
        $parts = parse_url((string)$url);

        if ( ! $parts || 'https' !== ($parts['scheme'] ?? '') || isset($parts['user']) || isset($parts['port'])) return false;

        if ( ! preg_match('/^sns\.[a-z0-9\-]+\.amazonaws\.com(\.cn)?$/', strtolower($parts['host'] ?? ''))) return false;

        return ! $certificate || '.pem' === substr($parts['path'] ?? '', -4);
    }

    protected function certificate($url)
    {
        $get = $this->config['cache_get'] ?? null;
        $set = $this->config['cache_set'] ?? null;

        if (is_callable($get)) {
            $cached = (string)call_user_func($get, $url);
            if (false !== strpos($cached, 'BEGIN CERTIFICATE')) return $cached;
        }

        $response = $this->http->request('GET', $url);

        if (200 !== $response->status || false === strpos($response->body, 'BEGIN CERTIFICATE')) return '';

        if (is_callable($set)) call_user_func($set, $url, $response->body);

        return $response->body;
    }
}
