<?php

namespace MailOptin\Core\Admin\PostListMenu;

// Exit if accessed directly

use MailOptin\Core\Repositories\EmailCampaignRepository;

if (!defined('ABSPATH')) {
    exit;
}

class PostPreview {
    private ?array $campaigns = null;

    public function __construct() {
        add_filter('post_row_actions', [$this, 'modify_list_row_actions'], 10, 2);
        add_action('in_admin_header', [$this, 'get_form_content']);
    }

    public function modify_list_row_actions($actions, $post) {
        if (count($this->get_campaigns()) > 0 && $post->post_status === 'draft') {
            add_thickbox();
            $actions[] = sprintf('<a href="#TB_inline?width=200&height=200&inlineId=email-modal" class="thickbox" data-postID="%d">Send Test Email</a> ', $post->ID);
        }
        return $actions;
    }

    public function get_form_content() {
        $campaignsHTML = '';
        foreach ($this->get_campaigns() as $campaign) {
            $campaignsHTML .= '<option value="'.$campaign['id'].'">'.$campaign['name'].'</option>';
        }
        the_post();
        echo '<div id="email-modal" style="display: none;">
            <form id="email-form" style="display: flex; flex-direction: column; justify-content: center; margin-left: 10%; margin-right: 10%;">
                <h2>Send Test Email</h2>
                <div style="padding-bottom: 10px">
                    <label for="campaigns">Choose a campaign:</label>
                    <select name="campaigns" id="campaigns" required>
                    ' . $campaignsHTML . '
                    </select>
                </div>
                <div style="padding-bottom: 10px">
                    <label for="email">Email Address:</label>
                    <input type="email" id="email" name="email" required/>
                </div>
                <div>
                    <input id="email-preview" type="submit"/>
                </div>
                <div>
                    <span id="mailoptin-success" style="display:none;">Email sent. Go check your message.</span>
                </div>
                <input id="mailoptin-send-test-email-nonce" type="hidden" value="'. wp_create_nonce('mailoptin-send-test-email-nonce') .'"/>
                <input type="hidden" id="wordpress-post-id" value="">
            </form>
        </div>';
    }

    public function get_campaigns(): array {
        if (!isset($this->campaigns)) {
            $email_campaign_ids = [];
            foreach (EmailCampaignRepository::get_email_campaign_ids() as $id){
                $campaignSettings = EmailCampaignRepository::get_settings_by_id($id);
                if (!EmailCampaignRepository::is_campaign_active($id)) {
                    continue;
                }
                $campaign = EmailCampaignRepository::get_email_campaign_by_id($id);
                $campaignPostType = $campaignSettings['custom_post_type'] ?? 'post';
                if ($campaign['campaign_type'] === 'new_publish_post' && $campaignPostType === get_post_type()) {
                    $email_campaign_ids[] = ['id' => $id, 'name' => $campaign['name']];
                }
            }
            $this->campaigns = $email_campaign_ids;
            return $email_campaign_ids;
        }
        return $this->campaigns;
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