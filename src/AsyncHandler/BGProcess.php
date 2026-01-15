<?php

namespace MailOptin\Core\AsyncHandler;

use MailOptin\Core\Libs\WPBGProcessing\WP_Background_Process;

class BGProcess extends WP_Background_Process
{
    /**
     * @var string
     */
    protected $action = 'mo_async_handler';

    protected $cron_interval = 1;

    public function __construct()
    {
        $this->prefix = 'mailoptin_' . get_current_blog_id();

        parent::__construct();
    }

    protected function task($item)
    {
        $action = $item['action'] ?? '';

        if ($item['action']) unset($item['action']);

        do_action('mailoptin_async_handler_job', $action, $item);

        return false;
    }
}