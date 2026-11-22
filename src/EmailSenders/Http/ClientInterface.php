<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Http;

interface ClientInterface
{
    /**
     * @param string       $method
     * @param string       $url
     * @param array        $headers name => value
     * @param string|array $body    a string, or form fields
     *
     * @return Response
     */
    public function request($method, $url, array $headers = [], $body = '');
}
