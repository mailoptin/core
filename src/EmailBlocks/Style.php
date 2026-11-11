<?php

namespace MailOptin\Core\EmailBlocks;

use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * The template's fonts and colours, for blocks to match it.
 */
class Style
{
    /** @var object|null the email's template */
    public $template;

    public $email_campaign_id;

    /** @var int the width blocks are drawn in: the email's body, or a column */
    public $width = 570;

    public function __construct($email_campaign_id, $template = null)
    {
        $this->email_campaign_id = absint($email_campaign_id);
        $this->template          = $template;
    }

    /**
     * The same style, for blocks drawn in a narrower space (a column).
     */
    public function with_width($width)
    {
        $style        = clone $this;
        $style->width = max(1, (int)$width);

        return $style;
    }

    /**
     * A font name as a CSS font stack with fallbacks.
     */
    public function font_stack($font_family)
    {
        if ($this->template && method_exists($this->template, 'get_font_family_stack')) {
            return $this->template->get_font_family_stack($font_family);
        }

        return $font_family;
    }

    /**
     * One of the email's design settings (e.g. "content_text_color"), from the template's defaults if unset.
     */
    public function get($key, $default = '')
    {
        $value = $this->email_campaign_id ? ER::get_merged_customizer_value($this->email_campaign_id, $key) : null;

        return null === $value || '' === $value ? $default : $value;
    }
}
