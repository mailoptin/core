<?php

namespace MailOptin\Core\EmailCampaigns\PostTemplates;

/**
 * Folio's look: calm and clean, Georgia titles, a green accent. Shared by its new-post and digest emails.
 */
trait Folio
{
    protected function design()
    {
        return [
            'heading_font'  => 'Georgia, \'Times New Roman\', serif',
            'body_font'     => 'Helvetica, Arial, sans-serif',
            'header'        => 'center',
            'card'          => true,
            'button_radius' => 4,
            'button_width'  => 100,
            'meta_upper'    => true,
            'defaults'      => [
                'page_background_color'                    => '#eef1f4',
                'header_background_color'                  => '#ffffff',
                'header_text_color'                        => '#2e6b4f',
                'header_web_version_link_color'            => '#5b6168',
                'content_background_color'                 => '#ffffff',
                'content_text_color'                       => '#4a4f55',
                'content_headline_color'                   => '#1d2125',
                'content_title_font_size'                  => '26',
                'content_body_font_size'                   => '16',
                'content_alignment'                        => 'left',
                'content_ellipsis_button_background_color' => '#2e6b4f',
                'content_ellipsis_button_text_color'       => '#ffffff',
                'footer_background_color'                  => '#f6f7f8',
                'footer_text_color'                        => '#5b6168',
                'footer_unsubscribe_link_color'            => '#5b6168',
                'footer_font_size'                         => '12',
            ],
        ];
    }
}
