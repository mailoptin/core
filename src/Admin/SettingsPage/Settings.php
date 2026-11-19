<?php

namespace MailOptin\Core\Admin\SettingsPage;

// Exit if accessed directly
use MailOptin\Core\RegisterActivation\CreateDBTables;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use W3Guy\Custom_Settings_Page_Api;

use function MailOptin\Core\get_capability;
use function MailOptin\Core\moVar;
use function MailOptin\Core\moVarGET;

if ( ! defined('ABSPATH')) {
    exit;
}

class Settings extends AbstractSettingsPage
{
    protected $instance;

    public function __construct()
    {
        $this->init_menu();

        add_action('mailoptin_admin_settings_page_pre', function ($active_menu) {

            if (in_array($active_menu, ['general'])) {
                $this->instance = Custom_Settings_Page_Api::instance([], MAILOPTIN_SETTINGS_DB_OPTION_NAME, __('Settings', 'mailoptin'));
                $this->set_settings_page_instance($this->instance);
            }
        });

        add_action('mailoptin_register_menu_page', array($this, 'register_menu_page'), 10);
        add_action('wp_cspa_persist_settings', array($this, 'check_for_mailoptin_affiliate_check'), 10, 2);
        add_action('admin_init', [$this, 'clear_optin_cache']);

        add_action('admin_init', [$this, 'install_missing_db_tables']);

        add_action('mailoptin_admin_settings_submenu_page_general', [$this, 'settings_admin_page_callback']);
    }

    public function register_menu_page()
    {
        add_submenu_page(
            MAILOPTIN_SETTINGS_SETTINGS_SLUG,
            __('Settings - MailOptin', 'mailoptin'),
            __('Settings', 'mailoptin'),
            get_capability(),
            MAILOPTIN_SETTINGS_SETTINGS_SLUG,
            array($this, 'admin_page_callback')
        );
    }

    public function default_header_menu()
    {
        return apply_filters('mailoptin_settings_default_header_menu', 'general');
    }

    public function header_menu_tabs()
    {
        $tabs = apply_filters(
                'mailoptin_settings_header_menu_tabs', [
            10 => ['id' => 'general', 'url' => MAILOPTIN_SETTINGS_SETTINGS_GENERAL_PAGE, 'label' => esc_html__('Settings', 'wp-user-avatar')]
        ]);

        ksort($tabs);

        return $tabs;
    }

    /**
     * When clear cache button is clicked, do justice.
     */
    public function clear_optin_cache()
    {
        if (defined('DOING_AJAX')) return;

        if (isset($_GET['clear-optin-cache']) && $_GET['clear-optin-cache'] == 'true' && \MailOptin\Core\current_user_has_privilege()) {
            check_admin_referer('mo_clear_optin_cache');
            OptinCampaignsRepository::burst_all_cache();
            wp_safe_redirect(add_query_arg('optin-cache', 'cleared', MAILOPTIN_SETTINGS_SETTINGS_GENERAL_PAGE));
            exit;
        }
    }

    /**
     * If changes is made to the mailoptin affiliate url field, clear cache.
     */
    public function check_for_mailoptin_affiliate_check($input, $option_name)
    {
        // Send an initial check in on settings save
        $old_data = get_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, []);
        if ( ! is_array($old_data)) {
            $old_data = [];
        }
        $old_data = moVar($old_data, 'mailoptin_affiliate_url', '');
        $new_data = moVar($input, 'mailoptin_affiliate_url', '');

