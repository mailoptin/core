<?php

namespace MailOptin\Core\OptinForms\Countdown;

use MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_Custom_Content;
use MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_Date_Picker_Control;
use MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_Toggle_Control;
use MailOptin\Core\OptinForms\AbstractOptinForm;
use MailOptin\Core\OptinForms\Messages;
use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;

/**
 * A countdown in optin campaigns: to a date and time (free), a time after each visitor first sees the
 * campaign, or the end of each day (Premium).
 *
 * Themes opt in by listing the positions they have room for (AbstractOptinForm::countdown_positions());
 * a theme that lists none gets no Countdown section. The countdown is placed around the headline,
 * description or button shortcodes, or wherever a theme puts [mo-optin-form-countdown].
 */
class Countdown
{
    const SECTION = 'mo_countdown_section';

    const FIXED     = 'fixed';
    const EVERGREEN = 'evergreen';
    const DAILY     = 'daily';

    const ON_END_STOP    = 'stop';
    const ON_END_MESSAGE = 'message';
    const ON_END_HIDE    = 'hide';

    public function __construct()
    {
        add_filter('mo_optin_customizer_sections_ids', [$this, 'sections'], 20);
        add_action('mo_optin_after_note_customizer_section', [$this, 'section'], 10, 2);
        add_action('mo_optin_customizer_settings', [$this, 'settings'], 10, 3);
        add_action('mo_optin_after_customizer_controls', [$this, 'controls'], 10, 5);

        add_filter('mo_optin_js_config', [$this, 'js_config'], 10, 2);
        add_filter('mailoptin_show_optin_form', [$this, 'show'], 10, 2);
        add_filter('mo_optin_table_column', [$this, 'list_value'], 10, 3);
    }

    public static function premium()
    {
        return defined('MAILOPTIN_DETACH_LIBSODIUM');
    }

    // -- What a campaign's theme supports -- //

    /**
     * The positions the campaign's theme declares, in its order. Empty: no countdown for this theme.
     *
     * @return string[]
     */
    public static function positions($optin_campaign_id)
    {
        $class = Messages::template_class($optin_campaign_id);

        if ( ! $class || ! class_exists($class) || ! is_subclass_of($class, AbstractOptinForm::class)) return [];

        return array_values(array_intersect((array)$class::countdown_positions(), array_keys(self::position_labels())));
    }

    public static function position_labels()
    {
        return [
            AbstractOptinForm::COUNTDOWN_ABOVE_HEADLINE    => __('Above the headline', 'mailoptin'),
            AbstractOptinForm::COUNTDOWN_BELOW_DESCRIPTION => __('Below the description', 'mailoptin'),
            AbstractOptinForm::COUNTDOWN_ABOVE_BUTTON      => __('Above the button', 'mailoptin'),
        ];
    }

    // -- Settings -- //

    public static function defaults($optin_campaign_id = 0)
    {
        // Read on every setting lookup, so built once per campaign per request.
        static $cache = [];

        if ($optin_campaign_id && isset($cache[$optin_campaign_id])) return $cache[$optin_campaign_id];

        $positions = $optin_campaign_id ? self::positions($optin_campaign_id) : [];
        $type      = $optin_campaign_id ? OCR::get_optin_campaign_type($optin_campaign_id) : '';

        $defaults = [
            'countdown_enabled'       => false,
            'countdown_mode'          => self::FIXED,
            'countdown_end'           => '',
            'countdown_timezone'      => 'site',
            'countdown_duration'      => 30,
            'countdown_position'      => $positions[0] ?? AbstractOptinForm::COUNTDOWN_BELOW_DESCRIPTION,
            // Boxes would make a notification bar too tall.
            'countdown_style'         => 'bar' === $type ? 'compact' : 'boxes',
            'countdown_label_days'    => __('Days', 'mailoptin'),
            'countdown_label_hours'   => __('Hours', 'mailoptin'),
            'countdown_label_minutes' => __('Minutes', 'mailoptin'),
            'countdown_label_seconds' => __('Seconds', 'mailoptin'),
            'countdown_hide_days'     => true,
            'countdown_on_end'        => self::ON_END_STOP,
            'countdown_end_message'   => __('This offer has ended.', 'mailoptin'),
            'countdown_number_color'  => '',
            'countdown_box_color'     => '',
        ];

        if ($optin_campaign_id) $cache[$optin_campaign_id] = $defaults;

        return $defaults;
    }

