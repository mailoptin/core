<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders\Senders;

use MailOptin\Core\EmailSenders\Address;
use MailOptin\Core\EmailSenders\ConnectionResult;
use MailOptin\Core\EmailSenders\Message;
use MailOptin\Core\EmailSenders\SenderInterface;
use MailOptin\Core\EmailSenders\SendResult;

/**
 * Any SMTP server: Gmail, Microsoft 365, Zoho, the host's mail server, or a provider's SMTP relay.
 *
 * Sends with the PHPMailer that ships with WordPress, so nothing extra is bundled, and talks to the
 * server directly: other plugins' wp_mail filters and SMTP settings don't touch these emails.
 * One connection carries a whole batch.
 *
 * SMTP has no bounce webhooks: a server that accepts an email and bounces it later reports that by
 * email to the sender's address, not here.
 */
class Smtp implements SenderInterface
{
    const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    const DEFAULT_PORTS = ['tls' => 587, 'ssl' => 465, 'none' => 25];

    /** @var array */
    protected $config;

    /** @var callable|null returns a fresh PHPMailer; for tests */
    protected $factory;

    /**
     * @param array $config host, port, encryption (tls, ssl or none), username, password,
     *                      auth (defaults to on when a username is set), timeout (seconds, default 15),
     *                      verify_peer (default true), helo, max_per_second, and for OAuth (Gmail,
     *                      Microsoft) oauth_token: a callable returning a current access token, used in
     *                      place of the password
     * @param callable|null $factory returns a PHPMailer\PHPMailer\PHPMailer with exceptions on
     */
    public function __construct(array $config = [], ?callable $factory = null)
    {
        $encryption = strtolower((string)($config['encryption'] ?? 'tls'));
        if ( ! in_array($encryption, self::ENCRYPTIONS, true)) $encryption = 'tls';

        $config['encryption'] = $encryption;
        $config['host']       = trim((string)($config['host'] ?? ''));
        $config['port']       = (int)($config['port'] ?? 0) ?: self::DEFAULT_PORTS[$encryption];
        $config['username']   = (string)($config['username'] ?? '');
        $config['password']   = (string)($config['password'] ?? '');
        $config['auth']       = isset($config['auth']) ? (bool)$config['auth'] : ('' !== $config['username']);
        $config['timeout']    = max(1, (int)($config['timeout'] ?? 15));

        $this->config  = $config;
        $this->factory = $factory;
    }

    public function id()
    {
        return 'smtp';
    }

    /**
     * The address this server sends as when an email doesn't say: the username when it is one.
     */
    public function default_from()
    {
        $address = (string)($this->config['envelope_from'] ?? '') ?: $this->config['username'];

        return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : '';
    }

    public function batch_size()
    {
        // Enough to save reconnecting, few enough that a dropped connection costs little.
        return 50;
    }

    public function max_per_second()
    {
        return empty($this->config['max_per_second']) ? null : max(1, (int)$this->config['max_per_second']);
    }

    public function test_connection()
    {
        if ('' === $this->config['host']) return new ConnectionResult(false, 'Enter the SMTP server\'s host name.');

        $details = ['host' => $this->config['host'], 'port' => $this->config['port'], 'encryption' => $this->config['encryption']];

        try {
            $mailer = $this->mailer();
        } catch (\Throwable $e) {
            return new ConnectionResult(false, $e->getMessage(), $details);
        }

        $failure = '';
        try {
            $connected = $mailer->smtpConnect($this->stream_options());
        } catch (\MailOptin\Core\EmailSenders\OAuth\OAuthException $e) {
            $mailer->smtpClose();

            return new ConnectionResult(false, $e->getMessage(), $details);
        } catch (\Throwable $e) {
            $connected = false;
            $failure   = $e->getMessage();
        }

        $error = $this->connect_error($mailer, $failure);
        try {
            $mailer->smtpClose();
        } catch (\Throwable $e) {
        }

        if ($connected) {
            return new ConnectionResult(true, sprintf(
                $this->config['auth'] ? 'Connected to %s and signed in.' : 'Connected to %s.',
                $this->config['host'] . ':' . $this->config['port']
            ), $details);
        }

        return new ConnectionResult(false, $this->explain($error), $details + ['error' => $error['text']]);
    }

