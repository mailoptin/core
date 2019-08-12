<?php

namespace MailOptin\Core\OptinForms;

use MailOptin\Core\PluginSettings\Settings;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use WP_Error;

class Recaptcha
{
    public function __construct()
    {
        add_filter('mailoptin_settings_page', [$this, 'settings_page'], 2222);

        add_filter('mo_optin_form_custom_field_output', [$this, 'render_field'], 10, 4);

        add_filter('mo_subscription_form_error', [$this, 'validate_submission'], 10, 2);
    }

    public function enqueue_script()
    {
        $src = 'https://www.google.com/recaptcha/api.js?onload=moFormRecaptchaLoadCallback&render=explicit';
        wp_enqueue_script('mo-recaptcha-script', $src, ['mailoptin'], MAILOPTIN_VERSION_NUMBER, true);
    }

    public function validate_submission($response, ConversionDataBuilder $conversion_data)
    {
        $site_key    = Settings::instance()->recaptcha_site_key();
        $site_secret = Settings::instance()->recaptcha_site_secret();

        if (empty($site_key) || empty($site_secret)) return $response;

        $optin_campaign_id = $conversion_data->optin_campaign_id;
        $fields            = OptinCampaignsRepository::form_custom_fields($optin_campaign_id);
        $has_recaptcha     = false;
        foreach ($fields as $field) {
            if ($field['field_type'] == 'recaptcha_v2') {
                $has_recaptcha = true;
                break;
            }
        }

        if ( ! $has_recaptcha) return $response;

        if (empty($conversion_data->payload['g-recaptcha-response'])) {
            return new WP_Error('mo-empty-captcha', __('reCAPTCHA is required.', 'mailoptin'));
        }

        $request = [
            'body' => [
                'secret'   => $site_secret,
                'response' => $conversion_data->payload['g-recaptcha-response'],
                'remoteip' => \MailOptin\Core\get_ip_address(),
            ],
        ];

        $result        = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', $request);
        $response_code = wp_remote_retrieve_response_code($result);

        if (200 !== (int)$response_code) {
            /* translators: %d: Response code. */
            return new WP_Error('mo-captcha-cant-connect', sprintf(__('Can not connect to the reCAPTCHA server (%d).', 'mailoptin'), $response_code));
        }

        $body = json_decode(wp_remote_retrieve_body($result), true);

        if ( ! isset($body['success']) || ! $body['success']) {
            return new WP_Error('mo-empty-captcha', __('Incorrect reCAPTCHA, please try again.', 'mailoptin'));
        }

        return $response;
    }

    public function render_field($output, $field_type, $field, $atts)
    {
        if ( ! in_array($field_type, ['recaptcha_v2', 'recaptcha_v3'])) return $output;

        $recaptcha_style = ! empty($field['recaptcha_v2_style']) ? $field['recaptcha_v2_style'] : 'light';
        $recaptcha_size  = ! empty($field['recaptcha_v2_size']) ? $field['recaptcha_v2_size'] : 'normal';

        $site_key = Settings::instance()->recaptcha_site_key();
        $output   .= $atts['tag_start'];
        if ($field_type == 'recaptcha_v2') {
            $output .= "<div style='margin: 5px 0' class=\"mo-g-recaptcha mo-optin-form-custom-field\" data-type=\"v2\" data-sitekey=\"$site_key\" data-theme='$recaptcha_style' data-size='$recaptcha_size'></div>";
        } else {
            $output .= "<div style='margin: 5px 0' class=\"mo-g-recaptcha mo-optin-form-custom-field\" data-type=\"v3\" data-sitekey=\"$site_key\"></div>";
        }

        $output .= $atts['tag_end'];

        return $output;
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