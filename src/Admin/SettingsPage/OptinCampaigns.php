<?php

namespace MailOptin\Core\Admin\SettingsPage;

// Exit if accessed directly
if ( ! defined('ABSPATH')) {
    exit;
}

use function MailOptin\Core\moVarGET;
use W3Guy\Custom_Settings_Page_Api;

class OptinCampaigns extends AbstractSettingsPage
{
    /**
     * @var OptinCampaign_List
     */
    protected $optin_forms_instance;

    protected $settingsInstance;

    public function __construct()
    {
        // "view=add-new" is the Emails page's; on this page it means the optin Add New screen.
        add_action('admin_init', function () {
            if (moVarGET('page') === MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_SLUG && moVarGET('view') === 'add-new') {
                wp_safe_redirect(add_query_arg('view', 'add-new-optin', MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE));
                exit;
            }
        });

        add_action('mailoptin_admin_settings_page_pre', function ($active_menu) {

            if (in_array($active_menu, ['optin-campaigns', 'add-new-optin', 'import-optins'])) {

                $this->settingsInstance = Custom_Settings_Page_Api::instance();

                if ($active_menu === 'optin-campaigns') {

                    $this->settingsInstance->option_name(MO_OPTIN_CAMPAIGN_WP_OPTION_NAME);
                    $this->settingsInstance->sidebar($this->sidebar_args());

                    // Hook the OptinCampaign_List table to Custom_Settings_Page_Api main content filter.
                    add_action('wp_cspa_main_content_area', [$this, 'wp_list_table'], 10, 2);
                    add_action('wp_cspa_before_post_body_content', [$this, 'optin_theme_sub_header'], 10, 2);
                    add_action('wp_cspa_before_closing_header', [$this, 'add_new_optin_form_button']);
                }

                $this->settingsInstance->page_header(__('Optin Campaigns', 'mailoptin'));

                $this->set_settings_page_instance($this->settingsInstance);
            }
        });

        add_action('mailoptin_register_menu_page', array($this, 'register_settings_page'), 25);
        add_action('mailoptin_admin_settings_submenu_page_optin-campaigns', [$this, 'settings_admin_page_callback']);
        add_action('mailoptin_admin_settings_submenu_page_add-new-optin', [$this, 'settings_admin_page_callback']);
        add_action('mailoptin_admin_settings_submenu_page_import-optins', [$this, 'settings_admin_page_callback']);

        add_filter('set-screen-option', array($this, 'set_screen'), 10, 3);
        add_filter('set_screen_option_optin_forms_per_page', array($this, 'set_screen'), 10, 3);
    }

    public function register_settings_page()
    {
        $hook = add_submenu_page(
                MAILOPTIN_SETTINGS_SETTINGS_SLUG,
                __('Optin Campaigns - MailOptin', 'mailoptin'),
                __('Optin Campaigns', 'mailoptin'),
                \MailOptin\Core\get_capability(),
                MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_SLUG,
                array($this, 'admin_page_callback')
        );

        do_action("mailoptin_register_optin_campaign_settings_page", $hook);

        add_action("load-$hook", array($this, 'screen_option'));
    }

    public function default_header_menu()
    {
        return 'optin-campaigns';
    }

