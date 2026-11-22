<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Senders;

use MailOptin\Core\EmailSenders\Aws\SignatureV4;
use MailOptin\Core\EmailSenders\ConnectionResult;
use MailOptin\Core\EmailSenders\Message;
use MailOptin\Core\EmailSenders\SendResult;
use MailOptin\Core\EmailSenders\WebhookSetupInterface;

/**
 * Amazon SES through the SESv2 API, signed with Signature Version 4 (no AWS SDK).
 *
 * Config: access_key, secret_key, region (default us-east-1), configuration_set (the one install_webhook()
 * returns, so bounces are reported), from_email (for the domain check), max_per_second (store the value
 * test_connection() reports). Message::$stream isn't used.
 */
class Ses extends AbstractHttpSender implements WebhookSetupInterface
{
    /**
     * The webhook URL's host and path as a name AWS accepts: letters, digits and dashes, 40 at most.
     */
    public static function site_name($url)
    {
        $parts = parse_url((string)$url);
        $name  = strtolower(($parts['host'] ?? '') . ($parts['path'] ?? ''));
        $name  = trim(preg_replace('/[^a-z0-9]+/', '-', $name), '-');

        if (strlen($name) > 40) $name = substr($name, 0, 31) . '-' . substr(md5($name), 0, 8);

        return $name ?: 'site';
    }

    public function id()
    {
        return 'ses';
    }

    protected function default_max_per_second()
    {
        // A new SES account in the sandbox allows 1 per second.
        return 1;
    }

    protected function region()
    {
        $region = strtolower($this->config('region') ?: 'us-east-1');

        return preg_match('/^[a-z]{2}(-[a-z]+)+-\d$/', $region) ? $region : 'us-east-1';
    }

    protected function endpoint($path)
    {
        return 'https://email.' . $this->region() . '.amazonaws.com' . $path;
    }

    protected function call($method, $path, $data = null, $service = 'ses', $url = null)
    {
        $url  = $url ?: $this->endpoint($path);
        $body = null === $data ? '' : (is_string($data) ? $data : self::json($data));

        $headers = [];
        if ('' !== $body) {
            $headers['Content-Type'] = is_string($data) ? 'application/x-www-form-urlencoded; charset=utf-8' : 'application/json';
        }

        // Temporary credentials (an IAM role, STS) come with a session token, signed with the rest.
        if ('' !== $this->config('session_token')) $headers['X-Amz-Security-Token'] = $this->config('session_token');

        $signer  = new SignatureV4($this->config('access_key'), $this->config('secret_key'), $this->region(), $service);
        $headers = $signer->sign($method, $url, $headers, $body);

        // The HTTP client sets Host itself.
        unset($headers['Host']);

        return $this->http->request($method, $url, $headers, $body);
    }

    protected function send_one(Message $message)
    {
        $data = [
            'FromEmailAddress' => $message->from->format_encoded(),
            'Destination'      => array_filter([
                'ToAddresses'  => array_map([self::class, 'format'], $message->to),
                'CcAddresses'  => array_map([self::class, 'format'], $message->cc),
                'BccAddresses' => array_map([self::class, 'format'], $message->bcc),
            ]),
        ];

        if ($message->reply_to) $data['ReplyToAddresses'] = [$message->reply_to->format_encoded()];

        // The configuration set carries the bounce notifications, so Message::$stream doesn't replace it.
        $set = $this->config('configuration_set');
        if ('' !== $set) $data['ConfigurationSetName'] = $set;

        foreach ($message->metadata as $name => $value) {
            $data['EmailTags'][] = ['Name' => $name, 'Value' => '' === $value ? '-' : $value];
        }

        if ($message->attachments) {
            $data['Content'] = ['Raw' => ['Data' => base64_encode(self::mime($message))]];
        } else {
            $body = [];
            if ('' !== $message->text) $body['Text'] = ['Data' => $message->text, 'Charset' => 'UTF-8'];
            if ('' !== $message->html) $body['Html'] = ['Data' => $message->html, 'Charset' => 'UTF-8'];

            $simple = ['Subject' => ['Data' => $message->subject, 'Charset' => 'UTF-8'], 'Body' => $body];

            foreach ($message->safe_headers() as $name => $value) {
                $simple['Headers'][] = ['Name' => $name, 'Value' => $value];
            }

            $data['Content'] = ['Simple' => $simple];
        }

        $response = $this->call('POST', '/v2/email/outbound-emails', $data);

        if (200 === $response->status) {
            return SendResult::sent($response->json()['MessageId'] ?? '');
        }

        return $this->result_for_error($response);
    }

