<?php

namespace MailOptin\Core\OptinForms;

use MailOptin\Core\PluginSettings\Settings;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use WP_Error;

use function MailOptin\Core\current_user_has_privilege;

class Turnstile
{
    public function __construct()
    {
        add_filter('mailoptin_settings_page', [$this, 'settings_page'], 2222);

        add_filter('mo_optin_form_custom_field_output', [$this, 'render_field'], 10, 4);

        add_filter('mo_subscription_form_error', [$this, 'validate_submission'], 10, 2);
    }

    private function should_enqueue_turnstile()
    {
        $site_key    = Settings::instance()->turnstile_site_key();
        $site_secret = Settings::instance()->turnstile_site_secret();

        if (empty($site_key) || empty($site_secret)) {
            return false;
        }

        if (function_exists('wp_scripts')) {
            $wp_scripts = wp_scripts();
            if ($wp_scripts) {
                foreach ($wp_scripts->registered as $reg) {
                    $src = $reg->src ?? '';
                    if ($src && strpos($src, 'challenges.cloudflare.com/turnstile/v0/api.js') !== false) {
                        // Another plugin already registered Turnstile; avoid duplicate load.
                        return false;
                    }
                }
            }
        }

        $ids = OptinCampaignsRepository::get_optin_campaign_ids();

        if ( ! is_array($ids) || empty($ids)) {
            return false;
        }

        foreach ($ids as $id) {
            if ( ! OptinCampaignsRepository::is_activated($id)) {
                continue;
            }
            $fields = OptinCampaignsRepository::form_custom_fields($id);
            foreach ($fields as $field) {
                if ( ! empty($field['field_type']) && $field['field_type'] === 'turnstile') {
                    return true;
                }
            }
        }

        return false;
    }

    public function enqueue_script()
    {
        if ( ! $this->should_enqueue_turnstile()) return;

        // Cloudflare Turnstile auto-detects widgets with the cf-turnstile class
        $src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

        // Avoid adding WP's ?ver parameter to the API URL
        wp_enqueue_script('mo-turnstile-script', $src, ['mailoptin'], null, true);

        // Ensure no version query param sneaks in, silencing console noise
        add_filter('script_loader_src', function ($src, $handle) {
            if ($handle === 'mo-turnstile-script') {
                $src = remove_query_arg('ver', $src);
            }

            return $src;
        }, 10, 2);
    }

    public function validate_submission($response, ConversionDataBuilder $conversion_data)
    {
        $site_key    = Settings::instance()->turnstile_site_key();
        $site_secret = Settings::instance()->turnstile_site_secret();

        if (empty($site_key) || empty($site_secret)) return $response;

        $optin_campaign_id = $conversion_data->optin_campaign_id;
        $fields            = OptinCampaignsRepository::form_custom_fields($optin_campaign_id);
        $has_turnstile     = false;
        foreach ($fields as $field) {
            if ($field['field_type'] === 'turnstile') {
                $has_turnstile = true;
                break;
            }
        }

        if ( ! $has_turnstile) return $response;

        if (empty($conversion_data->payload['cf-turnstile-response'])) {
            return new WP_Error('mo-empty-captcha', __('Cloudflare Turnstile is required.', 'mailoptin'));
        }

        $request = [
            'body' => [
                'secret'   => $site_secret,
                'response' => $conversion_data->payload['cf-turnstile-response'],
                'remoteip' => \MailOptin\Core\get_ip_address(),
            ],
        ];

        $result        = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', $request);
        $response_code = wp_remote_retrieve_response_code($result);

        if (200 !== (int)$response_code) {
            /* translators: %d: Response code. */
            return new WP_Error('mo-captcha-cant-connect', sprintf(esc_html__('Can not connect to the Turnstile server (%d).', 'mailoptin'), $response_code));
        }

        $body = json_decode(wp_remote_retrieve_body($result), true);

        if ( ! isset($body['success']) || ! $body['success']) {
            return new WP_Error('mo-empty-captcha', esc_html__('Cloudflare Turnstile verification failed, please try again.', 'mailoptin'));
        }

        return $response;
    }

