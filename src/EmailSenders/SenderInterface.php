<?php
// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.

namespace MailOptin\Core\EmailSenders;

interface SenderInterface
{
    /**
     * @return string the provider's key: wp_mail, postmark, ses, sendgrid, mailgun, brevo or smtp
     */
    public function id();

    /**
     * Send messages. A sender batches them into as few API calls as it can.
     *
     * @param Message[] $messages
     *
     * @return SendResult[] one per message, with the same keys
     */
    public function send(array $messages);

    /**
     * @return int the most messages worth passing to one send() call
     */
    public function batch_size();

    /**
     * @return int|null the most messages per second the provider accepts, null when it sets no limit
     *                  worth keeping to
     */
    public function max_per_second();

    /**
     * Check the credentials work, without sending anything.
     *
     * @return ConnectionResult
     */
    public function test_connection();
}
