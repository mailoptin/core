<?php

namespace MailOptin\Core\Admin\SettingsPage;

// Exit if accessed directly
use W3Guy\Custom_Settings_Page_Api;

use function MailOptin\Core\moVar;
use function MailOptin\Core\moVarGET;

if ( ! defined('ABSPATH')) {
    exit;
}

abstract class AbstractSettingsPage
{
    protected $option_name;

    public static $parent_menu_url_map = [];

    /** @var Custom_Settings_Page_Api */
    public $settings_page_instance;

    public function init_menu()
    {
        add_action('admin_menu', array($this, 'register_core_menu'));
    }

    private function getMenuIcon()
    {
        return 'data:image/svg+xml;base64,' . base64_encode('<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg" width="150" height="150" viewBox="0 0 11.16 11.16"  shape-rendering="geometricPrecision" image-rendering="optimizeQuality" fill-rule="evenodd" xmlns:v="https://vecta.io/nano"><path d="M.92.79V.8h0 .01l3.11 3.03 1.5 1.44L10.18.8c.04-.04.09-.07.15-.09.17-.07.36-.02.5.1.08.09.14.2.14.32v8.55c0 .44-.36.8-.8.8H.99c-.44 0-.8-.36-.8-.8V1.11h0c0-.24.2-.43.43-.43.12 0 .22.04.3.11zM2.3 5.14c-.3-.64.27-1.27.91-1.17.14.02.28.08.4.18l1.93 1.87 4.53-4.37c.05-.05.12-.08.19-.08a.28.28 0 0 1 .28.28v1.64L6.18 7.81c-.11.09-.21.17-.32.22-.21.12-.39.14-.62.03-.1-.04-.19-.11-.3-.19l-2.4-2.38a1.37 1.37 0 0 1-.24-.35z" fill="#a6aaad"/></svg>');
    }

    public function register_core_menu()
    {
        add_menu_page(
                __('MailOptin WordPress Plugin', 'mailoptin'),
                __('MailOptin', 'mailoptin'),
                \MailOptin\Core\get_capability(),
                MAILOPTIN_SETTINGS_SETTINGS_SLUG,
                '',
                $this->getMenuIcon()
        );

        do_action('mailoptin_register_menu_page_' . $this->active_menu_tab());

        do_action('mailoptin_register_menu_page');

        add_filter('admin_body_class', array($this, 'add_admin_body_class'));
    }

    /** --------------------------------------------------------------- */

    // commented out to prevent any fatal error
    //abstract function default_header_menu();

    public function default_header_menu()
    {
        return '';
    }

    public function header_menu_tabs()
    {
        return [];
    }

    public function settings_page_header($active_menu = '')
    {
        $logo_url = MAILOPTIN_ASSETS_URL . 'images/mailoptin.svg';
        ?>

        <div class="mailoptin-admin-wrap">
            <div class="mailoptin-header-items">
                <img src="<?= $logo_url ?>" alt="MailOptin">
                <?php if ( ! empty($this->settings_page_instance->page_header)): ?>
                    <h2 class="mailoptin-headers-main-title"><?php echo $this->settings_page_instance->page_header; ?></h2>
                <?php endif; ?>
                <?php $this->header_breadcrumbs(); ?>
            </div>
            <div class="mailoptin-headers-header-close">
                <div class="mo-admin-banner__helplinks">
                    <?php if (defined('MAILOPTIN_DETACH_LIBSODIUM')) : ?>
                        <span>
                            <a rel="noopener" href="https://mailoptin.io/submit-ticket/" target="_blank">
                                <?= __('Request Support', 'mailoptin'); ?>
                            </a>
                        </span>
                    <?php else: ?>
                        <span>
                            <a class="mailoptin-right-nav-active" rel="noopener" href="https://mailoptin.io/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=topmenu">
                                <?= __('Premium Upgrade', 'mailoptin'); ?>
                            </a>
                        </span>
                    <?php endif; ?>
                    <span>
                        <a rel="noopener" href="https://mailoptin.io/docs/" target="_blank">
                            <?= __('Help', 'mailoptin'); ?>
                        </a>
                    </span> <span>
                        <a rel="noopener" href="https://wordpress.org/support/view/plugin-reviews/mailoptin?filter=5#postform" target="_blank">
                            <?= __('Review', 'mailoptin'); ?>
                        </a>
                    </span>
                </div>
            </div>
        </div>
        <?php $this->settings_page_header_menus($active_menu); ?>
        <?php
        do_action('mailoptin_settings_page_header', $active_menu);
    }

    private function header_breadcrumbs()
    {
//        return '<nav class="mailoptin-headers-breadcrumbs">
//                    <span class="mailoptin-headers-crumb"><a href="#">Dashboard</a>
//                        <span class="mailoptin-headers-crumb-icon"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" class="mailoptin-headers-breadcrumb-icon" style="fill: #fff;"><path d="M4.90625 10.2734L8.17578 7.0039L4.90625 3.73438" stroke="#4B5563" stroke-width="1.08984" stroke-linecap="round" stroke-linejoin="round"></path></svg></span>
//                    </span>
//                    <span class="mailoptin-headers-crumb">Site Builder</span>
//                </nav>';
    }

