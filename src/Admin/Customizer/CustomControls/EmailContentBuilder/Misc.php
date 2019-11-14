<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder;


use MailOptin\Core\Admin\Customizer\EmailCampaign\SolitaryDummyContent;
use MailOptin\Core\Repositories\EmailCampaignRepository;

class Misc
{
    public static function elements_default_fields_values($email_campaign_id = null)
    {
        if (is_null($email_campaign_id)) {
            $email_campaign_id = absint($_GET['mailoptin_email_campaign_id']);
        }

        $text_element_default = wpautop(SolitaryDummyContent::content());

        $newsletter_editor_content = EmailCampaignRepository::get_customizer_value_without_default($email_campaign_id, 'newsletter_editor_content');

        if ( ! empty($newsletter_editor_content)) {
            $text_element_default = $newsletter_editor_content;
        }

        $block_settings_default = [
            'block_background_color' => '',
            'block_padding'          => [
                'top'    => '0',
                'bottom' => '0',
                'right'  => '0',
                'left'   => '0'
            ],
        ];

        return apply_filters('mo_ecb_elements_default_values', [
            'text'    => $block_settings_default + [
                    'text_content'     => $text_element_default,
                    'text_font_family' => '',
                    'text_font_size'   => '',
                    'text_line_height' => 'normal'
                ],
            'button'  => $block_settings_default + [
                    'button_text'             => esc_html__('Button', 'mailoptin'),
                    'button_link'             => home_url(),
                    'button_width'            => '70',
                    'button_background_color' => '',
                    'button_color'            => '',
                    'button_font_size'        => '18',
                    'button_padding'          => [
                        'top'    => '0',
                        'bottom' => '0',
                        'right'  => '0',
                        'left'   => '0'
                    ],
                    'button_font_family'      => '',
                    'button_font_weight'      => 'normal',
                    'button_alignment'        => 'center',
                    'button_border_radius'    => '0'
                ],
            'divider' => $block_settings_default + [
                    'divider_width'  => '100',
                    'divider_style'  => 'solid',
                    'divider_color'  => '#dcd6d1',
                    'divider_height' => '1'
                ],
            'spacer'  => $block_settings_default + [
                    'spacer_height' => '20',
                    'spacer_background_color' => '#ffffff'
                ],
            'image'   => $block_settings_default + [
                    'image_url'       => MAILOPTIN_ASSETS_URL . 'images/email-builder-elements/default-image.png',
                    'image_width'     => '500',
                    'image_alignment' => 'center',
                    'image_alt_text'  => '',
                    'image_link'      => '',
                ]
        ],
            $email_campaign_id
        );
    }
}