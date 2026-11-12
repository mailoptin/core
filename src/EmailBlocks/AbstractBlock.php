<?php

namespace MailOptin\Core\EmailBlocks;

/**
 * A block in the email builder: its fields, its defaults, how its settings are cleaned on save, and
 * the email-safe HTML it renders. Register one on the mailoptin_email_blocks action:
 *
 *     add_action('mailoptin_email_blocks', function (Blocks $blocks) { $blocks->add(new My_Block()); });
 *
 * A template can still draw a block its own way with a {block id}_block($id, $settings) method.
 */
abstract class AbstractBlock
{
    abstract public function id();

    abstract public function title();

    /**
     * Fields of its settings form. Each: type (text, textarea, tinymce, select, checkbox, range,
     * color_picker, dimension, select_image, font_family, url), label, tab, and for some types
     * choices / min / max / step. Settings not listed here are kept as they are.
     *
     * @return array name => field
     */
    abstract public function fields();

    /**
     * The HTML for one block: one or more <tr> rows of the email's content table.
     *
     * @param array $settings the block's settings, merged over its defaults
     * @param array $context what the email is about (for an automation: its run's data), may be empty
     * @param Style $style the template's fonts and colours
     * @param string $id the block's id, for the preview to find it
     */
    abstract public function render($settings, $context, Style $style, $id);

    public function icon()
    {
        return '';
    }

    public function description()
    {
        return '';
    }

    /**
     * @return array tab id => label
     */
    public function tabs()
    {
        return [];
    }

    /**
     * Whether it is offered in this email's "Add an element" list (e.g. only in abandoned-cart emails).
     */
    public function available_for($email_campaign_id)
    {
        return true;
    }

    /**
     * Default settings for a new block of this type in this email (the template may change them).
     */
    public function defaults($email_campaign_id)
    {
        $defaults = [];

        foreach ($this->fields() as $name => $field) {
            if (array_key_exists('default', $field)) $defaults[$name] = $field['default'];
        }

        // The email's template first (once per email): it adds its own defaults (its colours).
        static $made = [];
        $email_campaign_id = absint($email_campaign_id);
        if ($email_campaign_id && ! isset($made[$email_campaign_id])) {
            $made[$email_campaign_id] = true;
            \MailOptin\Core\Admin\Customizer\EmailCampaign\EmailCampaignFactory::make($email_campaign_id);
        }

        return (array)apply_filters('mailoptin_email_block_defaults', $defaults, $this->id(), $email_campaign_id);
    }

    /**
     * Settings as saved, each checked against its field type. Unknown settings are dropped.
     *
     * @param array $settings
     *
     * @return array
     */
    public function sanitize($settings)
    {
        $settings = (array)$settings;
        $clean    = [];

        foreach ($this->fields() as $name => $field) {
            if ( ! array_key_exists($name, $settings)) continue;

            $clean[$name] = Fields::sanitize($field, $settings[$name]);
        }

        return $clean;
    }

    /**
     * For the editor: everything about the block it needs, as data.
     */
    public function to_array($email_campaign_id)
    {
        return [
            'id'          => $this->id(),
            'title'       => $this->title(),
            'icon'        => $this->icon(),
            'description' => $this->description(),
            'tabs'        => $this->tabs(),
            'fields'      => $this->fields(),
            'defaults'    => $this->defaults($email_campaign_id),
        ];
    }
}
