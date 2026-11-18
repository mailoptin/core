<?php

namespace MailOptin\Core\OptinForms;

use MailOptin\Core\OptinForms\Wheel\Wheel;

/**
 * What every prize-wheel template shares (Spin to win). A template only says where things go: the wheel,
 * drawn here as SVG from the campaign's slices, and the form, built from core's own form parts (email
 * field, custom fields, consent, submit button), so integrations, double opt-in and the rest work as on
 * any optin. mailoptin.js turns the wheel once the site has drawn the prize, and shows the prize in
 * .mo-wheel-result.
 *
 * Colours come from the campaign's settings (a preset sets them all, Wheel::presets()), as CSS variables.
 */
abstract class AbstractWheelTheme extends AbstractOptinTheme
{
    /** @var string The optin type, as core names it: lightbox, slidein, inpost. */
    public static $type = '';

    /** @var string The layout: split, stacked, inline, edge or corner (the CSS class "mo-wheel-{layout}"). */
    public static $layout = 'split';

    /** @var int Where the pointer is, in degrees clockwise from the top: 0, or 90 for one on the right. */
    public static $pointer = 0;

    /** This template's defaults for core settings (as init_config_filters names them, without the prefix). */
    protected function template_defaults()
    {
        return [];
    }

    /** @return array wheel_* key => default, for this template */
    public static function wheel_defaults()
    {
        return Wheel::defaults();
    }

    public function __construct($optin_campaign_id)
    {
        $class = (new \ReflectionClass($this))->getShortName();

        static $registered = [];
        if ( ! isset($registered[static::class])) {
            $registered[static::class] = true;
            $type                      = static::$type;

            // The templates draw their own close button.
            add_filter('mo_optin_campaign_icon_close', static function ($val, $optin_class, $optin_type) use ($class, $type) {
                return $optin_class === $class && $optin_type === $type ? false : $val;
            }, 10, 3);
        }

        $p = Wheel::presets()['garden']['colors'];

        $defaults = array_merge([
            'headline'                       => __('Spin for a discount', 'mailoptin'),
            'description'                    => __('Up to 15% off, free shipping or a free guide. One spin per email.', 'mailoptin'),
            'note'                           => __('We\'ll email your prize, then send you news now and then. Unsubscribe any time.', 'mailoptin'),
            'submit_button'                  => __('Spin the wheel', 'mailoptin'),
            'email_field_placeholder'        => __('you@example.com', 'mailoptin'),
            'hide_name_field'                => true,
            'display_only_button'            => false,
            'headline_font'                  => 'Inter',
            'description_font'               => 'Inter',
            'note_font'                      => 'Inter',
            'submit_button_font'             => 'Inter',
            'email_field_font'               => 'Inter',
            'headline_font_color'            => $p['headline_font_color'],
            'description_font_color'         => $p['description_font_color'],
            'note_font_color'                => '#6b756f',
            'form_background_color'          => $p['form_background_color'],
            'submit_button_background'       => $p['submit_button_background'],
            'submit_button_color'            => $p['submit_button_color'],
            'email_field_background'         => '#ffffff',
            'email_field_color'              => '#1f2a24',
            'headline_font_size_desktop'     => 30,
            'headline_font_size_tablet'      => 28,
            'headline_font_size_mobile'      => 24,
            'description_font_size_desktop'  => 15,
            'description_font_size_tablet'   => 15,
            'description_font_size_mobile'   => 14,
            'note_font_size_desktop'         => 13,
            'note_font_size_tablet'          => 13,
            'note_font_size_mobile'          => 12,
            'form_width'                     => 860,
        ], $this->template_defaults());

        $filters = [];
        foreach ($defaults as $name => $value) {
            // Core names the "form_" settings' defaults without that prefix (mo_optin_form_width_default).
            $key       = 0 === strpos($name, 'form_') ? substr($name, 5) : $name;
            $filters[] = ['name' => 'mo_optin_form_' . $key . '_default', 'value' => $value, 'optin_class' => $class, 'optin_type' => static::$type];
        }

        $this->init_config_filters($filters);

        parent::__construct($optin_campaign_id);
    }

    public function features_support()
    {
        return [];
    }

    // No settings of their own beyond Wheel's (core's Customizer hooks are left as they are).
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

    // -- Pieces -- //

    protected function wheel($key)
    {
        return Wheel::value($this->optin_campaign_id, $key);
    }

    protected function color($key, $fallback)
    {
        $value = 0 === strpos($key, 'wheel_') ? $this->wheel($key) : $this->get_customizer_value($key);

        return sanitize_hex_color((string)$value) ?: $fallback;
    }

