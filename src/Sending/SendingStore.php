<?php

namespace MailOptin\Core\Sending;

use MailOptin\Core\Admin\SettingsPage\SendingSettings;
use MailOptin\Core\EmailSenders\ConnectionResult;
use MailOptin\Core\EmailSenders\Fields;
use MailOptin\Core\EmailSenders\OAuth\Token;
use MailOptin\Core\EmailSenders\SenderInterface;
use MailOptin\Core\EmailSenders\Senders;
use MailOptin\Core\SendQueue\QueueRunner;

/**
 * Settings, credentials and connection state of the sending services the free plugin offers: any SMTP
 * server, Gmail and Microsoft 365.
 *
 * Credentials sit in the same encrypted option as MailOptin Premium's (mo_sending_credentials), keyed
 * by service, with the same key, so the two never lose each other's.
 */
class SendingStore
{
    const CREDENTIALS_OPTION = 'mo_sending_credentials';
    const STATE_OPTION       = 'mo_sending_state';

    /** Posted fields are named mailoptin_settings[mo_sending_{service}_{field}]. */
    const FIELD_PREFIX = 'mo_sending_';

    const SERVICES = ['smtp', 'gmail', 'microsoft365'];

    /** Signed in with OAuth, not a password. */
    const OAUTH_SERVICES = ['gmail' => 'google', 'microsoft365' => 'microsoft'];

    public static function label($service)
    {
        $labels = [
            'smtp'         => __('Other SMTP', 'mailoptin'),
            'gmail'        => __('Gmail', 'mailoptin'),
            'microsoft365' => __('Microsoft 365', 'mailoptin'),
        ];

        return $labels[$service] ?? $service;
    }

    public static function is_ours($service)
    {
        return in_array($service, self::SERVICES, true);
    }

    /**
     * Each service's settings fields, in MailOptin's words and the site's language.
     *
     * @return array service => field => definition
     */
    public static function fields()
    {
        $args = [
            'plugin'    => 'MailOptin',
            // The package's strings, translated through MailOptin's text domain.
            'translate' => function ($text) {
                return __($text, 'mailoptin'); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
            },
        ];

        $fields = [];
        foreach (self::SERVICES as $service) $fields[$service] = Fields::for_sender($service, $args);

        return $fields;
    }

    /**
     * The service chosen in the settings (MailOptin's or Premium's), connected or not.
     */
    public static function chosen()
    {
        $settings = get_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, []);

