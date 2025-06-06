<?php

namespace MailOptin\Core;


use MailOptin\Core\OptinForms\Recaptcha;
use MailOptin\Core\PluginSettings\Settings;
use MailOptin\Core\Repositories\OptinCampaignsRepository;

class RegisterScripts
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', array($this, 'admin_css'));
        add_action('admin_enqueue_scripts', [$this, 'admin_js']);
        add_action('customize_controls_init', [$this, 'admin_js']);
        add_action('admin_enqueue_scripts', [$this, 'fancybox_assets']);
        add_action('wp_enqueue_scripts', array($this, 'public_css'));
        add_action('wp_enqueue_scripts', array($this, 'public_js'));
    }

    public function fancybox_assets()
    {
        wp_enqueue_script('mailoptin-fancybox', MAILOPTIN_ASSETS_URL . 'fancybox/jquery.fancybox.min.js', ['jquery'], false, true);
        wp_enqueue_script('mailoptin-init-fancybox', MAILOPTIN_ASSETS_URL . 'js/admin/fancybox-init.js', ['jquery'], false, true);
        wp_enqueue_style('mailoptin-fancybox', MAILOPTIN_ASSETS_URL . 'fancybox/jquery.fancybox.min.css', false, true);
        wp_enqueue_style('mailoptin-activate-fancybox', MAILOPTIN_ASSETS_URL . 'css/admin/fancybox.css', false, true);
    }

    /**
     * Admin JS
     */
    public function admin_js()
    {
        wp_enqueue_script('jquery');
        wp_enqueue_script('underscore');
        $this->mailoptin_only_js();
        $this->global_js_variables();
    }

    /**
     * MailOptin Only JS
     */
    public function mailoptin_only_js()
    {
        $screen = get_current_screen();

        $base_text = $screen->base;

        if (strpos($base_text, 'mailoptin') !== false || is_customize_preview()) {

            if (defined('MAILOPTIN_LIBSODIUM_ASSETS_URL')) {
                wp_enqueue_script('mailoptin-highcharts', MAILOPTIN_LIBSODIUM_ASSETS_URL . 'js/admin/highcharts.js', array('jquery'), MAILOPTIN_VERSION_NUMBER, true);
            }

            wp_enqueue_script('mailoptin-core-select2', MAILOPTIN_ASSETS_URL . 'js/customizer-controls/select2/select2.min.js', array('jquery'), false, true);
            wp_enqueue_style('mailoptin-core-select2', MAILOPTIN_ASSETS_URL . 'js/customizer-controls/select2/select2.min.css', null);

            wp_enqueue_script('mailoptin-admin-tooltipster', MAILOPTIN_ASSETS_URL . 'tooltipster/bundle.min.js', array('jquery'), MAILOPTIN_VERSION_NUMBER, true);
            wp_enqueue_script('mailoptin-admin-tooltipster-init', MAILOPTIN_ASSETS_URL . 'tooltipster/init.js', array(
                'jquery',
                'mailoptin-admin-tooltipster'
            ), MAILOPTIN_VERSION_NUMBER, true);
            wp_enqueue_script('mailoptin-ab-test-script', MAILOPTIN_ASSETS_URL . 'js/admin/ab-test.js', array('jquery'), MAILOPTIN_VERSION_NUMBER, true);

            wp_enqueue_script('mailoptin-add-optin-campaign', MAILOPTIN_ASSETS_URL . 'js/admin/new-optin-campaign.js', array('jquery'), MAILOPTIN_VERSION_NUMBER, true);
            wp_enqueue_script('mailoptin-optin-type-selection', MAILOPTIN_ASSETS_URL . 'js/admin/optin-type-selection.js', array('jquery'), MAILOPTIN_VERSION_NUMBER, true);
            wp_enqueue_script('mailoptin-add-email-campaign', MAILOPTIN_ASSETS_URL . 'js/admin/new-email-campaign.js', array('jquery'), MAILOPTIN_VERSION_NUMBER, true);
            wp_enqueue_script('mailoptin-admin-script', MAILOPTIN_ASSETS_URL . 'js/admin/admin-script.js', array('jquery'), MAILOPTIN_VERSION_NUMBER, true);

            do_action('mo_admin_js_enqueue');
        }
        if (strstr($base_text, 'edit')) {
            wp_enqueue_script('mailoptin-preview-post-email', MAILOPTIN_ASSETS_URL . 'js/admin/preview-post-email.js', array('jquery'), MAILOPTIN_VERSION_NUMBER, true);
        }
    }

    /**
     * Enqueue public scripts and styles.
     */
    public function public_js()
    {
        $this->modal_scripts();

        $this->mobile_detect_js();
    }

    public function mobile_detect_js()
    {
        wp_register_script('mo-mobile-detect', MAILOPTIN_ASSETS_URL . 'js/mobile-detect.min.js', ['jquery'], MAILOPTIN_VERSION_NUMBER);

        $ids = OptinCampaignsRepository::get_optin_campaign_ids();

        if ( ! is_array($ids) || empty($ids)) return;

        $status = false;

        foreach ($ids as $id) {
            if (OptinCampaignsRepository::has_device_targeting_active($id)) {
                $status = true;
                break;
            }
        }

        if ($status === true) {
            wp_enqueue_script('mo-mobile-detect');
        }
    }

    /**
     * Enqueue modal optin scripts.
     */
    public function modal_scripts()
    {
        wp_enqueue_script('jquery');

        $this->google_fonts_script();

        if (is_customize_preview()) {
            // when plugin like nextgen gallery is active, loading mailoptin.js in footer do not make lightbox, slidein, bar load
            // in customizer. but on header works.
            wp_enqueue_script('mailoptin', MAILOPTIN_ASSETS_URL . 'js/mailoptin.min.js', ['jquery'], MAILOPTIN_VERSION_NUMBER);
        } else {
            wp_enqueue_script('mailoptin', MAILOPTIN_ASSETS_URL . 'js/mailoptin.min.js', ['jquery'], MAILOPTIN_VERSION_NUMBER, true);
        }

        if (class_exists('\Ninja_Forms') && class_exists('\NF_Display_Render')) {
            $flag      = false;
            $optin_ids = OptinCampaignsRepository::get_optin_campaign_ids();
            foreach ($optin_ids as $optin_id) {
                if ( ! OptinCampaignsRepository::is_activated($optin_id)) continue;

                if (is_ninja_form_shortcode($optin_id)) {
                    $flag = true;
                    break;
                }
            }

            if ($flag) {
                ob_start();

                // since form id 0 does not exist, not_logged_in_msg returns false which cause a fatal error when false is
                // passed to do_shortcode in ./ninja-forms/includes/Display/Render.php:103
                // Fatal error: Uncaught TypeError: str_contains(): Argument #1 ($haystack) must be of type string, array given
                Ninja_Forms()->form(0)->get()->update_setting('not_logged_in_msg', '');

                \NF_Display_Render::localize(0);

                ob_clean();

                wp_add_inline_script('nf-front-end', 'var nfForms = nfForms || [];');
            }
        }

        Recaptcha::get_instance()->enqueue_script();

        $this->global_js_variables('mailoptin');
    }

    /**
     * @return void
     */
    public function google_fonts_script()
    {
        $google_fonts_status = Settings::instance()->dequeue_google_font();

        if ( ! empty($google_fonts_status) && in_array($google_fonts_status, ['true', true], true)) {
            return;
        }

        // trailing "true" function argument not needed because we want it loaded before hidden optin markup display in footer.
        wp_enqueue_script('mo-google-webfont', 'https://ajax.googleapis.com/ajax/libs/webfont/1.6.26/webfont.js', false, MAILOPTIN_VERSION_NUMBER, true);
    }

    /**
     * Global JS variables by required by mailoptin.
     *
     * @param string $handle
     */
    public function global_js_variables($handle = 'jquery')
    {
        global $post;

        $disable_impression_status = false;
        $disable_impression        = apply_filters('mo_disable_impression_tracking', Settings::instance()->disable_impression_tracking());
        if ( ! empty($disable_impression) && ($disable_impression == 'true' || $disable_impression === true)) {
            $disable_impression_status = true;
        }

        $enable_init_js_cookies_filter = apply_filters('mailoptin_enable_init_js_cookies', apply_filters('mailoptin_is_new_returning_visitors_cookies', true));

        $localize_strings = array(
            'admin_url'                         => admin_url(),
            'public_js'                         => MAILOPTIN_ASSETS_URL . 'js/src',
            'public_sound'                      => MAILOPTIN_ASSETS_URL . 'sound/',
            'nonce'                             => wp_create_nonce('mailoptin-admin-nonce'),
            'mailoptin_ajaxurl'                 => AjaxHandler::get_endpoint(),
            'is_customize_preview'              => is_customize_preview() ? 'true' : 'false',
            // for some weird reason, boolean false is converted to empty and true to "1" hence the use of 'false' in string form.
            'disable_impression_tracking'       => $disable_impression_status === true ? 'true' : 'false',
            'split_test_start_label'            => __('Start Test', 'mailoptin'),
            'split_test_pause_label'            => __('Pause Test', 'mailoptin'),
            'chosen_search_placeholder'         => __('Type to search', 'mailoptin'),
            'js_confirm_text'                   => __('Are you sure you?', 'mailoptin'),
            'js_clear_stat_text'                => __('Are you sure you want to do this? Clicking OK will delete all your optin analytics records.', 'mailoptin'),
            'custom_field_label'                => sprintf(__('Field %s', 'mailoptin'), '#{ID}'),
            'sidebar'                           => 0,
            'js_required_title'                 => __('Title is required.', 'mailoptin'),
            'is_new_returning_visitors_cookies' => defined('MAILOPTIN_DETACH_LIBSODIUM') && $enable_init_js_cookies_filter === true ? 'true' : 'false'
        );

        if ( ! is_admin()) {
            unset($localize_strings['admin_url']);
            unset($localize_strings['nonce']);
            unset($localize_strings['chosen_search_placeholder']);
            unset($localize_strings['js_confirm_text']);
            unset($localize_strings['js_clear_stat_text']);
            unset($localize_strings['custom_field_label']);
            unset($localize_strings['split_test_start_label']);
            unset($localize_strings['split_test_pause_label']);
        }

        //Localize this here instead of gutenberg function since 'get_current_screen()' won't be declared by then
        if (is_admin() && function_exists('get_current_screen')) {
            $screen = get_current_screen();

            //Ensure this is a post edit screen to save resources
            if (isset($screen->is_block_editor) && $screen->is_block_editor && post_can_new_post_notification($post)) {
                $localize_strings['sidebar']                   = 1;
                $localize_strings['disable_notifications_txt'] = __('Disable MailOptin new post notification for this post.', 'mailoptin');
            }
        }

        wp_localize_script(
            $handle, 'mailoptin_globals',
            apply_filters('mo_mailoptin_js_globals', $localize_strings)
        );
    }

    /**
     * Mailoptin only css to fix conflicts
     */
    public function admin_css()
    {
        $screen = get_current_screen();

        $base_text = $screen->base ?? '';

        wp_enqueue_style('mailoptin-menu', MAILOPTIN_ASSETS_URL . 'css/admin/mailoptin-menu.css', [], filemtime(MAILOPTIN_ASSETS_DIR . 'css/admin/mailoptin-menu.css'));

        if ( ! empty($base_text) && (strpos($base_text, 'mailoptin') !== false) || is_customize_preview()) {

            wp_enqueue_style('mailoptin-admin-tooltipster', MAILOPTIN_ASSETS_URL . 'tooltipster/bundle.min.css', [], MAILOPTIN_VERSION_NUMBER);
            wp_enqueue_style('mailoptin-admin-tooltipster-borderless', MAILOPTIN_ASSETS_URL . 'tooltipster/borderless.min.css', [], MAILOPTIN_VERSION_NUMBER);
            wp_enqueue_style('mailoptin-admin-tooltipster-light', MAILOPTIN_ASSETS_URL . 'tooltipster/light.min.css', [], MAILOPTIN_VERSION_NUMBER);
            wp_enqueue_style('mailoptin-admin', MAILOPTIN_ASSETS_URL . 'css/admin/admin.css', [], filemtime(MAILOPTIN_ASSETS_DIR . 'css/admin/admin.css'));

            wp_enqueue_style('mo-pure-css-toggle-buttons', MAILOPTIN_ASSETS_URL . 'js/customizer-controls/toggle-control/pure-css-togle-buttons.css', array(), false);

            $css = '
                .disabled-control-title {
                    color: #a0a5aa;
                }
                input[type=checkbox].tgl-light:checked + .tgl-btn {
                    background: #0085ba;
                }
                input[type=checkbox].tgl-light + .tgl-btn {
                  background: #a0a5aa;
                }
                input[type=checkbox].tgl-light + .tgl-btn:after {
                  background: #f7f7f7;
                }
    
                input[type=checkbox].tgl-ios:checked + .tgl-btn {
                  background: #0085ba;
                }
    
                input[type=checkbox].tgl-flat:checked + .tgl-btn {
                  border: 4px solid #0085ba;
                }
                input[type=checkbox].tgl-flat:checked + .tgl-btn:after {
                  background: #0085ba;
                }
    
            ';
            wp_add_inline_style('mo-pure-css-toggle-buttons', $css);
        }
    }

    /**
     * Front-end CSS
     */
    public function public_css()
    {
    }

    /**
     * @return RegisterScripts
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}