    /** Plain text: escaped, with square brackets as entities (the template is run through do_shortcode). */
    protected static function text($value)
    {
        return str_replace(['[', ']'], ['&#91;', '&#93;'], esc_html((string)$value));
    }

    /**
     * The wheel: slices with their labels along the radius, a rim and a hub. Slice 0 is centred under the
     * pointer before the first spin.
     */
    public static function wheel_svg($slices, $ring, $hub, $rotation = 0)
    {
        $n = count($slices);
        if ($n < 1) return '';

        $c    = 170;
        $r    = 160;
        $step = 2 * M_PI / $n;
        $font = $n > 8 ? 12 : ($n > 6 ? 13 : 15);

        $svg = '<svg class="mo-wheel-svg" viewBox="0 0 340 340" role="img" aria-label="' . esc_attr__('Prize wheel', 'mailoptin') . '" focusable="false">';
        $svg .= '<g class="mo-wheel-rotor" style="transform-origin:170px 170px;transform:rotate(' . (float)$rotation . 'deg)">';

        foreach (array_values($slices) as $i => $s) {
            $a0 = $i * $step - M_PI / 2 - $step / 2;
            $a1 = $a0 + $step;
            $x0 = round($c + $r * cos($a0), 2);
            $y0 = round($c + $r * sin($a0), 2);
            $x1 = round($c + $r * cos($a1), 2);
            $y1 = round($c + $r * sin($a1), 2);

            $large = $step > M_PI ? 1 : 0;
            $svg   .= sprintf('<path d="M%1$d,%1$d L%2$s,%3$s A%4$d,%4$d 0 %7$d 1 %5$s,%6$s Z" fill="%8$s" stroke="#ffffff" stroke-width="2"/>', $c, $x0, $y0, $r, $x1, $y1, $large, esc_attr($s['color']));

            // The label along the slice's middle, read from the centre outwards; turned round on the left half
            // so it's never upside down.
            $mid  = ($a0 + $a1) / 2;
            $deg  = rad2deg($mid);
            $tx   = round($c + 98 * cos($mid), 2);
            $ty   = round($c + 98 * sin($mid), 2);
            $turn = cos($mid) < -0.01 ? $deg + 180 : $deg;

            $svg .= sprintf(
                '<text x="%1$s" y="%2$s" fill="%3$s" font-size="%4$d" font-weight="700" text-anchor="middle" dominant-baseline="middle" transform="rotate(%5$s %1$s %2$s)">%6$s</text>',
                $tx, $ty, esc_attr($s['ink']), $font, round($turn, 2), self::text($s['label'])
            );
        }

        $svg .= '</g>';
        $svg .= sprintf('<circle cx="170" cy="170" r="%d" fill="none" stroke="%s" stroke-width="6"/>', $r + 4, esc_attr($ring));
        $svg .= sprintf('<circle cx="170" cy="170" r="24" fill="%s"/><circle cx="170" cy="170" r="8" fill="%s"/>', esc_attr($ring), esc_attr($hub));

        return $svg . '</svg>';
    }

    protected function dial_html()
    {
        $slices = Wheel::slices($this->optin_campaign_id);

        return '<div class="mo-wheel-dial" data-slices="' . count($slices) . '" data-pointer="' . (int)static::$pointer . '">'
               . self::wheel_svg($slices, $this->color('wheel_ring_color', '#1f2a24'), $this->color('form_background_color', '#ffffff'), static::$pointer)
               . '<span class="mo-wheel-pointer" aria-hidden="true"></span></div>';
    }

    protected function eyebrow_html()
    {
        $text = trim((string)$this->wheel('wheel_eyebrow'));

        return '' === $text ? '' : '<div class="mo-wheel-eyebrow">' . self::text($text) . '</div>';
    }

    protected function odds_html()
    {
        if ( ! Wheel::on($this->wheel('wheel_show_odds'))) return '';

        $line = Wheel::odds_line(Wheel::slices($this->optin_campaign_id));

        return '' === $line ? '' : '<div class="mo-wheel-odds">' . self::text($line) . '</div>';
    }

    protected function terms_html()
    {
        $url = esc_url((string)$this->wheel('wheel_terms_url'));

        return '' === $url ? '' : '<a class="mo-wheel-terms" href="' . $url . '" target="_blank" rel="noopener">' . esc_html__('Prize terms', 'mailoptin') . '</a>';
    }

    protected function no_thanks_html()
    {
        $text = trim((string)$this->wheel('wheel_no_thanks'));

        return '' === $text ? '' : '[mo-close-optin class="mo-wheel-no-thanks"]' . self::text($text) . '[/mo-close-optin]';
    }

    protected function close_html()
    {
        return '[mo-close-optin class="mo-wheel-close"]&times;[/mo-close-optin]';
    }

