<?php

namespace MailOptin\Core\Admin\Customizer\EmailCampaign;


use MailOptin\Core\EmailCampaigns\Newsletter\Templatify;
use MailOptin\Core\Repositories\EmailCampaignRepository;

class NewsletterTemplatePreview extends Templatify
{
    public function newsletter_content()
    {
        $instance = EmailCampaignFactory::make($this->email_campaign_id);

        $preview_structure = $instance->get_preview_structure();

        $content = EmailCampaignRepository::get_customizer_value($this->email_campaign_id, 'newsletter_editor_content');

        $search = ['{{newsletter.content}}'];

        $replace = [$content];

        return str_replace($search, $replace, $preview_structure);
    }
}