<?php

namespace MailOptin\Core\Admin\Customizer;

class SafeModeMUInstaller
{
    private $mu_plugin_filename = 'mailoptin-customizer-optimizer.php';

    private $current_version = '0.1';

    // Content of the MU plugin
    private $mu_plugin_content = '<?php
/**
 * Plugin Name: MailOptin Customizer Integration
 * Description: Improve customizer compatibility between MailOptin and other plugins.
 * Version: 0.1
 * Author: MailOptin
 */

// Prevent direct access
if ( ! defined(\'ABSPATH\')) {
    exit;
}

class MailOptinCustomizerIntegration
{
    // Allowed themes in order of preference
    private $allowed_themes = [
        \'twentytwentyfive\',
        \'twentytwentyfour\',
        \'twentytwentythree\',
        \'twentytwentytwo\',
        \'twentytwentyone\',
        \'twentytwenty\',
        \'twentynineteen\',
        \'twentyseventeen\',
        \'twentysixteen\',
        \'twentyfifteen\',
        \'twentyfourteen\',
        \'twentythirteen\',
        \'twentytwelve\',
        \'twentyeleven\',
        \'twentyten\',
    ];

    // Plugins that should remain active
    private $required_plugins = [
        \'mailoptin/mailoptin.php\',
        \'woocommerce/woocommerce.php\',
        \'woocommerce-memberships/woocommerce-memberships.php\',
        \'woocommerce-subscriptions/woocommerce-subscriptions.php\',
        \'woocommerce-payments/woocommerce-payments.php\',
        \'paid-memberships-pro/paid-memberships-pro.php\',
        \'lifterlms/lifterlms.php\',
        \'give/give.php\',
        \'ultimate-member/ultimate-member.php\',
    ];

    // Plugins that should remain active
    private $required_plugins_substring = [
        \'fluent\',
        \'mailpoet\',
        \'wemail\',
        \'memberpress\',
        \'elementor\',
        \'easy-digital-downloads\',
        \'edd-\',
        \'sfwd-lms\',
        \'tutor\',
        \'restrict-content\',
        \'polylang\',
        \'sitepress-multilingual-cms\',
        \'weglot\'
    ];

    public function __construct()
    {
        // Filter active plugins
        add_filter(\'option_active_plugins\', [$this, \'filter_active_plugins\']);

        // Filter current theme
        add_filter(\'stylesheet\', [$this, \'filter_theme\']);
        add_filter(\'template\', [$this, \'filter_theme\']);
    }

    private function should_modify_environment()
    {
        return (
            is_admin() &&
            (isset($_GET[\'mailoptin_optin_campaign_id\']) || isset($_GET[\'mailoptin_email_campaign_id\'])) &&
            isset($_SERVER[\'SCRIPT_FILENAME\']) &&
            basename($_SERVER[\'SCRIPT_FILENAME\']) === \'customize.php\' &&
            (get_option(\'mailoptin_settings\', [])[\'enable_safe_mode\'] ?? \'\') === \'true\'
        );
    }

    public function filter_active_plugins($plugins)
    {
        if ( ! $this->should_modify_environment()) {
            return $plugins;
        }

        // Filter to keep only the required plugins
        return array_values(array_filter($plugins, function ($plugin) {

            if (in_array($plugin, $this->required_plugins)) {
                return true;
            }

            // Then check for substrings
            foreach ($this->required_plugins_substring as $substring) {
                if (stripos($plugin, $substring) !== false) {
                    return true;
                }
            }

            return false;

        }));
    }

    public function filter_theme($stylesheet)
    {
        if ( ! $this->should_modify_environment()) {
            return $stylesheet;
        }

        // If current theme is already in allowed themes list, keep using it
        if (in_array($stylesheet, $this->allowed_themes)) {
            return $stylesheet;
        }

        // Otherwise, try to switch to first available allowed theme
        $installed_themes = wp_get_themes();

        foreach ($this->allowed_themes as $theme_slug) {
            if (isset($installed_themes[$theme_slug])) {
                return $theme_slug;
            }
        }

        // If no allowed theme is found, return the current theme
        return $stylesheet;
    }
}

// Initialize the plugin
new MailOptinCustomizerIntegration();';

    public function __construct()
    {
        // Hook into plugin activation
        register_activation_hook(__FILE__, [$this, 'install_mu_plugin']);

        // Hook into admin init to check for existing installations and updates
        add_action('admin_init', [$this, 'check_mu_plugin_status']);
    }

    /**
     * Get WP_Filesystem instance
     */
    private function get_filesystem()
    {
        global $wp_filesystem;

        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }

        return $wp_filesystem;
    }

    /**
     * Create the mu-plugins directory if it doesn't exist
     */
    private function ensure_mu_plugins_dir()
    {
        $filesystem     = $this->get_filesystem();
        $mu_plugins_dir = WPMU_PLUGIN_DIR;

        if ( ! $filesystem->exists($mu_plugins_dir)) {
            if ( ! $filesystem->mkdir($mu_plugins_dir, FS_CHMOD_DIR)) {
                return false;
            }
        }

        return $filesystem->is_dir($mu_plugins_dir) && $filesystem->is_writable($mu_plugins_dir);
    }

    /**
     * Install the MU plugin
     */
    public function install_mu_plugin()
    {
        if ( ! $this->ensure_mu_plugins_dir()) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>' .
                     esc_html__('Unable to create or write to mu-plugins directory. Please check permissions.', 'mailoptin') .
                     '</p></div>';
            });

            return false;
        }

        $filesystem     = $this->get_filesystem();
        $mu_plugin_path = WPMU_PLUGIN_DIR . '/' . $this->mu_plugin_filename;

        $installed = $filesystem->put_contents(
            $mu_plugin_path,
            $this->mu_plugin_content,
            FS_CHMOD_FILE
        );

        if ($installed === false) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>' .
                     esc_html__('Failed to install required MU plugin. Please check file permissions.', 'mailoptin') .
                     '</p></div>';
            });

            return false;
        }

        return true;
    }

    /**
     * Get the current version of the MU plugin
     */
    private function get_mu_plugin_version()
    {
        $filesystem     = $this->get_filesystem();
        $mu_plugin_path = WPMU_PLUGIN_DIR . '/' . $this->mu_plugin_filename;

        if ($filesystem->exists($mu_plugin_path)) {
            $content = $filesystem->get_contents($mu_plugin_path);
            if (preg_match('/Version:\s*([0-9\.]+)/', $content, $matches)) {
                return $matches[1];
            }
        }

        return false;
    }

    /**
     * Check if the MU plugin needs to be installed or updated
     */
    public function check_mu_plugin_status()
    {
        $filesystem     = $this->get_filesystem();
        $mu_plugin_path = WPMU_PLUGIN_DIR . '/' . $this->mu_plugin_filename;

        // If plugin doesn't exist, install it
        if ( ! $filesystem->exists($mu_plugin_path)) {
            $this->install_mu_plugin();

            return;
        }

        // Check version and update if necessary
        $installed_version = $this->get_mu_plugin_version();
        if ($installed_version && version_compare($installed_version, $this->current_version, '<')) {
            $this->install_mu_plugin();
        }
    }
}