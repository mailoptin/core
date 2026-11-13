<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

class CartContents extends ProductList
{
    public function id()
    {
        return 'cart_contents';
    }

    public function title()
    {
        return __('Cart contents', 'mailoptin');
    }

    public function description()
    {
        return __('The products left in the cart, for each person, with a button back to it.', 'mailoptin');
    }

    protected function needs()
    {
        return 'cart';
    }

    protected function button_default()
    {
        return __('Return to your cart', 'mailoptin');
    }

    protected function items($store, $context)
    {
        return $store->cart($context);
    }

    public function group()
    {
        return 'shop';
    }

    public function palette_icon()
    {
        return 'cart';
    }
}
