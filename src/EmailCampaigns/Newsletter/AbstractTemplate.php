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

        add_action('mo_get_preview_structure_before_closing_head', [$this, 'google_fonts']);
    }

    public function google_fonts()
    {
        echo <<<HTML
<!--[if !mso]><!-->
<link href="https://fonts.googleapis.com/css?family=Arvo:400,400i,700,700i|Lato:400,400i,700,700i|Lora:400,400i,700,700i|Merriweather:400,400i,700,700i|Merriweather+Sans:400,400i,700,700i|Noticia+Text:400,400i,700,700i|Open+Sans:400,400i,700,700i|Playfair+Display:400,400i,700,700i|Roboto:400,400i,700,700i|Source+Sans+Pro:400,400i,700,700i|Oswald:400,400i,700,700i|Raleway:400,400i,700,700i|Permanent+Marker:400,400i,700,700i|Pacifico:400,400i,700,700i" rel="stylesheet">
<!--<![endif]-->
HTML;

    }

    abstract function email_content_builder_element_defaults($defaults);

    abstract function text_block($id, $settings);

//    abstract function button_block();
//
//    abstract function divider_block();
//
//    abstract function image_block();
}