    public function settings_page_header_menus($active_menu)
    {
        $menus = $this->header_menu_tabs();

        if (count($menus) < 2) return;
        ?>
        <div class="mailoptin-header-menus">
            <nav class="mailoptin-nav-tab-wrapper">
                <?php foreach ($menus as $menu) :
                    $id = esc_attr(moVar($menu, 'id', ''));
                    $url = esc_url_raw(! empty($menu['url']) ? $menu['url'] : add_query_arg('view', $id));
                    $url = esc_url(remove_query_arg(wp_removable_query_args(), $url));
                    self::$parent_menu_url_map[$id] = $url;
                    ?>
                    <a href="<?php echo $url; ?>" class="mailoptin-nav-tab<?= $id == $active_menu ? ' mailoptin-nav-active' : '' ?>">
                        <?php echo esc_attr($menu['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php
    }

    public function active_menu_tab()
    {
        if (strpos(moVarGET('page'), 'mailoptin') !== false) {
            return isset($_GET['view']) ? sanitize_text_field($_GET['view']) : $this->default_header_menu();
        }

        return false;
    }

    public function admin_page_callback()
    {
        $active_menu = $this->active_menu_tab();

        do_action('mailoptin_admin_settings_page_pre', $active_menu);

        $this->settings_page_header($active_menu);

        do_action('mailoptin_admin_settings_page_' . $active_menu);

        do_action('mailoptin_admin_settings_submenu_page_' . $active_menu);
    }

    /** --------------------------------------------------------------- */

    public function set_settings_page_instance($instance)
    {
        $this->settings_page_instance = $instance;
    }

    /**
     * Register mailoptin core settings.
     *
     * @param Custom_Settings_Page_Api $instance
     */
    public function register_core_settings(Custom_Settings_Page_Api $instance)
    {
        $this->settings_page_header();
    }

    /**
     * Adds admin body class to all admin pages created by the plugin.
     *
     * @param string $classes Space-separated list of CSS classes.
     *
     * @return string Filtered body classes.
     * @since 0.1.0
     *
     */
    public function add_admin_body_class($classes)
    {
        $current_screen = get_current_screen();

        if (empty ($current_screen)) return;

        if (false !== strpos($current_screen->id, 'mailoptin')) {
            // Leave space on both sides so other plugins do not conflict.
            $classes .= ' mailoptin-admin ';

            if (defined('MAILOPTIN_DETACH_LIBSODIUM')) {
                $classes .= ' mailoptin-premium ';
            } else {
                $classes .= ' mailoptin-lite ';
            }
        }

        return $classes;
    }

    public function sidebar_args()
    {
        $sidebar_args = [
                ['section_title' => esc_html__('Upgrade to Premium', 'mailoptin'), 'content' => self::pro_upsell()]
        ];

        if (defined('MAILOPTIN_DETACH_LIBSODIUM')) {
            unset($sidebar_args[0]);
        }

        return $sidebar_args;
    }


    public static function pro_upsell()
    {
        $features = [
                esc_html__('Notification Bar optin', 'mailoptin'),
                esc_html__('Slide-in / Scroll-trigger optin', 'mailoptin'),
                esc_html__('Optin A/B split testing', 'mailoptin'),
                esc_html__('Advanced page-level targeting rules', 'mailoptin'),
                esc_html__('Powerful content locking', 'mailoptin'),
                esc_html__('Convert leaving visitors with Exit-Intent', 'mailoptin'),
                esc_html__('Access to saved leads', 'mailoptin'),
                esc_html__('Advanced optin display rules (time on site, page-views, cookie, device, adblock & referrer detection etc.)', 'mailoptin'),
                esc_html__('Display optin based on WooCommerce cart, order total & products etc.', 'mailoptin'),
            //
                esc_html__('Send emails to subscribers in Constant Contact, Mailchimp, AWeber etc.', 'mailoptin'),
                esc_html__('Send emails to WooCommerce, subscription & membership customers', 'mailoptin'),
                esc_html__('Email LearnDash, MemberPress, LifterLMS, Tutor LMS, GiveWP, Restrict Content Pro & Paid Memberships Pro users', 'mailoptin'),
            //
                esc_html__('Advanced analytics & reports', 'mailoptin'),
                esc_html__('Spam protection with reCAPTCHA', 'mailoptin'),
                esc_html__('Facebook custom audience integration', 'mailoptin'),
                esc_html__('Form plugins integration', 'mailoptin') . ' (Gravity Forms, Contact Form 7, WPForms, Ninja Forms, Elementor, Formidable forms etc)',
                esc_html__('Google Analytics integration', 'mailoptin')
        ];

        $upsell_url = 'https://mailoptin.io/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=sidebar_upsell';

        $content = '<p>';
        $content .= sprintf(
                esc_html__('Save %s with coupon %s when you %supgrade to MailOptin Premium%s.', 'mailoptin'),
                '10%', '<code>10PERCENTOFF</code>', '<a style="text-decoration:none" target="_blank" href="' . $upsell_url . '">', '</a>'
        );
        $content .= '</p>';

        $content .= '<ul>';

        foreach ($features as $feature) :
            $content .= sprintf('<li>%s</li>', $feature);
        endforeach;

        $content .= '</ul>';

        $content .= '<a href="' . $upsell_url . '" target="__blank" class="button-primary">' . esc_html__('Get MailOptin Premium →', 'mailoptin') . '</a>';

        return $content;
    }
}