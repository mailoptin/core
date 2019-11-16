<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


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
        return esc_html__('Embed a list of post.', 'mailoptin');
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
                'posts_search'      => [
                    'label'       => esc_html__('Search for Post', 'mailoptin'),
                    'placeholder' => 'Search',
                    'type'        => 'text',
                    'tab'         => 'tab-content'
                ],
                'posts_post_type' => [
                    'label'   => esc_html__('Select Post Type', 'mailoptin'),
                    'choices' => [
                        'left'   => esc_html__('Left', 'mailoptin'),
                        'right'  => esc_html__('Right', 'mailoptin'),
                        'center' => esc_html__('Center', 'mailoptin'),
                    ],
                    'type'    => 'select',
                    'tab'     => 'tab-content'
                ],
            ]
        );
    }
}