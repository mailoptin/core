<?php

namespace MailOptin\Core\Admin\Customizer\EmailCampaign;


use MailOptin\Core\EmailCampaigns\Newsletter\Templatify;
use MailOptin\Core\Repositories\EmailCampaignRepository;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

class NewsletterTemplatePreview extends Templatify
{
    public function newsletter_content()
    {
        $instance = EmailCampaignFactory::make($this->email_campaign_id);

        $preview_structure = $instance->get_preview_structure();

        $content = EmailCampaignRepository::get_customizer_value($this->email_campaign_id, 'newsletter_editor_content');

        if (empty($content)) {
            $content = $this->builderHtml();
        }

        $search = ['{{newsletter.content}}'];

        $replace = [$content];

        return str_replace($search, $replace, $preview_structure);
    }

    public function builderHtml()
    {
        $content = EmailCampaignRepository::get_customizer_value($this->email_campaign_id, 'email_newsletter_content');
        if (empty($content)) return $content;
        $content = json_decode($content, true);

        if (is_null($content)) return '';

        $template_class_instance = EmailCampaignFactory::make($this->email_campaign_id);

        $html = '';
        foreach ($content as $element) {
            $method = $element['type'] . '_block';
            $html   .= $template_class_instance->$method($element['id'], $element['settings']);
        }

        return $html;


        var_dump($content);

        return 'builder content goes here';

        ?>
        <?php
    }
}