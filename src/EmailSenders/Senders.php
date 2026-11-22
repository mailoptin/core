<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders;

use MailOptin\Core\EmailSenders\Http\ClientInterface;

/**
 * Builds a sender from its key.
 */
class Senders
{
    const MAP = [
        'wp_mail'      => Senders\WpMail::class,
        'postmark'     => Senders\Postmark::class,
        'ses'          => Senders\Ses::class,
        'sendgrid'     => Senders\SendGrid::class,
        'mailgun'      => Senders\Mailgun::class,
        'brevo'        => Senders\Brevo::class,
        'smtp'         => Senders\Smtp::class,
        'gmail'        => Senders\Gmail::class,
        'microsoft365' => Senders\Microsoft365::class,
    ];

    const NAMES = [
        'wp_mail'      => 'WordPress (wp_mail)',
        'postmark'     => 'Postmark',
        'ses'          => 'Amazon SES',
        'sendgrid'     => 'SendGrid',
        'mailgun'      => 'Mailgun',
        'brevo'        => 'Brevo',
        'smtp'         => 'Other SMTP server',
        'gmail'        => 'Gmail',
        'microsoft365' => 'Microsoft 365',
    ];

    /**
     * @return SenderInterface|null null for an unknown key
     */
    public static function make($id, array $config = [], ?ClientInterface $http = null)
    {
        $class = self::MAP[$id] ?? null;

        if ( ! $class) return null;

        return in_array($id, ['wp_mail', 'smtp'], true) ? new $class($config) : new $class($config, $http);
    }

    public static function ids()
    {
        return array_keys(self::MAP);
    }
}
