<?php

namespace MailOptin\Core\Admin\EmailEditor\Contexts;

use MailOptin\Core\Admin\EmailEditor\EmailContext;
use MailOptin\Core\Admin\SettingsPage\Broadcast_Page;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

class BroadcastContext extends EmailContext
{
    public function id()
    {
        return 'broadcast';
    }

    public function campaign_type()
    {
        return ER::NEWSLETTER;
    }

    public function handles($email_campaign_id)
    {
        return ER::is_newsletter($email_campaign_id);
    }

    public function create($template, $args)
    {
        $name = trim(sanitize_text_field((string)($args['name'] ?? '')));

        if ('' === $name) return new \WP_Error('mo_email_name', __('Give the broadcast a name.', 'mailoptin'));
        if (ER::campaign_name_exist($name)) return new \WP_Error('mo_email_name', __('A broadcast or email with this name exists already.', 'mailoptin'));

        $id = ER::add_email_campaign(mb_substr($name, 0, 50), ER::NEWSLETTER, $template);

        if ( ! is_int($id) || ! $id) return new \WP_Error('mo_email_create', __('The broadcast couldn\'t be created.', 'mailoptin'));

        EmailCampaignMeta::add_meta_data($id, 'created_at', current_time('mysql'));

        return $id;
    }

    public function back_url($email_campaign_id)
    {
        return Broadcast_Page::url($email_campaign_id);
    }

    public function label($email_campaign_id)
    {
        return __('Broadcast · Step 1 of 2: the email', 'mailoptin');
    }

    public function next_step($email_campaign_id)
    {
        return ['url' => Broadcast_Page::url($email_campaign_id), 'label' => __('Next: audience & sending', 'mailoptin')];
    }

    // Sent, or sending: a record now.
    public function editable($email_campaign_id)
    {
        return ! Broadcasts::is_done($email_campaign_id);
    }

    /** Subscriber fields show their fallback ("Hi {{subscriber.first_name|there}}" reads "Hi there"). */
    public function preview_html($email_campaign_id, $html)
    {
        return \MailOptin\Core\Audience\CampaignRenderer::replace_fallback_tags($html, ['id' => 0, 'email' => '', 'first_name' => '', 'last_name' => ''], true, []);
    }

    public function send_test($email_campaign_id, $to)
    {
        return (bool)Broadcasts::send_test($email_campaign_id, $to);
    }
}
