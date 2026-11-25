<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

class ProductsBought extends ProductList
{
    public function id()
    {
        return 'products_bought';
    }

    public function title()
    {
        return __('Products bought', 'mailoptin');
    }

    public function description()
    {
        return __('The products in the order, for each person, with a button to it.', 'mailoptin');
    }

    protected function needs()
    {
        return 'order';
    }

    protected function button_default()
    {
        return __('View your order', 'mailoptin');
    }

    protected function items($store, $context)
    {
        return $store->order($context);
    }

    public function group()
    {
        return 'shop';
    }

    public function palette_icon()
    {
        return 'bought';
    }
}
