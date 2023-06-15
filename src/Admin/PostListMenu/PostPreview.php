<?php

namespace MailOptin\Core\Admin\PostListMenu;

// Exit if accessed directly

use MailOptin\Core\Repositories\EmailCampaignRepository;

if (!defined('ABSPATH')) {
    exit;
}

class PostPreview {
    public function __construct() {
        add_filter('post_row_actions', [$this, 'modify_list_row_actions'], 10, 2);
    }

    public function modify_list_row_actions($actions, $post) {
        if($this->check_post_campaigns($post)) {
            add_thickbox();
            $actions[] = sprintf('<a href="#TB_inline?width=200&height=200&inlineId=email-modal" class="thickbox" data-postID="%d" data-nonce="%s">Send Test Email</a> ', $post->ID, wp_create_nonce('mailoptin-send-test-email-nonce'));
        }
        return $actions;
    }

    private function check_post_campaigns($post): bool {
        foreach (EmailCampaignRepository::get_email_campaign_ids() as $id) {
            $campaignSettings = EmailCampaignRepository::get_settings_by_id($id);
            if (!EmailCampaignRepository::is_campaign_active($id)) {
                continue;
            }
            $campaign = EmailCampaignRepository::get_email_campaign_by_id($id);
            $campaignPostType = $campaignSettings['custom_post_type'] ?? 'post';
            if ($campaign['campaign_type'] === 'new_publish_post' && $campaignPostType === $post->post_type) {
                return true;
            }
        }
        return false;
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