    public function send(array $messages)
    {
        $results = [];

        if ('' === $this->config['host']) {
            foreach ($messages as $key => $message) $results[$key] = SendResult::auth('No SMTP server is set up.');

            return $results;
        }

        try {
            $mailer = $this->mailer();
        } catch (\Throwable $e) {
            foreach ($messages as $key => $message) $results[$key] = SendResult::retry($e->getMessage());

            return $results;
        }

        $stop = null; // set when the rest of the batch can't go either

        foreach ($messages as $key => $message) {
            $results[$key] = $stop ? clone $stop : $this->send_one($mailer, $message, $stop);
        }

        $this->disconnect($mailer);

        return $results;
    }

    /**
     * One email, as PHPMailer's smtpSend() would send it, but step by step, so each failure is
     * judged by where it happened instead of by its wording (which holds the addresses).
     *
     * @param SendResult|null $stop set to the result the rest of the batch should get, when the
     *                              server can't be reached or refuses the sign-in
     */
    protected function send_one($mailer, Message $message, &$stop)
    {
        $problem = $message->problem();
        if ('' !== $problem) return SendResult::failed($problem);

        // Build the email. Anything wrong here is wrong with this email, not the server.
        try {
            $this->reset($mailer);
            $setup = $this->fill($mailer, $message);
            if ($setup) return $setup;
            $mailer->preSend();
        } catch (\Throwable $e) {
            return SendResult::failed($e->getMessage());
        }

        // Connect and sign in, or reuse the connection the last email left open.
        $smtp = $mailer->getSMTPInstance();
        if ( ! $smtp->connected()) {
            $failure = '';
            try {
                $connected = $mailer->smtpConnect($mailer->SMTPOptions);
            } catch (\MailOptin\Core\EmailSenders\OAuth\OAuthException $e) {
                // Refreshing the sign-in failed: revoked needs the account connected again,
                // anything else (the provider unreachable) is worth another try.
                $this->disconnect($mailer);
                $stop = $e->revoked ? SendResult::auth($e->getMessage()) : SendResult::retry($e->getMessage());

                return $stop;
            } catch (\Throwable $e) {
                $connected = false;
                $failure   = $e->getMessage();
            }

            if ( ! $connected) {
                $error = $this->connect_error($mailer, $failure);
                $stop  = $error['auth'] ? SendResult::auth($this->explain($error)) : SendResult::retry($this->explain($error));

                return $stop;
            }
        }

        // The envelope sender.
        if ( ! $smtp->mail($mailer->Sender ?: $mailer->From)) {
            return $this->refused($mailer, 'The SMTP server refused the sender ' . ($mailer->Sender ?: $mailer->From), false);
        }

        // Each recipient. Some may be refused while others are taken.
        $refused = [];
        $taken   = 0;
        $codes   = [];
        foreach (array_merge($mailer->getToAddresses(), $mailer->getCcAddresses(), $mailer->getBccAddresses()) as $to) {
            if ($smtp->recipient($to[0])) {
                $taken++;
                continue;
            }

            $error = (array)$smtp->getError();
            $code  = (int)($error['smtp_code'] ?? 0);
            if ( ! $code) {
                // No reply: the connection is gone or out of step. Nothing was delivered yet.
                $this->disconnect($mailer);

                return SendResult::retry(sprintf('The SMTP server stopped answering (%s).', $this->where()));
            }

            $codes[]   = $code;
            $refused[] = $to[0] . ': ' . trim(($error['detail'] ?? '') ?: ($error['error'] ?? '')) . ' (SMTP ' . $code . ')';
        }

        if ( ! $taken) {
            $this->rset($mailer);
            $text = 'The SMTP server refused every recipient. ' . implode('; ', $refused);
            // Any temporary refusal means trying again later may work.
            foreach ($codes as $code) {
                if ($code >= 400 && $code < 500) return $this->temporary($code, $text);
            }

            return SendResult::failed($text);
        }

        // The email itself.
        if ( ! $smtp->data($mailer->getSentMIMEMessage())) {
            $error = (array)$smtp->getError();
            $code  = (int)($error['smtp_code'] ?? 0);
            $after = false !== stripos((string)($error['error'] ?? ''), 'DATA END');

            if ( ! $code) {
                $this->disconnect($mailer);
            } elseif ( ! $after) {
                // DATA itself was refused: end the transaction so the connection can carry the next email.
                $this->rset($mailer);
            }

            if ($after && ! $code) {
                // Sent in full, but no answer: the server may have taken it, and sending again
                // could deliver it twice.
                return SendResult::failed(sprintf('The SMTP server didn\'t confirm the email after receiving it (%s). It may have been delivered.', $this->where()));
            }

            if ( ! $code) {
                return SendResult::retry(sprintf('The SMTP server stopped answering (%s).', $this->where()));
            }

            return $this->by_code($code, trim('The SMTP server didn\'t accept the email: ' . ($error['detail'] ?? '') . ' (SMTP ' . $code . ')'));
        }

        $result = SendResult::sent($this->message_id($mailer));

        // Delivered to the others: retrying would send them a second copy.
        if ($refused) $result->error = 'Not delivered to ' . implode('; ', $refused);

        return $result;
    }

