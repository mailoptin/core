<?php

namespace MailOptin\Core\Admin\SettingsPage;

/**
 * Tracking functions for reporting plugin usage to the MailOptin site for users that have opted in
 *
 * @copyright   Copyright (c) 2015, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.8.2
 */

// Exit if accessed directly
if ( ! defined('ABSPATH')) exit;


/**
 * Usage tracking
 *
 * @access public
 * @since  1.8.2
 * @return void
 */
class UsageTracking
{
    /**
     * The data to send to the MAILOPTIN site
     *
     * @access private
     */
    private $data;

    /**
     * Get things going
     *
     * @access public
     */
    public function __construct()
    {
        add_action('init', array($this, 'schedule_send'));
        add_action('mailoptin_admin_notices', function () {
            add_action('admin_notices', array($this, 'admin_notice'));
        });

        add_action('wp_cspa_persist_settings', array($this, 'check_for_settings_optin'), 10, 2);
        add_action('admin_init', array($this, 'act_on_tracking_decision'));

        add_action('init', [$this, 'create_recurring_schedule']);

        add_filter('cron_schedules', [$this, 'cron_add_weekly']);
    }

    public function cron_add_weekly($schedules)
    {
        $schedules['moweekly'] = array(
            'interval' => 604800,
            'display'  => 'Weekly'
        );

        return $schedules;
    }

    public function create_recurring_schedule()
    {
        //check if event scheduled before
        if ( ! wp_next_scheduled('mo_recurring_cron_job'))
            //schedule event to run after every day
            wp_schedule_event(time(), 'moweekly', 'mo_recurring_cron_job');
    }

    /**
     * Check if the user has opted into tracking
     *
     * @access private
     * @return bool
     */
    private function tracking_allowed()
    {
        return \MailOptin\Core\plugin_settings()->allow_tracking(false) == 'true';
    }

    /**
     * Setup the data that is going to be tracked
     *
     * @access private
     * @return void
     */
    private function setup_data()
    {
        $data = array();

        // Retrieve current theme info
        $theme_data = wp_get_theme();
        $theme      = $theme_data->Name . ' ' . $theme_data->Version;

        $data['php_version'] = phpversion();
        $data['edd_version'] = MAILOPTIN_VERSION_NUMBER;
        $data['wp_version']  = get_bloginfo('version');
        $data['server']      = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '';

        $data['install_date'] = get_option('mo_install_date', 'not set');

        $data['multisite'] = is_multisite();
        $data['url']       = home_url();
        $data['theme']     = $theme;
        $data['email']     = get_bloginfo('admin_email');

        // Retrieve current plugin information
        if ( ! function_exists('get_plugins')) {
            include ABSPATH . '/wp-admin/includes/plugin.php';
        }

        $plugins        = array_keys(get_plugins());
        $active_plugins = get_option('active_plugins', array());

        foreach ($plugins as $key => $plugin) {
            if (in_array($plugin, $active_plugins)) {
                // Remove active plugins from list so we can show active and inactive separately
                unset($plugins[$key]);
            }
        }

        $data['active_plugins']   = $active_plugins;
        $data['inactive_plugins'] = $plugins;
        $data['locale']           = ($data['wp_version'] >= 4.7) ? get_user_locale() : get_locale();

        $this->data = $data;
    }

    /**
     * Send the data to the MAILOPTIN server
     *
     * @access private
     * @return mixed
     */
    public function send_checkin($override = false, $ignore_last_checkin = false)
    {
        $home_url = trailingslashit(home_url());
        // Allows us to stop our own site from checking in, and a filter for our additional sites
        if ($home_url === 'https://my.mailoptin.io/' || apply_filters('mo_disable_tracking_checkin', false)) {
            return false;
        }

        if ( ! $this->tracking_allowed() && ! $override) {
            return false;
        }

        // Send a maximum of once per week
        $last_send = $this->get_last_send();
        if (is_numeric($last_send) && $last_send > strtotime('-1 week') && ! $ignore_last_checkin) {
            return false;
        }

        $this->setup_data();

        $request = wp_remote_post('https://my.mailoptin.io/?edd_action=checkin', array(
            'method'      => 'POST',
            'timeout'     => 20,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'body'        => $this->data,
            'user-agent'  => 'EDD/' . MAILOPTIN_VERSION_NUMBER . '; ' . get_bloginfo('url')
        ));

        if (is_wp_error($request)) {
            return $request;
        }

        update_option('mailoptin_tracking_last_send', time());

        return true;
    }