    /**
     * Sub-menu header for optin theme types.
     */
    public function optin_theme_sub_header()
    {
        if ( ! empty($_GET['page']) && $_GET['page'] == MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_SLUG) {
            $lightbox_url     = add_query_arg('optin-type', 'lightbox', MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE);
            $sidebar_url      = add_query_arg('optin-type', 'sidebar', MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE);
            $in_post          = add_query_arg('optin-type', 'inpost', MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE);
            $notification_bar = add_query_arg('optin-type', 'bar', MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE);
            $slide_in         = add_query_arg('optin-type', 'slidein', MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE);

            $all_menu_active               = ! isset($_GET['optin-type']) ? 'mailoptin-type-active' : null;
            $lightbox_menu_active          = isset($_GET['optin-type']) && $_GET['optin-type'] == 'lightbox' ? 'mailoptin-type-active' : null;
            $sidebar_menu_active           = isset($_GET['optin-type']) && $_GET['optin-type'] == 'sidebar' ? 'mailoptin-type-active' : null;
            $before_after_post_menu_active = isset($_GET['optin-type']) && $_GET['optin-type'] == 'inpost' ? 'mailoptin-type-active' : null;
            $notification_bar_menu_active  = isset($_GET['optin-type']) && $_GET['optin-type'] == 'bar' ? 'mailoptin-type-active' : null;
            $slide_in_menu_active          = isset($_GET['optin-type']) && $_GET['optin-type'] == 'slidein' ? 'mailoptin-type-active' : null;

            $tabs = [
                ''         => [MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE, $all_menu_active],
                'lightbox' => [$lightbox_url, $lightbox_menu_active],
                'inpost'   => [$in_post, $before_after_post_menu_active],
                'sidebar'  => [$sidebar_url, $sidebar_menu_active],
                'bar'      => [$notification_bar, $notification_bar_menu_active],
                'slidein'  => [$slide_in, $slide_in_menu_active],
            ];

            $counts = OptinCampaign_List::get_instance()->type_counts();
            ?>
            <div id="mailoptin-sub-bar">
                <div class="mailoptin-new-toolbar mailoptin-clear">
                    <h4 class="screen-reader-text"><?php _e('Show campaigns of type', 'mailoptin'); ?></h4>
                    <ul class="mailoptin-design-options">
                        <?php foreach ($tabs as $type => $tab) : ?>
                            <li>
                                <a href="<?php echo esc_url($tab[0]); ?>" class="<?php echo esc_attr($tab[1]); ?>"<?php echo $tab[1] ? ' aria-current="page"' : ''; ?>>
                                    <?php echo esc_html('' === $type ? __('All', 'mailoptin') : \MailOptin\Core\Admin\UI::optin_type_label($type)); ?>
                                    <span class="mo-ui-count"><?php echo esc_html(number_format_i18n(isset($counts[$type]) ? $counts[$type] : 0)); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php }
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
        if ('optin_forms_per_page' == $option) {
            return $value;
        }

        return $status;
    }

    /**
     * Screen options
     */
    public function screen_option()
    {
        if (isset($_GET['page']) && $_GET['page'] == MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_SLUG && ! isset($_GET['view'])) {

            $option = 'per_page';
            $args   = array(
                    'label'   => __('Optin Campaigns', 'mailoptin'),
                    'default' => 8,
                    'option'  => 'optin_forms_per_page',
            );

            add_screen_option($option, $args);
            $this->optin_forms_instance = OptinCampaign_List::get_instance();
        }
    }

    /**
     * Build the settings page structure. I.e tab, sidebar.
     */
    public function settings_admin_page_callback()
    {
        if ( ! empty($_GET['view']) && $_GET['view'] == 'add-new-optin') {
            AddOptinCampaign::get_instance()->settings_admin_page();
        } elseif ( ! empty($_GET['view']) && $_GET['view'] == 'import-optins') {
            OptinCampaignImport::get_instance()->settings_admin_page();
        } else {
            echo '<div class="mailoptin-data-listing">';
            $this->settingsInstance->build(defined('MAILOPTIN_DETACH_LIBSODIUM'));
            echo '</div>';

            $this->ab_split_test_form();
        }
    }

    public function ab_split_test_form()
    {
        ?>
        <div style="display: none">
            <div id="mo-optin-add-split">
                <div class="mo-modal">
                    <div class="mo-header">
                        <h2><?php _e('Create New Split Test', 'mailoptin'); ?></h2>
                    </div>
                    <div class="mo-content">
                        <p>
                            <label for="mo-variant-name"><?php _e('Variant Name', 'mailoptin'); ?></label>
                            <input type="text" id="mo-variant-name"> <input type="hidden" id="mo-split-parent-id">
                        </p>
                        <p>
                            <label for="mo-split-notes"><?php _e('Split Test Note', 'mailoptin'); ?></label>
                            <textarea id="mo-split-notes" rows="7"></textarea><br>
                            <span class="description mo-description"><?php _e('Useful for keeping track of changes between each split test you create.', 'mailoptin'); ?></span>
                        </p>
                        <p>
                            <input type="submit" class="button button-primary" id="mo-split-submit" value="<?php _e('Create Split Test', 'mailoptin'); ?>">
                            <img class="mo-spinner" id="mo-split-submit-spinner" style="margin:10px;display:none" src="<?php echo admin_url('images/spinner.gif'); ?>"/>
                        </p>
                        <div id="mo-split-submit-error" class="mailoptin-error" style="display:none;text-align:center;font-weight:normal;"><?php _e('An error occurred. Please try again.', 'mailoptin'); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function add_new_optin_form_button()
    {
        $url = add_query_arg('view', 'add-new-optin', MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE);
        echo "<a class=\"add-new-h2\" href=\"$url\">" . __('Add New', 'mailoptin') . '</a>';
        echo '<a class="add-new-h2" href="' . esc_url(OptinCampaignImport::url()) . '">' . esc_html__('Import', 'mailoptin') . '</a>';
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

        $this->optin_forms_instance->prepare_items();

        ob_start();
        $this->optin_forms_instance->display();

        return ob_get_clean();
    }

    /**
     * @return OptinCampaigns
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