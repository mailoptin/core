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
            'tab-content'        => esc_html__('Content', 'mailoptin'),
            'tab-style'          => esc_html__('Style', 'mailoptin'),
            'tab-block-settings' => esc_html__('Block Settings', 'mailoptin'),
        ];
    }

    public function settings()
    {
        return apply_filters('mo_email_content_elements_button_element', [
            'button_text'             => [
                'label' => esc_html__('Button Text', 'mailoptin'),
                'type'  => 'text',
                'tab'   => 'tab-content'
            ],
            'button_link'             => [
                'label' => esc_html__('Button Link (URL)', 'mailoptin'),
                'type'  => 'text',
                'tab'   => 'tab-content'
            ],
            'button_alignment'        => [
                'label'   => esc_html__('Button Alignment', 'mailoptin'),
                'choices' => [
                    'left'       => esc_html__('Left', 'mailoptin'),
                    'right'      => esc_html__('Right', 'mailoptin'),
                    'center'     => esc_html__('Center', 'mailoptin'),
                    'full_width' => esc_html__('Full Width', 'mailoptin'),
                ],
                'type'    => 'select',
                'tab'     => 'tab-content'
            ],
            'button_background_color' => [
                'label' => esc_html__('Background Color', 'mailoptin'),
                'type'  => 'color_picker',
                'tab'   => 'tab-style'
            ],
            'button_color'            => [
                'label' => esc_html__('Color', 'mailoptin'),
                'type'  => 'color_picker',
                'tab'   => 'tab-style'
            ],
            'button_font_size'        => [
                'label' => esc_html__('Font Size', 'mailoptin'),
                'type'  => 'range',
                'tab'   => 'tab-style'
            ],
            'button_font_family'      => [
                'label'   => esc_html__('Font Family', 'mailoptin'),
                'choices' => [
                    'Arial'               => esc_html__('Arial', 'mailoptin'),
                    'Arial Black'         => esc_html__('Arial Black', 'mailoptin'),
                    'Book Antiqua'        => esc_html__('Book Antiqua', 'mailoptin'),
                    'Comic Sans MS'       => esc_html__('Comic Sans MS', 'mailoptin'),
                    'Courier New'         => esc_html__('Courier New', 'mailoptin'),
                    'Georgia'             => esc_html__('Georgia', 'mailoptin'),
                    'Geneva'              => esc_html__('Geneva', 'mailoptin'),
                    'Helvetica'           => esc_html__('Helvetica', 'mailoptin'),
                    'Impact'              => esc_html__('Impact', 'mailoptin'),
                    'Lucida'              => esc_html__('Lucida', 'mailoptin'),
                    'Lucida Console'      => esc_html__('Lucida Console', 'mailoptin'),
                    'Lucida Sans Unicode' => esc_html__('Lucida Sans Unicode', 'mailoptin'),
                    'Lucida Grande'       => esc_html__('Lucida Grande', 'mailoptin'),
                    'Monaco'              => esc_html__('Monaco', 'mailoptin'),
                    'Palatino'            => esc_html__('Palatino', 'mailoptin'),
                    'Palatino Linotype'   => esc_html__('Palatino Linotype', 'mailoptin'),
                    'Tahoma'              => esc_html__('Tahoma', 'mailoptin'),
                    'Times New Roman'     => esc_html__('Times New Roman', 'mailoptin'),
                    'Trebuchet MS'        => esc_html__('Trebuchet MS', 'mailoptin'),
                    'Verdana'             => esc_html__('Verdana', 'mailoptin'),
                ],
                'type'    => 'select',
                'tab'     => 'tab-style'
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