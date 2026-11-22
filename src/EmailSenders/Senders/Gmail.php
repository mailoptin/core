<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Senders;

/**
 * Gmail or Google Workspace, signed in with Google. Google allows about 500 emails a day from a free
 * account and 2,000 from Workspace.
 */
class Gmail extends OAuthSmtp
{
    public function id()
    {
        return 'gmail';
    }

    protected function provider_id()
    {
        return 'google';
    }
}
