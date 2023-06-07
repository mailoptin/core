<?php

namespace MailOptin\Core\Admin\PostListMenu;

// Exit if accessed directly

use MailOptin\Core\Admin\Customizer\EmailCampaign\EmailCampaignFactory;
use MailOptin\Core\Admin\Customizer\EmailCampaign\NewPublishPostTemplatePreview;
use MailOptin\Core\Admin\Customizer\EmailCampaign\NewsletterTemplatePreview;
use MailOptin\Core\Admin\Customizer\EmailCampaign\PostsEmailDigestTemplatePreview;
use MailOptin\Core\Admin\SettingsPage\Email_Campaign_List;
use MailOptin\Core\Admin\SettingsPage\Newsletter;
use MailOptin\Core\EmailCampaigns\NewPublishPost\Templatify;
use MailOptin\Core\Repositories\EmailCampaignRepository;

if (!defined('ABSPATH')) {
    exit;
}

class PostPreview {
    public function __construct() {
        add_filter('post_row_actions', [$this, 'modify_list_row_actions'], 10, 2);
    }

    public function modify_list_row_actions($actions, $post) {
        add_thickbox();
        $actions[] = sprintf('<a href="#TB_inline?width=600&height=550&inlineId=modal-window-id" class="thickbox" data-postID="%d">Preview As Email</a>', $post->ID);
        return $actions;
    }


    /**
     * @return PostPreview|null
     */
    public static function get_instance(): ?PostPreview {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}