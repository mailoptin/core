<?php

namespace MailOptin\Core\OptinForms;

use MailOptin\Core\PluginSettings\Settings;

class Recaptcha
{
    public function __construct()
    {
        add_filter('mailoptin_settings_page', [$this, 'settings_page'], 2222);
    }

    public function enqueue_script()
    {
        $src = 'https://www.google.com/recaptcha/api.js?onload=moFormRecaptchaLoadCallback&render=explicit';
        wp_enqueue_script( 'mo-recaptcha-script', $src, ['mailoptin'], MAILOPTIN_VERSION_NUMBER, true );
    }

    public function settings_page($settings)
    {
        $value = Settings::instance()->recaptcha_type();

        $html = sprintf(
            '<label><input type="radio" name="mailoptin_settings[recaptcha_type]" value="v2" %s>%s</label>&nbsp;&nbsp;',
            checked($value, 'v2', false),
            __('reCAPTCHA v2', 'mailoptin')
        );

        $html .= sprintf(
            '<label><input type="radio" name="mailoptin_settings[recaptcha_type]" value="v3" %s>%s</label>',
            checked($value, 'v3', false),
            __('reCAPTCHA v3', 'mailoptin')
        );

        $settings['recaptcha_settings'] = [
            'tab_title' => __('reCAPTCHA', 'mailoptin'),
            [
                'section_title'         => __('reCAPTCHA Settings', 'mailoptin'),
                'recaptcha_type'        => [
                    'label' => __('Type', 'mailoptin'),
                    'type'  => 'custom_field_block',
                    'data'  => $html
                ],
                'recaptcha_site_key'    => [
                    'type'  => 'text',
                    'label' => __('Site Key', 'mailoptin')
                ],
                'recaptcha_site_secret' => [
                    'type'  => 'text',
                    'label' => __('Site Secret', 'mailoptin')
                ]
            ]
        ];

        return $settings;
    }

    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}