    /** The form: core's parts, so integrations, custom fields, consent and validation work as anywhere. */
    protected function form_html()
    {
        $eyebrow   = $this->eyebrow_html();
        $odds      = $this->odds_html();
        $terms     = $this->terms_html();
        $no_thanks = $this->no_thanks_html();

        return <<<HTML
<div class="mo-wheel-form">
$eyebrow
[mo-optin-form-headline class="mo-wheel-title" tag="div"]
$odds
[mo-optin-form-description class="mo-wheel-text"]
[mo-optin-form-error]
[mo-optin-form-fields-wrapper]
[mo-optin-form-name-field]
[mo-optin-form-email-field]
[mo-optin-form-custom-fields]
[mo-mailchimp-interests]
[mo-optin-form-submit-button]
[/mo-optin-form-fields-wrapper]
[mo-optin-form-note class="mo-wheel-note"]
$terms
$no_thanks
</div>
<div class="mo-wheel-result" aria-live="polite" hidden></div>
HTML;
    }

    public function optin_form()
    {
        $layout = sanitize_html_class(static::$layout);
        $close  = $this->close_html();
        $dial   = $this->dial_html();
        $form   = $this->form_html();

        return <<<HTML
[mo-optin-form-wrapper class="mo-wheel mo-wheel-$layout"]
$close
<div class="mo-wheel-side">$dial</div>
<div class="mo-wheel-main">$form</div>
[/mo-optin-form-wrapper]
HTML;
    }

    /** CSS selector for this campaign's wrapper. */
    protected function scope()
    {
        return "html div#{$this->optin_campaign_uuid} div#{$this->optin_css_id}.mo-wheel";
    }

