<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Senders;

use MailOptin\Core\EmailSenders\ConnectionResult;
use MailOptin\Core\EmailSenders\Http\ClientInterface;
use MailOptin\Core\EmailSenders\Http\Response;
use MailOptin\Core\EmailSenders\Http\WpClient;
use MailOptin\Core\EmailSenders\Message;
use MailOptin\Core\EmailSenders\SendResult;
use MailOptin\Core\EmailSenders\SenderInterface;

/**
 * Shared plumbing for senders that call a provider's HTTP API.
 */
abstract class AbstractHttpSender implements SenderInterface
{
    /** @var array */
    protected $config;

    /** @var ClientInterface */
    protected $http;

    /**
     * @param array                $config credentials and options, see each sender
     * @param ClientInterface|null $http   WordPress's HTTP API when null
     */
    public function __construct(array $config, ?ClientInterface $http = null)
    {
        $this->config = $config;
        $this->http   = $http ?: new WpClient();
    }

    protected function config($key, $default = '')
    {
        $value = $this->config[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    public function batch_size()
    {
        return 1;
    }

    public function max_per_second()
    {
        $limit = $this->config('max_per_second', null);

        return null === $limit || '' === $limit ? $this->default_max_per_second() : max(1, (int)$limit);
    }

    protected function default_max_per_second()
    {
        return null;
    }

    /**
     * One API call per message, for providers without a batch endpoint that keeps per-message content.
     */
    public function send(array $messages)
    {
        $results = [];

        foreach ($messages as $key => $message) {
            $results[$key] = $this->send_one($message);

            // Bad credentials won't fix themselves on the next message.
            if (SendResult::AUTH === $results[$key]->status) {
                foreach ($messages as $k => $_) {
                    if ( ! isset($results[$k])) $results[$k] = SendResult::auth($results[$key]->error);
                }
                break;
            }
        }

        return $results;
    }

    /**
     * @return SendResult
     */
    protected function send_one(Message $message)
    {
        return SendResult::failed('Not implemented.');
    }

    /**
     * Turn a whole-request failure into a result: retry what may work later, fail what won't.
     *
     * @param string $error the provider's explanation, when it gave one
     */
    protected function result_for_failure(Response $response, $error = '')
    {
        if (0 === $response->status) {
            // The request went out but no answer came back in time: the service may have sent it,
            // so trying again could send it twice. Not reaching it at all (DNS, a refused or timed-out
            // connection) is safe to retry.
            if (self::answer_timed_out($response->error)) {
                return SendResult::failed(sprintf('The sending service didn\'t answer in time (%s). It may still have sent this email, so it isn\'t sent again.', $response->error));
            }

            return SendResult::retry($response->error ?: 'No response from the sending service.');
        }

        $error = $error ?: sprintf('The sending service returned HTTP %d.', $response->status);

        if (401 === $response->status || 403 === $response->status) {
            return SendResult::auth($error);
        }

        if (429 === $response->status) {
            return SendResult::throttled($error, $this->retry_after($response));
        }

        if ($response->status >= 500 || 408 === $response->status) {
            return SendResult::retry($error);
        }

        return SendResult::failed($error);
    }

    /**
     * Whether a transport error says the request was sent but the answer timed out, as opposed to the
     * connection never being made.
     */
    public static function answer_timed_out($error)
    {
        $error = strtolower((string)$error);

        if (false === strpos($error, 'timed out') && false === strpos($error, 'curl error 28')) return false;

        return false === strpos($error, 'connection timed out') && false === strpos($error, 'resolving timed out') && false === strpos($error, 'connect timed out');
    }

    protected function retry_after(Response $response)
    {
        $value = $response->header('retry-after');

        if ('' === $value) return null;

        if (ctype_digit($value)) return (int)$value;

        $time = strtotime($value);

        return false === $time ? null : max(0, $time - time());
    }

    protected function connection_failure(Response $response, $error = '')
    {
        if (0 === $response->status) {
            return new ConnectionResult(false, 'Couldn\'t reach the sending service: ' . $response->error);
        }

        if (401 === $response->status || 403 === $response->status) {
            return new ConnectionResult(false, $error ?: 'The sending service didn\'t accept these credentials.');
        }

        return new ConnectionResult(false, $error ?: sprintf('The sending service returned HTTP %d.', $response->status));
    }

    protected static function json($data)
    {
        return (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Attachments as name, MIME type and base64 content. Unreadable files are left out.
     *
     * @return array[] name, type, content
     */
    protected static function attachments(Message $message)
    {
        $files = [];

        foreach ($message->attachments as $name => $path) {
            if ( ! is_string($path) || ! is_file($path) || ! is_readable($path)) continue;

            $type = function_exists('mime_content_type') ? (mime_content_type($path) ?: '') : '';

            $files[] = [
                'name'    => is_string($name) ? $name : basename($path),
                'type'    => $type ?: 'application/octet-stream',
                'content' => base64_encode((string)file_get_contents($path)),
            ];
        }

        return $files;
    }

    protected static function emails(array $addresses)
    {
        return array_map(function ($a) {
            return $a->email;
        }, $addresses);
    }
}
