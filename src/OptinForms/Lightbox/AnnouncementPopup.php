<?php

namespace MailOptin\Core\OptinForms\Lightbox;

use MailOptin\Core\OptinForms\AbstractMessageTheme;

/** Announcement Pop-up: an image, title, text, button and a "No thanks" link, for big news. */
class AnnouncementPopup extends AbstractMessageTheme
{
    /** Where a countdown fits in this theme, best first. */
    public static function countdown_positions()
    {
        return [self::COUNTDOWN_BELOW_DESCRIPTION, self::COUNTDOWN_ABOVE_HEADLINE];
    }

    public $optin_form_name = 'Announcement Pop-up';

    public static $type = 'lightbox';

    public static $parts = ['image', 'title', 'text', 'button', 'no_thanks'];

    public static function message_defaults()
    {
        return ['msg_no_thanks' => __('No thanks', 'mailoptin'), 'msg_image' => MAILOPTIN_ASSETS_URL . 'images/optin-themes/messages/sample-image.jpg'];
    }

    protected function template_defaults()
    {
        return [
            'mo_optin_form_headline_default'                   => __('Our spring catalogue is here', 'mailoptin'),
            'mo_optin_form_description_default'                => __('40 new varieties, from scented narcissus to dwarf tulips.', 'mailoptin'),
            'mo_optin_form_cta_button_default'                 => __('See what\'s new', 'mailoptin'),
            'mo_optin_form_headline_font_size_desktop_default' => 22,
            'mo_optin_form_headline_font_size_tablet_default'  => 22,
            'mo_optin_form_headline_font_size_mobile_default'  => 20,
            'mo_optin_form_description_font_size_desktop_default' => 15,
            'mo_optin_form_description_font_size_tablet_default'  => 15,
            'mo_optin_form_description_font_size_mobile_default'  => 14,
        ];
    }

    public function optin_form()
    {
        $image     = $this->image_html('mo-msg-image ap-image');
        $button    = $this->button_html();
        $no_thanks = $this->no_thanks_html();
        $close     = $this->close_html('mo-msg-close ap-close');

        return <<<HTML
[mo-optin-form-wrapper class="mo-msg-announcement-popup"]
$close
$image
<div class="ap-body">
[mo-optin-form-headline class="ap-title" tag="div"]
[mo-optin-form-description class="ap-text"]
<div class="ap-actions">$button</div>
$no_thanks
</div>
[/mo-optin-form-wrapper]
HTML;
    }

    public function optin_form_css()
    {
        $c  = $this->scope('mo-msg-announcement-popup');
        $bg = $this->color('form_background_color', '#ffffff');

        return $this->shared_css($c) . <<<CSS
$c { position: relative; width: 100%; max-width: 440px; margin: 0 auto; background: $bg; border-radius: 12px; overflow: hidden; text-align: center; }
$c .ap-image { height: 170px; }
$c .ap-body { padding: 22px 28px 24px; display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; }
$c .mo-optin-form-headline, $c .mo-optin-form-description { text-align: center; }
$c .ap-actions { margin-top: 8px; }
$c .ap-close { position: absolute; top: 10px; right: 10px; z-index: 2; background: rgba(255,255,255,.92); color: #1f2933; width: 30px; height: 30px; font-size: 20px; }
@media only screen and (max-width: 575px) {
    $c .ap-body { padding: 18px 18px 20px; }
    $c a.mo-msg-button { width: 100%; }
}
CSS;
    }
}
