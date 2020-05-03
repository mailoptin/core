<?php

namespace MailOptin\Core\Admin\SettingsPage\Flows;

// Exit if accessed directly
if ( ! defined('ABSPATH')) {
    exit;
}

use MailOptin\Core\Admin\SettingsPage\AbstractSettingsPage;
use W3Guy\Custom_Settings_Page_Api;

class Flows extends AbstractSettingsPage
{
    /**
     * @var Flows_List
     */
    protected $flows_list_instance;

    protected $addEditFlowInstance;

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_settings_page'));

        add_filter('set-screen-option', array($this, 'set_screen'), 10, 3);

        $this->addEditFlowInstance = AddEditFlow::get_instance();
    }

    public static function page_title()
    {
        $title = __('Flows', 'mailoptin');
        if (isset($_GET['view']) && $_GET['view'] == 'add') {
            $title = esc_html__('Add New Flow', 'mailoptin');
        }

        if (isset($_GET['view']) && $_GET['view'] == 'edit') {
            $title = esc_html__('Edit Flow', 'mailoptin');
        }

        return $title;
    }

    public function register_settings_page()
    {
        $hook = add_submenu_page(
            MAILOPTIN_SETTINGS_SETTINGS_SLUG,
            self::page_title() . ' - MailOptin',
            __('Flows', 'mailoptin'),
            \MailOptin\Core\get_capability(),
            MAILOPTIN_FLOWS_SETTINGS_SLUG,
            array($this, 'settings_admin_page_callback')
        );

        do_action("mailoptin_register_flows_settings_page", $hook);

        add_action("load-$hook", array($this, 'screen_option'));
    }

    /**
     * Save screen option.
     *
     * @param string $status
     * @param string $option
     * @param string $value
     *
     * @return mixed
     */
    public function set_screen($status, $option, $value)
    {
        return $value;
    }

    /**
     * Screen options
     */
    public function screen_option()
    {
        if (isset($_GET['page']) && $_GET['page'] == MAILOPTIN_FLOWS_SETTINGS_SLUG && ! isset($_GET['view'])) {

            $option = 'per_page';
            $args   = array(
                'label'   => __('Flows', 'mailoptin'),
                'default' => 10,
                'option'  => 'flows_per_page',
            );

            add_screen_option($option, $args);

            $this->flows_list_instance = Flows_List::get_instance();
        }
    }

    /**
     * Build the settings page structure. I.e tab, sidebar.
     */
    public function settings_admin_page_callback()
    {
        if ( ! empty($_GET['view']) && in_array($_GET['view'], ['add', 'edit'])) {
            $this->addEditFlowInstance->settings_admin_page();
        } else {
            // Hook the OptinCampaign_List table to Custom_Settings_Page_Api main content filter.
            add_action('wp_cspa_main_content_area', array($this, 'wp_list_table'), 10, 2);
            add_action('wp_cspa_before_closing_header', [$this, 'add_new_button']);

            $instance = Custom_Settings_Page_Api::instance();

            $instance->option_name(MO_OPTIN_CAMPAIGN_WP_OPTION_NAME);
            $instance->page_header(__('Flows', 'mailoptin'));
            $this->register_core_settings($instance);
            echo '<div class="mailoptin-data-listing mailoptin-flows">';
            $instance->build(true);
            echo '</div>';
        }
    }

    public function add_new_button()
    {
        $url = add_query_arg('view', 'add', MAILOPTIN_FLOWS_SETTINGS_PAGE);
        echo "<a class=\"add-new-h2\" href=\"$url\">" . __('Add New', 'mailoptin') . '</a>';
    }

    /**
     * Callback to output content of OptinCampaign_List table.
     *
     * @param string $content
     * @param string $option_name settings Custom_Settings_Page_Api option name.
     *
     * @return string
     */
    public function wp_list_table($content, $option_name)
    {
        if ($option_name != MO_OPTIN_CAMPAIGN_WP_OPTION_NAME) {
            return $content;
        }

        $this->flows_list_instance->prepare_items();

        ob_start();
        $this->flows_list_instance->display();

        return ob_get_clean();
    }

    /**
     * @return self
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