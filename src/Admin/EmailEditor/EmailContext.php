<?php

namespace MailOptin\Core\Admin\EmailEditor;

use MailOptin\Core\EmailBlocks\Templates;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * What the email editor needs to know about one kind of email (a broadcast, an automation's email):
 * how to make one, where "back" and "next" go, sample values for the preview, how a test is sent.
 * The editor, the template picker and the launcher are written once; each kind of email registers
 * a context on mailoptin_email_editor_contexts.
 */
abstract class EmailContext
{
    /** @return string e.g. "broadcast" */
    abstract public function id();

    /** Whether this context owns that email. */
    abstract public function handles($email_campaign_id);

    /**
     * Make a new email of this kind in a template.
     *
     * @param string $template a template id, or ER::CODE_YOUR_OWN_TEMPLATE
     * @param array $args what the kind needs (a broadcast: name; an automation: automation_id)
     *
     * @return int|\WP_Error the email's id
     */
    abstract public function create($template, $args);

    abstract public function back_url($email_campaign_id);

    /** Shown above the subject in the editor's top bar. */
    public function label($email_campaign_id)
    {
        return __('Editing email', 'mailoptin');
    }

    /**
     * A "next" button in the top bar, if this kind of email has a next step.
     *
     * @return array|null ['url' => ..., 'label' => ...]
     */
    public function next_step($email_campaign_id)
    {
        return null;
    }

    public function editable($email_campaign_id)
    {
        return true;
    }

    /** The preview, with sample values where the email has placeholders. */
    public function preview_html($email_campaign_id, $html)
    {
        return $html;
    }

    /** @return bool */
    abstract public function send_test($email_campaign_id, $to);

    /**
     * Placeholders this kind of email fills in, for the editor's "Insert placeholder" menu.
     *
     * @return array [['label' => group, 'items' => [['key' => 'subscriber.first_name', 'label' => ...], ...]], ...]
     */
    public function placeholders($email_campaign_id)
    {
        $items = [
            ['key' => 'subscriber.first_name', 'label' => __('First name', 'mailoptin')],
            ['key' => 'subscriber.last_name', 'label' => __('Last name', 'mailoptin')],
            ['key' => 'subscriber.email', 'label' => __('Email address', 'mailoptin')],
        ];

        if (\MailOptin\Core\Repositories\TagsRepository::is_ready()) {
            foreach (\MailOptin\Core\Repositories\CustomFieldsRepository::all() as $field) {
                $items[] = ['key' => 'subscriber.' . $field['merge_key'], 'label' => $field['label']];
            }
        }

        return [['label' => __('Subscriber', 'mailoptin'), 'items' => $items]];
    }

    /**
     * The templates a new email of this kind can start from: id => template. "Code your own" is
     * offered as well when this returns true from allows_code().
     */
    public function templates()
    {
        return array_filter(Templates::get_instance()->all(), function ($template) {
            return in_array($this->campaign_type(), $template['campaign_types'], true);
        });
    }

    public function allows_code()
    {
        return true;
    }

    /** The email campaign type this kind of email is stored as. */
    abstract public function campaign_type();

    /** Whether a template id is one a new email of this kind may use. */
    public function accepts_template($template)
    {
        return isset($this->templates()[$template]) || ($this->allows_code() && ER::CODE_YOUR_OWN_TEMPLATE === $template);
    }
}
