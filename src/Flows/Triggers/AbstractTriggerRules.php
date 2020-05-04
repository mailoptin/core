<?php

namespace MailOptin\Core\Flows\Triggers;


abstract class AbstractTriggerRules
{
    public function multi_select_compare()
    {
        return [
            'matches_any'  => __('matches any', 'mailoptin'),
            'matches_all'  => __('matches all', 'mailoptin'),
            'matches_none' => __('matches none', 'mailoptin'),
        ];
    }
}