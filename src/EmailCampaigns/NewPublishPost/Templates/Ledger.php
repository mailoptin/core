<?php

namespace MailOptin\Core\EmailCampaigns\NewPublishPost\Templates;

use MailOptin\Core\EmailCampaigns\AbstractTemplate;
use MailOptin\Core\EmailCampaigns\PostTemplates\Ledger as Look;
use MailOptin\Core\EmailCampaigns\PostTemplates\MjmlPostTemplate;

/**
 * Ledger for new-post emails (PostTemplates\Ledger has its look).
 */
class Ledger extends AbstractTemplate
{
    use MjmlPostTemplate, Look;

    public $template_name = 'Ledger';

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
