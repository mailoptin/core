<?php

namespace MailOptin\Core\Flows\Triggers;

abstract class AbstractTrigger extends AbstractTriggerRules implements TriggerInterface
{
    const WOOCOMMERCE_CATEGORY = 'woocommerce';

    const SELECT2_FIELD = 'select2';
    const TEXT_FIELD = 'text';

    public function __construct()
    {
        add_filter('mo_automate_flows_triggers', [$this, 'add_flow']);
        add_filter('mo_automate_flows_rules', [$this, 'add_rules']);
    }

    public function add_flow($triggers)
    {
        $triggers[$this->id()] = [
            'id'          => $this->id(),
            'title'       => $this->title(),
            'description' => $this->description(),
            'category'    => $this->category(),
            'trigger_settings'    => $this->settings()
        ];

        return $triggers;
    }

    public function add_rules($rules)
    {
        if ( ! empty($this->rules())) {
            $rules = array_merge($rules, $this->rules());
        }

        return $rules;
    }
}