<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Webhooks;

interface WebhookInterface
{
    /**
     * Check the request came from the provider, then read its bounces and complaints. Events of other
     * kinds (deliveries, opens) are left out.
     *
     * @return Result
     */
    public function handle(Request $request);
}