    protected static function format($address)
    {
        return $address->format_encoded();
    }

    protected function result_for_error($response)
    {
        $body  = $response->json();
        $type  = self::error_type($response);
        $error = trim($type . ': ' . ($body['message'] ?? $body['Message'] ?? ''), ': ');

        // Problems with the account or its setup stop every email, not just this one.
        if (in_array($type, ['AccountSuspendedException', 'SendingPausedException', 'MailFromDomainNotVerifiedException', 'NotFoundException'], true)) {
            return SendResult::auth($error);
        }

        if ('TooManyRequestsException' === $type || 'LimitExceededException' === $type) {
            return SendResult::throttled($error, $this->retry_after($response));
        }

        // MessageRejected covers both a bad message and, in the sandbox, an unverified recipient.
        if ('MessageRejected' === $type && false !== stripos($error, 'not verified')) {
            return SendResult::auth($error);
        }

        return $this->result_for_failure($response, $error);
    }

    protected static function error_type($response)
    {
        $type = $response->header('x-amzn-errortype') ?: (string)($response->json()['__type'] ?? '');

        // "MessageRejected:http://internal.amazon.com/..." or "com.amazon...#MessageRejected"
        $type = strtok($type, ':');
        if (false !== strpos((string)$type, '#')) $type = substr($type, strrpos($type, '#') + 1);

        return (string)$type;
    }