    /**
     * After MAIL FROM was refused: by the reply code, or a lost connection.
     */
    protected function refused($mailer, $what, $delivered)
    {
        $error = (array)$mailer->getSMTPInstance()->getError();
        $code  = (int)($error['smtp_code'] ?? 0);

        if ( ! $code) {
            $this->disconnect($mailer);

            return SendResult::retry(sprintf('The SMTP server stopped answering (%s).', $this->where()));
        }

        $this->rset($mailer);

        return $this->by_code($code, trim($what . ': ' . ($error['detail'] ?? '')) . ' (SMTP ' . $code . ')');
    }

    /**
     * 421 and 45x: the server is busy or limiting us; other 4xx: try later; 5xx: it won't take this.
     */
    protected function by_code($code, $text)
    {
        if ($code >= 400 && $code < 500) return $this->temporary($code, $text);

        return SendResult::failed($text);
    }

    protected function temporary($code, $text)
    {
        return in_array($code, [421, 450, 451, 452], true) ? SendResult::throttled($text) : SendResult::retry($text);
    }

    protected function rset($mailer)
    {
        try {
            if ( ! $mailer->getSMTPInstance()->reset()) $this->disconnect($mailer);
        } catch (\Throwable $e) {
            $this->disconnect($mailer);
        }
    }

    protected function disconnect($mailer)
    {
        try {
            $mailer->smtpClose();
        } catch (\Throwable $e) {
        }
    }

    protected function where()
    {
        return $this->config['host'] . ':' . $this->config['port'];
    }

    /** Headers PHPMailer writes itself; a second copy from the message would be a duplicate. */
    const RESERVED_HEADERS = Message::RESERVED_HEADERS;

    protected function reset($mailer)
    {
        $mailer->clearAllRecipients();
        $mailer->clearReplyTos();
        $mailer->clearAttachments();
        $mailer->clearCustomHeaders();
        $mailer->Body        = '';
        $mailer->AltBody     = '';
        $mailer->MessageID   = '';
        $mailer->Subject     = '';
        // PHPMailer lowers these to suit each email it builds; start every email from the defaults.
        $mailer->Encoding    = '8bit';
        $mailer->ContentType = 'text/plain';
    }

    /**
     * @return SendResult|null a result when the setup itself is wrong
     */
    protected function fill($mailer, Message $message)
    {
        $from = $message->from;
        if ('' === $from->email) {
            // The username is often the address (Gmail, Microsoft 365), but not always (SendGrid's
            // "apikey", SES's access keys).
            if ( ! filter_var($this->config['username'], FILTER_VALIDATE_EMAIL)) {
                return SendResult::auth('Set a From address: the SMTP username isn\'t an email address.');
            }
            $from = new Address($this->config['username'], '');
        }

        $mailer->setFrom($from->email, $from->name, false);
        // The envelope sender: bounces go back to the From address, or to the signed-in mailbox
        // when the server only sends from that (Microsoft 365).
        $mailer->Sender = (string)($this->config['envelope_from'] ?? '') ?: $from->email;

        foreach ($message->to as $a) $mailer->addAddress($a->email, $a->name);
        foreach ($message->cc as $a) $mailer->addCC($a->email, $a->name);
        foreach ($message->bcc as $a) $mailer->addBCC($a->email, $a->name);
        if ($message->reply_to) $mailer->addReplyTo($message->reply_to->email, $message->reply_to->name);

        foreach ($message->safe_headers() as $name => $value) {
            if (in_array(strtolower(trim($name)), self::RESERVED_HEADERS, true)) continue;
            $mailer->addCustomHeader($name, $value);
        }

        $mailer->CharSet = 'UTF-8';
        $mailer->Subject = $message->subject;

        if ('' !== $message->html) {
            $mailer->isHTML(true);
            $mailer->Body    = $message->html;
            $mailer->AltBody = $message->text;
        } else {
            $mailer->isHTML(false);
            $mailer->Body = $message->text;
        }

        foreach ($message->attachments as $name => $path) {
            if (is_string($path) && is_file($path) && is_readable($path)) $mailer->addAttachment($path, is_string($name) ? $name : '');
        }

        return null;
    }

