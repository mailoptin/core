<?php

namespace MailOptin\Core\EmailCampaigns\Newsletter;

use WP_Post;
use MailOptin\Core\Admin\Customizer\EmailCampaign\Customizer;
use MailOptin\Core\Admin\Customizer\EmailCampaign\AbstractCustomizer;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

abstract class AbstractTemplate extends \MailOptin\Core\EmailCampaigns\AbstractTemplate
{
    public function __construct($email_campaign_id)
    {
        parent::__construct($email_campaign_id);

        add_filter('mo_ecb_elements_default_values', [$this, 'email_content_builder_element_defaults']);
    }

    abstract function email_content_builder_element_defaults($defaults);

    abstract function text_block($id, $settings);

//    abstract function button_block();
//
//    abstract function divider_block();
//
//    abstract function image_block();
}