        if ($option_name == MAILOPTIN_SETTINGS_DB_OPTION_NAME && $old_data != $new_data) {
            OptinCampaignsRepository::burst_all_cache();
        }
    }

    public function settings_admin_page_callback()
    {
        $clear_optin_cache_url = wp_nonce_url(
            add_query_arg('clear-optin-cache', 'true', MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE),
            'mo_clear_optin_cache'
        );

        $fix_db_url = wp_nonce_url(
            add_query_arg('mo-install-missing-db', 'true', MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE),
            'mo_install_missing_db_tables'
        );

        $args = [
            'general_settings'        => apply_filters('mailoptin_settings_general_settings_page', [
                    'tab_title'                 => __('General', 'mailoptin'),
                    'section_title'             => __('General Settings', 'mailoptin'),
                    'remove_plugin_data'        => [
                        'type'        => 'checkbox',
                        'label'          => __('When MailOptin is deleted', 'mailoptin'),
                        'checkbox_label' => __('Delete all MailOptin data too', 'mailoptin'),
                        'description'    => __('Campaigns, subscribers, emails and settings are removed for good. Leave this off if you might reinstall.', 'mailoptin'),
                    ],
                    'clear_optin_cache'         => [
                        'type'        => 'custom_field_block',
                        'label'       => __('Design cache', 'mailoptin'),
                        'data'        => "<a href='$clear_optin_cache_url' class='button action'>" . __('Clear cache', 'mailoptin') . '</a>',
                        'description' => sprintf(
                                             __('MailOptin saves each %scampaign%s\'s design so pages load fast. Clear it if a change you made, or a new list in your email service, isn\'t showing on the site.', 'mailoptin'),
                                             '<a href="' . MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE . '">',
                                             '</a>'
                                         ),
                    ],
                    'switch_customizer_loader'  => [
                        'type'           => 'checkbox',
                        'checkbox_label' => __('Use the backup loader', 'mailoptin'),
                        'label'          => __('Customizer loading', 'mailoptin'),
                        'description'    => __('Turn this on if the campaign Customizer doesn\'t open or stays blank.', 'mailoptin'),
                    ],
                    'enable_safe_mode'          => [
                        'type'           => 'checkbox',
                        'checkbox_label' => __('Load the Customizer without other plugins\' scripts', 'mailoptin'),
                        'label'          => __('Safe mode', 'mailoptin'),
                        'description'    => __('Try this if the backup loader above didn\'t fix the Customizer.', 'mailoptin'),
                    ],
                    'mailoptin_affiliate_url'   => [
                        'type'        => 'text',
                        'label'       => __('Affiliate link', 'mailoptin'),
                        'description' => sprintf(
                            __('Earn a commission when people buy MailOptin through your link. %1$sJoin the affiliate program%2$s, then paste your link here: it replaces the "Powered by MailOptin" link on your campaigns.', 'mailoptin'),
                            '<a href="https://mailoptin.io/affiliates/" target="_blank">',
                            '</a>'
                        ),
                    ],
                    'install_missing_db_tables' => [
                        'type'  => 'custom_field_block',
                        'label' => __('Database tables', 'mailoptin'),
                        'data'  => "<a href='$fix_db_url' class='button action ppress-confirm-delete'>" . __('Repair tables', 'mailoptin') . '</a>',
                        'description' => __('Creates any MailOptin table that\'s missing. Use it if support asks you to, or after a failed update.', 'mailoptin'),
                    ]
                ]
            ),
            'optin_campaign_settings' => apply_filters('mailoptin_settings_optin_campaign_settings_page', [
                    'tab_title' => __('Optin campaigns', 'mailoptin'),
                    [
                        'section_title'               => __('Optin campaigns', 'mailoptin'),
                        'disable_impression_tracking' => [
                            'type'           => 'checkbox',
                            'label'          => __('Counting views', 'mailoptin'),
                            'checkbox_label' => __('Don\'t count views', 'mailoptin'),
                            'description'    => esc_html__('Saves a request per page view. Views and conversion rates stop updating.', 'mailoptin')
                        ],
                        'dequeue_google_font'         => [
                            'type'           => 'checkbox',
                            'label'          => __('Google Fonts', 'mailoptin'),
                            'checkbox_label' => __('Don\'t load Google Fonts', 'mailoptin'),
                            'description'    => esc_html__('Campaigns then use your theme\'s fonts.', 'mailoptin')
                        ],
                        'disable_optin_error_emails' => [
                            'type' => 'checkbox',
                            'label' => __('Error emails', 'mailoptin'),
                            'checkbox_label' => __('Don\'t email me when a signup fails', 'mailoptin'),
                            'description' => esc_html__('Failed signups are still kept under Submissions.', 'mailoptin')
                        ],
                        'enable_async_optin' => [
                                'type'           => 'checkbox',
                                'label'          => __('Background signups', 'mailoptin'),
                                'checkbox_label' => __('Add subscribers in the background', 'mailoptin'),
                                'description' => esc_html__('For signups that come with something else, like registering an account or a WooCommerce checkout, so sending them to your email service doesn\'t slow that down.', 'mailoptin')
                        ],
                        'global_cookie'               => [
                            'type'        => 'number',
                            'value'       => 0,
                            'label'       => __('After a visitor closes one', 'mailoptin'),
                            'description' => __('Days to show them no campaigns at all after they close any campaign, e.g. 30. 0 shows the others as usual.', 'mailoptin'),
                        ],
                        'global_success_cookie'       => [
                            'type'        => 'number',
                            'value'       => 0,
                            'label'       => __('After a visitor subscribes', 'mailoptin'),
                            'description' => __('Days to show them no campaigns at all after they subscribe through any campaign, e.g. 30. 0 shows the others as usual.', 'mailoptin'),
                        ],
                    ]
                ]
            ),
            'email_campaign_settings' => apply_filters('mailoptin_settings_email_campaign_settings_page', [
                    'tab_title'         => __('Emails', 'mailoptin'),
                    'section_title'     => __('Email Campaign Settings', 'mailoptin'),
                    'from_name'         => [
                        'type'        => 'text',
                        'label'       => __('From Name', 'mailoptin'),
                        'description' => sprintf(__('Enter the sender name to be used as the "From Name".', 'mailoptin')),
                    ],
                    'from_email'        => [
                        'type'        => 'text',
                        'label'       => __('From Email', 'mailoptin'),
                        'description' => __('Enter the email address to be used as the "From Email"', 'mailoptin'),
                    ],
                    'reply_to'          => [
                        'type'        => 'text',
                        'label'       => __('Reply To', 'mailoptin'),
                        'description' => __('Enter the email address to be used as the "Reply To"', 'mailoptin'),
                    ],
                    'company_name'      => [
                        'type'        => 'text',
                        'label'       => __('Company / Organization', 'mailoptin'),
                        'description' => __('Enter the name of your company or organization.', 'mailoptin'),
                    ],
                    'company_address'   => [
                        'type'  => 'text',
                        'label' => __('Address', 'mailoptin'),
                    ],
                    'company_address_2' => [
                        'type'  => 'text',
                        'label' => __('Address 2', 'mailoptin'),
                    ],
                    'company_city'      => [
                        'type'  => 'text',
                        'label' => __('City', 'mailoptin'),
                    ],
                    'company_state'     => [
                        'type'  => 'text',
                        'label' => __('State / Province / Region', 'mailoptin'),
                    ],
                    'company_zip'       => [
                        'type'  => 'text',
                        'label' => __('Zip / Postal code', 'mailoptin')
                    ],
                    'company_country'   => [
                        'type'    => 'select',
                        'label'   => __('Country', 'mailoptin'),
                        'options' => \MailOptin\Core\countries_array()
                    ]
                ]
            )
        ];

        do_action('mailoptin_before_settings_page', MAILOPTIN_SETTINGS_DB_OPTION_NAME);
        $settings_args    = apply_filters('mailoptin_settings_page', $args);
        $nav_tabs         = '';
        $tab_content_area = '';

        if ( ! empty($settings_args)) {
            $instance = $this->instance;
            foreach ($settings_args as $key => $settings_arg) {
                $tab_title     = $settings_arg['tab_title'];
                $section_title = $settings_arg['tab_title'];
                unset($settings_arg['tab_title']);
                unset($settings_arg['section_title']);
                $nav_tabs .= sprintf('<a href="#%1$s" class="nav-tab" id="%1$s-tab"><span class="dashicons dashicons-admin-generic"></span> %2$s</a>', $key, $tab_title);

                if (isset($settings_arg[0]['section_title'])) {
                    $tab_content_area .= sprintf('<div id="%s" class="mailoptin-group-wrapper">', $key);
                    foreach ($settings_arg as $single_arg) {
                        $tab_content_area .= $instance->metax_box_instance($single_arg);
                    }
                } else {
                    $settings_arg['section_title'] = $section_title;
                    $tab_content_area              .= sprintf('<div id="%s" class="mailoptin-group-wrapper">', $key);
                    $tab_content_area              .= $instance->metax_box_instance($settings_arg);
                }
                $tab_content_area .= '</div>';
            }

            $instance->persist_plugin_settings();
            $instance->do_settings_errors();
            settings_errors('wp_csa_notice');
            echo '<div class="wrap">';
            $instance->settings_page_heading();
            echo '<div class="mailoptin-settings-wrap" data-option-name="' . MAILOPTIN_SETTINGS_DB_OPTION_NAME . '">';
            echo '<h2 class="nav-tab-wrapper">' . $nav_tabs . '</h2>';
            echo '<div class="metabox-holder mailoptin-tab-settings">';
            echo '<form method="post">';
            $instance->nonce_field();
            echo $tab_content_area;
            echo '</form>';
            echo '</div>';
            echo '</div>';
            echo '</div>';

            do_action('mailoptin_after_settings_page', MAILOPTIN_SETTINGS_DB_OPTION_NAME);
        }
    }

    public function install_missing_db_tables()
    {
        if (defined('DOING_AJAX')) return;

        if (moVarGET('mo-install-missing-db') == 'true' && current_user_can('manage_options')) {

            check_admin_referer('mo_install_missing_db_tables');

            delete_option('mo_db_ver');

            CreateDBTables::make();

            wp_safe_redirect(add_query_arg('settings-updated', 'true', MAILOPTIN_SETTINGS_SETTINGS_GENERAL_PAGE));
            exit;
        }
    }

    /**
     * @return Settings
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
