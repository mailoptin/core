<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Submissions\SubmissionAlert;
use W3Guy\Custom_Settings_Page_Api;

use function MailOptin\Core\get_capability;

class LeadBank extends AbstractSettingsPage
{
    protected $settingsInstance;

    public function __construct()
    {
        add_action('mailoptin_admin_settings_page_pre', function ($active_menu) {

            if ($active_menu === 'leadbank') {

                $this->settingsInstance = Custom_Settings_Page_Api::instance();
                $this->settingsInstance->option_name('mo_leads');
                $this->settingsInstance->page_header(__('Submissions', 'mailoptin'));
                $this->settingsInstance->remove_h2_header();
                $this->set_settings_page_instance($this->settingsInstance);
            }
        });

        add_action('mailoptin_register_menu_page', array($this, 'register_settings_page'), 30);

        add_filter('wp_cspa_main_content_area', array($this, 'failure_banner'), 99, 2);
    }

    /**
     * Above the submissions list, or the locked screen on free sites.
     */
    public function failure_banner($content, $option_name)
    {
        if ('mo_leads' !== $option_name || isset($_GET['submission']) || isset($_GET['import'])) return $content;

        return SubmissionAlert::banner_html(apply_filters('mailoptin_enable_leadbank', false)) . $content;
    }

    public function register_settings_page()
    {
        $hook = add_submenu_page(
            MAILOPTIN_SETTINGS_SETTINGS_SLUG,
            __('Submissions - MailOptin', 'mailoptin'),
            SubmissionAlert::menu_title(__('Submissions', 'mailoptin')),
            get_capability(),
            MAILOPTIN_LEAD_BANK_SETTINGS_SLUG,
            array($this, 'admin_page_callback')
        );

        add_action("load-$hook", array($this, 'screen_option'));

        add_action('mailoptin_admin_settings_submenu_page_leadbank', [$this, 'settings_admin_page']);
    }

    public function default_header_menu()
    {
        return 'leadbank';
    }

    /**
     * Screen options
     */
    public function screen_option()
    {
        $option = 'per_page';
        $args   = [
            'label'   => __('Submissions', 'mailoptin'),
            'default' => 20,
            'option'  => 'conversions_per_page',
        ];

        add_screen_option($option, $args);

        do_action('mailoptin_leadbank_settings_page_screen_option');
    }

    public function settings_admin_page()
    {
        if ( ! apply_filters('mailoptin_enable_leadbank', false)) {
            add_filter('wp_cspa_main_content_area', array($this, 'upsell_settings_page'), 10, 2);
        }

        do_action("mailoptin_leadbank_settings_page");

        $this->settingsInstance->build(true);
    }

    public function upsell_settings_page($content, $option_name)
    {
        if ($option_name != 'mo_leads') {
            return $content;
        }

        $url = 'https://mailoptin.io/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=leadbank_btn';

        $preview = '<div class="mo-ui-preview-title">' . esc_html__('Submissions', 'mailoptin') . '</div>'
                   . \MailOptin\Core\Admin\UI::preview_rows([
                    ['maya@example.com', __('Footer form', 'mailoptin'), ['badge' => __('Sent', 'mailoptin'), 'tone' => 'ok']],
                    ['jonas@example.com', __('Exit popup', 'mailoptin'), ['badge' => __('Not sent: API key expired', 'mailoptin'), 'tone' => 'bad']],
                    ['priya@example.com', __('Footer form', 'mailoptin'), ['badge' => __('Sent', 'mailoptin'), 'tone' => 'ok']],
                    ['tom@example.com', __('Sidebar form', 'mailoptin'), ['badge' => __('Resent', 'mailoptin'), 'tone' => 'info']],
                ]);

        return '<div class="mo-ui-page">' . \MailOptin\Core\Admin\UI::locked_feature([
                'title'     => __('Never lose a signup, even when your email service is down', 'mailoptin'),
                'intro'     => __('Every form submission is saved on your site as a backup, with whether it reached your email service. (Previously called Leads.)', 'mailoptin'),
                'bullets'   => [
                    __('See which signups failed to reach Mailchimp, ConvertKit or any other service, and why', 'mailoptin'),
                    __('Resend failed signups in one click once the problem is fixed', 'mailoptin'),
                    __('Search, filter and export every submission as CSV', 'mailoptin'),
                ],
                'cta_url'   => $url,
                'learn_url' => 'https://mailoptin.io/docs/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=leadbank_learn',
                'preview'   => $preview,
            ]) . '</div>';
    }

    /**
     * @return LeadBank
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