    public static function value($optin_campaign_id, $key)
    {
        return OCR::get_customizer_value($optin_campaign_id, $key, self::defaults($optin_campaign_id)[$key] ?? '');
    }

    public static function on($value)
    {
        return Messages::on($value);
    }

    protected static function setting_args()
    {
        $text  = function ($v) { return mb_substr(sanitize_text_field((string)$v), 0, 200); };
        $label = function ($v) { return mb_substr(sanitize_text_field((string)$v), 0, 30); };
        $color = function ($v) { return sanitize_hex_color((string)$v) ?: ''; };

        return [
            'countdown_enabled'       => [self::class, 'on'],
            'countdown_mode'          => [self::class, 'sanitize_mode'],
            'countdown_end'           => function ($v) {
                $v = trim(sanitize_text_field((string)$v));

                return '' === $v || false === strtotime($v) ? '' : $v;
            },
            'countdown_timezone'      => [self::class, 'sanitize_timezone'],
            'countdown_duration'      => function ($v) { return max(1, min(60 * 24 * 30, absint($v))); },
            'countdown_position'      => function ($v) { return array_key_exists($v, self::position_labels()) ? $v : AbstractOptinForm::COUNTDOWN_BELOW_DESCRIPTION; },
            'countdown_style'         => function ($v) { return in_array($v, ['boxes', 'plain', 'compact'], true) ? $v : 'boxes'; },
            'countdown_label_days'    => $label,
            'countdown_label_hours'   => $label,
            'countdown_label_minutes' => $label,
            'countdown_label_seconds' => $label,
            'countdown_hide_days'     => [self::class, 'on'],
            'countdown_on_end'        => function ($v) { return in_array($v, [self::ON_END_STOP, self::ON_END_MESSAGE, self::ON_END_HIDE], true) ? $v : self::ON_END_STOP; },
            'countdown_end_message'   => $text,
            'countdown_number_color'  => $color,
            'countdown_box_color'     => $color,
        ];
    }

    /** Free sites count down to a date; the other two are Premium's. */
    public static function sanitize_mode($value)
    {
        $value = (string)$value;

        if ( ! in_array($value, [self::FIXED, self::EVERGREEN, self::DAILY], true)) return self::FIXED;

        return self::FIXED === $value || self::premium() ? $value : self::FIXED;
    }

    /** "site", "visitor", or a fixed offset in seconds copied from the campaign's schedule. */
    public static function sanitize_timezone($value)
    {
        if (in_array($value, ['site', 'visitor', 'visitors_local_time'], true)) return 'visitors_local_time' === $value ? 'visitor' : $value;

        return is_numeric($value) && abs((int)$value) <= 14 * HOUR_IN_SECONDS ? (string)(int)$value : 'site';
    }

    // -- When it ends -- //

    /**
     * Whether a countdown shows for this campaign now: switched on, a mode this site has, and the theme
     * has room for it.
     */
    public static function active($optin_campaign_id)
    {
        if ( ! self::on(self::value($optin_campaign_id, 'countdown_enabled'))) return false;

        if ( ! self::positions($optin_campaign_id)) return false;

        $mode = (string)self::value($optin_campaign_id, 'countdown_mode');

        // Premium's modes, saved before Premium stopped: no countdown rather than a different one.
        if (self::FIXED !== $mode && ! self::premium()) return false;

        return self::FIXED !== $mode || '' !== (string)self::value($optin_campaign_id, 'countdown_end');
    }

    /**
     * When a date-and-time countdown ends, as a Unix timestamp. Null for the other modes, and for one
     * set to the visitor's own time zone, which only the browser knows.
     *
     * @return int|null
     */
    public static function end_timestamp($optin_campaign_id)
    {
        if (self::FIXED !== self::value($optin_campaign_id, 'countdown_mode')) return null;

        $end = (string)self::value($optin_campaign_id, 'countdown_end');
        if ('' === $end) return null;

        $timezone = self::sanitize_timezone(self::value($optin_campaign_id, 'countdown_timezone'));
        if ('visitor' === $timezone) return null;

        try {
            if ('site' === $timezone) {
                $date = new \DateTime($end, wp_timezone());
            } else {
                $date = new \DateTime($end, new \DateTimeZone('UTC'));
                $date->modify((-(int)$timezone) . ' seconds');
            }
        } catch (\Exception $e) {
            return null;
        }

        return $date->getTimestamp();
    }

