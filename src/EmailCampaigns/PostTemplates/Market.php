<?php

namespace MailOptin\Core\EmailCampaigns\PostTemplates;

/**
 * Market's look: a bold shop for sales. Hard black rules, capitals, a "-18%" badge on products on sale, a yellow accent, a full-width "Get yours" button and an offer strip across the top (its own setting in the Customizer's header section).
 * Shared by its new-post and digest emails.
 */
trait Market
{
    protected function design()
    {
        return [
            'heading_font'    => 'Helvetica, Arial, sans-serif',
            'body_font'       => 'Helvetica, Arial, sans-serif',
            'header'          => 'center',
            'name_upper'      => true,
            'card'            => true,
            'read_more'       => 'button',
            'button_radius'   => 0,
            'button_width'    => 100,
            'button_upper'    => true,
            'meta_style'      => 'upper',
            'list'            => 'side',
            'product'         => true,
            'sale_badge'      => true,
            'title_upper'     => true,
            'offer_strip'     => true,
            'offer_strip_default' => __('New in the shop', 'mailoptin'),
            'footer_rule'     => '#111111',
            'defaults'        => [
                'page_background_color'                    => '#fff6e8',
                'header_background_color'                  => '#ffffff',
                'header_text_color'                        => '#111111',
                'header_web_version_link_color'            => '#5c5346',
                'content_background_color'                 => '#ffffff',
                'content_text_color'                       => '#333333',
                'content_headline_color'                   => '#111111',
                'content_title_font_size'                  => '28',
                'content_body_font_size'                   => '15',
                'content_alignment'                        => 'left',
                'content_ellipsis_button_background_color' => '#111111',
                'content_ellipsis_button_text_color'       => '#ffd23f',
                'content_ellipsis_button_label'            => __('Get yours', 'mailoptin'),
                'footer_background_color'                  => '#ffffff',
                'footer_text_color'                        => '#5c5346',
                'footer_unsubscribe_link_color'            => '#5c5346',
                'footer_font_size'                         => '12',
            ],
            'digest_defaults' => ['content_title_font_size' => '15', 'content_ellipsis_button_label' => __('Shop', 'mailoptin')],
        ];
    }
}
