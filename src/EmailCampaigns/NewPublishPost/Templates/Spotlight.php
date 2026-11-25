<?php

namespace MailOptin\Core\EmailCampaigns\NewPublishPost\Templates;

use MailOptin\Core\EmailCampaigns\AbstractTemplate;
use MailOptin\Core\EmailCampaigns\PostTemplates\Spotlight as Look;
use MailOptin\Core\EmailCampaigns\PostTemplates\MjmlPostTemplate;

/**
 * Spotlight for new-post emails (PostTemplates\Spotlight has its look).
 */
class Spotlight extends AbstractTemplate
{
    use MjmlPostTemplate, Look;

    public $template_name = 'Spotlight';

    public function __construct($email_campaign_id)
    {
        $this->register_logo_size();

        parent::__construct($email_campaign_id);
    }

    /** The post, as placeholders New Post's Templatify fills in. */
    protected function body_rows()
    {
        return $this->new_post_rows();
    }
}