    /** A date-and-time countdown that has reached zero (as far as the server can tell). */
    public static function ended($optin_campaign_id, $now = null)
    {
        $end = self::end_timestamp($optin_campaign_id);

        return null !== $end && (null === $now ? time() : $now) >= $end;
    }

    /** Ended, and set to stop showing the campaign: it isn't output at all. */
    public function show($show, $optin_campaign_id)
    {
        if ( ! $show || ! self::active($optin_campaign_id)) return $show;

        return ! (self::ON_END_STOP === self::value($optin_campaign_id, 'countdown_on_end') && self::ended($optin_campaign_id));
    }

    // -- Output -- //

    /**
     * Put the countdown before or after a shortcode's output when the campaign shows it at that
     * position.
     *
     * @param string $position AbstractOptinForm::COUNTDOWN_*
     * @param string $where    before or after
     * @param string $button   for the button position: submit or cta. Templates render both and hide
     *                         one, so the countdown goes with the one the campaign shows.
     */
    public static function place($theme, $position, $html, $where = 'before', $button = '')
    {
        if ( ! $theme instanceof AbstractOptinForm || ! $theme->optin_campaign_id) return $html;

        $id = $theme->optin_campaign_id;

        if ( ! self::active($id) || self::resolved_position($id) !== $position) return $html;

        if ('' !== $button) {
            // Through the theme, so a theme's own default for it counts.
            $cta = self::on($theme->get_customizer_value('display_only_button'));
            if (('cta' === $button) !== $cta) return $html;
        }

        $countdown = self::html($theme);

        return 'after' === $where ? $html . $countdown : $countdown . $html;
    }

    /**
     * The saved position if the theme has it, else the theme's first: never nowhere.
     */
    public static function resolved_position($optin_campaign_id)
    {
        $positions = self::positions($optin_campaign_id);
        $saved     = (string)self::value($optin_campaign_id, 'countdown_position');

        return in_array($saved, $positions, true) ? $saved : ($positions[0] ?? '');
    }

    /** [mo-optin-form-countdown], for a theme or a custom layout that places it itself. */
    public static function shortcode($theme)
    {
        if ( ! $theme instanceof AbstractOptinForm || ! self::active($theme->optin_campaign_id)) return '';

        return self::html($theme);
    }

    /**
     * The countdown's markup. The server writes the time left so it reads right before the script runs
     * (and without it); mailoptin.js keeps it ticking.
     */
    public static function html($theme)
    {
        $id   = $theme->optin_campaign_id;
        $uuid = $theme->optin_campaign_uuid;

        $style   = (string)self::value($id, 'countdown_style');
        $on_end  = (string)self::value($id, 'countdown_on_end');
        $mode    = (string)self::value($id, 'countdown_mode');
        $message = (string)self::value($id, 'countdown_end_message');

        if (self::ended($id)) {
            if (self::ON_END_MESSAGE === $on_end && '' !== $message) {
                return sprintf('<div class="mo-countdown mo-countdown-ended" data-mo-countdown="%s"><p class="mo-countdown-message">%s</p></div>', esc_attr($uuid), esc_html($message)) . self::css($theme);
            }

            // Visitors don't see the campaign any more; tell the person editing it why.
            if (self::ON_END_STOP === $on_end && is_customize_preview()) {
                return sprintf('<div class="mo-countdown mo-countdown-ended" data-mo-countdown="%s"><p class="mo-countdown-message">%s</p></div>', esc_attr($uuid), esc_html__('The countdown has ended, so visitors no longer see this campaign.', 'mailoptin')) . self::css($theme);
            }

            return '';
        }

        $left = self::seconds_left($id);

        $units = self::units($left, self::on(self::value($id, 'countdown_hide_days')));

        $labels = self::labels($id);

        $parts = '';
        foreach ($units as $unit => $number) {
            if ('compact' === $style) continue;
            $parts .= sprintf(
                '<span class="mo-countdown-unit" data-unit="%1$s"%4$s><span class="mo-countdown-number">%2$s</span><span class="mo-countdown-label">%3$s</span></span>',
                esc_attr($unit),
                esc_html(str_pad((string)$number, 2, '0', STR_PAD_LEFT)),
                esc_html($labels[$unit]),
                null === $number ? ' hidden' : ''
            );
        }

        if ('compact' === $style) {
            $parts = '<span class="mo-countdown-compact">' . esc_html(self::compact_text($units)) . '</span>';
        }

        return sprintf(
            '<div class="mo-countdown mo-countdown-%1$s" data-mo-countdown="%2$s" data-mode="%3$s" role="timer" aria-live="off"><span class="mo-countdown-sr">%4$s</span><div class="mo-countdown-units" aria-hidden="true">%5$s</div></div>',
            esc_attr($style),
            esc_attr($uuid),
            esc_attr($mode),
            esc_html(self::sentence($left)),
            $parts
        ) . self::css($theme);
    }

