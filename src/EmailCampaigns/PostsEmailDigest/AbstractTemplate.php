<?php

namespace MailOptin\Core\EmailCampaigns\PostsEmailDigest;

use MailOptin\Core\EmailCampaigns\AbstractTemplate as ParentAbstractTemplate;
use MailOptin\Core\EmailCampaigns\TemplateTrait;
use WP_Post;

abstract class AbstractTemplate extends ParentAbstractTemplate
{
    use TemplateTrait;

    public $posts;

    public $email_campaign_id;

    public function __construct($email_campaign_id, $posts)
    {
        $this->posts = $posts;

        $this->email_campaign_id = $email_campaign_id;

        parent::__construct($email_campaign_id);
    }

    /**
     * HTML structure for single post item
     *
     * @return mixed
     */
    abstract function single_post_item();

    public function row_wrapper_start()
    {
        return '';
    }

    public function row_wrapper_end()
    {
        return '';
    }

    public function item_wrapper_start()
    {
        return '';
    }

    public function item_wrapper_end()
    {
        return '';
    }

    /**
     * Eg a Divider
     *
     * @return mixed
     */
    abstract function delimiter();

    public function parsed_post_list($column_count = 1)
    {
        $delimiter   = $this->delimiter();
        $posts_count = count($this->posts);

        ob_start();

        /**
         * @var int $index
         * @var WP_Post $post
         */


        foreach ($this->posts as $index => $post) {
            // index starts at 0. so we increment by one.
            $index++;

            // Start a new row at the beginning and after every $column_count items
            if (($index - 1) % $column_count === 0) {
                echo $this->row_wrapper_start();
            }

            $search = apply_filters('mo_email_campaign_ped_search_args', [
                '{{post.title}}',
                '{{post.content}}',
                '{{post.feature.image}}',
                '{{post.feature.image.alt}}',
                '{{post.url}}',
                '{{post.meta}}'
            ], $post, $this->email_campaign_id, $this);

            $replace = apply_filters('mo_email_campaign_ped_replace_args', [
                apply_filters('mo_posts_email_digest_post_title', $this->post_title($post), $post, $this->email_campaign_id),
                apply_filters('mo_posts_email_digest_post_content', $this->post_content($post), $post, $this->email_campaign_id),
                apply_filters('mo_posts_email_digest_post_feature_image', $this->feature_image($post), $post, $this->email_campaign_id),
                apply_filters('mo_posts_email_digest_post_feature_image_alt', $this->feature_image_alt($post), $post, $this->email_campaign_id),
                apply_filters('mo_posts_email_digest_post_url', $this->post_url($post), $post, $this->email_campaign_id),
                apply_filters('mo_posts_email_digest_post_meta', $this->post_meta($post), $post, $this->email_campaign_id)
            ], $post, $this->email_campaign_id, $this);

            echo $this->item_wrapper_start();
            echo apply_filters(
                'mo_email_campaign_ped_single_post_item',
                str_replace($search, $replace, $this->single_post_item()),
                $post, $this->email_campaign_id, $this
            );
            echo $this->item_wrapper_end();

            // End the row after every $column_count items or at the last item
            if ($index % $column_count === 0 || $index === $posts_count) {
                echo $this->row_wrapper_end();
                // Echo delimiter after row end, but not after the last row
                if ( ! empty($delimiter) && $index < $posts_count) echo $delimiter;
            }
        }

        return ob_get_clean();
    }
}