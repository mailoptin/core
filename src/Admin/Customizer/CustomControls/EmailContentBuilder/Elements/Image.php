<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\AbstractElement;

class Image extends AbstractElement
{
    public function id()
    {
        return 'image';
    }

    public function icon()
    {
        return '<span class="dashicons dashicons-format-image"></span>';
    }

    public function title()
    {
        return esc_html__('Image', 'mailoptin');
    }

    public function description()
    {
        return esc_html__('Adds an image.', 'mailoptin');
    }

    public function tabs()
    {
        return [
            'tab-content'        => esc_html__('Content', 'mailoptin'),
            'tab-style'          => esc_html__('Style', 'mailoptin'),
            'tab-block-settings' => esc_html__('Block Settings', 'mailoptin'),
        ];
    }

    public function settings()
    {

        return apply_filters('mo_email_content_elements_image_element', [
            'image-url'  => [
                'label' => esc_html__('Image URL', 'mailoptin'),
                'type'  => 'select_image',
                'tab'   => 'tab-content'
            ],

            'block-background-color'  => [
                'label' => esc_html__('Background Color', 'mailoptin'),
                'type'  => 'color_picker',
                'tab'   => 'tab-block-settings'
            ],
            'block-margin'            => [
                'label' => esc_html__('Margin', 'mailoptin'),
                'type'  => 'dimension',
                'tab'   => 'tab-block-settings'
            ],
            'block-padding'           => [
                'label' => esc_html__('Padding', 'mailoptin'),
                'type'  => 'dimension',
                'tab'   => 'tab-block-settings'
            ],
        ]);
    }
}