<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders;

/**
 * What each sender's settings form asks for, so every host plugin draws the same form.
 *
 * Each field: label, type (text, secret, select, number or choice), and optionally default, options
 * (value => label, for select and choice), help, and placeholder. Secrets are stored encrypted by
 * the host and never shown back.
 *
 * Words mention the host as %plugin% ("Filled in when %plugin% sets up the webhook"), filled in
 * from $args['plugin']. $args['translate'] is called on every string the form shows.
 */
class Fields
{
    const SES_REGIONS = [
        'us-east-1', 'us-east-2', 'us-west-1', 'us-west-2', 'ca-central-1', 'eu-west-1', 'eu-west-2', 'eu-west-3',
        'eu-central-1', 'eu-north-1', 'eu-south-1', 'ap-south-1', 'ap-southeast-1', 'ap-southeast-2',
        'ap-northeast-1', 'ap-northeast-2', 'ap-northeast-3', 'sa-east-1', 'af-south-1', 'me-south-1', 'il-central-1',
    ];

    /**
     * @param string $id a sender key, see Senders::ids()
     * @param array  $args plugin (the host's name, default "the plugin"), translate (callable)
     *
     * @return array field key => definition
     */
    public static function for_sender($id, array $args = [])
    {
        $fields = self::definitions()[$id] ?? [];

        return self::localise($fields, $args);
    }

    /**
     * For Gmail and Microsoft 365: the OAuth provider, and the steps to create the site's own app.
     *
     * @return array|null provider (google or microsoft), console (URL), steps (strings; %redirect_uri%
     *                    is the host's redirect URI), limit (a note on sending limits)
     */
    public static function oauth($id, array $args = [])
    {
        $oauth = [
            'gmail'        => [
                'provider' => 'google',
                'console'  => 'https://console.cloud.google.com/apis/credentials',
                'steps'    => [
                    'In Google Cloud Console, create a project (or pick one) and turn on the Gmail API.',
                    'Under OAuth consent screen, choose Internal for a Google Workspace account, or External and add your address as a test user.',
                    'Under Credentials, create an OAuth client ID of type "Web application".',
                    'Add this authorised redirect URI: %redirect_uri%',
                    'Paste the client ID and client secret here, save, and sign in with Google.',
                ],
                'limit'    => 'Gmail sends up to 2,000 emails a day from Google Workspace and 500 from a free account. Fine for a site\'s own emails; for campaigns to a large list, use Amazon SES, Postmark or Brevo.',
            ],
            'microsoft365' => [
                'provider' => 'microsoft',
                'console'  => 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
                'steps'    => [
                    'In Microsoft Entra admin center, go to App registrations and create a new registration.',
                    'Under Redirect URI, choose Web and add: %redirect_uri%',
                    'Under API permissions, add Office 365 Exchange Online → Delegated → SMTP.Send.',
                    'Under Certificates & secrets, create a client secret.',
                    'Paste the application (client) ID and the secret value here, save, and sign in with Microsoft.',
                    'If sign-in works but sending is refused, ask your Microsoft 365 admin to turn on Authenticated SMTP for the mailbox.',
                ],
                'limit'    => 'Microsoft 365 sends up to 10,000 emails a day per mailbox. For campaigns to a large list, use Amazon SES, Postmark or Brevo.',
            ],
        ][$id] ?? null;

        if ( ! $oauth) return null;

        $oauth['steps'] = array_map(function ($step) use ($args) {
            return self::words($step, $args);
        }, $oauth['steps']);
        $oauth['limit'] = self::words($oauth['limit'], $args);

        return $oauth;
    }

