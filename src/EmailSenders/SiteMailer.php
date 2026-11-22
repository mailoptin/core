<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders;

/**
 * Sends every email the site sends with wp_mail() (password resets, WooCommerce orders, other
 * plugins' emails) through one of the senders.
 *
 * Several plugins may bundle this package, each with its own prefixed copy. Only one of them can
 * own the site's email: the owner is kept in one option whose name is a plain string, the same in
 * every copy. A plugin claims it when the site owner turns the feature on there, which takes it
 * from any other plugin; each copy hooks wp_mail only while its plugin is the owner.
 *
 *     // On every request:
 *     (new SiteMailer('mailoptin', function () { return Senders::make('smtp', $config); }, $options, $logger))->register();
 *     // When the site owner turns it on or off:
 *     SiteMailer::claim('mailoptin', 'MailOptin');
 *     SiteMailer::release('mailoptin');
 */
class SiteMailer
{
    /** Not prefixed by php-scoper: a string, shared by every bundled copy. */
    const OPTION = 'wp_email_senders_site_mailer';

    /** @var string */
    protected $owner;

    /** @var callable returns a SenderInterface, or null to leave the email to WordPress */
    protected $sender;

    /** @var array */
    protected $options;

    /** @var callable|null */
    protected $logger;

    /**
     * @param string        $owner   the host plugin's key, e.g. mailoptin or fusewp
     * @param callable      $sender  returns the SenderInterface to use (built only when an email goes)
     * @param array         $options from_email, from_name (used when an email sets no From),
     *                               force_from (use them for every email)
     * @param callable|null $logger  receives an array for each email: to (string[]), subject,
     *                               source (plugin folder, theme folder or "wordpress"), sender
     *                               (the sender's id), status (sent, failed, or fallback: the
     *                               sender's setup was refused, so WordPress's mailer sent it),
     *                               error, message_id. The error can name recipients: keep the log
     *                               no longer than needed.
     */
    public function __construct($owner, callable $sender, array $options = [], ?callable $logger = null)
    {
        $this->owner   = (string)$owner;
        $this->sender  = $sender;
        $this->options = $options;
        $this->logger  = $logger;
    }

    /**
     * @return array|null owner, label, since
     */
    public static function current()
    {
        $current = get_option(self::OPTION);

        return is_array($current) && ! empty($current['owner']) ? $current : null;
    }

    /** The plugin that sends the site's email, '' for none. */
    public static function owner()
    {
        $current = self::current();

        return $current ? (string)$current['owner'] : '';
    }

    /**
     * Make $owner the plugin that sends the site's email.
     *
     * @return array|null the owner it took over from, if another plugin
     */
    public static function claim($owner, $label)
    {
        $previous = self::current();

        update_option(self::OPTION, ['owner' => (string)$owner, 'label' => (string)$label, 'since' => time()], true);

        return $previous && $previous['owner'] !== $owner ? $previous : null;
    }

    /** Stop sending the site's email, if $owner is the one sending it. */
    public static function release($owner)
    {
        // Kept as an autoloaded empty value rather than deleted: a missing option costs a query
        // on every page load.
        if (self::owner() === $owner) update_option(self::OPTION, [], true);
    }

    /**
     * Hook into wp_mail when this plugin owns the site's email.
     *
     * @return bool whether it did
     */
    public function register()
    {
        if (self::owner() !== $this->owner) return false;

        add_filter('pre_wp_mail', [$this, 'pre_wp_mail'], 10, 2);

        return true;
    }

