<?php

namespace MailOptin\Core\SendQueue;

/**
 * Sends one email straight away, outside the queue: confirmation and test emails. Goes through the
 * sending service when one is set up, otherwise wp_mail().
 */
class Mailer
{
    /**
     * @param string $to
     * @param string $subject
     * @param string $html
     * @param array $headers wp_mail-style "Name: value" lines
     * @param string $text plain-text version, optional
     *
     * @return true|\WP_Error
     */
    public static function send($to, $subject, $html, $headers = [], $text = '')
    {
        $args = ['to' => $to, 'subject' => $subject, 'html' => $html, 'headers' => (array)$headers, 'text' => $text];

        // A sending service returns true or a WP_Error here; null leaves it to wp_mail().
        $result = apply_filters('mo_send_email_now', null, $args);

        if (null !== $result) return $result;

        $error   = null;
        $capture = function ($wp_error) use (&$error) {
            $error = $wp_error;
        };

        $alt_body = function ($phpmailer) use ($text) {
            if ('' !== $text) $phpmailer->AltBody = $text;
        };

        add_action('wp_mail_failed', $capture);
        add_action('phpmailer_init', $alt_body);

        $sent = wp_mail($to, $subject, $html, $headers);

        remove_action('wp_mail_failed', $capture);
        remove_action('phpmailer_init', $alt_body);

        if ($sent) return true;

        return $error instanceof \WP_Error ? $error : new \WP_Error('mo_wp_mail_failed', __('wp_mail() could not send the email.', 'mailoptin'));
    }
}
