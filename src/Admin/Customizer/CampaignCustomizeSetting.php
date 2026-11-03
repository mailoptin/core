<?php

namespace MailOptin\Core\Admin\Customizer;

use MailOptin\Core\Repositories\CampaignSettingsRepository as Settings;

/**
 * A Customizer setting of an optin or email campaign, kept in the campaign settings table instead of
 * the mo_optin_campaign / mo_email_campaigns option rows.
 *
 * Setting IDs don't change (mo_optin_campaign[12][headline]), so controls, live preview scripts and
 * saved changesets keep working: swap() replaces each registered setting with one of these after the
 * Customizer is set up, keeping its default and transport. Its sanitize and validate callbacks stay
 * hooked by setting ID, as WordPress registered them.
 */
class CampaignCustomizeSetting extends \WP_Customize_Setting
{
    public $type = 'mo_campaign_setting';

    /** @var string optin or email */
    public $campaign_type;

    /** @var int */
    public $campaign_id;

    /** @var string[] the setting's keys within the campaign: usually one, such as ['headline'] */
    public $setting_keys = [];

    /**
     * @param \WP_Customize_Manager $manager
     * @param string $id
     * @param array $args
     */
    public function __construct($manager, $id, $args = [])
    {
        parent::__construct($manager, $id, $args);

        list($campaign_type, $campaign_id, $keys) = self::parse_id($id);

        $this->campaign_type = $campaign_type;
        $this->campaign_id   = $campaign_id;
        $this->setting_keys  = $keys;
    }

    /**
     * @return array [campaign type, campaign id, keys] or [null, 0, []] for another setting
     */
    public static function parse_id($id)
    {
        if ( ! preg_match('/^([a-z_]+)\[(\d+)\]((?:\[[^\]]*\])+)$/', (string)$id, $m)) return [null, 0, []];

        $type = Settings::type_for_option($m[1]);

        if ( ! $type) return [null, 0, []];

        preg_match_all('/\[([^\]]*)\]/', $m[3], $keys);

        return [$type, (int)$m[2], $keys[1]];
    }

    /**
     * Register a campaign setting: in the table once the site has moved, as a WordPress option before.
     * Creating it as an option would make WordPress load every campaign's settings at once.
     *
     * @param \WP_Customize_Manager $wp_customize
     * @param string $id such as mo_optin_campaign[12][headline]
     * @param array $args add_setting() arguments
     */
    public static function add($wp_customize, $id, $args = [])
    {
        list($type) = self::parse_id($id);

        if ( ! $type || ! Settings::is_ready()) {
            return 'email' === $type ? $wp_customize->add_setting(new \MailOptin\Core\Admin\Customizer\EmailCampaign\EC_Customizer_Setting($wp_customize, $id, $args)) : $wp_customize->add_setting($id, $args);
        }

        unset($args['type'], $args['autoload']);

        return $wp_customize->add_setting(new self($wp_customize, $id, $args));
    }

    /**
     * Replace the campaign settings registered as WordPress options with this class. Runs again safely.
     *
     * @param \WP_Customize_Manager $wp_customize
     */
    public static function swap($wp_customize)
    {
        if ( ! Settings::is_ready()) return;

        foreach ($wp_customize->settings() as $id => $setting) {
            if ($setting instanceof self || 'option' !== $setting->type) continue;

            list($type) = self::parse_id($id);

            if ( ! $type) continue;

            $wp_customize->remove_setting($id);

            // The sanitize and validate callbacks are already hooked to this setting ID.
            $wp_customize->add_setting(new self($wp_customize, $id, [
                'default'        => $setting->default,
                'transport'      => $setting->transport,
                'capability'     => $setting->capability,
                'theme_supports' => $setting->theme_supports,
                'dirty'          => $setting->dirty,
            ]));
        }
    }

    /**
     * Email campaigns' numeric fields, as EC_Customizer_Setting checks them.
     */
    public function validate($value)
    {
        $validity = parent::validate($value);

        if (is_wp_error($validity) || Settings::TYPE_EMAIL !== $this->campaign_type) return $validity;

        $check = \MailOptin\Core\Admin\Customizer\EmailCampaign\EC_Customizer_Setting::check_value($this->id, $value);

        return is_wp_error($check) ? $check : $validity;
    }

    public function value()
    {
        if ($this->is_previewed) {
            $posted = $this->post_value(null);
            if (null !== $posted) return $posted;
        }

        $settings = Settings::get_saved($this->campaign_type, $this->campaign_id);

        $settings = apply_filters('mo_campaign_customizer_settings', $settings, $this->campaign_type, $this->campaign_id);

        return $this->multidimensional_get($settings, $this->setting_keys, $this->default);
    }

    /**
     * Show the unsaved value wherever the campaign's settings are read while previewing.
     */
    public function preview()
    {
        if ( ! isset($this->_previewed_blog_id)) {
            $this->_previewed_blog_id = get_current_blog_id();
        }

        if ($this->is_previewed) return true;

        $posted = $this->post_value(null);

        // No value yet: preview it once one is set, as WordPress does for its own settings.
        if (null === $posted) {
            if ( ! has_action("customize_post_value_set_{$this->id}", [$this, 'preview'])) {
                add_action("customize_post_value_set_{$this->id}", [$this, 'preview']);
            }

            return false;
        }

        $top = $this->setting_keys[0] ?? null;

        if (null === $top) return false;

        if (count($this->setting_keys) > 1) {
            $current = Settings::get($this->campaign_type, $this->campaign_id)[$top] ?? [];
            $posted  = $this->multidimensional_replace(is_array($current) ? $current : [], array_slice($this->setting_keys, 1), $posted);
        }

        Settings::set_preview($this->campaign_type, $this->campaign_id, $top, $posted);

        $this->is_previewed = true;

        return true;
    }

    /**
     * Save straight to the campaign's row: a Customizer save, or a scheduled changeset publishing.
     */
    protected function update($value)
    {
        $top = $this->setting_keys[0] ?? null;

        if (null === $top) return false;

        if (count($this->setting_keys) > 1) {
            $current = Settings::get_saved($this->campaign_type, $this->campaign_id)[$top] ?? [];
            $value   = $this->multidimensional_replace(is_array($current) ? $current : [], array_slice($this->setting_keys, 1), $value);
        }

        return Settings::update($this->campaign_type, $this->campaign_id, [$top => $value]);
    }
}
