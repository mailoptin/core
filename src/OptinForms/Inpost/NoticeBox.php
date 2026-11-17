<?php

namespace MailOptin\Core\OptinForms\Inpost;

use MailOptin\Core\OptinForms\AbstractMessageTheme;

/** Notice Box: a highlighted box inside a post, with an icon, a title, a line of text and a button. */
class NoticeBox extends AbstractMessageTheme
{
    public $optin_form_name = 'Notice Box';

    public static $type = 'inpost';

    public static $parts = ['icon', 'title', 'text', 'button'];

    public static function message_defaults()
    {
        return ['msg_icon' => '🌷', 'msg_accent' => '#1f7a4d'];
    }

    protected function template_defaults()
    {
        return [
            'mo_optin_form_headline_default'                   => __('Planting tulips this month?', 'mailoptin'),
            'mo_optin_form_description_default'                => __('Our step-by-step guide covers depth, spacing and timing.', 'mailoptin'),
            'mo_optin_form_cta_button_default'                 => __('Read the guide', 'mailoptin'),
            'mo_optin_form_background_color_default'           => '#eef6f1',
            'mo_optin_form_headline_font_size_desktop_default' => 16,
            'mo_optin_form_headline_font_size_tablet_default'  => 16,
            'mo_optin_form_headline_font_size_mobile_default'  => 15,
            'mo_optin_form_description_font_size_desktop_default' => 14,
            'mo_optin_form_description_font_size_tablet_default'  => 14,
            'mo_optin_form_description_font_size_mobile_default'  => 14,
            'mo_optin_form_inpost_form_optin_position_default' => 'after_content',
        ];
    }

    public function optin_form()
    {
        $icon   = $this->icon_html('mo-msg-icon nb-icon');
        $button = $this->button_html();

        return <<<HTML
[mo-optin-form-wrapper class="mo-msg-notice-box"]
$icon
<div class="nb-body">
[mo-optin-form-headline class="nb-title" tag="div"]
[mo-optin-form-description class="nb-text"]
</div>
$button
[/mo-optin-form-wrapper]
HTML;
    }

    public function optin_form_css()
    {
        $c      = $this->scope('mo-msg-notice-box');
        $bg     = $this->color('form_background_color', '#eef6f1');
        $accent = sanitize_hex_color((string)$this->msg('msg_accent')) ?: '#1f7a4d';

        return $this->shared_css($c) . <<<CSS
$c { display: flex; align-items: center; gap: 16px; margin: 24px 0; padding: 16px 20px; background: $bg; border-left: 4px solid $accent; border-radius: 6px; }
$c .nb-icon { font-size: 24px; }
$c .nb-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
@media only screen and (max-width: 575px) {
    $c { flex-wrap: wrap; }
    $c a.mo-msg-button { width: 100%; }
}
CSS;
    }
}
