<?php

namespace MailOptin\Core\OptinForms;

use MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_Custom_Content;
use MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_Toggle_Control;
use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;
use MailOptin\Core\Repositories\OptinCampaignStat;
use MailOptin\Core\Repositories\OptinThemesRepository;

/**
 * Message campaigns ("Announcements" on Add New): optin campaigns made from a message template
 * (AbstractMessageTheme) show a banner, notice or pop-up with a button, and collect no email.
 *
 * - The Customizer shows a Message section with only the parts the template has, and hides Headline,
 *   Description, Note, Fields, Integrations and After Conversion. The colours are in Design.
 * - A click on the button counts as the campaign's conversion, so the list shows clicks and the click
 *   rate. The success cookie (Configuration › Success Cookie Duration) then hides it for that visitor.
 */
class Messages
{
    const OBJECTIVE = 'messages';
    const SECTION = 'mo_message_section';

    /** Sections a message has no use for. */
    const HIDDEN_SECTIONS = ['mo_headline_section', 'mo_description_section', 'mo_note_section', 'mo_fields_section', 'mo_integration_section', 'mo_success_section'];

    public function __construct()
    {
        add_filter('mo_optin_customizer_sections_ids', [$this, 'sections'], 20);
        add_action('mo_optin_before_design_customizer_section', [$this, 'section'], 10, 2);
        add_action('mo_optin_customizer_settings', [$this, 'settings'], 10, 3);
        add_action('mo_optin_after_customizer_controls', [$this, 'controls'], 10, 4);

        add_filter('mo_optin_js_config', [$this, 'js_config'], 10, 2);
        add_action('mailoptin_ajax_track_message_click', [$this, 'track_click']);
        add_filter('mo_optin_table_column', [$this, 'list_value'], 10, 3);
    }

    // -- Which campaigns are messages -- //

    /** The template class a campaign is drawn with, as OptinFormFactory resolves it. */
    public static function template_class($optin_campaign_id)
    {
        static $cache = [];

        $optin_campaign_id = absint($optin_campaign_id);
        if (isset($cache[$optin_campaign_id])) return $cache[$optin_campaign_id];

        return $cache[$optin_campaign_id] = self::resolve_class($optin_campaign_id);
    }

    protected static function resolve_class($optin_campaign_id)
    {
        $class = OCR::get_optin_campaign_class($optin_campaign_id);
        $type  = ucfirst((string)OCR::get_optin_campaign_type($optin_campaign_id));

        if ( ! $class || ! $type) return '';

        return (string)apply_filters('mailoptin_register_optin_class', "\\MailOptin\\Core\\OptinForms\\$type\\$class", $optin_campaign_id, $class, $type);
    }

    public static function is_campaign($optin_campaign_id)
    {
        static $cache = [];

        $id = absint($optin_campaign_id);
        if ( ! $id) return false;

        if ( ! isset($cache[$id])) {
            $class      = self::template_class($id);
            $cache[$id] = $class && class_exists($class) && is_subclass_of($class, AbstractMessageTheme::class);
        }

        return $cache[$id];
    }

    public static function on($value)
    {
        return true === $value || 1 === $value || '1' === $value || 'true' === $value || 'on' === $value;
    }

    /** A msg_* value: saved, else the template's default. */
    public static function value($optin_campaign_id, $key, $class = null)
    {
        $class    = $class ?: self::template_class($optin_campaign_id);
        $defaults = $class && class_exists($class) && is_subclass_of($class, AbstractMessageTheme::class) ? $class::message_defaults() : [];

        return OCR::get_customizer_value($optin_campaign_id, $key, $defaults[$key] ?? '');
    }

    /** The parts the campaign's template has: label, title, text, image, icon, code, button, no_thanks. */
    public static function parts($optin_campaign_id)
    {
        $class = self::template_class($optin_campaign_id);

        return $class && class_exists($class) && is_subclass_of($class, AbstractMessageTheme::class) ? (array)$class::$parts : [];
    }

    // -- Customizer -- //

    protected static function customizer_campaign_id()
    {
        $id = absint($_REQUEST['mailoptin_optin_campaign_id'] ?? 0);

        return $id && self::is_campaign($id) ? $id : 0;
    }

    public function sections($sections)
    {
        if ( ! self::customizer_campaign_id()) return $sections;

        $sections[] = self::SECTION;

        return array_values(array_diff($sections, (array)apply_filters('mailoptin_message_hidden_sections', self::HIDDEN_SECTIONS)));
    }

    public function section($wp_customize, $customizer)
    {
        if ( ! isset($customizer->optin_campaign_id) || ! self::is_campaign($customizer->optin_campaign_id)) return;

        $wp_customize->add_section(self::SECTION, [
            'title'    => __('Message', 'mailoptin'),
            'priority' => 1,
        ]);
    }