    /**
     * A MIME message, for emails with attachments.
     */
    protected static function mime(Message $message)
    {
        $encode = function ($text) {
            return preg_match('/[^\x20-\x7e]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
        };

        $boundary = 'mo_' . bin2hex(random_bytes(12));
        $alt      = 'mo_alt_' . bin2hex(random_bytes(12));

        $lines   = [];
        $lines[] = 'From: ' . $message->from->format_encoded();
        $lines[] = 'To: ' . implode(', ', array_map([self::class, 'format'], $message->to));
        if ($message->cc) $lines[] = 'Cc: ' . implode(', ', array_map([self::class, 'format'], $message->cc));
        if ($message->reply_to) $lines[] = 'Reply-To: ' . $message->reply_to->format_encoded();
        $lines[] = 'Subject: ' . $encode(str_replace(["\r", "\n"], ' ', $message->subject));
        foreach ($message->safe_headers() as $name => $value) $lines[] = $name . ': ' . $value;
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $lines[] = '';
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: multipart/alternative; boundary="' . $alt . '"';
        $lines[] = '';

        foreach (['text/plain' => $message->text, 'text/html' => $message->html] as $type => $content) {
            if ('' === $content) continue;
            $lines[] = '--' . $alt;
            $lines[] = 'Content-Type: ' . $type . '; charset=UTF-8';
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = '';
            $lines[] = rtrim(chunk_split(base64_encode($content), 76, "\r\n"));
        }

        $lines[] = '--' . $alt . '--';

        foreach (self::attachments($message) as $file) {
            $name    = str_replace(['"', "\r", "\n"], '', $file['name']);
            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: ' . $file['type'] . '; name="' . $name . '"';
            $lines[] = 'Content-Disposition: attachment; filename="' . $name . '"';
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = '';
            $lines[] = rtrim(chunk_split($file['content'], 76, "\r\n"));
        }

        $lines[] = '--' . $boundary . '--';

        return implode("\r\n", $lines);
    }

    public function test_connection()
    {
        $response = $this->call('GET', '/v2/email/account');

        if (200 !== $response->status) {
            return $this->connection_failure($response, $response->json()['message'] ?? '');
        }

        $account = $response->json();
        $quota   = (array)($account['SendQuota'] ?? []);

        $details = [
            'sandbox'         => empty($account['ProductionAccessEnabled']),
            'sending_enabled' => ! empty($account['SendingEnabled']),
            'max_per_second'  => (int)($quota['MaxSendRate'] ?? 1),
            'max_per_day'     => (int)($quota['Max24HourSend'] ?? 0),
            'sent_last_day'   => (int)($quota['SentLast24Hours'] ?? 0),
        ];

        $from = $this->config('from_email');

        if ('' !== $from && false !== strpos($from, '@')) {
            $domain   = strtolower(substr($from, strrpos($from, '@') + 1));
            $identity = $this->call('GET', '/v2/email/identities/' . rawurlencode($domain));
            if (404 === $identity->status) $identity = $this->call('GET', '/v2/email/identities/' . rawurlencode($from));

            $info = $identity->json();

            $details['domain']          = $domain;
            $details['domain_verified'] = 200 === $identity->status && ! empty($info['VerifiedForSendingStatus']);
            $details['dkim']            = (string)($info['DkimAttributes']['Status'] ?? '');
        }

        if ( ! $details['sending_enabled']) {
            return new ConnectionResult(false, 'Sending is paused on this SES account.', $details);
        }

        if (isset($details['domain_verified']) && ! $details['domain_verified']) {
            return new ConnectionResult(false, sprintf('Connected, but %s isn\'t verified in SES in this region.', $details['domain']), $details);
        }

        $message = $details['sandbox']
            ? 'Connected. This account is in the SES sandbox: it can only send to verified addresses until AWS grants production access.'
            : 'Connected to Amazon SES.';

        return new ConnectionResult(true, $message, $details);
    }

    /**
     * Create an SNS topic subscribed to $url, and a configuration set whose bounces and complaints go
     * to it. Sends must then use that configuration set (set 'configuration_set' to the one returned).
     */
    public function install_webhook($url, $secret)
    {
        // Names unique to this site, so another site using the same AWS account keeps its own.
        $site  = self::site_name($url);
        $set   = $this->config('configuration_set') ?: 'mailoptin-' . $site;
        $topic = 'mailoptin-' . $site . '-email-events';

        $sns = function ($params) {
            $params['Version'] = '2010-03-31';

            return $this->call('POST', '/', http_build_query($params, '', '&', PHP_QUERY_RFC3986), 'sns', 'https://sns.' . $this->region() . '.amazonaws.com/');
        };

        $created = $sns(['Action' => 'CreateTopic', 'Name' => $topic]);

        if (200 !== $created->status || ! preg_match('#<TopicArn>([^<]+)</TopicArn>#', $created->body, $m)) {
            return $this->connection_failure($created, 'Couldn\'t create the SNS topic. The access key needs the sns:CreateTopic and sns:Subscribe permissions.');
        }

        $topic_arn = html_entity_decode($m[1]);

        $subscribed = $sns(['Action' => 'Subscribe', 'TopicArn' => $topic_arn, 'Protocol' => 'https', 'Endpoint' => $url]);

        if (200 !== $subscribed->status) {
            return $this->connection_failure($subscribed, 'Couldn\'t subscribe this site to the SNS topic.');
        }

        $made = $this->call('POST', '/v2/email/configuration-sets', ['ConfigurationSetName' => $set]);

        if (200 !== $made->status && 'AlreadyExistsException' !== self::error_type($made)) {
            return $this->connection_failure($made, 'Couldn\'t create the SES configuration set.');
        }

        $destination = [
            'Enabled'            => true,
            'MatchingEventTypes' => ['BOUNCE', 'COMPLAINT'],
            'SnsDestination'     => ['TopicArn' => $topic_arn],
        ];

        $path  = '/v2/email/configuration-sets/' . rawurlencode($set) . '/event-destinations';
        $name  = 'mailoptin-' . $site;
        $added = $this->call('POST', $path, ['EventDestinationName' => $name, 'EventDestination' => $destination]);

        if ('AlreadyExistsException' === self::error_type($added)) {
            $added = $this->call('PUT', $path . '/' . rawurlencode($name), ['EventDestination' => $destination]);
        }

        if (200 !== $added->status) {
            return $this->connection_failure($added, 'Couldn\'t send the configuration set\'s bounces and complaints to SNS.');
        }

        return new ConnectionResult(true, 'SES will send bounces and spam complaints to this site through SNS.', [
            'configuration_set' => $set,
            'topic_arn'         => $topic_arn,
        ]);
    }
}
