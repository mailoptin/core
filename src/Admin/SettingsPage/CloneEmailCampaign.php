<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\Repositories\EmailCampaignRepository;

class CloneEmailCampaign
{
    protected $email_campaign_id;
    protected $new_email_campaign_name;

    /**
     * @param int $email_campaign_id
     * @param string $new_email_campaign_name
     */
    public function __construct($email_campaign_id, $new_email_campaign_name = '')
    {
        $this->email_campaign_id       = $email_campaign_id;
        $this->new_email_campaign_name = $new_email_campaign_name;
    }

    /**
     * Do the clone.
     *
     * @return bool
     */
    public function forge()
    {
        // get settings of email campaign being cloned
        $clone_meta_data = EmailCampaignRepository::get_email_campaign_by_id($this->email_campaign_id);

        $new_email_campaign_name = ! empty($this->new_email_campaign_name) ? $this->new_email_campaign_name : $clone_meta_data['name'] . ' - Copy';
        $new_email_campaign_name = apply_filters('mailoptin_email_campaign_clone_name', $new_email_campaign_name, $clone_meta_data);

        $new_email_campaign_id = EmailCampaignRepository::add_email_campaign(
            $new_email_campaign_name,
            $clone_meta_data['campaign_type'],
            $clone_meta_data['template_class']
        );

        EmailCampaignMeta::add_meta_data(
            $new_email_campaign_id,
            'created_at',
            current_time('mysql')
        );

        $settings = EmailCampaignRepository::get_settings_by_id($this->email_campaign_id);

        // deactivate cloned campaign by default
        $settings['activate_email_campaign'] = false;

        if (EmailCampaignRepository::is_newsletter($new_email_campaign_id)) {
            $settings['email_campaign_title'] = $new_email_campaign_name;
        }

        return EmailCampaignRepository::save_settings_by_id($new_email_campaign_id, $settings);

    }
}