    /**
     * Check for a new opt-in on settings save
     *
     * This runs during the sanitation of General settings, thus the return
     *
     * @access public
     * @return array
     */
    public function check_for_settings_optin($input, $option_name)
    {
        // Send an initial check in on settings save
        if ($option_name == MAILOPTIN_SETTINGS_DB_OPTION_NAME && isset($input['allow_tracking']) && $input['allow_tracking'] == 'true') {
            $this->send_checkin(true);
        }
    }

    public function act_on_tracking_decision()
    {
        if (isset($_GET['edd_action'])) {
            if ($_GET['edd_action'] == 'mo_opt_into_tracking') {
                $this->check_for_optin();
            }

            if ($_GET['edd_action'] == 'mo_opt_out_of_tracking') {
                $this->check_for_optout();
            }

        }
    }

    /**
     * Check for a new opt-in via the admin notice
     *
     * @access public
     * @return void
     */
    public function check_for_optin()
    {
        \MailOptin\Core\plugin_settings()->update('allow_tracking', 'true');

        $this->send_checkin(true);

        update_option('mailoptin_tracking_notice', '1');

    }

    /**
     * Check for a new opt-in via the admin notice
     *
     * @access public
     * @return void
     */
    public function check_for_optout()
    {
        \MailOptin\Core\plugin_settings()->delete('allow_tracking');
        update_option('mailoptin_tracking_notice', '1');
        wp_redirect(remove_query_arg('edd_action'));
        exit;
    }

    /**
     * Get the last time a checkin was sent
     *
     * @access private
     * @return false|string
     */
    private function get_last_send()
    {
        return get_option('mailoptin_tracking_last_send');
    }

    /**
     * Schedule a weekly checkin
     *
     * @access public
     * @return void
     */
    public function schedule_send()
    {
        // We send once a week (while tracking is allowed) to check in, which can be used to determine active sites
        add_action('mo_recurring_cron_job', array($this, 'send_checkin'));
    }

    /**
     * Display the admin notice to users that have not opted-in or out
     *
     * @access public
     * @return void
     */
    public function admin_notice()
    {
        $hide_notice = get_option('mailoptin_tracking_notice');

        if ($hide_notice) {
            return;
        }

        if (self::tracking_allowed()) {
            return;
        }

        if ( ! current_user_can('manage_options')) {
            return;
        }

        if (stristr(network_site_url('/'), 'dev') !== false ||
            stristr(network_site_url('/'), 'localhost') !== false ||
            stristr(network_site_url('/'), ':8888') !== false // This is common with MAMP on OS X
        ) {
            update_option('mailoptin_tracking_notice', '1');
        } else {


            $optin_url  = add_query_arg('edd_action', 'mo_opt_into_tracking');
            $optout_url = add_query_arg('edd_action', 'mo_opt_out_of_tracking');

            $source    = substr(md5(get_bloginfo('name')), 0, 10);
            $store_url = 'https://mailoptin.io/pricing/?utm_source=' . $source . '&utm_medium=admin&utm_term=notice&utm_campaign=MailOptinUsageTracking';

            echo '<div class="updated"><p>';
            printf(
                __('Allow MailOptin to track plugin usage. We guarantee no sensitive data is collected. Opt-in to tracking immediately be emailed a 15%s discount for <a href="%s">plugin upgrade</a>.', 'mailoptin'),
                '%', $store_url
            );
            echo '</p><p><a href="' . esc_url($optin_url) . '" class="button-primary">' . __('Sure! I\'d love to help', 'mailoptin') . '</a>';
            echo '&nbsp;<a href="' . esc_url($optout_url) . '" class="button-secondary">' . __('No thanks', 'mailoptin') . '</a>';
            echo '</p></div>';
        }
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