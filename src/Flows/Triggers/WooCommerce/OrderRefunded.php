<?php

namespace MailOptin\Core\Flows\Triggers\WooCommerce;


use MailOptin\Core\Flows\Triggers\AbstractTrigger;

class OrderRefunded extends AbstractTrigger
{
    public function id()
    {
        return 'wc_order_refunded';
    }

    public function category()
    {
        return self::WOOCOMMERCE_CATEGORY;
    }

    public function title()
    {
        return esc_html__('Order Refunded', 'mailoptin');
    }

    public function description()
    {
        return esc_html__('This trigger fires after an orders has been refunded.', 'mailoptin');
    }

    public function rules()
    {
        return [];
    }

    public function settings()
    {
        return [];
    }
}