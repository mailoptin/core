<?php

namespace MailOptin\Core\Flows\Actions\Mailchimp;


use MailOptin\Core\Flows\Actions\AbstractAction;
use MailOptin\MailChimpConnect\Connect;

class AddUpdateSubscriber extends AbstractAction
{
    public function id()
    {
        return 'mailchimp_add_to_list';
    }

    public function category()
    {
        return self::MAILCHIMP_CATEGORY;
    }

    public function title()
    {
        return esc_html__('Add/Update Subscriber', 'mailoptin');
    }

    public function description()
    {
        return '';
    }

    public function settings()
    {
        return [
            'mailchimp_adl_list'             => [
                'field'   => parent::SELECT_FIELD,
                'label'   => esc_html__('Audience / List', 'mailoptin'),
                'options' => Connect::get_instance()->get_email_list()
            ],
            'mailchimp_adl_subscriber_email' => [
                'field' => parent::TEXT_FIELD,
                'label' => esc_html__('Email Address', 'mailoptin')
            ],
            'mailchimp_adl_first_name'       => [
                'field' => parent::TEXT_FIELD,
                'label' => esc_html__('First Name', 'mailoptin')
            ],
            'mailchimp_adl_last_name'        => [
                'field' => parent::TEXT_FIELD,
                'label' => esc_html__('Last Name', 'mailoptin')
            ],
            'mailchimp_adl_custom_fields'    => [
                'field' => parent::FIELD_MAP,
                'label' => esc_html__('Map Fields', 'mailoptin'),
                'fields' => []
            ]
        ];
    }
}