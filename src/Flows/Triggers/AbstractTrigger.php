<?php

namespace MailOptin\Core\Flows\Triggers;


abstract class AbstractTrigger implements TriggerInterface
{
    const WOOCOMERCE_CATEGORY = 'woocommerce';

    public function __construct()
    {
        add_filter('mo_automate_flows_triggers', [$this, 'add_flow']);
    }

    public function add_flow($triggers)
    {
        $triggers[$this->category()][$this->id()] = [
            'id'          => $this->id(),
            'title'       => $this->title(),
            'description' => $this->description()
        ];

        return $triggers;
    }
}