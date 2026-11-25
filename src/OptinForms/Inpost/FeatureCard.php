<?php

namespace MailOptin\Core\OptinForms\Inpost;

use MailOptin\Core\OptinForms\AbstractMessageTheme;

/** Feature Card: an image beside a label, title, text and button, to point readers to a product or page. */
class FeatureCard extends AbstractMessageTheme
{
    public $optin_form_name = 'Feature Card';

    public static $type = 'inpost';

    public static $parts = ['label', 'title', 'text', 'image', 'button'];

    public static function message_defaults()
    {
        return ['msg_label' => __('From our shop', 'mailoptin'), 'msg_accent' => '#1f7a4d', 'msg_image' => MAILOPTIN_ASSETS_URL . 'images/optin-themes/messages/sample-image.jpg'];
    }

    protected function template_defaults()
    {
        return [
            'mo_optin_form_headline_default'                   => __('Bulbs that flower in their first year', 'mailoptin'),
            'mo_optin_form_description_default'                => __('Every bulb we sell is guaranteed to bloom, or we replace it.', 'mailoptin'),
            'mo_optin_form_cta_button_default'                 => __('Browse bulbs', 'mailoptin'),
            'mo_optin_form_headline_font_size_desktop_default' => 18,
            'mo_optin_form_headline_font_size_tablet_default'  => 18,
            'mo_optin_form_headline_font_size_mobile_default'  => 17,
            'mo_optin_form_description_font_size_desktop_default' => 14,
            'mo_optin_form_description_font_size_tablet_default'  => 14,
            'mo_optin_form_description_font_size_mobile_default'  => 14,
            'mo_optin_form_inpost_form_optin_position_default' => 'after_content',
        ];
    }

    public function optin_form()
    {
        $image  = $this->image_html('mo-msg-image fc-image');
        $label  = $this->label_html();
        $button = $this->button_html();

        return <<<HTML
[mo-optin-form-wrapper class="mo-msg-feature-card"]
$image
<div class="fc-body">
$label
[mo-optin-form-headline class="fc-title" tag="div"]
[mo-optin-form-description class="fc-text"]
<div class="fc-actions">$button</div>
</div>
[/mo-optin-form-wrapper]
HTML;
    }

    public function optin_form_css()
    {
        $c      = $this->scope('mo-msg-feature-card');
        $bg     = $this->color('form_background_color', '#ffffff');
        $border = $this->color('form_border_color', '#e4e7eb');

        return $this->shared_css($c) . <<<CSS
$c { display: flex; align-items: stretch; margin: 24px 0; background: $bg; border: 1px solid $border; border-radius: 10px; overflow: hidden; }
$c .fc-image { flex: 0 0 200px; min-height: 150px; }
$c .fc-body { flex: 1; min-width: 0; padding: 18px 20px; display: flex; flex-direction: column; gap: 5px; }
$c .fc-actions { margin-top: 8px; }
$c .fc-actions:empty { display: none; }
@media only screen and (max-width: 575px) {
    $c { flex-direction: column; }
    $c .fc-image { flex-basis: auto; height: 170px; }
}
CSS;
    }
}
