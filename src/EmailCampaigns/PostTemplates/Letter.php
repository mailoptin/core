<?php

namespace MailOptin\Core\EmailCampaigns\PostTemplates;

/**
 * Letter's look: a note from a person. No header or buttons and no images unless switched on; the title is a link, with a short line before and after the post (the Customizer's before and after content).
 * Shared by its new-post and digest emails.
 */
trait Letter
{
    protected function design()
    {
        return [
            'heading_font'    => 'Helvetica, Arial, sans-serif',
            'body_font'       => 'Helvetica, Arial, sans-serif',
            'header'          => 'sender',
            'card'            => true,
            'read_more'       => 'link',
            'meta_style'      => 'plain',
            'list'            => 'stack',
            'footer_rule'     => '#e5e5e5',
            'side_padding'    => 44,
            'defaults'        => [
                'page_background_color'                    => '#f7f7f5',
                'header_background_color'                  => '#ffffff',
                'header_text_color'                        => '#5f6368',
                'header_web_version_link_color'            => '#5f6368',
                'content_background_color'                 => '#ffffff',
                'content_text_color'                       => '#444444',
                'content_headline_color'                   => '#1f5f8b',
                'content_title_font_size'                  => '20',
                'content_body_font_size'                   => '16',
                'content_alignment'                        => 'left',
                'content_remove_feature_image'             => true,
                'content_ellipsis_button_background_color' => '#1f5f8b',
                'content_ellipsis_button_text_color'       => '#ffffff',
                'content_ellipsis_button_label'            => __('Read it on the blog', 'mailoptin'),
                'content_before_main_content'              => __('I\'ve just published something I think you\'ll find useful:', 'mailoptin'),
                'content_after_main_content'               => __('Thanks for reading.', 'mailoptin'),
                'footer_background_color'                  => '#ffffff',
                'footer_text_color'                        => '#5f6368',
                'footer_unsubscribe_link_color'            => '#5f6368',
                'footer_font_size'                         => '12',
            ],
            'digest_defaults' => [
                'content_title_font_size'     => '18',
                'content_body_font_size'      => '15',
                'content_before_main_content' => __('Here\'s what went up on the blog recently:', 'mailoptin'),
                'content_after_main_content'  => __('See you next time.', 'mailoptin'),
            ],
        ];
    }
}
