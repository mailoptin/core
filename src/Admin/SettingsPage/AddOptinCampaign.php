<?php
/**
 * Copyright (C) 2016  Agbonghama Collins <me@w3guy.com>
 */

namespace MailOptin\Core\Admin\SettingsPage;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

use MailOptin\Core\Repositories\OptinThemesRepository;
use W3Guy\Custom_Settings_Page_Api;

class AddOptinCampaign extends AbstractSettingsPage
{
    /**
     * Build the settings page structure. I.e tab, sidebar.
     */
    public function settings_admin_page()
    {
        add_action('wp_cspa_before_closing_header', [$this, 'back_to_optin_overview']);
        add_action('wp_cspa_before_post_body_content', array($this, 'optin_theme_sub_header'), 10, 2);
        add_filter('wp_cspa_main_content_area', [$this, 'optin_form_list']);

        $instance = Custom_Settings_Page_Api::instance();
        $instance->page_header(__('Add New', 'mailoptin'));
        $instance->build(true, true);
    }

    /**
     * The optin type to open on: ?optin-type= when it's a known type, else lightbox.
     */
    public static function chosen_type()
    {
        $type = sanitize_key($_GET['optin-type'] ?? '');

        return in_array($type, ['lightbox', 'inpost', 'sidebar', 'bar', 'slidein'], true) ? $type : 'lightbox';
    }

    /**
     * Sub-menu header for optin theme types.
     */
    public function optin_theme_sub_header()
    {
        if (!empty($_GET['page']) && $_GET['page'] == MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_SLUG) : ?>
            <div class="mailoptin-optin-new-list mailoptin-optin-clear">
                <label for="mailoptin-add-optin-campaign-title"><?php _e('Campaign name', 'mailoptin'); ?></label>
                <input type="text" name="mailoptin-optin-campaign" id="mailoptin-add-optin-campaign-title" placeholder="<?php esc_attr_e('e.g. Spring newsletter pop-up', 'mailoptin') ?>">
                <span class="mo-optin-new-help"><?php _e('Only you see this name.', 'mailoptin'); ?></span>
            </div>
            <div id="mailoptin-sub-bar">
                <div class="mailoptin-new-toolbar mailoptin-clear">
                    <h4><?php _e('Type', 'mailoptin'); ?>
                        <span class="spinner mo-dash-spinner"></span>
                    </h4>
                    <span class="sr-only"><?php __('Loading...', 'mailoptin'); ?></span>
                    <ul class="mailoptin-design-options">
                        <li>
                            <a href="#" class="mo-select-optin-type<?php echo self::chosen_type() === 'lightbox' ? ' mailoptin-type-active' : ''; ?>" data-optin-type="lightbox">
                                <?php echo esc_html(\MailOptin\Core\Admin\UI::optin_type_label('lightbox')); ?>
                            </a>
                        </li>
                        <li>
                            <a href="#" class="mo-select-optin-type<?php echo self::chosen_type() === 'inpost' ? ' mailoptin-type-active' : ''; ?>" data-optin-type="inpost">
                                <?php echo esc_html(\MailOptin\Core\Admin\UI::optin_type_label('inpost')); ?>
                            </a>
                        </li>
                        <li>
                            <a href="#" class="mo-select-optin-type<?php echo self::chosen_type() === 'sidebar' ? ' mailoptin-type-active' : ''; ?>" data-optin-type="sidebar">
                                <?php echo esc_html(\MailOptin\Core\Admin\UI::optin_type_label('sidebar')); ?>
                            </a></li>
                        <li>
                            <a href="#" class="mo-select-optin-type<?php echo self::chosen_type() === 'bar' ? ' mailoptin-type-active' : ''; ?>" data-optin-type="bar">
                                <?php echo esc_html(\MailOptin\Core\Admin\UI::optin_type_label('bar')); ?>
                            </a>
                        </li>
                        <li>
                            <a href="#" class="mo-select-optin-type<?php echo self::chosen_type() === 'slidein' ? ' mailoptin-type-active' : ''; ?>" data-optin-type="slidein">
                                <?php echo esc_html(\MailOptin\Core\Admin\UI::optin_type_label('slidein')); ?>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        <?php endif;
    }

    /**
     * Display list of optin
     */
    public function optin_form_list()
    {
        // Lightbox by default, or the type chosen from the empty Optin Campaigns screen.
        $optin_type = self::chosen_type();

        echo '<div class="mailoptin-optin-themes mailoptin-optin-clear">';
        OptinThemesRepository::listing_display_template($optin_type);
        echo '</div>';
    }

    public function back_to_optin_overview()
    {
        $url = MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE;
        echo "<a class=\"add-new-h2\" style='margin-left: 15px;' href=\"$url\">" . __('All campaigns', 'mailoptin') . '</a>';
    }


    /**
     * @return AddOptinCampaign
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