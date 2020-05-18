<?php

namespace MailOptin\Core\Flows\Actions\Mailchimp;


use MailOptin\Core\Flows\Actions\AbstractAction;

class AddToList extends AbstractAction
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
        return esc_html__('Add Contact to List', 'mailoptin');
    }

    public function description()
    {
        return '';
    }

    public function settings()
    {
        return [
            'order_status' => [
                'field'       => self::SELECT2_FIELD,
                'label'       => esc_html__('Select Order Status'),
                'description' => esc_html__('Select order statuses that will trigger this flow', 'mailoptin'),
                'options'     => [
                    'processing' => esc_html__('Processing', 'mailoptin'),
                    'completed'  => esc_html__('Completed', 'mailoptin'),
                    'onhold'     => esc_html__('On hold', 'mailoptin')
                ]
            ]
        ];
    }

    public function rules()
    {
        return [
            'order_item_categories' => [
                'label'       => esc_html__('Order Item Categories'),
                'category'    => self::WOOCOMMERCE_CATEGORY,
                'compare'     => self::multi_select_compare(),
                'value_field' => self::SELECT2_FIELD,
                'value'       => Helpers::get_wc_categories()
            ]
        ];
    }
}