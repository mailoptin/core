<?php

namespace MailOptin\Core\Flows\Actions;

use MailOptin\Core\Flows\AbstractTriggerAction;

abstract class AbstractAction extends AbstractTriggerAction implements ActionInterface
{
    public function __construct()
    {
        add_filter('mo_automate_flows_actions', [$this, 'add_action']);
    }

    /**
     * @param $custom_fields
     * @param bool $is_full_name
     *
     * @return array
     */
    public function get_mappable_fields($custom_fields, $is_full_name = false)
    {
        $fields = [
            'moEmail'     => [
                'name'     => 'moEmail',
                'label'    => esc_html__('Email Address', 'mailoptin'),
                'required' => true
            ],
            'moName'      => [
                'name'  => 'moName',
                'label' => esc_html__('Full Name', 'mailoptin')
            ],
            'moFirstName' => [
                'name'  => 'moFirstName',
                'label' => esc_html__('First Name', 'mailoptin')
            ],
            'moLastName'  => [
                'name'  => 'moLastName',
                'label' => esc_html__('Last Name', 'mailoptin')
            ],
        ];

        if ($is_full_name) {
            unset($fields['moFirstName']);
            unset($fields['moLastName']);
        }

        if (is_array($custom_fields) && ! empty($custom_fields)) {
            foreach ($custom_fields as $name => $label) {
                $fields[$name] = [
                    'name'  => $name,
                    'label' => $label,
                ];
            }
        }

        return $fields;
    }

    public function add_action($actions)
    {
        $actions[$this->id()] = [
            'id'              => $this->id(),
            'title'           => $this->title(),
            'description'     => $this->description(),
            'category'        => $this->category(),
            'action_settings' => $this->settings()
        ];

        return $actions;
    }
}