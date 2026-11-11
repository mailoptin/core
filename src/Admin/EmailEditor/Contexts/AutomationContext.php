<?php

namespace MailOptin\Core\Admin\EmailEditor\Contexts;

use MailOptin\Core\Admin\EmailEditor\EmailContext;
use MailOptin\Core\Admin\SettingsPage\Automations_Page;
use MailOptin\Core\Automations\Engine;
use MailOptin\Core\Automations\Triggers;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

class AutomationContext extends EmailContext
{
    public function id()
    {
        return 'automation';
    }

    public function campaign_type()
    {
        return Repo::EMAIL_CAMPAIGN_TYPE;
    }

    public function handles($email_campaign_id)
    {
        return ER::is_automation_email($email_campaign_id);
    }

    /**
     * The email, and the step that sends it, at the end of the automation.
     */
    public function create($template, $args)
    {
        $automation = Repo::get(absint($args['automation_id'] ?? 0));

        if ( ! $automation) return new \WP_Error('mo_email_create', __('That automation doesn\'t exist.', 'mailoptin'));

        $id = Automations_Page::add_email_step($automation, $template);

        return $id ?: new \WP_Error('mo_email_create', __('The email couldn\'t be created.', 'mailoptin'));
    }

    protected function owner($email_campaign_id)
    {
        return Repo::email_owner($email_campaign_id);
    }

    public function back_url($email_campaign_id)
    {
        $owner = $this->owner($email_campaign_id);

        return $owner ? Automations_Page::edit_url($owner[0]['id']) : Automations_Page::url();
    }

    public function label($email_campaign_id)
    {
        $owner = $this->owner($email_campaign_id);

        /* translators: %s: automation name */
        return $owner ? sprintf(__('Automation · %s', 'mailoptin'), $owner[0]['name']) : __('Automation email', 'mailoptin');
    }

    /**
     * Placeholders the automation's trigger provides show sample values; subscriber fields show
     * their fallback ("Hi {{subscriber.first_name|there}}" reads "Hi there").
     */
    public function preview_html($email_campaign_id, $html)
    {
        $owner   = $this->owner($email_campaign_id);
        $trigger = $owner && class_exists(Engine::class) ? Triggers::get_instance()->get($owner[0]['trigger_id']) : null;

        if ( ! $trigger) return $html;

        $run  = ['id' => 0, 'automation_id' => $owner[0]['id'], 'email' => '', 'subscriber_id' => 0, 'context' => [], 'started_at' => Repo::now()];
        $html = Engine::replace_placeholders($html, $trigger, $run, true, true);

        return \MailOptin\Core\Audience\CampaignRenderer::replace_fallback_tags($html, ['id' => 0, 'email' => '', 'first_name' => '', 'last_name' => ''], true, []);
    }

    public function send_test($email_campaign_id, $to)
    {
        $owner = $this->owner($email_campaign_id);

        return $owner && Engine::send_test($owner[0], $owner[1], $to);
    }
}