    /**
     * WordPress's short-circuit for wp_mail() (5.7+): returning true or false sends the email here.
     *
     * @param null|bool $return
     * @param array     $atts to, subject, message, headers, attachments
     */
    public function pre_wp_mail($return, $atts)
    {
        // Another plugin already handled it, or this is our own send coming back through wp_mail().
        if (null !== $return || self::$sending) return $return;

        // From here on nothing may escape: wp_mail() never throws, and its callers (a password
        // reset, a checkout) would fail with it.
        try {
            $sender = call_user_func($this->sender);
        } catch (\Throwable $e) {
            $sender = null;
        }

        // No sender, or WordPress's own mailer (which would only come back here): wp_mail() as usual.
        if ( ! $sender instanceof SenderInterface || $sender instanceof Senders\WpMail) return null;

        try {
            $message = $this->message((array)$atts, $sender);
        } catch (\Throwable $e) {
            $message = null;
        }
        if ( ! $message) return null;

        self::$sending = true;
        try {
            $start  = microtime(true);
            $result = $this->attempt($sender, $message);

            // One more try for a dropped connection, only when the first failed fast: a visitor is
            // waiting on this request. Not when the server asked us to slow down.
            if (SendResult::RETRY === $result->status && ! $result->throttled && microtime(true) - $start < 5) {
                $result = $this->attempt($sender, $message);
            }
        } finally {
            self::$sending = false;
        }

        // A setup problem (a refused password, a revoked sign-in) or a server that can't be reached
        // (a host blocking outgoing SMTP): let WordPress send it the usual way, so password resets
        // still go out while the site owner fixes it.
        $fallback = in_array($result->status, [SendResult::AUTH, SendResult::RETRY], true);
        $sent     = SendResult::SENT === $result->status;

        $this->log($message, $sender, $sent ? 'sent' : ($fallback ? 'fallback' : 'failed'), $result);

        if ($fallback) return null;

        $mail_data = [
            'to'          => $atts['to'] ?? '',
            'subject'     => $message->subject,
            'message'     => $atts['message'] ?? '',
            'headers'     => $atts['headers'] ?? [],
            'attachments' => $message->attachments,
        ];

        if ($sent) {
            do_action('wp_mail_succeeded', $mail_data);

            return true;
        }

        do_action('wp_mail_failed', new \WP_Error('wp_mail_failed', $result->error ?: 'The email could not be sent.', $mail_data));

        return false;
    }

    /** @var bool set while this class is sending, so a sender that calls wp_mail() can't loop */
    protected static $sending = false;

    protected function attempt(SenderInterface $sender, Message $message)
    {
        try {
            $results = $sender->send(['wp' => $message]);
            $result  = $results['wp'] ?? null;
        } catch (\Throwable $e) {
            return SendResult::retry($e->getMessage());
        }

        return $result instanceof SendResult ? $result : SendResult::retry('The sender gave no result.');
    }

    protected function log(Message $message, SenderInterface $sender, $status, SendResult $result)
    {
        if ( ! $this->logger) return;

        try {
            call_user_func($this->logger, [
                'to'         => array_map(function ($a) {
                    return $a->email;
                }, $message->to),
                'subject'    => $message->subject,
                'source'     => self::source(),
                'sender'     => $sender->id(),
                'status'     => $status,
                'error'      => (string)$result->error,
                'message_id' => (string)$result->message_id,
            ]);
        } catch (\Throwable $e) {
            // A broken log must not stop the site's email.
        }
    }

