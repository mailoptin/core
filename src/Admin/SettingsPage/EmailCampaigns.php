<?php

namespace MailOptin\Core\Admin\SettingsPage;

// Exit if accessed directly
if ( ! defined('ABSPATH')) {
    exit;
}

use W3Guy\Custom_Settings_Page_Api;
use MailOptin\Core;

use function MailOptin\Core\get_capability;
use function MailOptin\Core\moVarGET;

class EmailCampaigns extends AbstractSettingsPage
{
    /**
     * @var Email_Campaign_List
     */
    protected $email_campaigns_instance;

    protected $settingsInstance;

    public function __construct()
    {
        add_action('mailoptin_admin_settings_page_pre', function ($active_menu) {

            if (moVarGET('page') === MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG && in_array($active_menu, [
                            'post-notifications',
                            'broadcasts',
                            'campaign-log',
                            'add-new',
                            'add-new-email-automation',
                            'create-broadcast'
                    ])) {

                add_action('wp_cspa_before_closing_header', [$this, 'add_new_email_campaign']);

                $this->settingsInstance = Custom_Settings_Page_Api::instance();
                $this->settingsInstance->option_name(MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME);
                $this->settingsInstance->page_header(__('Emails', 'mailoptin'));
                if ( ! in_array($active_menu, ['add-new-email-automation', 'add-new', 'create-broadcast'])) {
                    $this->settingsInstance->sidebar($this->sidebar_args());
                }

                $this->set_settings_page_instance($this->settingsInstance);
            }
        });

        add_action('mailoptin_register_menu_page', array($this, 'register_menu_page'), 40);

        add_filter('set-screen-option', array($this, 'set_screen'), 10, 3);
        add_filter('set_screen_option_email_campaign_per_page', array($this, 'set_screen'), 10, 3);

        add_action(
                'mailoptin_admin_settings_submenu_page_post-notifications',
                [$this, 'settings_admin_page_callback']
        );
        add_action('mailoptin_admin_settings_submenu_page_add-new', [$this, 'settings_admin_page_callback']);
        add_action(
                'mailoptin_admin_settings_submenu_page_add-new-email-automation',
                [$this, 'settings_admin_page_callback']
        );
        add_action('mailoptin_admin_settings_submenu_page_create-broadcast', [$this, 'settings_admin_page_callback']);

        add_action('post_submitbox_misc_actions', [$this, 'new_publish_post_exclude_metabox']);
        add_action('save_post', [$this, 'save_new_publish_post_exclude']);
    }

    public function register_menu_page()
    {
        $hook = add_submenu_page(
                MAILOPTIN_SETTINGS_SETTINGS_SLUG,
                __('Emails - MailOptin', 'mailoptin'),
                __('Emails', 'mailoptin'),
                get_capability(),
                MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG,
                array($this, 'admin_page_callback')
        );

        add_action("load-$hook", array($this, 'screen_option'));

        do_action("mailoptin_register_email_campaign_settings_page", $hook);
    }

    public function default_header_menu()
    {
        return 'post-notifications';
    }

    public function header_menu_tabs()
    {
        $tabs = apply_filters('mailoptin_emails_settings_page_tabs', [
                20  => [
                        'id'    => 'post-notifications',
                        'url'   => MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE,
                        'label' => esc_html__('Post Notifications', 'wp-user-avatar')
                ],
                40  => [
                        'id'    => 'broadcasts',
                        'url'   => MAILOPTIN_EMAIL_NEWSLETTERS_SETTINGS_PAGE,
                        'label' => esc_html__('Broadcasts', 'wp-user-avatar')
                ],
                100 => [
                        'id'    => 'campaign-log',
                        'url'   => MAILOPTIN_CAMPAIGN_LOG_SETTINGS_PAGE,
                        'label' => esc_html__('Logs', 'wp-user-avatar')
                ]
        ]);

        ksort($tabs);

        return $tabs;
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
        if ('email_campaign_per_page' == $option) {
            return $value;
        }

        return $status;
    }