    protected static function definitions()
    {
        return [
            'wp_mail'      => [],
            'postmark'     => [
                'server_token'         => ['label' => 'Server API token', 'type' => 'secret', 'help' => 'Postmark → your server → API Tokens.'],
                'message_stream'       => ['label' => 'Broadcast stream', 'type' => 'text', 'default' => 'broadcast', 'help' => 'The message stream for campaigns. Create a Broadcasts stream in Postmark if you haven\'t.'],
                'transactional_stream' => ['label' => 'Transactional stream', 'type' => 'text', 'default' => 'outbound', 'help' => 'For welcome and confirmation emails.'],
            ],
            'ses'          => [
                'access_key' => ['label' => 'Access key ID', 'type' => 'text'],
                'secret_key' => ['label' => 'Secret access key', 'type' => 'secret', 'help' => 'Create an IAM user for %plugin% with the permissions ses:SendEmail, ses:GetAccount, ses:GetEmailIdentity, ses:CreateConfigurationSet, ses:CreateConfigurationSetEventDestination, ses:UpdateConfigurationSetEventDestination, sns:CreateTopic and sns:Subscribe.'],
                'region'     => ['label' => 'Region', 'type' => 'select', 'default' => 'us-east-1', 'options' => array_combine(self::SES_REGIONS, self::SES_REGIONS)],
            ],
            'sendgrid'     => [
                'api_key'    => ['label' => 'API key', 'type' => 'secret', 'help' => 'Give it the Mail Send and Event Webhook permissions.'],
                'region'     => ['label' => 'Region', 'type' => 'select', 'default' => 'global', 'options' => ['global' => 'Global', 'eu' => 'EU']],
                'public_key' => ['label' => 'Event webhook verification key', 'type' => 'secret', 'default' => '', 'help' => 'Filled in when %plugin% sets up the webhook. Only needed if you add the webhook by hand: SendGrid → Settings → Mail Settings → Event Webhook → Signed Event Webhook.'],
            ],
            'mailgun'      => [
                'api_key'     => ['label' => 'Private API key', 'type' => 'secret'],
                'domain'      => ['label' => 'Sending domain', 'type' => 'text', 'help' => 'As set up in Mailgun, for example mg.example.com.'],
                'region'      => ['label' => 'Region', 'type' => 'select', 'default' => 'us', 'options' => ['us' => 'US', 'eu' => 'EU']],
                'signing_key' => ['label' => 'HTTP webhook signing key', 'type' => 'secret', 'default' => '', 'help' => 'Filled in when %plugin% sets up the webhooks. Only needed if you add them by hand: Mailgun → Sending → Webhooks.'],
            ],
            'brevo'        => [
                'api_key' => ['label' => 'API key', 'type' => 'secret', 'help' => 'Brevo → SMTP & API → API Keys.'],
            ],
            'smtp'         => [
                'host'       => ['label' => 'SMTP host', 'type' => 'text', 'placeholder' => 'smtp.example.com'],
                'encryption' => ['label' => 'Encryption', 'type' => 'choice', 'default' => 'tls', 'options' => ['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'], 'help' => 'TLS on port 587 suits most servers.'],
                'port'       => ['label' => 'Port', 'type' => 'number', 'default' => '', 'placeholder' => '587', 'help' => 'Leave empty to use the usual port for the encryption: 587 for TLS, 465 for SSL, 25 for none.'],
                'username'   => ['label' => 'Username', 'type' => 'text', 'help' => 'Usually your email address.'],
                'password'   => ['label' => 'Password', 'type' => 'secret', 'help' => 'Gmail and Microsoft accounts need an app password here, or choose Gmail or Microsoft 365 to sign in instead.'],
            ],
            'gmail'        => [
                'client_id'     => ['label' => 'Client ID', 'type' => 'text', 'placeholder' => '…apps.googleusercontent.com'],
                'client_secret' => ['label' => 'Client secret', 'type' => 'secret'],
            ],
            'microsoft365' => [
                'client_id'     => ['label' => 'Application (client) ID', 'type' => 'text'],
                'client_secret' => ['label' => 'Client secret value', 'type' => 'secret'],
            ],
        ];
    }

    protected static function localise(array $fields, array $args)
    {
        foreach ($fields as $key => $field) {
            foreach (['label', 'help', 'placeholder'] as $part) {
                if (isset($field[$part])) $fields[$key][$part] = self::words($field[$part], $args);
            }
            if (isset($field['options'])) {
                foreach ($field['options'] as $value => $label) {
                    // Codes such as AWS regions are shown as they are.
                    if ((string)$value !== $label) $fields[$key]['options'][$value] = self::words($label, $args);
                }
            }
        }

        return $fields;
    }

    protected static function words($text, array $args)
    {
        $translate = $args['translate'] ?? null;
        if (is_callable($translate)) $text = (string)call_user_func($translate, $text);

        return str_replace('%plugin%', (string)($args['plugin'] ?? 'the plugin'), $text);
    }
}
