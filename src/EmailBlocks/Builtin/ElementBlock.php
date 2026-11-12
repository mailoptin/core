<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Misc;
use MailOptin\Core\EmailBlocks\AbstractBlock;

/**
 * A built-in block whose fields come from the builder element of the same name, so the Customizer
 * builder and this API describe it the same way.
 */
abstract class ElementBlock extends AbstractBlock
{
    /** @return string the builder element's class */
    abstract protected function element_class();

    protected function element()
    {
        $class = $this->element_class();

        return $class::get_instance();
    }

    public function id()
    {
        return $this->element()->id();
    }

    public function title()
    {
        return $this->element()->title();
    }

    public function icon()
    {
        return $this->element()->icon();
    }

    public function description()
    {
        return $this->element()->description();
    }

    public function tabs()
    {
        return $this->element()->tabs();
    }

    public function fields()
    {
        return $this->element()->settings();
    }

    /** @var array email campaign ID => every element's defaults, for this request */
    protected static $defaults = [];

    public function defaults($email_campaign_id)
    {
        $email_campaign_id = absint($email_campaign_id);

        // Built once per email per request: it reads the email's settings and the template's defaults.
        if ( ! isset(self::$defaults[$email_campaign_id])) {
            // The template adds its own defaults (sizes, colours) when it is made.
            if ($email_campaign_id) \MailOptin\Core\Admin\Customizer\EmailCampaign\EmailCampaignFactory::make($email_campaign_id);

            self::$defaults[$email_campaign_id] = Misc::elements_default_fields_values($email_campaign_id);
        }

        return (array)apply_filters('mailoptin_email_block_defaults', (array)(self::$defaults[$email_campaign_id][$this->id()] ?? []), $this->id(), $email_campaign_id);
    }

    public static function flush_defaults()
    {
        self::$defaults = [];
    }
}
