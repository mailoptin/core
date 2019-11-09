<?php

namespace MailOptin\Core\EmailCampaigns\Newsletter;

use WP_Post;
use MailOptin\Core\Admin\Customizer\EmailCampaign\Customizer;
use MailOptin\Core\Admin\Customizer\EmailCampaign\AbstractCustomizer;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

abstract class AbstractTemplate extends \MailOptin\Core\EmailCampaigns\AbstractTemplate
{
    abstract function text_block($id, $settings);

//    abstract function button_block();
//
//    abstract function divider_block();
//
//    abstract function image_block();
}