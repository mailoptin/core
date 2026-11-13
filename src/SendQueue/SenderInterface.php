<?php

namespace MailOptin\Core\SendQueue;

interface SenderInterface
{
    /**
     * Send one queued email.
     *
     * @param array $row send queue row
     * @param int $campaign_log_id
     *
     * @return true|\WP_Error a WP_Error with ['retryable' => true] in its data is retried later.
     */
    public function send($row, $campaign_log_id);
}