    public function render_field($output, $field_type, $field, $atts)
    {
        if ($field_type !== 'turnstile') return $output;

        $turnstile_theme = ! empty($field['turnstile_theme']) ? $field['turnstile_theme'] : (Settings::instance()->turnstile_theme('auto'));
        $turnstile_size  = ! empty($field['turnstile_size']) ? $field['turnstile_size'] : (Settings::instance()->turnstile_size('normal'));

        $site_key    = Settings::instance()->turnstile_site_key();
        $site_secret = Settings::instance()->turnstile_site_secret();

        $output .= $atts['tag_start'];
        if (current_user_has_privilege() && (empty($site_key) || empty($site_secret))) {
            $output .= '<div style="margin:5px 0;color:#31708f;background-color: #d9edf7;border-color: #bcdff1;">' . esc_html__('To use Cloudflare Turnstile, add the API keys in Dashboard > MailOptin > Settings > Turnstile.', 'mailoptin') . '</div>';
        } else {
            // Cloudflare Turnstile auto renders this container
            $output .= "<div style='margin: 5px 0' class=\"cf-turnstile mo-optin-form-custom-field\" data-sitekey=\"$site_key\" data-theme='$turnstile_theme' data-size='$turnstile_size'></div>";
        }

        $output .= $atts['tag_end'];

        return $output;
    }

    public function settings_page($settings)
    {
        $is_premium = defined('MAILOPTIN_DETACH_LIBSODIUM');

        $fields = $is_premium ? [
            'turnstile_site_key'    => [
                'type'        => 'text',
                'label'       => __('Site Key', 'mailoptin'),
                'description' => sprintf(__('Necessary for displaying Turnstile. Grab it %shere%s', 'mailoptin'), '<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer">', '</a>')
            ],
            'turnstile_site_secret' => [
                'type'        => 'text',
                'label'       => __('Site Secret', 'mailoptin'),
                'description' => sprintf(__('Required for server-side verification. Grab it %shere%s', 'mailoptin'), '<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer">', '</a>')
            ],
            'turnstile_theme'       => [
                'type'        => 'select',
                'label'       => __('Default Theme', 'mailoptin'),
                'options'     => [
                    'auto'  => __('Auto', 'mailoptin'),
                    'light' => __('Light', 'mailoptin'),
                    'dark'  => __('Dark', 'mailoptin'),
                ],
                'value'       => 'auto',
                'description' => __('Default Turnstile theme; can be overridden per form field.', 'mailoptin')
            ],
            'turnstile_size'        => [
                'type'        => 'select',
                'label'       => __('Default Size', 'mailoptin'),
                'options'     => [
                    'normal'    => __('Normal', 'mailoptin'),
                    'compact'   => __('Compact', 'mailoptin'),
                    'invisible' => __('Invisible', 'mailoptin'),
                ],
                'value'       => 'normal',
                'description' => __('Default Turnstile size; can be overridden per form field.', 'mailoptin')
            ],
        ] : [
            'turnstile_site_key'    => [
                'label' => __('Type', 'mailoptin'),
                'type'  => 'arbitrary',
                'data'  => sprintf(
                    '<p style="text-align: center">%s</p><div class="moBtncontainer mobtnUpgrade"><a target="_blank" href="%s" class="mobutton mobtnPush mobtnGreen">%s</a></div>',
                    esc_html__('Do you want to stop spam bots from filling out your form? You can add captcha from Cloudflare Turnstile to your forms to protects against spam and bot attacks.', 'mailoptin'),
                    'https://mailoptin.io/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=turnstile_unlock',
                    esc_html__('Upgrade to Unlock', 'mailoptin')
                )
            ],
            'disable_submit_button' => true,
        ];

        $settings['turnstile_settings'] = [
            'tab_title' => __('Cloudflare Turnstile', 'mailoptin'),
            array_merge(['section_title' => __('Cloudflare Turnstile Settings', 'mailoptin')], $fields)
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