    /**
     * Screen options
     */
    public function screen_option()
    {
        if (isset($_GET['page']) && $_GET['page'] == MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG && ! isset($_GET['view'])) {
            $option = 'per_page';
            $args   = [
                    'label'   => __('Email Automation', 'mailoptin'),
                    'default' => 15,
                    'option'  => 'email_campaign_per_page',
            ];
            add_screen_option($option, $args);
            $this->email_campaigns_instance = Email_Campaign_List::get_instance();
        }
    }

    /**
     * Build the settings page structure. I.e tab, sidebar.
     */
    public function settings_admin_page_callback()
    {
        if (moVarGET('page') == MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG) {

            if (moVarGET('view') == 'add-new') {
                return AddNewEmail::get_instance()->settings_admin_page();
            }

            if (moVarGET('view') == 'add-new-email-automation') {
                return AddEmailCampaign::get_instance()->settings_admin_page();
            }

            if (moVarGET('view') == 'create-broadcast') {
                return AddNewsletter::get_instance()->settings_admin_page();
            }
        }

        // Hook the OptinCampaign_List table to Custom_Settings_Page_Api main content filter.
        add_action('wp_cspa_main_content_area', array($this, 'wp_list_table'), 10, 2);
        echo '<div class="mailoptin-data-listing">';
        $this->settingsInstance->page_header(__('Post Notifications', 'mailoptin'));
        $this->settingsInstance->build(defined('MAILOPTIN_DETACH_LIBSODIUM'));
        echo '</div>';
    }

    public function add_new_email_campaign()
    {
        if (
                isset($_GET['view']) &&
                in_array($_GET['view'], ['add-new-email-automation', 'add-new', 'create-broadcast', 'campaign-log'])
        ) {
            return;
        }

        $url = add_query_arg('view', 'add-new', MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE);
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
        if ($option_name != MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME) {
            return $content;
        }

        $this->email_campaigns_instance->prepare_items();

        $this->email_campaigns_instance->display();

        return ob_get_clean();
    }

    /**
     * @param \WP_Post $post
     */
    public function new_publish_post_exclude_metabox($post)
    {
        //Maybe abort early
        if ( ! Core\post_can_new_post_notification($post)) {
            return;
        }

        ?>
        <div style="text-align: left;margin: 10px;">
            <?php
            printf(
                    __('Disable %sMailOptin new post notification%s for this post.', 'mailoptin'),
                    '<strong>',
                    '</strong>'
            );

            wp_nonce_field('mo-disable-npp-nonce', 'mo-disable-npp-nonce');
            $val = get_post_meta($post->ID, '_mo_disable_npp', true);

            ?>
            <input type="hidden" name="mo-disable-npp" value="no">
            <input name="mo-disable-npp" id="mo-disable-npp" type="checkbox" class="tgl tgl-light" value="yes" <?php
            checked($val, 'yes'); ?>> <label for="mo-disable-npp" style="display:inline-block;" class="tgl-btn"></label>
        </div>
        <?php
    }

    public function save_new_publish_post_exclude($post_id)
    {
        // Check if our nonce is set.
        if ( ! isset($_POST['mo-disable-npp-nonce'])) {
            return;
        }

        // Verify that the nonce is valid.
        if ( ! wp_verify_nonce($_POST['mo-disable-npp-nonce'], 'mo-disable-npp-nonce')) {
            return;
        }

        // If this is an autosave, our form has not been submitted, so we don't want to do anything.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check the user's permissions.
        if (isset($_POST['post_type']) && 'page' == $_POST['post_type']) {
            if ( ! current_user_can('edit_page', $post_id)) {
                return;
            }
        }

        if ( ! current_user_can('edit_post', $post_id)) {
            return;
        }

        // Make sure that it is set.
        if ( ! isset($_POST['mo-disable-npp'])) {
            return;
        }

        // Sanitize user input.
        $val = sanitize_text_field($_POST['mo-disable-npp']);

        // Update the meta field in the database.
        update_post_meta($post_id, '_mo_disable_npp', $val);
    }

    /**
     * @return EmailCampaigns
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