    /**
     * The email wp_mail() was asked to send, read the way wp_mail() reads it, including the
     * wp_mail_from, wp_mail_from_name and wp_mail_content_type filters other plugins rely on.
     *
     * @return Message|null null when it can't be sent this way (a plugin building its own MIME parts)
     */
    public function message(array $atts, ?SenderInterface $sender = null)
    {
        $headers = $atts['headers'] ?? [];
        if ( ! is_array($headers)) $headers = explode("\n", str_replace("\r\n", "\n", (string)$headers));

        $from = $from_name = '';
        $cc   = $bcc = $reply_to = $custom = [];
        $seen = [];
        $type = '';

        foreach ($headers as $name => $line) {
            // Headers given as name => value, or as "Name: value" lines.
            if (is_string($name) && ! is_numeric($name)) {
                $line = $name . ': ' . $line;
            }
            if ( ! is_string($line) || false === strpos($line, ':')) continue;

            list($name, $value) = array_map('trim', explode(':', $line, 2));

            switch (strtolower($name)) {
                case 'from':
                    $address   = Address::from($value);
                    $from      = $address->email;
                    $from_name = $address->name ?: 'WordPress'; // as wp_mail() names a bare address
                    break;
                case 'cc':
                    $cc = array_merge($cc, self::addresses($value));
                    break;
                case 'bcc':
                    $bcc = array_merge($bcc, self::addresses($value));
                    break;
                case 'reply-to':
                    $reply_to = array_merge($reply_to, self::addresses($value));
                    break;
                case 'content-type':
                    if (false !== stripos($value, 'multipart/')) return null; // the plugin built its own parts
                    $type = strtolower(trim(explode(';', $value)[0]));
                    break;
                case 'mime-version':
                case 'content-transfer-encoding':
                    break;
                default:
                    if ('' === $name || '' === $value) break;
                    // A repeated header keeps every value, joined as RFC 5322 allows for lists.
                    $key = strtolower($name);
                    if (isset($seen[$key])) {
                        $custom[$seen[$key]] .= ', ' . $value;
                    } else {
                        $seen[$key]    = $name;
                        $custom[$name] = $value;
                    }
            }
        }

        // The defaults wp_mail() uses, through the same filters.
        if ('' === $from) {
            // The setting, else the account the sender signs in as (Microsoft 365 only sends as
            // the signed-in mailbox), else WordPress's own default.
            $account   = $sender && method_exists($sender, 'default_from') ? (string)$sender->default_from() : '';
            $from      = (string)($this->options['from_email'] ?? '') ?: ($account ?: self::default_from());
            $from_name = (string)($this->options['from_name'] ?? '') ?: 'WordPress';
        }
        $from      = (string)apply_filters('wp_mail_from', $from);
        $from_name = (string)apply_filters('wp_mail_from_name', $from_name);

        if ( ! empty($this->options['force_from']) && ! empty($this->options['from_email'])) {
            $from      = (string)$this->options['from_email'];
            $from_name = (string)($this->options['from_name'] ?? $from_name);
        }

        // Gmail and Microsoft 365 send only as the signed-in mailbox. An email from another address
        // (a shop's orders@, a form's visitor) goes out as the account, with replies still going to
        // the address it asked for.
        $account = $sender && method_exists($sender, 'sends_as_account') && $sender->sends_as_account() ? (string)$sender->default_from() : '';
        if ('' !== $account && 0 !== strcasecmp($from, $account)) {
            if ( ! $reply_to && '' !== $from) $reply_to = [Address::from(['email' => $from, 'name' => $from_name])];
            $from = $account;
        }

        $type = (string)apply_filters('wp_mail_content_type', $type ?: 'text/plain');
        $body = (string)($atts['message'] ?? '');
        $html = 'text/html' === strtolower($type);

        $attachments = $atts['attachments'] ?? [];
        if ( ! is_array($attachments)) $attachments = explode("\n", str_replace("\r\n", "\n", (string)$attachments));
        // Keep 'name.pdf' => '/path' keys (WordPress 6.4+); Message drops anything that isn't a path.

        return Message::make([
            'from'        => ['email' => $from, 'name' => $from_name],
            'to'          => self::addresses($atts['to'] ?? []),
            'cc'          => $cc,
            'bcc'         => $bcc,
            'reply_to'    => $reply_to[0] ?? '',
            'subject'     => (string)($atts['subject'] ?? ''),
            'html'        => $html ? $body : '',
            'text'        => $html ? self::plain_text($body) : $body,
            'headers'     => $custom,
            'attachments' => $attachments,
            // Not a campaign: Postmark sends it on the transactional stream.
            'stream'      => Message::TRANSACTIONAL,
        ]);
    }

    /**
     * Addresses from a comma-separated string or a list, keeping "Name <email>" forms whole.
     */
    public static function addresses($value)
    {
        return Address::split($value);
    }

    /**
     * WordPress's default From address: wordpress@ the site's domain, without www.
     */
    public static function default_from()
    {
        $host = strtolower((string)parse_url(network_home_url(), PHP_URL_HOST));
        if (0 === strpos($host, 'www.')) $host = substr($host, 4);

        return 'wordpress@' . $host;
    }

    /**
     * A plain-text version of an HTML email, for clients that show text only.
     */
    public static function plain_text($html)
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', (string)$html);
        $text = preg_replace('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '$2 ($1)', $text);
        $text = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr)\b[^>]*>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);

        return trim(implode("\n", array_map('trim', explode("\n", $text))));
    }

    /**
     * Which plugin or theme called wp_mail(): the folder of the first plugin or theme file in the
     * call stack, other than this package's own. "wordpress" when WordPress itself sent it.
     *
     * @return string e.g. woocommerce, mailoptin, theme:twentytwentyfive or wordpress
     */
    public static function source()
    {
        $own     = dirname(__DIR__);
        $plugins = defined('WP_PLUGIN_DIR') ? wp_normalize_path(WP_PLUGIN_DIR) : '';
        $mu      = defined('WPMU_PLUGIN_DIR') ? wp_normalize_path(WPMU_PLUGIN_DIR) : '';
        $themes  = function_exists('get_theme_root') ? wp_normalize_path(get_theme_root()) : '';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (empty($frame['file'])) continue;
            $file = wp_normalize_path($frame['file']);
            if (0 === strpos($file, wp_normalize_path($own))) continue;

            foreach (['' => $plugins, 'mu:' => $mu, 'theme:' => $themes] as $prefix => $root) {
                if ('' !== $root && 0 === strpos($file, $root . '/')) {
                    return $prefix . explode('/', substr($file, strlen($root) + 1))[0];
                }
            }
        }

        return 'wordpress';
    }
}
