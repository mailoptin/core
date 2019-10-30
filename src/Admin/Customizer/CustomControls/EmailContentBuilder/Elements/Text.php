<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\AbstractElement;

class Text extends AbstractElement
{
    public function id()
    {
        return 'text';
    }

    public function icon()
    {
        return '<span class="dashicons dashicons-text"></span>';
    }

    public function title()
    {
        return esc_html__('Text', 'mailoptin');
    }

    public function description()
    {
        return esc_html__('Text, HTML and multimedia content.', 'mailoptin');
    }

    public function tabs()
    {
        return [
            'tab-content' => esc_html__('Content', 'mailoptin'),
            'tab-style'   => esc_html__('Block Settings', 'mailoptin'),
        ];
    }

    public function settings()
    {
        return apply_filters('mo_email_content_elements_text_element', [
            'text-content'           => [
                'type' => 'tinymce',
                'tab'  => 'tab-content'
            ],
            'block-margin'           => [
                'label' => esc_html__('Margin', 'mailoptin'),
                'type'  => 'dimension',
                'tab'   => 'tab-style'
            ],
            'block-padding'          => [
                'label' => esc_html__('Padding', 'mailoptin'),
                'type'  => 'dimension',
                'tab'   => 'tab-style'
            ],
            'block-background-color' => [
                'label' => esc_html__('Background Color', 'mailoptin'),
                'type'  => 'color_picker',
                'tab'   => 'tab-style'
            ]
        ]);
    }
}