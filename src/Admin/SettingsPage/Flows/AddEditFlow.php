<?php

namespace MailOptin\Core\Admin\SettingsPage\Flows;

// Exit if accessed directly
if ( ! defined('ABSPATH')) {
    exit;
}

use MailOptin\Core\Admin\SettingsPage\AbstractSettingsPage;
use MailOptin\Core\Repositories\FlowsRepository;
use W3Guy\Custom_Settings_Page_Api;

class AddEditFlow extends AbstractSettingsPage
{
    /**
     * Back to campaign overview button.
     */
    public function back_to_optin_overview()
    {
        $url = MAILOPTIN_FLOWS_SETTINGS_PAGE;
        echo "<a class=\"add-new-h2\" style='margin-left: 10px;' href=\"$url\">" . __('Back to Overview', 'mailoptin') . '</a>';
    }

    /**
     * Build the settings page structure. I.e tab, sidebar.
     */
    public function settings_admin_page()
    {
        add_action('wp_cspa_before_closing_header', [$this, 'back_to_optin_overview']);
        add_filter('wp_cspa_main_content_area', [$this, 'flow_builder_page']);
        add_filter('wp_cspa_setting_page_sidebar', [$this, 'flow_builder_page_sidebar']);

        $instance = Custom_Settings_Page_Api::instance();
        $instance->page_header(__('Add New Flow', 'mailoptin'));
        $this->register_core_settings($instance);
        $instance->build();
    }

    public function flow_builder_page()
    {
        require dirname(__FILE__) . '/view.tmpl.php';
    }

    public function flow_builder_page_sidebar()
    {
        ob_start();
        require dirname(__FILE__) . '/sidebar-view.tmpl.php';

        return ob_get_clean();
    }

    public function save_flow()
    {
        if (isset($_GET['view'], $_GET['flowid']) && $_GET['view'] = 'edit') {
            // update
        }

        if (isset($_GET['view']) && $_GET['view'] == 'add') {

            check_admin_referer('mo_save_automate_flows', 'security');

            $response = FlowsRepository::add_flow(
                sanitize_text_field($_POST['flow_title']),
                sanitize_text_field($_POST['flow_status']),
                $_POST['aw_workflow_data']
            );

            var_dump($response);
            exit;
        }
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