<?php

namespace MailOptin\Core\OptinForms;

/**
 * What every message template shares (Announcements: banners, notices and pop-ups that don't ask for an
 * email). A message has a title, and depending on the template a small label, a line of text, an image or
 * an icon, a discount code to copy, a button and a "No thanks" link.
 *
 * - The title, text and button are core's own settings (headline, description, cta_button and its link),
 *   edited in the Customizer's Message section, so live preview and split tests work as for any optin.
 * - The button is a link, so it works without JavaScript. A click counts as the campaign's conversion
 *   (Messages::track_click) and sets the success cookie, which hides the message for the Success Cookie
 *   Duration; closing it sets the usual cookie.
 */
abstract class AbstractMessageTheme extends AbstractOptinTheme
{
    /** @var string The optin type, as core names it: lightbox, slidein, bar, inpost, sidebar. */
    public static $type = '';

    /**
     * The parts this template has, in the order the Message section lists them: label, title, text, image,
     * icon, code, button, no_thanks.
     *
     * @var string[]
     */
    public static $parts = ['title', 'text', 'button'];

    /** This template's defaults (core settings, as init_config_filters names them, and msg_* values). */
    protected function template_defaults()
    {
        return [];
    }

    /** @return array msg_* key => default, for this template */
    public static function message_defaults()
    {
        return [];
    }

    public function __construct($optin_campaign_id)
    {
        $class = (new \ReflectionClass($this))->getShortName();

        static $registered = [];
        if ( ! isset($registered[static::class])) {
            $registered[static::class] = true;
            $type                      = static::$type;

            // The templates draw their own close button. (Inter is loaded from the font defaults below.)
            add_filter('mo_optin_campaign_icon_close', static function ($val, $optin_class, $optin_type) use ($class, $type) {
                return $optin_class === $class && $optin_type === $type ? false : $val;
            }, 10, 3);
        }

        $defaults = array_merge([
            // A button that goes to a link; no email field.
            'mo_optin_form_display_only_button_default'      => true,
            'mo_optin_form_cta_button_action_default'        => 'navigate_to_url',
            'mo_optin_form_cta_button_navigation_url_default' => home_url('/'),
            'mo_optin_form_hide_note_default'                => true,
            'mo_optin_form_hide_name_field_default'          => true,
            'mo_optin_form_headline_font_default'            => 'Inter',
            'mo_optin_form_description_font_default'         => 'Inter',
            'mo_optin_form_cta_button_font_default'          => 'Inter',
            'mo_optin_form_cta_button_color_default'         => '#ffffff',
            'mo_optin_form_cta_button_background_default'    => '#1f7a4d',
            'mo_optin_form_headline_font_color_default'      => '#1f2933',
            'mo_optin_form_description_font_color_default'   => '#52606d',
            'mo_optin_form_background_color_default'         => '#ffffff',
            'mo_optin_form_border_color_default'             => '#e4e7eb',
        ], $this->template_defaults());

        $filters = [];
        foreach ($defaults as $name => $value) {
            $filters[] = ['name' => $name, 'value' => $value, 'optin_class' => $class, 'optin_type' => static::$type];
        }

        $this->init_config_filters($filters);

        parent::__construct($optin_campaign_id);
    }

    public function features_support()
    {
        return [];
    }

    // No settings of their own beyond Messages' (core's Customizer hooks are left as they are).
    public function customizer_design_settings($settings, $CustomizerSettingsInstance) { return $settings; }
    public function customizer_design_controls($controls, $wp_customize, $option_prefix, $customizerClassInstance) { return $controls; }
    public function customizer_headline_settings($settings, $CustomizerSettingsInstance) { return $settings; }
    public function customizer_headline_controls($controls, $wp_customize, $option_prefix, $customizerClassInstance) { return $controls; }
    public function customizer_description_settings($settings, $CustomizerSettingsInstance) { return $settings; }
    public function customizer_description_controls($controls, $wp_customize, $option_prefix, $customizerClassInstance) { return $controls; }
    public function customizer_note_settings($settings, $CustomizerSettingsInstance) { return $settings; }
    public function customizer_note_controls($controls, $wp_customize, $option_prefix, $customizerClassInstance) { return $controls; }
    public function customizer_fields_settings($fields_settings, $CustomizerSettingsInstance) { return $fields_settings; }
    public function customizer_fields_controls($fields_controls, $wp_customize, $option_prefix, $customizerClassInstance) { return $fields_controls; }
    public function customizer_configuration_settings($configuration_settings, $CustomizerSettingsInstance) { return $configuration_settings; }
    public function customizer_configuration_controls($configuration_controls, $wp_customize, $option_prefix, $customizerClassInstance) { return $configuration_controls; }
    public function customizer_output_settings($output_settings, $CustomizerSettingsInstance) { return $output_settings; }
    public function customizer_output_controls($output_controls, $wp_customize, $option_prefix, $customizerClassInstance) { return $output_controls; }

    public function optin_script()
    {
        return '';
    }

    // -- Pieces for the layouts -- //

    /** A msg_* value of this campaign, with this template's default. */
    protected function msg($key)
    {
        return Messages::value($this->optin_campaign_id, $key, static::class);
    }

    protected function color($key, $fallback)
    {
        return sanitize_hex_color((string)$this->get_customizer_value($key)) ?: $fallback;
    }

    /**
     * Plain text for the template: escaped, with square brackets as entities, since the template is run
     * through do_shortcode and a typed "[...]" mustn't become a shortcode.
     */
    protected static function text($value)
    {
        return str_replace(['[', ']'], ['&#91;', '&#93;'], esc_html((string)$value));
    }

