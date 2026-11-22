<?php

namespace MailOptin\Core\Sending;

use MailOptin\Core\Admin\SettingsPage\SendingSettings;
use MailOptin\Core\EmailSenders\Message;
use MailOptin\Core\EmailSenders\OAuth\OAuthException;
use MailOptin\Core\EmailSenders\OAuth\Provider;
use MailOptin\Core\EmailSenders\SenderInterface;
use MailOptin\Core\EmailSenders\SendResult;
use MailOptin\Core\EmailSenders\SiteMailer;
use MailOptin\Core\SendQueue\QueueRunner;

use function MailOptin\Core\current_user_has_privilege;

/**
 * Sending through any SMTP server, Gmail or Microsoft 365 (free), and sending every email the site
 * sends through the chosen service.
 */
class Sending
{
    /** mailoptin_settings keys of "All of this site's emails". */
    const SITE_ENABLED    = 'mo_site_mail_enabled';
    const SITE_FROM_EMAIL = 'mo_site_mail_from_email';
    const SITE_FROM_NAME  = 'mo_site_mail_from_name';
    const SITE_FORCE_FROM = 'mo_site_mail_force_from';

    /** SiteMailer's owner key for MailOptin. */
    const OWNER = 'mailoptin';

    const OAUTH_TRANSIENT = 'mo_sending_oauth_';

    public function __construct()
    {
        add_filter('wp_cspa_santized_data', [$this, 'take_credentials'], 9, 2);
        add_action('wp_cspa_after_persist_settings', [$this, 'after_save'], 9, 2);

        add_filter('mo_send_queue_sender', [$this, 'queue_sender'], 10);
        add_filter('mo_send_email_now', [$this, 'send_now'], 5, 2);

        add_action('admin_init', [$this, 'admin_actions']);
        add_action('admin_init', [$this, 'oauth_callback'], 1);

        add_action('mo_daily_recurring_job', [EmailLog::class, 'purge']);

        $this->register_site_mailer();
    }

    /* ---------------------------------------------------------------------------------------------
     * Saving
     * ------------------------------------------------------------------------------------------ */

    public function take_credentials($data, $option_name = '')
    {
        if (MAILOPTIN_SETTINGS_DB_OPTION_NAME !== $option_name || ! is_array($data)) return $data;

        $data = SendingStore::take_credentials($data);

        // An unticked box isn't posted at all: a hidden empty field stands in for it.
        foreach ([self::SITE_ENABLED, self::SITE_FORCE_FROM] as $key) {
            if (array_key_exists($key, $data)) $data[$key] = in_array($data[$key], ['1', 'on', 'true', true], true) ? '1' : '';
        }
        if (isset($data[self::SITE_FROM_EMAIL])) $data[self::SITE_FROM_EMAIL] = sanitize_email($data[self::SITE_FROM_EMAIL]);
        if (isset($data[self::SITE_FROM_NAME])) $data[self::SITE_FROM_NAME] = sanitize_text_field($data[self::SITE_FROM_NAME]);

        return $data;
    }

    public function after_save($data, $option_name = '')
    {
        if (MAILOPTIN_SETTINGS_DB_OPTION_NAME !== $option_name) return;

        SendingStore::after_save();

        // Turned on here: MailOptin sends the site's email, taking it from any other plugin.
        $settings = self::settings();
        if (array_key_exists(self::SITE_ENABLED, $settings)) {
            if ('1' === $settings[self::SITE_ENABLED]) {
                $previous = SiteMailer::claim(self::OWNER, 'MailOptin');
                if ($previous) set_transient('mo_site_mail_taken_' . get_current_user_id(), (string)$previous['label'], 120);
            } else {
                SiteMailer::release(self::OWNER);
            }
        }
    }

    public static function settings()
    {
        $settings = get_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, []);

