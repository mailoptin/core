<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Senders;

/**
 * Microsoft 365 or Outlook.com, signed in with Microsoft. The mailbox needs "Authenticated SMTP"
 * turned on by its admin.
 */
class Microsoft365 extends OAuthSmtp
{
    public function id()
    {
        return 'microsoft365';
    }

    protected function provider_id()
    {
        return 'microsoft';
    }
}