    /** Seconds left as the server sees it: exact for a date, the full length for the others. */
    public static function seconds_left($optin_campaign_id, $now = null)
    {
        $now  = null === $now ? time() : $now;
        $mode = (string)self::value($optin_campaign_id, 'countdown_mode');

        if (self::EVERGREEN === $mode) return absint(self::value($optin_campaign_id, 'countdown_duration')) * MINUTE_IN_SECONDS;

        if (self::DAILY === $mode) {
            $midnight = (new \DateTime('@' . $now))->setTimezone(wp_timezone())->modify('tomorrow');

            return max(0, $midnight->getTimestamp() - $now);
        }

        $end = self::end_timestamp($optin_campaign_id);

        // In the visitor's own time zone: the browser works it out; until then, roughly.
        if (null === $end) {
            try {
                $end = (new \DateTime((string)self::value($optin_campaign_id, 'countdown_end'), wp_timezone()))->getTimestamp();
            } catch (\Exception $e) {
                return 0;
            }
        }

        return max(0, $end - $now);
    }

    /**
     * Days, hours, minutes and seconds. Days are null (hidden) when none are left and hide_days is on.
     */
    public static function units($seconds, $hide_days = true)
    {
        $seconds = max(0, (int)$seconds);

        $days = intdiv($seconds, DAY_IN_SECONDS);

        return [
            'days'    => $hide_days && 0 === $days ? null : $days,
            'hours'   => intdiv($seconds % DAY_IN_SECONDS, HOUR_IN_SECONDS),
            'minutes' => intdiv($seconds % HOUR_IN_SECONDS, MINUTE_IN_SECONDS),
            'seconds' => $seconds % MINUTE_IN_SECONDS,
        ];
    }

    /** "2d 05:14:09", or "05:14:09" without days. */
    public static function compact_text(array $units)
    {
        $time = sprintf('%02d:%02d:%02d', $units['hours'], $units['minutes'], $units['seconds']);

        return null === $units['days'] ? $time : $units['days'] . 'd ' . $time;
    }

    /** What a screen reader hears, to the minute. */
    public static function sentence($seconds)
    {
        $u     = self::units($seconds, false);
        $parts = [];

        if ($u['days']) $parts[] = sprintf(_n('%s day', '%s days', $u['days'], 'mailoptin'), number_format_i18n($u['days']));
        if ($u['hours']) $parts[] = sprintf(_n('%s hour', '%s hours', $u['hours'], 'mailoptin'), number_format_i18n($u['hours']));
        if ($u['minutes']) $parts[] = sprintf(_n('%s minute', '%s minutes', $u['minutes'], 'mailoptin'), number_format_i18n($u['minutes']));

        return $parts ? sprintf(__('Ends in %s', 'mailoptin'), implode(', ', $parts)) : __('Ends in under a minute', 'mailoptin');
    }

    public static function labels($optin_campaign_id)
    {
        return [
            'days'    => (string)self::value($optin_campaign_id, 'countdown_label_days'),
            'hours'   => (string)self::value($optin_campaign_id, 'countdown_label_hours'),
            'minutes' => (string)self::value($optin_campaign_id, 'countdown_label_minutes'),
            'seconds' => (string)self::value($optin_campaign_id, 'countdown_label_seconds'),
        ];
    }

    /**
     * The three styles, scoped to the campaign. Colours start from the theme's own text colour, so the
     * countdown fits dark and light themes; the two colour settings override them.
     */
    /** A hex or rgb()/rgba() colour, or '' for anything else, so it is safe inside a style block. */
    public static function css_color($value)
    {
        $value = trim((string)$value);

        if (preg_match('/^#[0-9a-f]{3,8}$/i', $value) || preg_match('/^rgba?\(\s*[\d.\s,%]+\)$/i', $value)) return $value;

        return '';
    }

