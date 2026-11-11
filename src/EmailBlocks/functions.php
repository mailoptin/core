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

if ( ! function_exists('mailoptin_register_audience_source')) {
    /**
     * Add a place broadcasts can find people besides lists, like a membership plugin's members.
     * Extend MailOptin\Core\Audience\Sources\AbstractSource; MailOptin asks it who its people are
     * when an email is counted or sent, and copies nothing.
     *
     *     add_action('init', function () {
     *         mailoptin_register_audience_source(new Acme_Members_Source());
     *     });
     *
     * @param \MailOptin\Core\Audience\Sources\AbstractSource $source
     */
    function mailoptin_register_audience_source($source)
    {
        \MailOptin\Core\Audience\Sources\Sources::add($source);
    }
}
