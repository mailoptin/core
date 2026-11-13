<?php

namespace MailOptin\Core\EmailCampaigns\NewPublishPost;

use MailOptin\Core\Admin\Customizer\EmailCampaign\EmailCampaignFactory;
use MailOptin\Core\EmailCampaigns\Shortcodes;
use MailOptin\Core\EmailCampaigns\TemplateTrait;
use MailOptin\Core\EmailCampaigns\TemplatifyInterface;
use MailOptin\Core\EmailCampaigns\VideoToImageLink;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use WP_Post;


class Templatify implements TemplatifyInterface
{
    use TemplateTrait;

    protected $post;
    protected $email_campaign_id;
    protected $template_class;
    protected $post_content_length;

    /**
     * @param null|int $email_campaign_id
     * @param mixed $post could be WP_Post object, post ID or stdClass for customizer preview
     */
    public function __construct($email_campaign_id, $post = null)
    {
        //used for sending test emails.
        if ($post instanceof \stdClass) {
            $this->post = $post;
        } else {
            $this->post = get_post($post);
        }

        $this->email_campaign_id   = $email_campaign_id;
        $this->template_class      = ER::get_template_class($email_campaign_id);
        $this->post_content_length = absint(ER::get_customizer_value($email_campaign_id, 'post_content_length'));
    }

    public function post_content_forge()
    {
        $preview_structure = EmailCampaignFactory::make($this->email_campaign_id)->get_preview_structure();

        $feature_image = $this->feature_image($this->post);

        // Templates that mark their image (Folio and the other MJML ones) leave it out when there is none.
        if (empty($feature_image)) {
            $preview_structure = preg_replace('#<!--mo-feature-image-->.*?<!--/mo-feature-image-->#s', '', $preview_structure);
        }

        $preview_structure = str_replace('{{post.feature.image}}', $feature_image, $preview_structure);

        // The category label (Ledger, Spotlight): left out when the post has none.
        $category = esc_html($this->post_category($this->post));
        if ('' === $category) {
            $preview_structure = preg_replace('#<!--mo-post-category-->.*?<!--/mo-post-category-->#s', '', $preview_structure);
        }
        $preview_structure = str_replace('{{post.category}}', $category, $preview_structure);
        $preview_structure = str_replace('{{post.feature.image.alt}}', $this->feature_image_alt($this->post), $preview_structure);

        $search = array(
            '{{post.title}}',
            '{{post.content}}',
            '{{post.url}}',
            '{{post.meta}}'
        );

        $replace = [
            apply_filters('mo_new_publish_post_title', $this->post->post_title, $this->post, $this->email_campaign_id),
            apply_filters('mo_new_publish_post_content', $this->post_content($this->post), $this->post, $this->email_campaign_id),
            apply_filters('mo_new_publish_post_url', $this->post_url($this->post), $this->post, $this->email_campaign_id),
            apply_filters('mo_new_publish_post_meta', $this->post_meta($this->post), $this->post, $this->email_campaign_id)
        ];

        return apply_filters(
            'mo_new_post_notification_post_content_forge',
            str_replace($search, $replace, $preview_structure),
            $this->post, $this->email_campaign_id, $this
        );
    }

    /**
     * Turn {@see WP_Post} object to email campaign template.
     *
     * @return mixed
     */
    public function forge()
    {
        do_action('mailoptin_email_template_before_forge', $this->email_campaign_id, $this->template_class);

        if (ER::is_code_your_own_template($this->email_campaign_id)) {
            $content = ER::get_customizer_value($this->email_campaign_id, 'code_your_own');
        } else {
            $content = $this->post_content_forge();
        }

        $templatified_content = (new Shortcodes($this->email_campaign_id))->from($this->post)->parse($content);

        $templatified_content = apply_filters('mo_new_publish_post_post_templatify_forge', $templatified_content, $this->post, $this);

        $content = (new VideoToImageLink($templatified_content))->forge();

        if ( ! is_customize_preview()) {
            $content = \MailOptin\Core\emogrify($content);
        }

        // Columns' widths on wider screens, as MJML writes them (the MJML templates, such as Folio):
        // after inlining, which would drop rules for classes it can't find.
        if ( ! ER::is_code_your_own_template($this->email_campaign_id) && false !== strpos($content, 'mj-column-per-')) {
            $head    = \MailOptin\Core\EmailBlocks\Mjml::columns_head();
            $content = false !== stripos($content, '</head>') ? preg_replace('#</head>#i', $head . '</head>', $content, 1) : $head . $content;
        }

        return $this->replace_footer_placeholder_tags(
        // we found out urlencode was been done especially to the url part. previously we were doing
        // str_replace(['%5B', '%5D', '%7B', '%7D'], ['[', ']', '{', '}'], $content) and then used urldecode($content)
        // which caused + in content to be replaced with space. now back to using str_replace
            str_replace(['%5B', '%5D', '%7B', '%7D', '%24', '%20'], ['[', ']', '{', '}', '$', ' '], $content)
        );
    }
}