        return is_array($settings) ? (string)($settings[SendingSettings::SERVICE_SETTING] ?? 'wp_mail') : 'wp_mail';
    }

    /**
     * The free service emails go through now: the chosen one, once its connection test passed.
     *
     * @return string '' when none (WordPress, or a Premium service)
     */
    public static function active()
    {
        $service = self::chosen();

        if ( ! self::is_ours($service) || self::credentials_unreadable()) return '';

        return ! empty(self::state()['connected'][$service]) ? $service : '';
    }

    /* ---------------------------------------------------------------------------------------------
     * Stored values (shared with Premium: same options, same key)
     * ------------------------------------------------------------------------------------------ */

    protected static function key()
    {
        return hash('sha256', wp_salt('auth') . '|mo_sending_credentials', true);
    }

    public static function encrypt(array $data)
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox((string)wp_json_encode($data), $nonce, self::key()));
    }

    public static function decrypt($stored)
    {
        return self::open($stored) ?? [];
    }

    /**
     * @return array|null null when it can't be decrypted
     */
    protected static function open($stored)
    {
        $raw = base64_decode((string)$stored, true);

        if (false === $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;

        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());

        $data = false === $plain ? null : json_decode($plain, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Saved credentials that can't be decrypted: WordPress's security keys changed.
     */
    public static function credentials_unreadable()
    {
        $stored = (string)get_option(self::CREDENTIALS_OPTION, '');

        return '' !== $stored && null === self::open($stored);
    }

    public static function credentials()
    {
        return self::decrypt(get_option(self::CREDENTIALS_OPTION, ''));
    }

    public static function save_credentials(array $credentials)
    {
        update_option(self::CREDENTIALS_OPTION, self::encrypt($credentials), false);
    }

    /** Change one service's credentials, keeping everything else (Premium's included). */
    public static function update_service_credentials($service, array $changes)
    {
        $credentials           = self::credentials();
        $credentials[$service] = array_replace((array)($credentials[$service] ?? []), $changes);
        self::save_credentials($credentials);
    }

    public static function state()
    {
        $state = get_option(self::STATE_OPTION, []);

        return is_array($state) ? $state : [];
    }

    public static function update_state(array $changes)
    {
        $state = self::state();

        foreach ($changes as $key => $value) {
            if (is_array($value) && isset($state[$key]) && is_array($state[$key])) {
                $state[$key] = array_replace($state[$key], $value);
            } else {
                $state[$key] = $value;
            }
        }

        update_option(self::STATE_OPTION, $state, false);
    }

    /* ---------------------------------------------------------------------------------------------
     * Senders
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return SenderInterface|null
     */
    public static function sender($service)
    {
        if ( ! self::is_ours($service)) return null;

        return Senders::make($service, self::config($service));
    }

    public static function config($service)
    {
        $config = (array)(self::credentials()[$service] ?? []);

        foreach (self::fields()[$service] ?? [] as $field => $definition) {
            if ( ! isset($config[$field]) || '' === $config[$field]) $config[$field] = $definition['default'] ?? '';
        }

        if (isset(self::OAUTH_SERVICES[$service])) {
            $config['token'] = (array)($config['token'] ?? []);
            // Each refreshed token is stored straight away: the old one stops working within the hour.
            $config['on_token'] = function (Token $token) use ($service) {
                self::update_service_credentials($service, ['token' => $token->to_array()]);
            };
        }

        return $config;
    }

    /**
     * Whether the service has what it needs to try sending: a host for SMTP, a signed-in account for
     * Gmail and Microsoft 365.
     */
    public static function is_configured($service)
    {
        $config = (array)(self::credentials()[$service] ?? []);

        if ('smtp' === $service) return '' !== trim((string)($config['host'] ?? ''));

        if (isset(self::OAUTH_SERVICES[$service])) {
            return '' !== (string)($config['client_id'] ?? '') && '' !== (string)($config['client_secret'] ?? '')
                   && Token::from_array($config['token'] ?? [])->is_connected();
        }

        return false;
    }

    /** The address the signed-in account sends as, for Gmail and Microsoft 365. */
    public static function account_email($service)
    {
        return Token::from_array(self::credentials()[$service]['token'] ?? [])->email;
    }

    /* ---------------------------------------------------------------------------------------------
     * Saving the settings
     * ------------------------------------------------------------------------------------------ */

    /**
     * Take the credential fields out of the posted settings and keep them encrypted instead. A blank
     * secret keeps the saved one.
     */
    public static function take_credentials($data)
    {
        if ( ! is_array($data)) return $data;

        if (self::credentials_unreadable()) {
            // Old security keys: start again from what's posted now. Premium does the same for its own.
            delete_option(self::CREDENTIALS_OPTION);
            update_option(self::STATE_OPTION, [], false);
        }

        $credentials   = self::credentials();
        $changed       = [];
        $username_sent = array_key_exists(self::FIELD_PREFIX . 'smtp_username', $data);

        foreach (self::fields() as $service => $fields) {
            foreach ($fields as $field => $definition) {
                $key = self::FIELD_PREFIX . $service . '_' . $field;

                if ( ! array_key_exists($key, $data)) continue;

                // Posted raw (see Sending::skip_sanitize): only the slashes WordPress adds come off.
                $value = is_string($data[$key]) ? trim(wp_unslash($data[$key])) : '';
                unset($data[$key]);

                if ('secret' === ($definition['type'] ?? '') && '' === $value) continue;
                if ('port' === $field) $value = '' === $value ? '' : (string)absint($value);
                if ('host' === $field) $value = preg_replace('#^[a-z]+://#i', '', $value);

                if ((string)($credentials[$service][$field] ?? '') !== $value) {
                    $changed[$service] = true;
                    // A different app can't use the old app's sign-in.
                    if (in_array($field, ['client_id', 'client_secret'], true)) unset($credentials[$service]['token']);
                }

                $credentials[$service][$field] = $value;
            }
        }

        // A server without a login: no username, so the saved password goes too.
        if ($username_sent && '' === (string)($credentials['smtp']['username'] ?? '') && '' !== (string)($credentials['smtp']['password'] ?? '')) {
            $credentials['smtp']['password'] = '';
            $changed['smtp'] = true;
        }

        if ($changed) {
            self::save_credentials($credentials);
            // New settings need testing before they send.
            self::update_state(['connected' => array_fill_keys(array_keys($changed), false)]);
        }

        return $data;
    }

    /**
     * After saving: test the chosen service unless it already passed with these settings.
     */
    public static function after_save()
    {
        $service = self::chosen();

        if ( ! self::is_ours($service)) return;

        QueueRunner::unblock();

        if ( ! self::is_configured($service) || ! empty(self::state()['connected'][$service])) return;

        self::connect($service);
    }

    /**
     * Test the connection and remember the result.
     *
     * @return ConnectionResult
     */
    public static function connect($service)
    {
        $sender = self::sender($service);
        $test   = $sender ? $sender->test_connection() : new ConnectionResult(false, __('Unknown sending service.', 'mailoptin'));

        self::update_state([
            'tests'     => [$service => ['ok' => $test->ok, 'message' => $test->message, 'at' => time()]],
            'connected' => [$service => $test->ok],
        ]);

        return $test;
    }

    /** Forget a service's sign-in (Gmail, Microsoft 365), keeping the app's ID and secret. */
    public static function disconnect($service)
    {
        $credentials = self::credentials();
        unset($credentials[$service]['token']);
        self::save_credentials($credentials);
        self::update_state(['connected' => [$service => false], 'tests' => [$service => null]]);
    }
}
