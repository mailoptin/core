<?php

namespace MailOptin\Core\EmailCampaigns\Newsletter\Templates;

use MailOptin\Core\EmailCampaigns\Newsletter\AbstractTemplate;
use MailOptin\Core\EmailCampaigns\PostTemplates\Letter as Look;
use MailOptin\Core\EmailCampaigns\PostTemplates\MjmlPostTemplate;

/**
 * Letter for broadcasts and automation emails (PostTemplates\Letter has its look): the email's
 * blocks inside its header and footer.
 */
class Letter extends AbstractTemplate
{
    use MjmlPostTemplate, Look;

    public $template_name = 'Letter';

    public function __construct($email_campaign_id)
    {
        $this->register_logo_size();

        parent::__construct($email_campaign_id);
    }

    /** The blocks, as Newsletter's Templatify fills them in. */
    protected function body_rows()
    {
        return '{{newsletter.content}}';
    }
}
