<?php

namespace MailOptin\Core\Flows\Triggers\WooCommerce;


use MailOptin\Core\Flows\Triggers\AbstractTrigger;

class OrderCreated extends AbstractTrigger
{
    public function id()
    {
        return 'wc_order_created';
    }

    public function category()
    {
        return self::WOOCOMERCE_CATEGORY;
    }

    public function title()
    {
        return esc_html__('Order Created', 'mailoptin');
    }

    public function description()
    {
        return esc_html__('This trigger fires after a new orders is created with selected statuses.', 'mailoptin');
    }
}