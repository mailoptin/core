<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


use MailOptin\Core\Admin\Customizer\CustomControls\ControlsHelpers;
use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\AbstractElement;

class Posts extends AbstractElement
{
    public function id()
    {
        return 'posts';
    }

    public function icon()
    {
        return '<span class="dashicons dashicons-admin-post mo-email-content-element-img"></span>';
    }

    public function title()
    {
        return esc_html__('Posts', 'mailoptin');
    }

    public function description()
    {
        return esc_html__('Embed a list of posts.', 'mailoptin');
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
        return apply_filters('mo_email_content_elements_posts_element', $this->element_block_settings() + [
                'posts_post_type'   => [
                    'label'   => esc_html__('Select Post Type', 'mailoptin'),
                    'choices' => ['post' => esc_html__('Posts', 'mailoptin')] + ControlsHelpers::custom_post_types(),
                    'type'    => 'select',
                    'tab'     => 'tab-content'
                ],
                'post_list'         => [
                    'label'           => esc_html__('Select Posts', 'mailoptin'),
                    'type'            => 'select',
                    'choices'         => [],
                    'multiple'        => true,
                    'tab'             => 'tab-content',
                    'select2_options' => [
                        'placeholder'        => esc_html__('Search for posts', 'mailoptin'),
                        'minimumInputLength' => 2,
                        'ajax'               => [
                            'url'      => admin_url('admin-ajax.php'),
                            'method'   => 'POST',
                            'dataType' => 'json',
                        ]
                    ]
                ],
                'read_more_text'    => [
                    'label' => esc_html__('Read More Link Text', 'mailoptin'),
                    'type'  => 'text',
                    'tab'   => 'tab-content'
                ],
                'default_image_url' => [
                    'label' => esc_html__('Fallback Featured Image', 'mailoptin'),
                    'type'  => 'select_image',
                    'tab'   => 'tab-content'
                ],
                'post_title_color' => [
                    'label' => esc_html__('Post Title Color', 'mailoptin'),
                    'type'  => 'color_picker',
                    'tab'   => 'tab-style'
                ],
                'read_more_color' => [
                    'label' => esc_html__('Read More Link Color', 'mailoptin'),
                    'type'  => 'color_picker',
                    'tab'   => 'tab-style'
                ],
            ]
        );
    }
}