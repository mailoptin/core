<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Aws;

/**
 * AWS Signature Version 4, so SES and SNS can be called without the AWS SDK.
 */
class SignatureV4
{
    /** @var string */
    protected $access_key;

    /** @var string */
    protected $secret_key;

    /** @var string */
    protected $region;

    /** @var string */
    protected $service;

    public function __construct($access_key, $secret_key, $region, $service)
    {
        $this->access_key = (string)$access_key;
        $this->secret_key = (string)$secret_key;
        $this->region     = (string)$region;
        $this->service    = (string)$service;
    }

    /**
     * @param string   $method
     * @param string   $url
     * @param array    $headers name => value
     * @param string   $body
     * @param int|null $time    Unix time, for tests
     *
     * @return array the headers with Host, X-Amz-Date and Authorization added
     */
    public function sign($method, $url, array $headers, $body, $time = null)
    {
        $time  = null === $time ? time() : (int)$time;
        $parts = parse_url($url);
        $host  = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $amz_date = gmdate('Ymd\THis\Z', $time);
        $date     = gmdate('Ymd', $time);

        $headers['Host']       = $host;
        $headers['X-Amz-Date'] = $amz_date;

        $canonical_headers = [];
        foreach ($headers as $name => $value) {
            $canonical_headers[strtolower($name)] = preg_replace('/\s+/', ' ', trim((string)$value));
        }
        ksort($canonical_headers);

        $signed_headers = implode(';', array_keys($canonical_headers));

        $header_lines = '';
        foreach ($canonical_headers as $name => $value) {
            $header_lines .= $name . ':' . $value . "\n";
        }

        $canonical_request = implode("\n", [
            strtoupper($method),
            self::canonical_path($parts['path'] ?? '/', 's3' !== $this->service),
            self::canonical_query($parts['query'] ?? ''),
            $header_lines,
            $signed_headers,
            hash('sha256', (string)$body),
        ]);

        $scope = $date . '/' . $this->region . '/' . $this->service . '/aws4_request';

        $string_to_sign = implode("\n", ['AWS4-HMAC-SHA256', $amz_date, $scope, hash('sha256', $canonical_request)]);

        $key = hash_hmac('sha256', $date, 'AWS4' . $this->secret_key, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        $signature = hash_hmac('sha256', $string_to_sign, $key);

        $headers['Authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->access_key,
            $scope,
            $signed_headers,
            $signature
        );

        return $headers;
    }

    /**
     * Every service but S3 encodes each path segment twice: once in the URL, again here.
     *
     * @param string $path the path as it appears in the URL, already encoded
     */
    public static function canonical_path($path, $double_encode = true)
    {
        return implode('/', array_map(function ($segment) use ($double_encode) {
            $once = rawurlencode(rawurldecode($segment));

            return $double_encode ? rawurlencode($once) : $once;
        }, explode('/', $path)));
    }

    protected static function canonical_query($query)
    {
        if ('' === $query) return '';

        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ('' === $pair) continue;
            $kv      = explode('=', $pair, 2);
            $pairs[] = [rawurlencode(rawurldecode($kv[0])), rawurlencode(rawurldecode($kv[1] ?? ''))];
        }

        usort($pairs, function ($a, $b) {
            return strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]);
        });

        return implode('&', array_map(function ($p) {
            return $p[0] . '=' . $p[1];
        }, $pairs));
    }
}
