<?php

namespace MailOptin\Core\EmailCampaigns\PostsEmailDigest\Templates;

use MailOptin\Core\EmailCampaigns\PostsEmailDigest\AbstractTemplate;
use MailOptin\Core\EmailCampaigns\PostTemplates\Shelf as Look;
use MailOptin\Core\EmailCampaigns\PostTemplates\MjmlPostTemplate;

/**
 * Shelf for digests (PostTemplates\Shelf has its look), in the Customizer's columns (one, two or three).
 */
class Shelf extends AbstractTemplate
{
    use MjmlPostTemplate, Look;

    public $template_name = 'Shelf';

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
