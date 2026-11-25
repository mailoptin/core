<?php

namespace MailOptin\Core\EmailCampaigns\PostTemplates;

/**
 * Spotlight's look: a magazine. A dark bar, the newest post leading with its image across the email and its title on a dark band, then the rest in the chosen columns; a yellow accent.
 * Shared by its new-post and digest emails.
 */
trait Spotlight
{
    protected function design()
    {
        return [
            'heading_font'    => 'Helvetica, Arial, sans-serif',
            'body_font'       => 'Helvetica, Arial, sans-serif',
            'header'          => 'bar',
            'card'            => true,
            'read_more'       => 'button',
            'button_radius'   => 0,
            'button_width'    => 50,
            'button_upper'    => true,
            'meta_style'      => 'upper',
            'kicker'          => true,
            'kicker_color'    => 'content_ellipsis_button_text_color',
            'list'            => 'side',
            'lead'            => true,
            'side_padding'    => 28,
            'defaults'        => [
                'page_background_color'                    => '#e9ebee',
                'header_background_color'                  => '#14171a',
                'header_text_color'                        => '#ffffff',
                'header_web_version_link_color'            => '#4a5058',
                'content_background_color'                 => '#ffffff',
                'content_text_color'                       => '#3a3f45',
                'content_headline_color'                   => '#14171a',
                'content_title_font_size'                  => '18',
                'content_body_font_size'                   => '16',
                'content_alignment'                        => 'left',
                'content_ellipsis_button_background_color' => '#14171a',
                'content_ellipsis_button_text_color'       => '#f2b705',
                'content_ellipsis_button_label'            => __('Read the story', 'mailoptin'),
                'footer_background_color'                  => '#14171a',
                'footer_text_color'                        => '#c6cbd1',
                'footer_unsubscribe_link_color'            => '#c6cbd1',
                'footer_font_size'                         => '12',
            ],
            'digest_defaults' => ['content_title_font_size' => '16', 'content_body_font_size' => '15'],
        ];
    }
}