    protected static function css($theme)
    {
        $id     = $theme->optin_campaign_id;
        // The id twice: many themes style everything in the form with two ids (html div#uuid div#form *),
        // which would otherwise win over the countdown's sizes.
        $scope  = '#' . $theme->optin_campaign_uuid . '#' . $theme->optin_campaign_uuid . ' .mo-countdown';
        $number = self::css_color(self::value($id, 'countdown_number_color'));
        $box    = self::css_color(self::value($id, 'countdown_box_color'));

        $css = "$scope{margin:16px 0;font-variant-numeric:tabular-nums;line-height:1.2;text-align:center}"
               . "$scope .mo-countdown-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}"
               . "$scope .mo-countdown-units{display:flex;gap:8px;justify-content:center}"
               . "$scope .mo-countdown-units .mo-countdown-unit[hidden]{display:none!important}"
               . "$scope.mo-countdown-boxes .mo-countdown-unit{flex:1 1 0;max-width:96px;min-width:0;display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 4px 8px;border-radius:6px;background:rgba(127,127,127,.14)}"
               . "$scope.mo-countdown-boxes .mo-countdown-number{font-size:28px;font-weight:700}"
               . "$scope.mo-countdown-boxes .mo-countdown-label{font-size:12px}"
               . "$scope.mo-countdown-plain .mo-countdown-units{gap:12px}"
               . "$scope.mo-countdown-plain .mo-countdown-unit{display:inline-flex;align-items:baseline;gap:4px}"
               . "$scope.mo-countdown-plain .mo-countdown-number{font-size:20px;font-weight:700}"
               . "$scope.mo-countdown-plain .mo-countdown-label{font-size:14px}"
               . "$scope.mo-countdown-compact{display:inline-block;margin:0 8px;vertical-align:middle}"
               . "$scope.mo-countdown-compact .mo-countdown-compact{display:inline-block;font-size:16px;font-weight:700;padding:4px 10px;border-radius:4px;background:rgba(127,127,127,.2)}"
               . "$scope .mo-countdown-message{margin:0;font-size:16px}"
               . "@media (max-width:480px){{$scope}.mo-countdown-boxes .mo-countdown-units{gap:6px}{$scope}.mo-countdown-boxes .mo-countdown-number{font-size:22px}}"
               // Narrow forms (a sidebar, a small slide-in): smaller labels so "Minutes" and "Seconds" don't touch.
               . "$scope.mo-countdown-boxes{container-type:inline-size;width:100%;box-sizing:border-box}"
               . "@container (max-width:250px){{$scope}.mo-countdown-boxes .mo-countdown-units{gap:4px}{$scope}.mo-countdown-boxes .mo-countdown-unit{padding:8px 2px 6px}{$scope}.mo-countdown-boxes .mo-countdown-number{font-size:20px}{$scope}.mo-countdown-boxes .mo-countdown-label{font-size:10px}}"
               // Very narrow: two by two, so each label has room.
               . "@container (max-width:180px){{$scope}.mo-countdown-boxes .mo-countdown-units{flex-wrap:wrap}{$scope}.mo-countdown-boxes .mo-countdown-unit{flex:1 1 calc(50% - 4px);max-width:none}}";

        // Themes colour their text on its elements, not on the form, so the countdown takes the colour of
        // what it sits beside. Near the headline: the headline's (description colours are often muted, or
        // meant for a column phones don't show). Above the button, in the form's body, where a headline
        // colour can be white on white: the note's, which sits under the button.
        $color = '';
        if (AbstractOptinForm::COUNTDOWN_ABOVE_BUTTON === self::resolved_position($id)) {
            $color = self::css_color($theme->get_customizer_value('note_font_color'));
        }
        if ('' === $color) $color = self::css_color($theme->get_customizer_value('headline_font_color'));
        if ('' !== $color) $css .= "$scope{color:$color}";

        if ('' !== $number) $css .= "$scope .mo-countdown-number,$scope .mo-countdown-compact{color:$number}";
        if ('' !== $box) $css .= "$scope.mo-countdown-boxes .mo-countdown-unit,$scope.mo-countdown-compact .mo-countdown-compact{background:$box}";

        return '<style>' . $css . '</style>';
    }