    protected function label_html($class = 'mo-msg-label')
    {
        $label = trim((string)$this->msg('msg_label'));

        return '' === $label ? '' : '<div class="' . esc_attr($class) . '">' . self::text($label) . '</div>';
    }

    /** The image: an img element (never a CSS url(), where a crafted address could add styles). */
    protected function image_html($class = 'mo-msg-image')
    {
        $url = esc_url((string)$this->msg('msg_image'));

        return '' === $url ? '' : '<div class="' . esc_attr($class) . '"><img src="' . $url . '" alt=""></div>';
    }

    protected function icon_html($class = 'mo-msg-icon')
    {
        $icon = trim((string)$this->msg('msg_icon'));

        return '' === $icon ? '' : '<span class="' . esc_attr($class) . '" aria-hidden="true">' . self::text($icon) . '</span>';
    }

    /** A code with a Copy button (the copying is done by mailoptin.js). */
    protected function code_html($class = 'mo-msg-code')
    {
        $code = trim((string)$this->msg('msg_code'));
        if ('' === $code) return '';

        return '<span class="' . esc_attr($class) . '"><span class="mo-msg-code-value">' . self::text($code) . '</span>'
               . '<button type="button" class="mo-msg-copy" data-mo-msg-copy="' . str_replace(['[', ']'], ['&#91;', '&#93;'], esc_attr($code)) . '" data-copied="' . esc_attr__('Copied', 'mailoptin') . '">' . esc_html__('Copy', 'mailoptin') . '</button></span>';
    }

    /**
     * The button: a link to the button link, or nothing when its text is empty. Its id is the one
     * mailoptin.js listens on for the click.
     */
    protected function button_html($class = 'mo-msg-button')
    {
        $text = trim((string)$this->get_customizer_value('cta_button'));
        if ('' === $text) return '';

        $url    = esc_url((string)$this->get_customizer_value('cta_button_navigation_url'));
        $target = Messages::on($this->msg('msg_new_tab')) ? ' target="_blank" rel="noopener"' : '';

        return '<a id="' . esc_attr($this->optin_css_id) . '_cta_button" class="mo-optin-form-cta-button ' . esc_attr($class) . '" href="' . ($url ?: '#') . '"' . $target . '>' . self::text($text) . '</a>';
    }

    protected function no_thanks_html($class = 'mo-msg-no-thanks')
    {
        $text = trim((string)$this->msg('msg_no_thanks'));

        return '' === $text ? '' : '[mo-close-optin class="' . esc_attr($class) . '"]' . self::text($text) . '[/mo-close-optin]';
    }

    protected function close_html($class = 'mo-msg-close')
    {
        return '[mo-close-optin class="' . esc_attr($class) . '"]&times;[/mo-close-optin]';
    }

    /** CSS selector for this campaign's wrapper. */
    protected function scope($wrapper_class)
    {
        return "html div#{$this->optin_campaign_uuid} div#{$this->optin_css_id}.$wrapper_class";
    }

    /** The CSS every message template shares, scoped to its wrapper. */
    protected function shared_css($c)
    {
        $title  = $this->color('headline_font_color', '#1f2933');
        $text   = $this->color('description_font_color', '#52606d');
        $btn_bg = $this->color('cta_button_background', '#1f7a4d');
        $btn    = $this->color('cta_button_color', '#ffffff');
        $accent = sanitize_hex_color((string)$this->msg('msg_accent')) ?: $btn_bg;

        return <<<CSS
$c, $c * { box-sizing: border-box; }
/* Core wraps the content in a form: it takes no part in the layout. */
$c form.mo-optin-form { display: contents; }
$c { font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.4; text-align: left; }
$c .mo-optin-form-headline { color: $title; font-weight: 700; margin: 0; }
$c .mo-optin-form-description { color: $text; margin: 0; }
$c .mo-optin-form-headline:empty, $c .mo-optin-form-description:empty { display: none; }
$c .mo-msg-label { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: $accent; }
$c .mo-msg-image { overflow: hidden; background: rgba(127,127,127,.12); }
$c .mo-msg-image img { display: block; width: 100%; height: 100%; max-width: none; margin: 0; object-fit: cover; }
$c .mo-msg-icon { display: inline-flex; align-items: center; justify-content: center; line-height: 1; }
$c a.mo-msg-button {
    display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 0 18px;
    border-radius: 4px; background: $btn_bg; color: $btn !important; font-weight: 700; font-size: 15px;
    text-decoration: none !important; line-height: 1.2; white-space: nowrap; cursor: pointer;
}
$c a.mo-msg-button:hover, $c a.mo-msg-button:focus { filter: brightness(.92); }
$c .mo-msg-code {
    display: inline-flex; align-items: center; gap: 8px; padding: 4px 4px 4px 12px; border: 2px dashed currentColor;
    border-radius: 6px; font-weight: 700; letter-spacing: .06em;
}
$c .mo-msg-copy {
    margin: 0; padding: 5px 9px; border: 0; border-radius: 4px; background: rgba(127,127,127,.22); color: inherit;
    cursor: pointer; font-size: 12px; font-weight: 600; letter-spacing: 0; line-height: 1.2;
}
$c .mo-msg-close {
    display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%;
    font-size: 22px; line-height: 1; cursor: pointer; text-decoration: none !important; user-select: none; opacity: .8;
}
$c .mo-msg-close:hover, $c .mo-msg-close:focus { opacity: 1; }
$c .mo-msg-no-thanks { font-size: 13px; opacity: .7; cursor: pointer; text-decoration: none !important; }
@media only screen and (max-width: 575px) {
    $c .mo-msg-close { width: 44px; height: 44px; }
}
CSS;
    }
}
