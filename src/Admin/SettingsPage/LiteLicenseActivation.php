<?php

namespace MailOptin\Core\Admin\SettingsPage;

class LiteLicenseActivation
{
    const slug = MAILOPTIN_LICENSE_SETTINGS_SLUG;

    public function __construct()
    {
        // plugins_loaded hook is used so it is shown as the last sub menu.
        add_action('plugins_loaded', function () {
            if (defined('MAILOPTIN_DETACH_LIBSODIUM')) return;
            add_action('admin_menu', array(__CLASS__, 'register_settings_page'));
            add_action('admin_init', [$this, 'perform_upgrade']);
        }, 199);
    }

    public static function register_settings_page()
    {
        add_submenu_page(
            MAILOPTIN_SETTINGS_SETTINGS_SLUG,
            __('License', 'mailoptin') . ' - MailOptin',
            __('License', 'mailoptin'),
            'manage_options',
            self::slug,
            array(__CLASS__, 'license_page')
        );
    }

    /**
     * License settings page
     */
    public static function license_page()
    {
        ?>
        <div class="wrap">
        <h2><?php _e('MailOptin License', 'mailoptin'); ?></h2>
        <!--	Output Settings error	-->
        <?php settings_errors(); ?>
        <?php ?>
        <div class="mo-banner"><?php _e('Upgrade to MailOptin Premium', 'mailoptin'); ?></div>
        <br/><br/><br/><br/>
        <form method="post">
            <table class="form-table">
                <tbody>
                <tr valign="top">
                    <th scope="row" valign="top"></th>
                    <td>
                        <input id="mo_plugin_license_key" name="mo_license_key" type="text" class="regular-text" value=""/>
                        <p class="description">
                            <label class="description" for="mo_plugin_license_key"><?php _e('Enter your license key and click the submit button to upgrade MailOptin premium.', 'mailoptin'); ?></label>
                        </p>
                    </td>
                </tr>
                <tr valign="top" id="license_Activate_th">
                    <th scope="row" valign="top"></th>
                    <td>
                        <input type="submit" class="button-secondary" name="mo_activate_license" value="<?php _e('Click to Upgrade to MailOptin Premium'); ?>"/>
                    </td>
                </tr>
                </tbody>
            </table>
            <?php wp_nonce_field('mo_plugin_nonce', 'mo_plugin_nonce'); ?>
        </form>
        <?php
    }

    public static function perform_upgrade()
    {
        if (!isset($_POST['mo_activate_license']) || empty($_POST['mo_license_key'])) return;

        // run a quick security check
        if (!check_admin_referer('mo_plugin_nonce', 'mo_plugin_nonce')) return wp_nonce_ays('');;

        $license_key = sanitize_key($_POST['mo_license_key']);

        $response = wp_remote_get(
            sprintf('https://my.mailoptin.io/?edd_action=get_version&item_id=%s&license=%s', EDD_MO_ITEM_ID, $license_key),
            ['timeout' => 15]
        );

        // make sure the response came back okay
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            $error = $response->get_error_message();
            if (empty($error)) {
                $error = __('Error fetching downloads. Please try again.', 'mailoptin');
            }

            return add_settings_error(self::slug, 'activation_error', $error);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        $plugin_transient = get_site_transient('update_plugins');
        $plugin_folder = plugin_basename(dirname(MAILOPTIN_SYSTEM_FILE_PATH));
        $plugin_file = basename(MAILOPTIN_SYSTEM_FILE_PATH);
        $temp_array = array(
            'slug' => $plugin_folder,
            'new_version' => $body['new_version'],
            'url' => $body['homepage'],
            'package' => $body['package'],
        );

        $temp_object = (object)$temp_array;
        $plugin_transient->response[$plugin_folder . '/' . $plugin_file] = $temp_object;
        set_site_transient('update_plugins', $plugin_transient);


        require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
        $title = __('MailOptin Upgrade to Premium', 'mailoptin');
        $plugin = 'mailoptin/mailoptin.php';
        $nonce = 'upgrade-plugin_' . $plugin;
        $url = 'update.php?action=upgrade-plugin&plugin=' . rawurlencode($plugin);
        $upgrader_skin = new \Plugin_Upgrader_Skin(compact('title', 'nonce', 'url', 'plugin'));
        $upgrader = new \Plugin_Upgrader($upgrader_skin);
        $upgrader->upgrade($plugin);
        wp_die(
            '', __('MailOptin Upgrade to Premiumzz', 'rocket'), [
                'response' => 200,
            ]
        );
    }

    /**
     * @return LiteLicenseActivation
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