    /**
     * What mailoptin.js needs to keep it ticking and to act at zero.
     */
    public function js_config($data, $form)
    {
        $id = isset($form->optin_campaign_id) ? $form->optin_campaign_id : 0;

        if ( ! $id || ! self::active($id)) return $data;

        $mode     = (string)self::value($id, 'countdown_mode');
        $timezone = self::sanitize_timezone(self::value($id, 'countdown_timezone'));

        $countdown = [
            'mode'      => $mode,
            'on_end'    => (string)self::value($id, 'countdown_on_end'),
            'message'   => (string)self::value($id, 'countdown_end_message'),
            'hide_days' => self::on(self::value($id, 'countdown_hide_days')),
            'labels'    => self::labels($id),
            'texts'     => [
                'ends_in' => __('Ends in %s', 'mailoptin'),
                'soon'    => __('Ends in under a minute', 'mailoptin'),
                'day'     => [__('%s day', 'mailoptin'), __('%s days', 'mailoptin')],
                'hour'    => [__('%s hour', 'mailoptin'), __('%s hours', 'mailoptin')],
                'minute'  => [__('%s minute', 'mailoptin'), __('%s minutes', 'mailoptin')],
            ],
        ];

        if (self::FIXED === $mode) {
            if ('visitor' === $timezone) {
                // Year, month, day, hour, minute: the browser builds the date on the visitor's clock.
                try {
                    $local = new \DateTime((string)self::value($id, 'countdown_end'), new \DateTimeZone('UTC'));

                    $countdown['end_local'] = array_map('intval', explode(',', $local->format('Y,n,j,G,i')));
                } catch (\Exception $e) {
                    $countdown['end_local'] = null;
                }
            } else {
                $countdown['end'] = self::end_timestamp($id);
            }
        } elseif (self::EVERGREEN === $mode) {
            $countdown['duration'] = absint(self::value($id, 'countdown_duration')) * MINUTE_IN_SECONDS;
        } else {
            // The site's next few midnights, worked out here so a clock change lands on the right one.
            $midnight          = new \DateTime('tomorrow', wp_timezone());
            $countdown['ends'] = [];
            for ($i = 0; $i < 3; $i++) {
                $countdown['ends'][] = $midnight->getTimestamp();
                $midnight->modify('+1 day');
            }
        }

        $data['countdown'] = $countdown;

        return $data;
    }

    /** The campaign list says when a countdown has stopped a campaign showing. */
    public function list_value($value, $item, $column)
    {
        if ('uuid' !== $column) return $value;

        $id = absint($item['id'] ?? 0);

        if ($id && self::active($id) && self::ON_END_STOP === self::value($id, 'countdown_on_end') && self::ended($id)) {
            $value .= '<br/><span class="mo-test-mode-title">' . esc_html__('Countdown ended', 'mailoptin') . '</span>';
        }

        return $value;
    }

    // -- Customizer -- //

    protected static function customizer_campaign_id()
    {
        $id = absint($_REQUEST['mailoptin_optin_campaign_id'] ?? 0);

        return $id && self::positions($id) ? $id : 0;
    }

    public function sections($sections)
    {
        if ( ! self::customizer_campaign_id()) return $sections;

        $sections[] = self::SECTION;

        return $sections;
    }

    public function section($wp_customize, $customizer)
    {
        if ( ! isset($customizer->optin_campaign_id) || ! self::positions($customizer->optin_campaign_id)) return;

        $wp_customize->add_section(self::SECTION, [
            'title'    => __('Countdown', 'mailoptin'),
            'priority' => 22,
        ]);
    }

    public function settings($wp_customize, $option_prefix, $customizer)
    {
        $id = isset($customizer->optin_campaign_id) ? $customizer->optin_campaign_id : 0;
        if ( ! $id || ! self::positions($id)) return;

        $defaults = self::defaults($id);

        foreach (self::setting_args() as $key => $sanitize) {
            $wp_customize->add_setting($option_prefix . '[' . $key . ']', [
                'default'           => $defaults[$key] ?? '',
                'type'              => 'option',
                'transport'         => 'refresh',
                'sanitize_callback' => $sanitize,
            ]);
        }
    }

