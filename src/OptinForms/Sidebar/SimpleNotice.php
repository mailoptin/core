<?php

namespace MailOptin\Core\OptinForms\Sidebar;

use MailOptin\Core\OptinForms\AbstractMessageTheme;

/** Simple Notice: a coloured card in the sidebar with an icon, a title, a line of text and a link. */
class SimpleNotice extends AbstractMessageTheme
{
    /** Where a countdown fits in this theme, best first. */
    public static function countdown_positions()
    {
        return [self::COUNTDOWN_BELOW_DESCRIPTION, self::COUNTDOWN_ABOVE_HEADLINE];
    }

    public $optin_form_name = 'Simple Notice';

    public static $type = 'sidebar';

    public static $parts = ['icon', 'title', 'text', 'button'];

    public static function message_defaults()
    {
        return ['msg_icon' => '📦'];
    }

    protected function template_defaults()
    {
        return [
            'mo_optin_form_headline_default'                   => __('Free UK delivery over £35', 'mailoptin'),
            'mo_optin_form_description_default'                => __('Orders by 2pm go out the same day.', 'mailoptin'),
            'mo_optin_form_cta_button_default'                 => __('Delivery info', 'mailoptin'),
            'mo_optin_form_background_color_default'           => '#fef3c7',
            'mo_optin_form_headline_font_color_default'        => '#713f12',
            'mo_optin_form_description_font_color_default'     => '#713f12',
            'mo_optin_form_cta_button_background_default'      => '#713f12',
            'mo_optin_form_cta_button_color_default'           => '#713f12',
            'mo_optin_form_headline_font_size_desktop_default' => 16,
            'mo_optin_form_headline_font_size_tablet_default'  => 16,
            'mo_optin_form_headline_font_size_mobile_default'  => 16,
            'mo_optin_form_description_font_size_desktop_default' => 14,
            'mo_optin_form_description_font_size_tablet_default'  => 14,
            'mo_optin_form_description_font_size_mobile_default'  => 14,
        ];
    }

    public function optin_form()
    {
        $icon   = $this->icon_html('mo-msg-icon sn-icon');
        $button = $this->button_html('mo-msg-button sn-link');

        return <<<HTML
[mo-optin-form-wrapper class="mo-msg-simple-notice"]
$icon
[mo-optin-form-headline class="sn-title" tag="div"]
[mo-optin-form-description class="sn-text"]
$button
[/mo-optin-form-wrapper]
HTML;
    }

    public function optin_form_css()
    {
        $c    = $this->scope('mo-msg-simple-notice');
        $bg   = $this->color('form_background_color', '#fef3c7');
        $link = $this->color('cta_button_color', '#713f12');

        return $this->shared_css($c) . <<<CSS
$c { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; padding: 18px; background: $bg; border-radius: 8px; }
$c .sn-icon { font-size: 24px; }
/* A text link rather than a filled button. */
$c a.mo-msg-button.sn-link { min-height: 0; padding: 0; margin-top: 4px; background: none; color: $link !important; text-decoration: underline !important; font-size: 14px; font-weight: 600; }
CSS;
    }
}