        return is_array($settings) ? $settings : [];
    }

    /* ---------------------------------------------------------------------------------------------
     * Sending MailOptin's own emails
     * ------------------------------------------------------------------------------------------ */

    public function queue_sender($sender)
    {
        $client = SendingStore::sender(SendingStore::active());

        return $client ? new ServiceSender($client) : $sender;
    }

    /**
     * Confirmation, welcome and test emails, through the service when one of ours is connected.
     *
     * @param null|true|\WP_Error $result
     */
    public function send_now($result, $args)
    {
        if (null !== $result) return $result;

        $client = SendingStore::sender(SendingStore::active());
        if ( ! $client) return null;

        $blocked = QueueRunner::blocked();
        if ($blocked) return new \WP_Error('mo_sending_stopped', $blocked['error']);

        $message = ServiceSender::message($args['to'], $args['subject'], $args['html'], (string)$args['text'], (array)$args['headers']);
        $sent    = current($client->send([$message]));

        if ( ! $sent instanceof SendResult) return new \WP_Error('mo_sending_service_failed', __('The sending service returned no result.', 'mailoptin'));

        // Refused credentials stop the queue too, as they would from a campaign.
        if (SendResult::AUTH === $sent->status) QueueRunner::block($sent->error);

        return ServiceSender::to_queue_result($sent);
    }

    /* ---------------------------------------------------------------------------------------------
     * All of this site's emails
     * ------------------------------------------------------------------------------------------ */

    /**
     * The sender for the site's emails: MailOptin's connected service, or Premium's.
     *
     * @return SenderInterface|null
     */
    public static function site_sender()
    {
        $client = SendingStore::sender(SendingStore::active());

        return apply_filters('mailoptin_site_mail_sender', $client);
    }

    protected function register_site_mailer()
    {
        $settings = self::settings();

        (new SiteMailer(self::OWNER, [self::class, 'site_sender'], [
            'from_email' => (string)($settings[self::SITE_FROM_EMAIL] ?? ''),
            'from_name'  => (string)($settings[self::SITE_FROM_NAME] ?? ''),
            'force_from' => '1' === ($settings[self::SITE_FORCE_FROM] ?? ''),
        ], [EmailLog::class, 'add']))->register();
    }

    /* ---------------------------------------------------------------------------------------------
     * Buttons on the Sending tab
     * ------------------------------------------------------------------------------------------ */

    public static function action_url($action, $service)
    {
        return wp_nonce_url(add_query_arg(['mo_sending_action' => $action, 'service' => $service], admin_url('admin.php?page=' . MAILOPTIN_SETTINGS_SETTINGS_SLUG)), 'mo_sending_' . $action . '_' . $service);
    }

    public static function back_url($notice = '')
    {
        $url = remove_query_arg(['mo_sending_action', 'service', '_wpnonce', 'code', 'state', 'scope', 'session_state'], admin_url('admin.php?page=' . MAILOPTIN_SETTINGS_SETTINGS_SLUG . '&view=general'));

        return add_query_arg(array_filter(['mo_sending_notice' => $notice]), $url) . '#sending_settings';
    }

    public function admin_actions()
    {
        if (empty($_GET['mo_sending_action']) || empty($_GET['service'])) return;

        $action  = sanitize_key($_GET['mo_sending_action']);
        $service = sanitize_key($_GET['service']);

        if ( ! SendingStore::is_ours($service) || ! current_user_has_privilege()) return;

        check_admin_referer('mo_sending_' . $action . '_' . $service);

        switch ($action) {
            case 'test':
                $test = SendingStore::connect($service);
                $this->go($test->ok ? 'tested' : 'test_failed');
                break;

            case 'test_email':
                $this->go($this->test_email($service));
                break;

            case 'sign_in':
                $this->sign_in($service);
                break;

            case 'disconnect':
                SendingStore::disconnect($service);
                $this->go('disconnected');
                break;
        }
    }

    protected function go($notice)
    {
        wp_safe_redirect(self::back_url($notice));
        exit;
    }

    /**
     * A test email to the person clicking, through the service even before it's chosen.
     */
    protected function test_email($service)
    {
        $sender = SendingStore::sender($service);
        $to     = wp_get_current_user()->user_email;

        $settings = self::settings();
        $from     = (string)($settings[self::SITE_FROM_EMAIL] ?? '') ?: (string)($settings['from_email'] ?? '');
        if ('' === $from && $sender && method_exists($sender, 'default_from')) $from = (string)$sender->default_from();

        $message = Message::make([
            'from'    => ['email' => $from ?: get_option('admin_email'), 'name' => get_bloginfo('name')],
            'to'      => $to,
            'subject' => sprintf(__('Test email from %s', 'mailoptin'), get_bloginfo('name')),
            'text'    => sprintf(__("This test email went out through %s.\n\nIf it reached your inbox, MailOptin can send your site's emails.", 'mailoptin'), SendingStore::label($service)),
        ]);

        $result = $sender ? current($sender->send([$message])) : null;

        $ok = $result instanceof SendResult && SendResult::SENT === $result->status;

        SendingStore::update_state(['test_email' => [$service => [
            'ok'      => $ok,
            'message' => $ok ? sprintf(__('Test email sent to %s. Check your inbox.', 'mailoptin'), $to) : ($result ? $result->error : __('The sending service returned no result.', 'mailoptin')),
            'at'      => time(),
        ]]]);

        return $ok ? 'test_email_sent' : 'test_email_failed';
    }

    /* ---------------------------------------------------------------------------------------------
     * Signing in with Google or Microsoft
     * ------------------------------------------------------------------------------------------ */

    /**
     * Where the provider sends the person back. A path with no query string, because Microsoft
     * refuses query strings in some apps' redirect URIs; the state says which sign-in it belongs to.
     */
    public static function redirect_uri()
    {
        return admin_url('admin.php');
    }

    protected function sign_in($service)
    {
        $config   = (array)(SendingStore::credentials()[$service] ?? []);
        $provider = Provider::make(SendingStore::OAUTH_SERVICES[$service] ?? '');

        if ( ! $provider || '' === (string)($config['client_id'] ?? '') || '' === (string)($config['client_secret'] ?? '')) {
            $this->go('oauth_needs_app');
        }

        $state    = 'mo' . wp_generate_password(40, false);
        $verifier = Provider::verifier();

        set_transient(self::OAUTH_TRANSIENT . $state, ['service' => $service, 'verifier' => $verifier, 'user' => get_current_user_id()], 15 * MINUTE_IN_SECONDS);

        wp_redirect($provider->authorize_url($config['client_id'], self::redirect_uri(), $state, $verifier, SendingStore::account_email($service)));
        exit;
    }

    /**
     * Back from Google or Microsoft: swap the code for tokens and test the account.
     */
    public function oauth_callback()
    {
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if (0 !== strpos($state, 'mo') || ! preg_match('/^mo[A-Za-z0-9]{40}$/', $state)) return;

        $pending = get_transient(self::OAUTH_TRANSIENT . $state);
        if ( ! is_array($pending)) return;

        // Used once, by the person who started it.
        delete_transient(self::OAUTH_TRANSIENT . $state);
        if ((int)$pending['user'] !== get_current_user_id() || ! current_user_has_privilege()) return;

        $service = (string)$pending['service'];

        if ( ! empty($_GET['error'])) {
            SendingStore::update_state(['tests' => [$service => ['ok' => false, 'message' => sprintf(__('Sign-in was cancelled or refused: %s', 'mailoptin'), sanitize_text_field(wp_unslash($_GET['error_description'] ?? $_GET['error'])))]]]);
            $this->go('oauth_failed');
        }

        $config = (array)(SendingStore::credentials()[$service] ?? []);

        try {
            $token = Provider::make(SendingStore::OAUTH_SERVICES[$service])->exchange(
                (string)($config['client_id'] ?? ''),
                (string)($config['client_secret'] ?? ''),
                self::redirect_uri(),
                sanitize_text_field(wp_unslash($_GET['code'] ?? '')),
                (string)$pending['verifier']
            );
        } catch (OAuthException $e) {
            SendingStore::update_state(['tests' => [$service => ['ok' => false, 'message' => $e->getMessage(), 'at' => time()]], 'connected' => [$service => false]]);
            $this->go('oauth_failed');
        }

        SendingStore::update_service_credentials($service, ['token' => $token->to_array()]);
        $test = SendingStore::connect($service);

        $this->go($test->ok ? 'oauth_connected' : 'test_failed');
    }
}