    protected function message_id($mailer)
    {
        return trim((string)$mailer->getLastMessageID(), '<> ');
    }

    /**
     * A PHPMailer set up for this server.
     */
    protected function mailer()
    {
        if ($this->factory) {
            $mailer = call_user_func($this->factory);
        } else {
            self::load_phpmailer();
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        }

        $mailer->isSMTP();
        $mailer->Host       = $this->config['host'];
        $mailer->Port       = $this->config['port'];
        $mailer->Timeout    = $this->config['timeout'];
        $mailer->SMTPSecure = 'none' === $this->config['encryption'] ? '' : $this->config['encryption'];
        // Upgrading a plain connection when the server offers it is right for "tls", and wrong to
        // force on someone who chose "none" for a server with a broken certificate.
        $mailer->SMTPAutoTLS = 'none' !== $this->config['encryption'];
        $mailer->SMTPAuth    = $this->config['auth'];
        $mailer->XMailer     = ' '; // no "PHPMailer" header advertising the library version

        if ( ! empty($this->config['helo'])) $mailer->Helo = (string)$this->config['helo'];

        if ($this->config['auth']) {
            $mailer->Username = $this->config['username'];

            if (isset($this->config['oauth_token']) && is_callable($this->config['oauth_token'])) {
                $mailer->AuthType = 'XOAUTH2';
                $mailer->setOAuth(new SmtpOAuthToken($this->config['username'], $this->config['oauth_token']));
            } else {
                $mailer->Password = $this->config['password'];
            }
        }

        $mailer->SMTPOptions = $this->stream_options();

        // PHPMailer waits up to 5 minutes for each reply; a stalled server shouldn't hold a send
        // run that long.
        $smtp = $mailer->getSMTPInstance();
        if (property_exists($smtp, 'Timelimit')) $smtp->Timelimit = $this->config['timeout'];

        return $mailer;
    }

    protected function stream_options()
    {
        if (array_key_exists('verify_peer', $this->config) && ! $this->config['verify_peer']) {
            return ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        }

        return [];
    }

    /**
     * WordPress's PHPMailer (5.5+), loaded the way wp_mail() loads it.
     */
    public static function load_phpmailer()
    {
        if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) return;

        if ( ! defined('ABSPATH') || ! defined('WPINC')) {
            throw new \RuntimeException('PHPMailer isn\'t available.');
        }

        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
    }

    /**
     * Why connecting or signing in failed. At this step the error holds no email addresses, so its
     * wording can be trusted.
     *
     * @return array text, code (the SMTP reply code, 0 if none), auth, connection
     */
    protected function connect_error($mailer, $failure = '')
    {
        $text = trim((string)$failure) ?: trim((string)$mailer->ErrorInfo);
        $last = (array)$mailer->getSMTPInstance()->getError();
        $code = (int)($last['smtp_code'] ?? 0);

        if ('' === $text && ! empty($last['error'])) $text = trim($last['error'] . ' ' . ($last['detail'] ?? ''));
        if ( ! $code && preg_match('/SMTP code: (\d{3})/', $text, $m)) $code = (int)$m[1];

        $auth = (bool)preg_match('/could not authenticate|authentication (failed|unsuccessful)|username and password not accepted|invalid credentials/i', $text)
                || in_array($code, [530, 534, 535], true);

        return ['text' => $text, 'code' => $code, 'auth' => $auth, 'connection' => true];
    }

    /**
     * Plain words for a connection or sign-in failure.
     */
    protected function explain(array $error)
    {
        $where = $this->config['host'] . ':' . $this->config['port'];

        if ($error['auth']) {
            return isset($this->config['oauth_token'])
                ? sprintf('%s refused the sign-in. Connect the account again.', $where)
                : sprintf('%s refused the username or password. Some providers (Gmail, Microsoft 365) need an app password or OAuth sign-in instead of your normal password.', $where);
        }

        if (preg_match('/\bssl\b|\btls\b|certificate|crypto/i', $error['text'])) {
            return sprintf('Couldn\'t start a secure connection to %s. Check the encryption matches the port (TLS for 587, SSL for 465). %s', $where, $error['text']);
        }

        if ($error['connection']) {
            return sprintf('Couldn\'t reach %s. Check the host and port, and that your web host allows outgoing SMTP. %s', $where, $error['text']);
        }

        return $error['text'] ?: sprintf('%s didn\'t accept the email.', $where);
    }
}
