<?php

namespace MailOptin\Core\Flows\Triggers\WooCommerce;


use MailOptin\Core\Flows\Helpers;
use MailOptin\Core\Flows\Triggers\AbstractTrigger;

class OrderCreated extends AbstractTrigger
{
    public function id()
    {
        return 'wc_order_created';
    }

    public function category()
    {
        return self::WOOCOMMERCE_CATEGORY;
    }

    public function title()
    {
        return esc_html__('Order Created', 'mailoptin');
    }

    public function description()
    {
        return esc_html__('This trigger fires after a new orders is created with selected statuses.', 'mailoptin');
    }

    public function settings()
    {
        return [
            'order_status' => [
                'field'       => self::SELECT2_FIELD,
                'label'       => esc_html__('Select Order Status', 'mailoptin'),
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
                'label'       => esc_html__('Order Item Categories', 'mailoptin'),
                'category'    => self::WOOCOMMERCE_CATEGORY,
                'compare'     => self::multi_select_compare(),
                'value_field' => self::SELECT2_FIELD,
                'value'       => Helpers::get_wc_categories()
            ]
        ];
    }
}