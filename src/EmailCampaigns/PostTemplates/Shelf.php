<?php

namespace MailOptin\Core\EmailCampaigns\PostTemplates;

/**
 * Shelf's look: a clean shop for WooCommerce products. Neutral so the photos carry it, centred, the price with the regular price crossed out when on sale, a blue "Shop now" button. Posts that aren't products are drawn without prices.
 * Shared by its new-post and digest emails.
 */
trait Shelf
{
    protected function design()
    {
        return [
            'heading_font'    => 'Helvetica, Arial, sans-serif',
            'body_font'       => 'Helvetica, Arial, sans-serif',
            'header'          => 'left',
            'card'            => true,
            'read_more'       => 'button',
            'button_radius'   => 4,
            'button_width'    => 50,
            'meta_style'      => 'plain',
            'kicker'          => true,
            'list'            => 'side',
            'product'         => true,
            'image_width'     => 360,
            'defaults'        => [
                'page_background_color'                    => '#f3f4f6',
                'header_background_color'                  => '#ffffff',
                'header_text_color'                        => '#1c1f23',
                'header_web_version_link_color'            => '#555b62',
                'content_background_color'                 => '#ffffff',
                'content_text_color'                       => '#444a51',
                'content_headline_color'                   => '#1c1f23',
                'content_title_font_size'                  => '24',
                'content_body_font_size'                   => '15',
                'content_alignment'                        => 'center',
                'content_ellipsis_button_background_color' => '#1f4f7a',
                'content_ellipsis_button_text_color'       => '#ffffff',
                'content_ellipsis_button_label'            => __('Shop now', 'mailoptin'),
                'footer_background_color'                  => '#f7f8f9',
                'footer_text_color'                        => '#555b62',
                'footer_unsubscribe_link_color'            => '#555b62',
                'footer_font_size'                         => '12',
            ],
            'digest_defaults' => ['content_title_font_size' => '16', 'content_ellipsis_button_label' => __('View product', 'mailoptin')],
        ];
    }
}