    public function controls($instance, $wp_customize, $option_prefix, $customizer, $optin_class_instance = null)
    {
        $campaign_id = isset($customizer->optin_campaign_id) ? $customizer->optin_campaign_id : 0;
        $positions   = $campaign_id ? self::positions($campaign_id) : [];
        if ( ! $positions) return;

        $id       = function ($key) use ($option_prefix) { return $option_prefix . '[' . $key . ']'; };
        $priority = 0;
        $value    = function ($key) use ($wp_customize, $id) {
            $setting = $wp_customize->get_setting($id($key));

            return $setting ? $setting->value() : '';
        };
        $when     = function ($key, $expected) use ($value) {
            return function () use ($value, $key, $expected) {
                return in_array((string)$value($key), (array)$expected, true);
            };
        };
        $add      = function ($key, $args) use ($wp_customize, $id, &$priority) {
            $wp_customize->add_control($id('countdown_control_' . $key), $args + ['section' => self::SECTION, 'settings' => $id($key), 'priority' => $priority += 5]);
        };

        $wp_customize->add_control(new WP_Customize_Toggle_Control($wp_customize, $id('countdown_control_enabled'), [
            'label'    => __('Show a countdown', 'mailoptin'),
            'section'  => self::SECTION,
            'settings' => $id('countdown_enabled'),
            'type'     => 'light',
            'priority' => $priority += 5,
        ]));

        $premium = self::premium() ? '' : ' ' . __('(Premium)', 'mailoptin');

        $add('countdown_mode', [
            'type'        => 'radio',
            'label'       => __('Count down to', 'mailoptin'),
            'choices'     => [
                self::FIXED     => __('A date and time: everyone sees the same deadline', 'mailoptin'),
                self::EVERGREEN => __('A time after each visitor first sees it', 'mailoptin') . $premium,
                self::DAILY     => __('The end of each day, in the site\'s time zone', 'mailoptin') . $premium,
            ],
            'description' => self::premium() ? '' : __('The other two need MailOptin Premium. Without it, the countdown runs to a date and time.', 'mailoptin'),
        ]);

        $wp_customize->add_control(new WP_Customize_Date_Picker_Control($wp_customize, $id('countdown_control_end'), [
            'label'           => __('Ends', 'mailoptin'),
            'section'         => self::SECTION,
            'settings'        => $id('countdown_end'),
            'priority'        => $priority += 5,
            'active_callback' => $when('countdown_mode', self::FIXED),
        ]));

        $timezone = self::sanitize_timezone($value('countdown_timezone'));
        $zones    = [
            'site'    => sprintf(__('Site time zone (%s)', 'mailoptin'), wp_timezone_string()),
            'visitor' => __('Each visitor\'s own time zone', 'mailoptin'),
        ];
        // The saved offset, and the schedule's (so "Use that date" below always has its option).
        $schedule_timezone = self::sanitize_timezone(OCR::get_customizer_value($campaign_id, 'schedule_timezone'));
        foreach ([$timezone, $schedule_timezone] as $offset) {
            if (is_numeric($offset)) {
                $zones[$offset] = sprintf(__('UTC%s (from the schedule)', 'mailoptin'), ($offset >= 0 ? '+' : '-') . gmdate('H:i', abs((int)$offset)));
            }
        }

        $add('countdown_timezone', [
            'type'            => 'select',
            'label'           => __('Time zone', 'mailoptin'),
            'choices'         => $zones,
            'active_callback' => $when('countdown_mode', self::FIXED),
        ]);

        $schedule_end = OCR::get_customizer_value($campaign_id, 'schedule_end');
        if ($schedule_end && self::on(OCR::get_customizer_value($campaign_id, 'schedule_status'))) {
            $wp_customize->add_control(new WP_Customize_Custom_Content($wp_customize, $id('countdown_control_schedule'), [
                'section'         => self::SECTION,
                'settings'        => [],
                'priority'        => $priority += 5,
                'active_callback' => $when('countdown_mode', self::FIXED),
                'content'         => self::schedule_link_html($option_prefix, (string)$schedule_end, OCR::get_customizer_value($campaign_id, 'schedule_timezone')),
            ]));
        }

        $add('countdown_duration', [
            'type'            => 'number',
            'label'           => __('Minutes after they first see it', 'mailoptin'),
            'description'     => __('Each visitor\'s browser remembers their own deadline.', 'mailoptin'),
            'input_attrs'     => ['min' => 1, 'max' => 60 * 24 * 30],
            'active_callback' => $when('countdown_mode', self::EVERGREEN),
        ]);

        $labels = array_intersect_key(self::position_labels(), array_flip($positions));

        if (count($labels) > 1) {
            $add('countdown_position', ['type' => 'select', 'label' => __('Position', 'mailoptin'), 'choices' => $labels]);
        } else {
            $wp_customize->add_control(new WP_Customize_Custom_Content($wp_customize, $id('countdown_control_position'), [
                'section'  => self::SECTION,
                'settings' => [],
                'priority' => $priority += 5,
                'content'  => '<span class="customize-control-title">' . esc_html__('Position', 'mailoptin') . '</span>'
                              . '<p style="margin:0 0 4px;padding:8px 10px;background:#fff;border:1px solid #dcdcde;border-radius:4px">' . esc_html(reset($labels)) . '</p>'
                              . '<span class="description customize-control-description">' . esc_html__('The only place this theme has room for a countdown.', 'mailoptin') . '</span>',
            ]));
        }

        $add('countdown_style', [
            'type'        => 'radio',
            'label'       => __('Style', 'mailoptin'),
            'choices'     => [
                'boxes'   => __('Boxes', 'mailoptin'),
                'plain'   => __('Plain: 2 days 5 hours 14 minutes', 'mailoptin'),
                'compact' => __('Compact: 2d 05:14:09', 'mailoptin'),
            ],
            'description' => __('Compact suits notification bars.', 'mailoptin'),
        ]);

        foreach (['days' => __('Days label', 'mailoptin'), 'hours' => __('Hours label', 'mailoptin'), 'minutes' => __('Minutes label', 'mailoptin'), 'seconds' => __('Seconds label', 'mailoptin')] as $unit => $label) {
            $add('countdown_label_' . $unit, ['type' => 'text', 'label' => $label, 'active_callback' => function () use ($value) { return 'compact' !== $value('countdown_style'); }]);
        }

        $wp_customize->add_control(new WP_Customize_Toggle_Control($wp_customize, $id('countdown_control_hide_days'), [
            'label'    => __('Hide days once none are left', 'mailoptin'),
            'section'  => self::SECTION,
            'settings' => $id('countdown_hide_days'),
            'type'     => 'light',
            'priority' => $priority += 5,
        ]));

        $add('countdown_on_end', [
            'type'    => 'radio',
            'label'   => __('When it reaches zero', 'mailoptin'),
            'choices' => [
                self::ON_END_STOP    => __('Stop showing this campaign', 'mailoptin'),
                self::ON_END_MESSAGE => __('Keep showing it, with a message instead of the countdown', 'mailoptin'),
                self::ON_END_HIDE    => __('Keep showing it, without the countdown', 'mailoptin'),
            ],
        ]);

        $add('countdown_end_message', ['type' => 'text', 'label' => __('Message', 'mailoptin'), 'active_callback' => $when('countdown_on_end', self::ON_END_MESSAGE)]);

        foreach (['countdown_number_color' => __('Numbers colour', 'mailoptin'), 'countdown_box_color' => __('Boxes colour', 'mailoptin')] as $key => $label) {
            $wp_customize->add_control(new \WP_Customize_Color_Control($wp_customize, $id('countdown_control_' . $key), [
                'label'       => $label,
                'description' => 'countdown_box_color' === $key ? __('Empty: they start from the campaign\'s own colours.', 'mailoptin') : '',
                'section'     => self::SECTION,
                'settings'    => $id($key),
                'priority'    => $priority += 5,
            ]));
        }
    }

