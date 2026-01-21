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

        ob_start();
        ?>
        <div class="mo-settings-page-disabled">
            <div class="mo-upgrade-plan">
                <div class="mo-text-center">
                    <div class="mo-lock-icon"></div>
                    <h1><?php _e('Advance Analytics Locked', 'mailoptin'); ?></h1>
                    <p>
                        <?php printf(
                                __('Get important metrics and insights to improve your lead-generation strategy and make data-driven decisions.', 'mailoptin'),
                                '<strong>',
                                '</strong>'
                        ); ?>
                    </p>
                    <p>
                        <?php _e('Your current plan does not include it.', 'mailoptin'); ?>
                    </p>
                    <div class="moBtncontainer mobtnUpgrade">
                        <a target="_blank" href="<?= $url; ?>" class="mobutton mobtnPush mobtnGreen">
                            <?php _e('Upgrade to Unlock', 'mailoptin'); ?>
                        </a>
                    </div>
                </div>
            </div>
            <img src="<?php echo MAILOPTIN_ASSETS_URL; ?>images/advanceanalytics.png">
        </div>
        <?php

        return ob_get_clean();
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