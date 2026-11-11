<?php
/**
 * PHP functions for extending the email builder.
 */

use MailOptin\Core\EmailBlocks\Templates;

if ( ! function_exists('mailoptin_register_email_template')) {
    /**
     * Add a template broadcasts and automation emails can be built in. See
     * MailOptin\Core\EmailBlocks\Templates for what the class needs.
     *
     *     mailoptin_register_email_template([
     *         'id'         => 'acme-shop',
     *         'name'       => 'Acme Shop',
     *         'class'      => Acme_Shop_Template::class,
     *         'screenshot' => plugins_url('acme-shop.png', __FILE__),
     *     ]);
     *
     * @param array $args id, name, class; screenshot, description, campaign_types
     *
     * @return true|WP_Error
     */
    function mailoptin_register_email_template($args)
    {
        return Templates::get_instance()->register($args);
    }
}

if ( ! function_exists('mailoptin_register_email_block')) {
    /**
     * Add a block to the email builder (a MailOptin\Core\EmailBlocks\AbstractBlock).
     *
     * @return bool
     */
    function mailoptin_register_email_block(\MailOptin\Core\EmailBlocks\AbstractBlock $block)
    {
        return \MailOptin\Core\EmailBlocks\Blocks::get_instance()->add($block);
    }
}