    /** msg_* key => sanitize callback */
    protected static function setting_args()
    {
        $text = function ($v) { return sanitize_text_field((string)$v); };

        return [
            'msg_label'     => $text,
            'msg_image'     => 'esc_url_raw',
            'msg_icon'      => function ($v) { return mb_substr(sanitize_text_field((string)$v), 0, 8); },
            // As a coupon code is typed: shown and copied exactly as entered.
            'msg_code'      => function ($v) { return mb_substr(trim(sanitize_text_field((string)$v)), 0, 60); },
            'msg_new_tab'   => function ($v) { return self::on($v); },
            'msg_no_thanks' => $text,
            'msg_accent'    => function ($v) { return sanitize_hex_color((string)$v) ?: ''; },
        ];
    }

    public function settings($wp_customize, $option_prefix, $customizer)
    {
        $id = isset($customizer->optin_campaign_id) ? $customizer->optin_campaign_id : 0;
        if ( ! self::is_campaign($id)) return;

        $class    = self::template_class($id);
        $defaults = $class::message_defaults();

        foreach (self::setting_args() as $key => $sanitize) {
            $wp_customize->add_setting($option_prefix . '[' . $key . ']', [
                'default'           => $defaults[$key] ?? '',
                'type'              => 'option',
                'transport'         => 'refresh',
                'sanitize_callback' => $sanitize,
            ]);
        }
    }

    public function controls($instance, $wp_customize, $option_prefix, $customizer)
    {
        $campaign_id = isset($customizer->optin_campaign_id) ? $customizer->optin_campaign_id : 0;
        if ( ! self::is_campaign($campaign_id)) return;

        $id    = function ($key) use ($option_prefix) { return $option_prefix . '[' . $key . ']'; };
        $parts = self::parts($campaign_id);
        $has   = function ($part) use ($parts) { return in_array($part, $parts, true); };

        // The button and its colours are drawn by the template, so changes redraw the preview; the title
        // and text keep core's live update.
        foreach (['cta_button', 'cta_button_navigation_url', 'cta_button_background', 'cta_button_color', 'headline_font_color', 'description_font_color'] as $key) {
            $setting = $wp_customize->get_setting($id($key));
            if ($setting) $setting->transport = 'refresh';
        }

        $priority = 0;
        $add      = function ($key, $args) use ($wp_customize, $id, &$priority) {
            $wp_customize->add_control($id('msg_control_' . $key), $args + ['section' => self::SECTION, 'settings' => $id($key), 'priority' => $priority += 5]);
        };

        if ($has('label')) {
            $add('msg_label', ['type' => 'text', 'label' => __('Label', 'mailoptin'), 'description' => __('Small text above the title. Leave empty for none.', 'mailoptin')]);
        }

        if ($has('title')) {
            $add('headline', ['type' => 'text', 'label' => $has('text') ? __('Title', 'mailoptin') : __('Message', 'mailoptin')]);
        }

        if ($has('text')) {
            $add('description', ['type' => 'textarea', 'label' => __('Text', 'mailoptin'), 'description' => __('Bold, italic and links allowed.', 'mailoptin')]);
        }

        if ($has('image')) {
            $wp_customize->add_control(new \WP_Customize_Image_Control($wp_customize, $id('msg_control_image'), [
                'label'    => __('Image', 'mailoptin'),
                'section'  => self::SECTION,
                'settings' => $id('msg_image'),
                'priority' => $priority += 5,
            ]));
        }

        if ($has('icon')) {
            $add('msg_icon', ['type' => 'text', 'label' => __('Icon', 'mailoptin'), 'description' => __('An emoji, such as 🎁 or 🌷. Leave empty for none.', 'mailoptin')]);
        }

        if ($has('code')) {
            $add('msg_code', ['type' => 'text', 'label' => __('Code', 'mailoptin'), 'description' => (string)apply_filters('mailoptin_message_code_description', __('Shown with a Copy button. Leave empty for none.', 'mailoptin'), $campaign_id)]);
        }

        if ($has('button')) {
            $add('cta_button', ['type' => 'text', 'label' => __('Button text', 'mailoptin'), 'description' => __('Leave empty for no button.', 'mailoptin')]);
            $add('cta_button_navigation_url', ['type' => 'url', 'label' => __('Button link', 'mailoptin')]);
            $wp_customize->add_control(new WP_Customize_Toggle_Control($wp_customize, $id('msg_control_new_tab'), [
                'label'    => __('Open the link in a new tab', 'mailoptin'),
                'section'  => self::SECTION,
                'settings' => $id('msg_new_tab'),
                'type'     => 'light',
                'priority' => $priority += 5,
            ]));
        }

        if ($has('no_thanks')) {
            $add('msg_no_thanks', ['type' => 'text', 'label' => __('"No thanks" link', 'mailoptin'), 'description' => __('Closes it. Leave empty for none.', 'mailoptin')]);
        }

        $wp_customize->add_control(new WP_Customize_Custom_Content($wp_customize, $id('msg_control_when'), [
            'section'  => self::SECTION,
            'settings' => [],
            'priority' => 200,
            'content'  => '<div class="mo-msg-when"><strong>' . esc_html__('When it shows', 'mailoptin') . '</strong><p>'
                          . esc_html__('Where, to whom and when: Display Rules (and Schedule, with Premium).', 'mailoptin') . ' <a href="#" data-mo-msg-focus="panel:mo_display_rules_panel_section">' . esc_html__('Open Display Rules', 'mailoptin') . '</a></p><p>'
                          . esc_html__('Pop-ups, bars and slide-ins stay hidden for a while after someone closes them (Cookie Duration) or clicks the button (Success Cookie Duration): Configuration.', 'mailoptin') . ' <a href="#" data-mo-msg-focus="section:mo_configuration_section">' . esc_html__('Open Configuration', 'mailoptin') . '</a></p></div>'
                          . '<style>.mo-msg-when{padding:12px;background:#fff;border:1px solid #dcdcde;border-radius:4px;font-size:13px;line-height:1.5}.mo-msg-when p{margin:6px 0 0}</style>'
                          . '<script>jQuery(function($){$(document).on("click","[data-mo-msg-focus]",function(e){e.preventDefault();var t=$(this).data("mo-msg-focus").split(":");var o=t[0]==="panel"?wp.customize.panel(t[1]):wp.customize.section(t[1]);if(o)o.focus();});});</script>',
        ]));

        // Design: the colours that live in the hidden Headline, Description and Fields sections.
        $colors = ['headline_font_color' => $has('text') ? __('Title colour', 'mailoptin') : __('Text colour', 'mailoptin')];
        if ($has('text')) $colors['description_font_color'] = __('Text colour', 'mailoptin');
        if ($has('label') || $has('code')) $colors['msg_accent'] = $has('label') ? __('Label colour', 'mailoptin') : __('Code colour', 'mailoptin');
        if ($has('button')) {
            $colors['cta_button_background'] = __('Button colour', 'mailoptin');
            $colors['cta_button_color']      = __('Button text colour', 'mailoptin');
        }

        $priority = 50;
        foreach ($colors as $key => $label) {
            $wp_customize->add_control(new \WP_Customize_Color_Control($wp_customize, $id('msg_design_' . $key), [
                'label'    => $label,
                'section'  => 'mo_design_section',
                'settings' => $id($key),
                'priority' => $priority += 5,
            ]));
        }
    }

