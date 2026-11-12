<?php

namespace MailOptin\Core\EmailCampaigns\PostsEmailDigest\Templates;

use MailOptin\Core\EmailCampaigns\PostsEmailDigest\AbstractTemplate;
use MailOptin\Core\EmailCampaigns\PostTemplates\Folio as Look;
use MailOptin\Core\EmailCampaigns\PostTemplates\MjmlPostTemplate;

/**
 * Folio for digests: posts in one column (image on the left), two (cards) or three (image and title).
 */
class Folio extends AbstractTemplate
{
    use MjmlPostTemplate, Look {
        MjmlPostTemplate::customizer_content_settings as mjml_content_settings;
    }

    public $template_name = 'Folio';

    public function __construct($email_campaign_id, $posts)
    {
        $this->register_logo_size();

        add_filter('mailoptin_customizer_email_campaign_post_content_length', function () {
            return 30;
        });

        parent::__construct($email_campaign_id, $posts);
    }

    public function default_customizer_values()
    {
        return array_merge((array)$this->design_value('defaults', []), ['content_title_font_size' => '20']);
    }

    public function customizer_content_settings($settings)
    {
        $settings['column_count'] = ['default' => '1', 'type' => 'option'];

        return $this->mjml_content_settings($settings);
    }

    public function customizer_content_controls($controls, $wp_customize, $option_prefix, $customizerClassInstance)
    {
        $controls['column_count'] = [
            'label'    => __('Columns', 'mailoptin'),
            'section'  => $customizerClassInstance->campaign_content_section_id,
            'settings' => $option_prefix . '[column_count]',
            'type'     => 'select',
            'choices'  => ['1' => __('One', 'mailoptin'), '2' => __('Two', 'mailoptin'), '3' => __('Three', 'mailoptin')],
            'priority' => 21,
        ];

        return $controls;
    }

    protected function body_rows()
    {
        return $this->digest_rows();
    }
}
