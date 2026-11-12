<?php

namespace MailOptin\Core\EmailCampaigns\Newsletter\Templates;

use MailOptin\Core\EmailCampaigns\Newsletter\AbstractTemplate;
use MailOptin\Core\EmailCampaigns\PostTemplates\Spotlight as Look;
use MailOptin\Core\EmailCampaigns\PostTemplates\MjmlPostTemplate;

/**
 * Spotlight for broadcasts and automation emails (PostTemplates\Spotlight has its look): the email's
 * blocks inside its header and footer.
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

    /** The blocks, as Newsletter's Templatify fills them in. */
    protected function body_rows()
    {
        return '{{newsletter.content}}';
    }
}
