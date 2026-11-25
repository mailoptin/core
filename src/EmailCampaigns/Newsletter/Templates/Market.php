<?php

namespace MailOptin\Core\EmailCampaigns\Newsletter\Templates;

use MailOptin\Core\EmailCampaigns\Newsletter\AbstractTemplate;
use MailOptin\Core\EmailCampaigns\PostTemplates\Market as Look;
use MailOptin\Core\EmailCampaigns\PostTemplates\MjmlPostTemplate;

/**
 * Market for broadcasts and automation emails (PostTemplates\Market has its look): the email's
 * blocks inside its header and footer.
 */
class Market extends AbstractTemplate
{
    use MjmlPostTemplate, Look;

    public $template_name = 'Market';

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

    public static function extra_design_fields()
    {
        return ['offer_strip_text' => ['type' => 'text', 'label' => __('Offer strip', 'mailoptin'), 'description' => __('A line across the top of the email, such as a delivery offer. Leave empty for none.', 'mailoptin'), 'section' => 'header']];
    }
}
