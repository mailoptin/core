<?php

namespace MailOptin\Core\Admin\SettingsPage;

use W3Guy\Custom_Settings_Page_Api;

class LicenseUpgrader
{
    public function __construct()
    {
        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) {

            add_action('mailoptin_admin_settings_page_license', [$this, 'admin_page']);

            add_action('admin_enqueue_scripts', [$this, 'settings_enqueues']);

            add_action('wp_ajax_mailoptin_connect_url', array($this, 'generate_url'));
        }

        add_action('wp_ajax_nopriv_mailoptin_connect_process', array($this, 'process'));
    }

    public function admin_page()
    {
        add_action('wp_cspa_main_content_area', array($this, 'admin_page_callback'), 10, 2);

        $instance = Custom_Settings_Page_Api::instance([], 'mailoptin_license', esc_html__('License', 'wp-user-avatar'));
        $instance->remove_white_design();
        $instance->remove_h2_header();
        $instance->build(true);
    }

    public function admin_page_callback()
    {
        $nonce = wp_create_nonce('mailoptin-connect-url');

        ob_start();

        ?>
        <style>
            .mailoptin-admin-wrap .wrap h2 {
                display: none;
            }

            .mailoptin-admin .remove_white_styling #post-body-content .form-table th {
                width: 200px !important;
            }

            .mailoptin-admin .remove_white_styling #post-body-content input[type=text] {
                width: 25em !important;
            }
        </style>

        <div class="mailoptin-lite-license-wrap">
            <p style="font-size: 110%;">
                <?php
                esc_html_e('You\'re using MailOptin Lite - no license needed. Enjoy! 😊', 'wp-user-avatar');
                ?>
            </p>

            <p class="description" style="margin-bottom: 8px;">
                <?php
                echo wp_kses_post(
                    sprintf(
                    /* translators: %1$s Opening anchor tag, do not translate. %2$s Closing anchor tag, do not translate. */
                        __(
                            'Already purchased? Simply %1$sretrieve your license key%2$s and enter it below to connect with MailOptin Pro.',
                            'wp-user-avatar'
                        ),
                        sprintf(
                            '<a href="%s" target="_blank" rel="noopener noreferrer">',
                            'https://my.mailoptin.io/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page'
                        ),
                        '</a>'
                    )
                );
                ?>
            </p>

            <div class="mailoptin-license-field">
                <input
                        type="text"
                        id="mailoptin-connect-license-key"
                        name="mailoptin-license-key"
                        value=""
                        class="regular-text"
                        style="line-height: 1; font-size: 1.15rem; padding: 10px;"
                />

                <button
                        class="button button-secondary mailoptin-license-button"
                        id="mailoptin-connect-license-submit"
                        data-connecting="<?php esc_attr_e('Connecting...', 'wp-user-avatar'); ?>"
                        data-connect="<?php esc_attr_e('Unlock Premium Features', 'wp-user-avatar'); ?>"
                >
                    <?php esc_html_e('Unlock Premium Features', 'wp-user-avatar'); ?>
                </button>

                <input type="hidden" name="mailoptin-action" value="mailoptin-connect"/>
                <input type="hidden" id="mailoptin-connect-license-nonce" name="mailoptin-connect-license-nonce" value="<?php echo esc_attr($nonce); ?>"/>
            </div>

            <div id="mailoptin-connect-license-feedback" class="mailoptin-license-message"></div>

            <div class="mailoptin-settings-upgrade">
                <div class="mailoptin-settings-upgrade__inner">
                    <span class="dashicons dashicons-unlock" style="font-size: 40px; width: 40px; height: 50px;"></span>
                    <h3>
                        <?php esc_html_e('Unlock Powerful Premium Features', 'wp-user-avatar'); ?>
                    </h3>

                    <ul>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            Notification Bar
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            Slide-In / Scroll-Trigger
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            A/B Split Test
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            Popup User Registration Form
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            Advanced Page-level Targeting
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            Powerful Content Locking
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            Exit-Intent
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            WooCommerce Display Rules
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            Email to List Subscribers
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            Emails MemberPress members
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            Advanced analytics &amp; reports
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            reCAPTCHA Spam Protection
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            Facebook Custom Audience
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            Form plugins integration
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            Google Analytics integration
                        </li>
                        <li>
                            <div class="dashicons dashicons-yes"></div>
                            And lots more
                        </li>
                    </ul>

                    <a href="https://mailoptin.io/pricing/?discount=10PPOFF&utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page" class="button button-primary button-large mailoptin-upgrade-btn mailoptin-upgrade-btn-large" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Upgrade to MailOptin Premium', 'wp-user-avatar'); ?>
                    </a>
                </div>

                <div class="mailoptin-upgrade-btn-subtext">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" role="img" aria-hidden="true" focusable="false">
                        <path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z"></path>
                    </svg>

                    <?php
                    echo wp_kses(
                        sprintf(
                        /* translators: %1$s Opening anchor tag, do not translate. %2$s Closing anchor tag, do not translate. */
                            __(
                                '<strong>Bonus</strong>: Loyal MailOptin Lite users get <u>10%% off</u> regular price using the coupon code <u>10PERCENTOFF</u>, automatically applied at checkout. %1$sUpgrade to Premium →%2$s',
                                'wp-user-avatar'
                            ),
                            sprintf(
                                '<a href="%s" rel="noopener noreferrer" target="_blank">',
                                'https://mailoptin.com/pricing/?utm_source=wp_dashboard&utm_medium=retrieve_license&utm_campaign=lite_license_page'
                            ),
                            '</a>'
                        ),
                        array(
                            'a'      => array(
                                'href'   => true,
                                'rel'    => true,
                                'target' => true,
                            ),
                            'strong' => array(),
                            'u'      => array(),
                        )
                    );
                    ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function settings_enqueues()
    {
        wp_enqueue_script(
            'mailoptin-license-connect',
            MAILOPTIN_ASSETS_URL . "/js/admin/license.js",
            ['jquery'],
            MAILOPTIN_VERSION_NUMBER,
            true
        );
    }

    public function generate_url()
    {
        check_ajax_referer('mailoptin-connect-url', 'nonce');

        // Check for permissions.
        if ( ! current_user_can('install_plugins')) {
            wp_send_json_error(['message' => esc_html__('You are not allowed to install plugins.', 'wp-user-avatar')]);
        }

        $key = ! empty($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';

        if (empty($key)) {
            wp_send_json_error(['message' => esc_html__('Please enter your license key to connect.', 'wp-user-avatar')]);
        }

        if (ExtensionManager::is_premium()) {
            wp_send_json_error(['message' => esc_html__('Only the Lite version can be upgraded.', 'wp-user-avatar')]);
        }

        $active = activate_plugin('profilepress-pro/profilepress-pro.php', false, false, true);

        if ( ! is_wp_error($active)) {

            update_option('mailoptin_license_key', $key);

            wp_send_json_success([
                'message' => \esc_html__('You already have MailOptin Pro installed! Activating it now', 'wp-user-avatar'),
                'reload'  => true,
            ]);
        }

        $oth = hash('sha512', wp_rand());

        update_option('mailoptin_connect_token', $oth);
        update_option('mailoptin_license_key', $key);

        $version  = PPRESS_VERSION_NUMBER;
        $endpoint = admin_url('admin-ajax.php');
        $redirect = PPRESS_SETTINGS_SETTING_GENERAL_PAGE;
        $url      = add_query_arg(
            [
                'key'      => $key,
                'oth'      => $oth,
                'endpoint' => $endpoint,
                'version'  => $version,
                'siteurl'  => \admin_url(),
                'homeurl'  => \home_url(),
                'redirect' => rawurldecode(base64_encode($redirect)), // phpcs:ignore
                'v'        => 1,
            ],
            'https://upgrade.profilepress.com'
        );

        wp_send_json_success(['url' => $url]);
    }

    public function process()
    {
        $error = wp_kses(
            sprintf(
            /* translators: %1$s Opening anchor tag, do not translate. %2$s Closing anchor tag, do not translate. */
                __(
                    'Oops! We could not automatically install an upgrade. Please download the plugin from profilepress.com and install it manually.',
                    'wp-user-avatar'
                )
            ),
            [
                'a' => [
                    'target' => true,
                    'href'   => true,
                ],
            ]
        );

        $post_oth = ! empty($_REQUEST['oth']) ? sanitize_text_field($_REQUEST['oth']) : '';
        $post_url = ! empty($_REQUEST['file']) ? esc_url_raw($_REQUEST['file']) : '';

        $license = get_option('mailoptin_license_key', '');

        if (empty($post_oth) || empty($post_url)) {
            wp_send_json_error(['message' => $error, 'code_err' => '1']);
        }

        $oth = get_option('mailoptin_connect_token');

        if (empty($oth)) {
            wp_send_json_error(['message' => $error, 'code_err' => '2']);
        }

        if ( ! hash_equals($oth, $post_oth)) {
            wp_send_json_error(['message' => $error, 'code_err' => '3']);
        }

        delete_option('mailoptin_connect_token');

        // Set the current screen to avoid undefined notices.
        set_current_screen('profilepress_page_mailoptin-config');

        $url = PPRESS_SETTINGS_SETTING_GENERAL_PAGE;

        // Verify pro not activated.
        if (ExtensionManager::is_premium()) {
            wp_send_json_success(esc_html__('Plugin installed & activated.', 'wp-user-avatar'));
        }

        // Verify pro not installed.
        $active = activate_plugin('profilepress-pro/profilepress-pro.php', $url, false, true);

        if ( ! is_wp_error($active)) {

            wp_send_json_success([
                'message'  => esc_html__('Plugin installed & activated.', 'wp-user-avatar'),
                'code_err' => '3.5'
            ]);
        }

        $creds = request_filesystem_credentials($url, '', false, false, null);

        // Check for file system permissions.
        if (false === $creds || ! \WP_Filesystem($creds)) {
            wp_send_json_error(['message' => $error, 'code_err' => '4']);
        }

        /*
         * We do not need any extra credentials if we have gotten this far, so let's install the plugin.
         */

        // Do not allow WordPress to search/download translations, as this will break JS output.
        remove_action('upgrader_process_complete', ['Language_Pack_Upgrader', 'async_upgrade'], 20);

        // Create the plugin upgrader with our custom skin.
        $installer = new PluginSilentUpgrader(new PluginSilentUpgraderSkin());

        // Error check.
        if ( ! method_exists($installer, 'install')) {
            wp_send_json_error(['message' => $error, 'code_err' => '5']);
        }

        if (empty($license)) {
            wp_send_json_error([
                'message'  => esc_html__('You are not licensed.', 'wp-user-avatar'),
                'code_err' => '6'
            ]);
        }

        $installer->install($post_url);

        // Flush the cache and return the newly installed plugin basename.
        wp_cache_flush();

        $plugin_basename = $installer->plugin_info();

        if ($plugin_basename) {

            // Activate the plugin silently.
            $activated = activate_plugin($plugin_basename, '', false, true);

            if ( ! is_wp_error($activated)) {
                wp_send_json_success(esc_html__('Plugin installed & activated.', 'wp-user-avatar'));
            }
        }

        wp_send_json_error(['message' => $error, 'code_err' => '7']);
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
