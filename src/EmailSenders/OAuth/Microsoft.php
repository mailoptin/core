<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\OAuth;

/**
 * Microsoft 365 (work and school accounts) and Outlook.com. The app is an Entra ID (Azure) app
 * registration with the host's redirect URI and the SMTP.Send permission; the mailbox needs
 * "Authenticated SMTP" turned on by its admin.
 */
class Microsoft extends Provider
{
    public function id()
    {
        return 'microsoft';
    }

    public function name()
    {
        return 'Microsoft';
    }

    protected function authorize_endpoint()
    {
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    }

    protected function token_endpoint()
    {
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    }

    protected function scopes()
    {
        return ['https://outlook.office.com/SMTP.Send', 'offline_access', 'openid', 'email'];
    }

    protected function authorize_extras()
    {
        return ['response_mode' => 'query', 'prompt' => 'select_account'];
    }

    protected function refresh_extras()
    {
        // Microsoft wants the scope again on refresh.
        return ['scope' => implode(' ', $this->scopes())];
    }

    public function smtp()
    {
        return ['host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls'];
    }
}
