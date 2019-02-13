<?php

namespace MailOptin\Core\EmailCampaigns;

class Shortcodes
{
    use TemplateTrait;

    /** @var \WP_Post */
    protected $wp_post_obj;

    protected $email_campaign_id;

    protected $post_id;

    /**
     * Shortcodes constructor.
     *
     * @param int|\WP_Post $post
     */
    public function __construct($post, $email_campaign_id)
    {
        if ($post instanceof \stdClass) {
            $this->wp_post_obj = $post;
        } else {
            $this->wp_post_obj = get_post($post);
        }

        $this->email_campaign_id = $email_campaign_id;
    }

    public function parse($content)
    {
        global $shortcode_tags;
        $old_shortcode_tags = $shortcode_tags;
        $shortcode_tags     = array();

        $this->define_shortcodes();

        $parsed_content = do_shortcode($content);

        // restore back old shortcode
        $shortcode_tags = $old_shortcode_tags;

        return $parsed_content;
    }

    public function define_shortcodes()
    {
        add_shortcode('post-title', [$this, 'post_title_tag']);
        add_shortcode('post-content', [$this, 'post_content_tag']);
        add_shortcode('post-feature-image', [$this, 'post_feature_image_tag']);
        add_shortcode('post-feature-image-url', [$this, 'post_feature_image_url_tag']);

        add_shortcode('unsubscribe', [$this, 'unsubscribe']);
        add_shortcode('webversion', [$this, 'webversion']);

        do_action('mo_define_email_automation_shortcodes', $this->wp_post_obj);
    }

    public function post_title_tag()
    {
        return $this->wp_post_obj->post_title;
    }

    public function post_feature_image_tag()
    {
        return sprintf(
            '<img class="mo-post-feature-image" src="%s" alt="%s">',
            $this->feature_image($this->wp_post_obj->ID),
            $this->wp_post_obj->post_title
        );
    }

    public function post_feature_image_url_tag()
    {
        return $this->feature_image($this->wp_post_obj->ID);
    }

    public function post_content_tag()
    {
        return $this->post_content($this->wp_post_obj);
    }

    public function webversion()
    {
        return '{{webversion}}';
    }

    public function unsubscribe()
    {
        return '{{unsubscribe}}';
    }
}