    /** "Use that date": copies the schedule's end and time zone into the countdown. */
    protected static function schedule_link_html($option_prefix, $schedule_end, $schedule_timezone)
    {
        $timezone = 'visitors_local_time' === $schedule_timezone ? 'visitor' : (string)(int)$schedule_timezone;

        return '<p class="description customize-control-description" style="margin:0">'
               . esc_html(sprintf(__('This campaign has a schedule ending %s.', 'mailoptin'), $schedule_end))
               . ' <button type="button" class="button-link" id="mo-countdown-use-schedule">' . esc_html__('Use that date', 'mailoptin') . '</button></p>'
               . '<script>(function(){var b=document.getElementById("mo-countdown-use-schedule");if(!b)return;b.addEventListener("click",function(){'
               . 'wp.customize(' . wp_json_encode($option_prefix . '[countdown_end]') . ').set(' . wp_json_encode($schedule_end) . ');'
               . 'wp.customize(' . wp_json_encode($option_prefix . '[countdown_timezone]') . ').set(' . wp_json_encode($timezone) . ');'
               // The picker keeps its own date: set it through the picker so the two agree.
               . 'var i=document.getElementById(' . wp_json_encode('_' . $option_prefix . '[countdown_control_end]') . '),p=i&&window.jQuery?jQuery(i).data("DateTimePicker"):null;'
               . 'if(p){p.date(' . wp_json_encode($schedule_end) . ');}else if(i){i.value=' . wp_json_encode($schedule_end) . ';}'
               . '});})();</script>';
    }

    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) $instance = new self();

        return $instance;
    }
}
