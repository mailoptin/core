<?php
/**
 * Copyright (C) 2020 Collins Agbonghama <me@w3guy.com>
 */

namespace MailOptin\Core\Flows;


class Helpers
{
    public static function get_wc_categories()
    {
        return get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'fields'     => 'id=>name'
        ]);
    }
}