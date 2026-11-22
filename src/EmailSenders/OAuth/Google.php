<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\OAuth;

/**
 * Gmail and Google Workspace. The app needs the Gmail API turned on and a "Web application" OAuth
 * client with the host's redirect URI.
 */
class Google extends Provider
{
    public function id()
    {
        return 'google';
    }

    public function name()
    {
        return 'Google';
    }

    protected function authorize_endpoint()
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function token_endpoint()
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function scopes()
    {
        // Full mail access is the only scope Google accepts for SMTP sign-in.
        return ['https://mail.google.com/', 'openid', 'email'];
    }

    protected function authorize_extras()
    {
        // offline + consent: a refresh token every time, even when the account connected before.
        return ['access_type' => 'offline', 'prompt' => 'consent'];
    }

    public function smtp()
    {
        return ['host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls'];
    }
}
