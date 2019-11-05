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
        return 'text.svg';
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
            'tab-content'        => esc_html__('Content', 'mailoptin'),
            'tab-style'          => esc_html__('Style', 'mailoptin'),
            'tab-block-settings' => esc_html__('Block Settings', 'mailoptin'),
        ];
    }

    public function settings()
    {
        return apply_filters('mo_email_content_elements_text_element', [
            'text_content'           => [
                'type' => 'tinymce',
                'tab'  => 'tab-content'
            ],
            'button_font_family'     => [
                'label' => esc_html__('Font Family', 'mailoptin'),
                'type'  => 'font_family',
                'tab'   => 'tab-style'
            ],
            'block_background_color' => [
                'label' => esc_html__('Background Color', 'mailoptin'),
                'type'  => 'color_picker',
                'tab'   => 'tab-block-settings'
            ],
            'block_margin'           => [
                'label' => esc_html__('Margin', 'mailoptin'),
                'type'  => 'dimension',
                'tab'   => 'tab-block-settings'
            ],
            'block_padding'          => [
                'label' => esc_html__('Padding', 'mailoptin'),
                'type'  => 'dimension',
                'tab'   => 'tab-block-settings'
            ],
        ]);
    }
}