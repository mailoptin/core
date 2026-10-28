<?php

namespace MailOptin\Core\SendQueue;

use MailOptin\Core\Repositories\SendQueueRepository as Queue;

/**
 * Run and inspect the MailOptin send queue.
 */
class QueueCommand
{
    /**
     * Send queued emails until nothing is ready or the hourly limit is reached.
     *
     * ## OPTIONS
     *
     * [--campaign=<campaign_log_id>]
     * : Only process this campaign log.
     *
     * ## EXAMPLES
     *
     *     wp mailoptin queue run
     *     wp mailoptin queue run --campaign=42
     *
     * @when after_wp_load
     */
    public function run($args, $assoc_args)
    {
        $runner   = QueueRunner::get_instance();
        $campaign = isset($assoc_args['campaign']) ? absint($assoc_args['campaign']) : 0;
        $total    = 0;

        do {
            $handled = $campaign ? $runner->process($campaign) : $runner->run_all();
            $total   += $handled;

            if ($handled > 0) \WP_CLI::log(sprintf('Handled %d emails.', $handled));

        } while ($handled > 0);

        if ($runner->hourly_allowance() <= 0) {
            \WP_CLI::warning('Hourly sending limit reached. Run again later.');
        }

        \WP_CLI::success(sprintf('Done. %d emails handled.', $total));
    }

    /**
     * Show progress for a campaign log.
     *
     * ## OPTIONS
     *
     * <campaign_log_id>
     * : The campaign log ID.
     *
     * @when after_wp_load
     */
    public function status($args)
    {
        $counts = Queue::counts(absint($args[0]));

        \WP_CLI\Utils\format_items('table', [$counts], array_keys($counts));
    }
}