    /** The CSS every wheel shares; a template adds its layout. */
    protected function shared_css($c)
    {
        $side    = $this->color('wheel_side_bg', '#f3ead8');
        $card    = $this->color('form_background_color', '#fbf8f2');
        $title   = $this->color('headline_font_color', '#1f2a24');
        $text    = $this->color('description_font_color', '#3d4741');
        $note    = $this->color('note_font_color', '#6b756f');
        $btn     = $this->color('submit_button_background', '#2f6b4f');
        $btntext = $this->color('submit_button_color', '#ffffff');
        $eyebrow = $this->color('wheel_eyebrow_color', '#c8643b');
        $ring    = $this->color('wheel_ring_color', '#1f2a24');
        $font    = 'sans' === $this->wheel('wheel_heading_font') ? 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif' : 'Georgia, "Times New Roman", serif';

        return <<<CSS
$c, $c * { box-sizing: border-box; }
$c { --mo-w-side: $side; --mo-w-card: $card; --mo-w-title: $title; --mo-w-text: $text; --mo-w-note: $note; --mo-w-btn: $btn; --mo-w-btntext: $btntext; --mo-w-eyebrow: $eyebrow; --mo-w-ring: $ring;
    position: relative; margin: 0 auto; background: var(--mo-w-card); color: var(--mo-w-text); border-radius: 16px; overflow: hidden;
    font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.5; text-align: left; }
/* Core wraps the content in a form: it takes no part in the layout. */
$c form.mo-optin-form { display: contents; }
$c .mo-wheel-side { background: var(--mo-w-side); position: relative; display: flex; align-items: center; justify-content: center; }
$c .mo-wheel-dial { position: relative; width: 100%; max-width: 340px; aspect-ratio: 1 / 1; }
$c .mo-wheel-svg { display: block; width: 100%; height: 100%; overflow: visible; }
$c .mo-wheel-rotor { will-change: transform; }
$c .mo-wheel-pointer { position: absolute; left: 50%; top: -6px; transform: translateX(-50%); width: 0; height: 0;
    border-left: 14px solid transparent; border-right: 14px solid transparent; border-top: 26px solid var(--mo-w-ring); filter: drop-shadow(0 2px 2px rgba(0,0,0,.2)); }
$c .mo-wheel-main { padding: 44px 40px 32px; }
$c .mo-wheel-eyebrow { font-size: 13px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--mo-w-eyebrow); margin: 0 0 8px; }
$c .mo-wheel-title { font-family: $font !important; font-weight: 700; line-height: 1.2 !important; color: var(--mo-w-title); margin: 0 0 10px; }
$c .mo-wheel-odds { font-weight: 600; color: var(--mo-w-title); margin: 0 0 4px; }
$c .mo-wheel-text { color: var(--mo-w-text); margin: 0 0 18px; }
$c .mo-wheel-title:empty, $c .mo-wheel-text:empty { display: none; }
$c .mo-optin-error { display: none; margin: 0 0 12px; padding: 8px 12px; border-radius: 6px; background: #fcf0f1; color: #b32d2e; font-size: 14px; }
$c .mo-optin-fields-wrapper { display: flex; flex-direction: column; gap: 12px; }
$c .mo-optin-fields-wrapper input.mo-optin-field { width: 100% !important; height: 48px !important; padding: 0 14px !important; border: 1px solid rgba(127,127,127,.45) !important; border-radius: 8px !important; font-size: 15px !important; margin: 0 !important; line-height: normal !important; }
$c .mo-optin-fields-wrapper .mo-optin-form-submit-button { width: 100%; min-height: 52px; border: 0; border-radius: 8px; font-size: 16px; font-weight: 700; cursor: pointer; margin: 0; }
$c .mo-optin-fields-wrapper .mo-optin-form-submit-button:focus-visible { outline: 2px solid var(--mo-w-ring); outline-offset: 3px; }
$c .mo-wheel-note { color: var(--mo-w-note); margin: 14px 0 0; }
$c .mo-wheel-terms { display: inline-block; margin-top: 6px; font-size: 13px; color: var(--mo-w-note); }
$c .mo-wheel-no-thanks { display: block; text-align: center; min-height: 44px; line-height: 44px; margin-top: 6px; color: var(--mo-w-note); font-size: 14px; text-decoration: underline !important; cursor: pointer; }
$c .mo-wheel-close { position: absolute; top: 10px; right: 10px; z-index: 3; display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; border-radius: 8px;
    color: var(--mo-w-note); font-size: 24px; line-height: 1; cursor: pointer; text-decoration: none !important; }
$c .mo-wheel-close:hover, $c .mo-wheel-close:focus-visible { color: var(--mo-w-title); }
/* The prize, after the spin (filled in by mailoptin.js). */
$c .mo-wheel-result[hidden] { display: none; }
$c .mo-wheel-result .mo-wheel-result-title { font-family: $font; font-size: 28px; font-weight: 700; line-height: 1.2; color: var(--mo-w-title); margin: 0 0 12px; }
$c .mo-wheel-result p { margin: 0 0 14px; color: var(--mo-w-text); }
$c .mo-wheel-prize { display: flex; align-items: center; gap: 12px; background: #fff; border: 2px dashed var(--mo-w-eyebrow); border-radius: 10px; padding: 12px 12px 12px 16px; margin: 4px 0 16px; }
$c .mo-wheel-code { flex: 1; font: 700 22px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: .04em; color: #1f2a24; word-break: break-all; }
$c .mo-wheel-copy, $c .mo-wheel-action { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0 18px; border-radius: 8px; font-weight: 700; cursor: pointer; text-decoration: none !important; }
$c .mo-wheel-copy { border: 1px solid var(--mo-w-btn); background: #fff; color: var(--mo-w-btn); }
$c .mo-wheel-action { width: 100%; min-height: 52px; margin: 4px 0 14px; border: 0; background: var(--mo-w-btn); color: var(--mo-w-btntext) !important; font-size: 16px; }
$c .mo-wheel-check { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; }
$c .mo-wheel-check:before { content: "\\2713"; flex: none; width: 20px; height: 20px; border-radius: 50%; background: rgba(127,127,127,.18); color: var(--mo-w-title); display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; margin-top: 1px; }
@media (prefers-reduced-motion: reduce) { $c .mo-wheel-rotor { transition-duration: .4s !important; } }
CSS;
    }

    /**
     * On phones a pop-up wheel is a panel along the bottom of the screen: the wheel on top, smaller, and the
     * form under it.
     */
    protected function phone_sheet_css($c)
    {
        // The pop-up's container is placed by script (an inline top); on phones it's pinned to the bottom.
        $box = "html div#{$this->optin_campaign_uuid}.moModal div#{$this->optin_css_id}_container";

        return <<<CSS
@media only screen and (max-width: 575px) {
    $box { position: fixed !important; left: 0 !important; right: 0 !important; bottom: 0 !important; top: auto !important; width: 100% !important; max-width: none !important; margin: 0 !important; transform: none !important; }
    $c { width: 100% !important; max-width: none !important; max-height: 94vh; overflow-y: auto;
        border-radius: 18px 18px 0 0; display: block !important; box-shadow: 0 -10px 40px rgba(0,0,0,.25); }
    $c .mo-wheel-side { padding: 18px 20px 6px !important; background: var(--mo-w-card) !important; }
    $c .mo-wheel-dial { max-width: 230px !important; margin: 0 auto; }
    $c .mo-wheel-main { padding: 8px 20px 20px !important; text-align: center; }
    $c .mo-wheel-title { font-size: 22px !important; }
    $c .mo-wheel-text { margin-bottom: 14px; }
    $c .mo-wheel-prize { text-align: left; }
}
CSS;
    }
}
