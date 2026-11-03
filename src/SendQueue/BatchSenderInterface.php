<?php

namespace MailOptin\Core\SendQueue;

/**
 * A sender that can send several queued emails in one go, such as a sending service's batch API.
 */
interface BatchSenderInterface extends SenderInterface
{
    /**
     * @param array[] $rows send queue rows, keyed by row id
     * @param int $campaign_log_id
     *
     * @return array row id => true|\WP_Error, as SenderInterface::send() returns. A WP_Error with
     *               ['stop' => true] in its data stops all sending for a while: the credentials or the
     *               account need fixing, so other emails would fail the same way.
     */
    public function send_batch(array $rows, $campaign_log_id);

    /**
     * @return int the most rows worth passing to send_batch() at once
     */
    public function batch_size();

    /**
     * @return int|null the most emails per second, or null for no limit
     */
    public function max_per_second();
}
