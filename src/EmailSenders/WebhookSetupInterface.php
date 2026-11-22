<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders;

/**
 * Senders that can point the provider's bounce and complaint webhook at a URL themselves.
 */
interface WebhookSetupInterface
{
    /**
     * Create the webhook, or update the one already pointing at this URL.
     *
     * @param string $url    where the provider should send events
     * @param string $secret sent back with each event where the provider supports it, checked by the
     *                       webhook parser
     *
     * @return ConnectionResult details may hold values the parser needs later, such as SendGrid's
     *                          'public_key' or Mailgun's 'signing_key'
     */
    public function install_webhook($url, $secret);
}
