<?php

namespace MailOptin\Core\EmailCampaigns\Newsletter\Templates;

use MailOptin\Core\EmailCampaigns\Newsletter\AbstractTemplate;
use MailOptin\Core\EmailCampaigns\PostTemplates\Ledger as Look;
use MailOptin\Core\EmailCampaigns\PostTemplates\MjmlPostTemplate;

/**
 * Ledger for broadcasts and automation emails (PostTemplates\Ledger has its look): the email's
 * blocks inside its header and footer.
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

    /** The blocks, as Newsletter's Templatify fills them in. */
    protected function body_rows()
    {
        return '{{newsletter.content}}';
    }
}
