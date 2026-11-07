<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\AdvanceAnalytics\SettingsPage;
use W3Guy\Custom_Settings_Page_Api;

use function MailOptin\Core\get_capability;

class AdvanceAnalytics extends AbstractSettingsPage
{
    protected $settingsInstance;

    public function __construct()
    {
        add_action('mailoptin_register_menu_page', array($this, 'register_settings_page'), 35);

        add_action('mailoptin_admin_settings_page_pre', function ($active_menu) {

            if ($active_menu === 'statistics') {
                $this->settingsInstance = Custom_Settings_Page_Api::instance();
                $this->settingsInstance->option_name('mo_analytics');
                $this->settingsInstance->page_header(__('Statistics', 'mailoptin'));
                $this->settingsInstance->remove_h2_header();
                $this->set_settings_page_instance($this->settingsInstance);
            }
        });

        add_action('mailoptin_admin_settings_submenu_page_statistics', [$this, 'settings_admin_page_callback']);
    }

    public function register_settings_page()
    {
        $hook = add_submenu_page(
                MAILOPTIN_SETTINGS_SETTINGS_SLUG,
                __('Statistics - MailOptin', 'mailoptin'),
                __('Statistics', 'mailoptin'),
                get_capability(),
                MAILOPTIN_ADVANCE_ANALYTICS_SETTINGS_SLUG,
                array($this, 'admin_page_callback')
        );

        do_action("mailoptin_advance_analytics_settings_page", $hook);

        if ( ! apply_filters('mailoptin_enable_advance_analytics', false)) {
            add_filter('wp_cspa_main_content_area', array($this, 'upsell_settings_page'), 10, 2);
        }
    }

    public function default_header_menu()
    {
        return 'statistics';
    }

    public function upsell_settings_page($content, $option_name)
    {
        if ($option_name != 'mo_analytics') return $content;

        $url = 'https://mailoptin.io/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=advanceanalytics_btn';

        $preview = '<div class="mo-ui-preview-title">' . esc_html__('Last 30 days', 'mailoptin') . '</div>'
                   . '<div class="mo-ui-preview-stats">'
                   . '<div><b>18,240</b><span>' . esc_html__('Impressions', 'mailoptin') . '</span></div>'
                   . '<div><b>912</b><span>' . esc_html__('Signups', 'mailoptin') . '</span></div>'
                   . '<div><b>5.0%</b><span>' . esc_html__('Conversion rate', 'mailoptin') . '</span></div>'
                   . '</div>'
                   . '<div class="mo-ui-preview-chart"><i style="height:35%"></i><i style="height:52%"></i><i style="height:44%"></i><i style="height:61%"></i><i style="height:58%"></i><i style="height:73%"></i><i style="height:66%"></i><i style="height:80%"></i><i style="height:71%"></i><i style="height:88%"></i></div>';

        return '<div class="mo-ui-page">' . \MailOptin\Core\Admin\UI::locked_feature([
                'title'     => __('See which forms bring in subscribers', 'mailoptin'),
                'intro'     => __('Impressions, signups and conversion rate for every optin form, so you know what to keep and what to change.', 'mailoptin'),
                'bullets'   => [
                    __('Compare forms, pages and date ranges side by side', 'mailoptin'),
                    __('Find the pages where your forms convert best', 'mailoptin'),
                    __('Check how A/B split tests are doing before you pick a winner', 'mailoptin'),
                ],
                'cta_url'   => $url,
                'learn_url' => 'https://mailoptin.io/docs/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=advanceanalytics_learn',
                'preview'   => $preview,
            ]) . '</div>';
    }

    /**
     * Build the settings page structure. I.e tab, sidebar.
     */
    public function settings_admin_page_callback()
    {
        if (apply_filters('mailoptin_enable_advance_analytics', false)) {
            $this->settingsInstance->sidebar(SettingsPage::get_instance()->analytic_chart_sidebar());
            SettingsPage::get_instance()->process_actions();
        }

        $this->settingsInstance->build(! apply_filters('mailoptin_enable_advance_analytics', false));
    }

    /**
     * @return AdvanceAnalytics
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