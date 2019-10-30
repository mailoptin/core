<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\AbstractElement;

class Button extends AbstractElement
{
    public function id()
    {
        return 'button';
    }

    public function icon()
    {
        return '<span class="dashicons dashicons-editor-removeformatting"></span>';
    }

    public function title()
    {
        return esc_html__('Button', 'mailoptin');
    }

    public function description()
    {
        return esc_html__('A simple button.', 'mailoptin');
    }

    public function tabs()
    {
        return [
            'tab-content' => esc_html__('Content', 'mailoptin'),
            'tab-style'   => esc_html__('Style', 'mailoptin'),
            'tab-block-settings'   => esc_html__('Block Settings', 'mailoptin'),
        ];
    }

    public function settings()
    {
        return apply_filters('mo_email_content_elements_button_element', [
            'button_text' => [
                'label' => esc_html__('Button Text', 'mailoptin'),
                'type'  => 'text',
                'tab'   => 'tab-content'
            ],
            'button_link' => [
                'label' => esc_html__('Button Link (URL)', 'mailoptin'),
                'type'  => 'text',
                'tab'   => 'tab-content'
            ],
            'button_background_color' => [
                'label' => esc_html__('Background Color', 'mailoptin'),
                'type'  => 'color_picker',
                'tab'   => 'tab-style'
            ],
            'button_color' => [
                'label' => esc_html__('Color', 'mailoptin'),
                'type'  => 'color_picker',
                'tab'   => 'tab-style'
            ],
            'button_font_size' => [
                'label' => esc_html__('Font Size', 'mailoptin'),
                'type'  => 'range',
                'tab'   => 'tab-style'
            ],
            'block-background-color' => [
                'label' => esc_html__('Background Color', 'mailoptin'),
                'type'  => 'color_picker',
                'tab'   => 'tab-block-settings'
            ],
            'block-margin'           => [
                'label' => esc_html__('Margin', 'mailoptin'),
                'type'  => 'dimension',
                'tab'   => 'tab-block-settings'
            ],
            'block-padding'          => [
                'label' => esc_html__('Padding', 'mailoptin'),
                'type'  => 'dimension',
                'tab'   => 'tab-block-settings'
            ],
        ]);
    }
}