    // -- Front end -- //

    public function js_config($data, $form)
    {
        $id = isset($form->optin_campaign_id) ? $form->optin_campaign_id : 0;

        if ($id && self::is_campaign($id)) {
            $data['is_message'] = true;
            // The button is a link (escaped in its href); the script doesn't navigate for it.
            unset($data['cta_navigate_url']);
        }

        return $data;
    }

    /**
     * A click on a message's button: the campaign's conversion. Sent by mailoptin.js as the visitor leaves
     * for the link (navigator.sendBeacon), so it never delays them.
     */
    public function track_click()
    {
        $payload = isset($_REQUEST['stat_data']) && is_array($_REQUEST['stat_data']) ? \MailOptin\Core\sanitize_data($_REQUEST['stat_data']) : [];
        $uuid    = isset($payload['optin_uuid']) && is_string($payload['optin_uuid']) ? $payload['optin_uuid'] : '';
        $id      = '' !== $uuid ? absint(OCR::get_optin_campaign_id_by_uuid($uuid)) : 0;

        if ( ! $id || ! self::is_campaign($id) || ! OCR::is_activated($id)) wp_die('', '', ['response' => 400]);

        if (apply_filters('mo_disable_message_click_tracking', false, $id)) wp_die('', '', ['response' => 204]);

        // One click per visitor (IP address) per message per day: the endpoint is public, and clicks are
        // what split tests on messages are judged on.
        $ip  = function_exists('MailOptin\Core\get_ip_address') ? (string)\MailOptin\Core\get_ip_address() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $key = 'mo_msg_click_' . md5($id . '|' . $ip);
        if (get_transient($key)) wp_die('', '', ['response' => 204]);
        set_transient($key, 1, DAY_IN_SECONDS);

        (new OptinCampaignStat($id))->save('conversion');

        do_action('mailoptin_message_clicked', $id);

        wp_die('', '', ['response' => 204]);
    }

    /** The Optin Campaigns list: a message's conversions are clicks on its button. */
    public function list_value($value, $item, $column)
    {
        if ('conversion' !== $column || ! self::is_campaign(absint($item['id'] ?? 0))) return $value;

        $count = absint($value);

        return '<span title="' . esc_attr__('Clicks on the button', 'mailoptin') . '">' . esc_html(sprintf(_n('%s click', '%s clicks', $count, 'mailoptin'), number_format_i18n($count))) . '</span>';
    }

    public static function get_instance()
    {
        static $instance = null;

        if (null === $instance) $instance = new self();

        return $instance;
    }
}
