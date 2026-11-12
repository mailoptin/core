<?php

namespace MailOptin\Core\EmailCampaigns\PostTemplates;

/**
 * Ledger's look: a newspaper. Serif type, a masthead over a double rule, text first, category labels and "Continue reading →" links in a deep red.
 * Shared by its new-post and digest emails.
 */
trait Ledger
{
    protected function design()
    {
        return [
            'heading_font'    => 'Georgia, \'Times New Roman\', serif',
            'body_font'       => 'Georgia, \'Times New Roman\', serif',
            'header'          => 'masthead',
            'card'            => true,
            'read_more'       => 'link',
            'meta_style'      => 'italic',
            'kicker'          => true,
            'list'            => 'stack',
            'footer_rule'     => '#1d1d1d',
            'defaults'        => [
                'page_background_color'                    => '#f4f1ea',
                'header_background_color'                  => '#fffdf8',
                'header_text_color'                        => '#1d1d1d',
                'header_web_version_link_color'            => '#5b5750',
                'content_background_color'                 => '#fffdf8',
                'content_text_color'                       => '#3a3833',
                'content_headline_color'                   => '#1d1d1d',
                'content_title_font_size'                  => '32',
                'content_body_font_size'                   => '17',
                'content_alignment'                        => 'left',
                'content_ellipsis_button_background_color' => '#9b2226',
                'content_ellipsis_button_text_color'       => '#ffffff',
                'content_ellipsis_button_label'            => __('Continue reading', 'mailoptin'),
                'footer_background_color'                  => '#fffdf8',
                'footer_text_color'                        => '#5b5750',
                'footer_unsubscribe_link_color'            => '#5b5750',
                'footer_font_size'                         => '12',
            ],
            'digest_defaults' => ['content_title_font_size' => '21', 'content_body_font_size' => '15'],
        ];
    }
}
