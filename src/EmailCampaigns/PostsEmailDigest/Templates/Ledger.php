<?php

namespace MailOptin\Core\EmailCampaigns\PostsEmailDigest\Templates;

use MailOptin\Core\EmailCampaigns\PostsEmailDigest\AbstractTemplate;
use MailOptin\Core\EmailCampaigns\PostTemplates\Ledger as Look;
use MailOptin\Core\EmailCampaigns\PostTemplates\MjmlPostTemplate;

/**
 * Ledger for digests (PostTemplates\Ledger has its look), in the Customizer's columns (one, two or three).
 */
class Ledger extends AbstractTemplate
{
    use MjmlPostTemplate, Look;

    public $template_name = 'Ledger';

    public function __construct($email_campaign_id, $posts)
    {
        $this->register_logo_size();

        add_filter('mailoptin_customizer_email_campaign_post_content_length', function () {
            return 30;
        });

        parent::__construct($email_campaign_id, $posts);
    }

    protected function body_rows()
    {
        return $this->digest_rows();
    }
}
