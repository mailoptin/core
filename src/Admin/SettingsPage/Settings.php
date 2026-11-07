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
                        'label'       => __('Remove Data on Uninstall', 'mailoptin'),
                        'description' => __('Check this box if you would like MailOptin to completely remove all of its data when uninstalled.', 'mailoptin'),
                    ],
                    'clear_optin_cache'         => [
                        'type'        => 'custom_field_block',
                        'label'       => __('Clear Cache', 'mailoptin'),
                        'data'        => "<a href='$clear_optin_cache_url' class='button action'>" . __('Clear Cache', 'mailoptin') . '</a>',
                        'description' => '<p class="description">' .
                                         sprintf(
                                             __('Each time you create and make changes to your %soptin campaigns%s, MailOptin caches the designs so it does not hurt your website speed and performance. If updates to your connected email marketing list or changes to your campaigns are not reflected on your website frontend, use this button to clear the cache.', 'mailoptin'),
                                             '<a href="' . MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE . '">',
                                             '</a>'
                                         ) .
                                         '</p>',
                    ],
                    'switch_customizer_loader'  => [
                        'type'           => 'checkbox',
                        'checkbox_label' => __('Enable', 'mailoptin'),
                        'label'          => __('Switch Customizer Loader Method', 'mailoptin'),
                        'description'    => __('Check this if you are having problem with Customizer not loading properly.', 'mailoptin'),
                    ],
                    'enable_safe_mode'          => [
                        'type'           => 'checkbox',
                        'checkbox_label' => __('Enable', 'mailoptin'),
                        'label'          => __('Safe Mode', 'mailoptin'),
                        'description'    => __('Check this if you are having problem with Customizer and enabling "Switch Customizer Loader Method" did not fix the issue.', 'mailoptin'),
                    ],
                    'mailoptin_affiliate_url'   => [
                        'type'        => 'text',
                        'label'       => __('MailOptin Affiliate Link', 'mailoptin'),
                        'description' => sprintf(
                            __('You can earn money by promoting MailOptin! %1$sJoin our affiliate program%2$s, and paste your %1$saffiliate link here%2$s. Once entered, it will replace the default MailOptin branding link on your optins.', 'mailoptin'),
                            '<a href="https://mailoptin.io/affiliates/" target="_blank">',
                            '</a>'
                        ),
                    ],
                    'install_missing_db_tables' => [
                        'type'  => 'custom_field_block',
                        'label' => __('Install Missing DB Tables', 'mailoptin'),
                        'data'  => "<a href='$fix_db_url' class='button action ppress-confirm-delete'>" . __('Fix Database', 'mailoptin') . '</a>',
                    ]
                ]
            ),
            'optin_campaign_settings' => apply_filters('mailoptin_settings_optin_campaign_settings_page', [
                    'tab_title' => __('Optin Campaign', 'mailoptin'),
                    [
                        'section_title'               => __('Optin Campaign Settings', 'mailoptin'),
                        'disable_impression_tracking' => [
                            'type'           => 'checkbox',
                            'label'          => __('Disable Impression Tracking', 'mailoptin'),
                            'checkbox_label' => __('Disable', 'mailoptin')
                        ],
                        'dequeue_google_font'         => [
                            'type'           => 'checkbox',
                            'label'          => __('Disable Google Fonts', 'mailoptin'),
                            'checkbox_label' => __('Disable', 'mailoptin'),
                            'description'    => esc_html__('Check to stop us from loading Google Fonts on your site.', 'mailoptin')
                        ],
                        'disable_optin_error_emails' => [
                            'type' => 'checkbox',
                            'label' => __('Disable Optin Error Emails', 'mailoptin'),
                            'checkbox_label' => __('Disable', 'mailoptin'),
                            'description' => esc_html__('Check to disable sending optin error notification emails.', 'mailoptin')
                        ],
                        'enable_async_optin' => [
                                'type'           => 'checkbox',
                                'label'          => __('Optin Background Processing', 'mailoptin'),
                                'checkbox_label' => __('Enable', 'mailoptin'),
                                'description' => esc_html__('Enable background processing of optin for non-form integrations such as optin after user registration, WooCommerce checkout etc. This is useful e.g for WooCommerce so customer subscription does not slow down the checkout.', 'mailoptin')
                        ],
                        'global_cookie'               => [
                            'type'        => 'number',
                            'value'       => 0,
                            'label'       => __('Global Interaction Cookie', 'mailoptin'),
                            'description' => __(
                                'Entering a number of days (e.g. 30) will set a global cookie once any optin is closed by a user or visitor.
                            This global cookie will prevent any other optins from loading on your site for that visitor until the cookie expires. Defaults to 0 (no global interaction cookie).',
                                'mailoptin'
                            ),
                        ],
                        'global_success_cookie'       => [
                            'type'        => 'number',
                            'value'       => 0,
                            'label'       => __('Global Success Cookie', 'mailoptin'),
                            'description' => __(
                                'Entering a number of days (e.g. 30) will set a global cookie once any optin has resulted in a successful conversion. This global cookie will prevent any other optins from loading on your site for that visitor until the cookie expires. Defaults to 0 (no global success cookie).',
                                'mailoptin'
                            ),
                        ],
                    ]
                ]
            ),
            'email_campaign_settings' => apply_filters('mailoptin_settings_email_campaign_settings_page', [
                    'tab_title'         => __('Email Campaign', 'mailoptin'),
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
        $tab_content_area = '';

        if ( ! empty($settings_args)) {
            $instance = $this->instance;
            $grouped  = self::group_tabs(array_keys($settings_args));
            $nav_tabs = [];

            foreach ($settings_args as $key => $settings_arg) {
                $tab_title     = $settings_arg['tab_title'];
                $section_title = $settings_arg['tab_title'];
                unset($settings_arg['tab_title']);
                unset($settings_arg['section_title']);
                $nav_tabs[$key] = sprintf('<a href="#%1$s" class="nav-tab" id="%1$s-tab">%2$s<span class="mo-settings-matches" hidden></span></a>', esc_attr($key), $tab_title);

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
            echo '<div class="mailoptin-settings-wrap mo-settings-v2" data-option-name="' . MAILOPTIN_SETTINGS_DB_OPTION_NAME . '">';
            echo $this->search_html();
            // Group labels are spans, not links, so the existing tab script (which works on "a" tags) is unchanged.
            echo '<h2 class="nav-tab-wrapper">';
            foreach ($grouped as $group_label => $keys) {
                echo '<span class="mo-settings-nav-group">' . esc_html($group_label) . '</span>';
                foreach ($keys as $key) echo $nav_tabs[$key];
            }
            echo '</h2>';
            echo '<div class="metabox-holder mailoptin-tab-settings">';
            echo '<form method="post" class="mo-settings-form">';
            $instance->nonce_field();
            echo $tab_content_area;
            echo self::save_bar_html();
            echo '</form>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo self::styles() . self::script();

            do_action('mailoptin_after_settings_page', MAILOPTIN_SETTINGS_DB_OPTION_NAME);
        }
    }

    /**
     * Sort the settings tabs into labelled groups for the left-hand menu. Tabs added by add-ons that
     * aren't in a group go under "More", so nothing disappears.
     *
     * @param string[] $keys tab keys in their registered order
     *
     * @return array group label => tab keys
     */
    public static function group_tabs($keys)
    {
        $groups = apply_filters('mailoptin_settings_tab_groups', [
            __('General', 'mailoptin')         => ['general_settings'],
            __('Sending', 'mailoptin')         => ['sending_settings', 'email_campaign_settings'],
            __('Forms', 'mailoptin')           => ['optin_campaign_settings', 'leadbank_settings'],
            __('Spam protection', 'mailoptin') => ['recaptcha_settings', 'turnstile_settings'],
        ]);

        $out    = [];
        $placed = [];

        foreach ($groups as $label => $group_keys) {
            $present = array_values(array_intersect((array)$group_keys, $keys));
            $present = array_diff($present, $placed);
            if (empty($present)) continue;
            $out[$label] = array_values($present);
            $placed      = array_merge($placed, $present);
        }

        $rest = array_values(array_diff($keys, $placed));
        if ( ! empty($rest)) $out[__('More', 'mailoptin')] = $rest;

        return $out;
    }

    protected function search_html()
    {
        return '<div class="mo-settings-search">'
               . '<label class="screen-reader-text" for="mo-settings-search">' . esc_html__('Search settings', 'mailoptin') . '</label>'
               . '<input type="search" id="mo-settings-search" class="mo-ui-search" placeholder="' . esc_attr__('Search settings', 'mailoptin') . '" hidden>'
               . '<p class="mo-ui-note mo-settings-search-note" role="status" hidden></p>'
               . '</div>';
    }

    /**
     * Shown once a setting changes. Its button saves every tab, like the per-section buttons do.
     */
    protected static function save_bar_html()
    {
        return '<div class="mo-settings-savebar" hidden>'
               . '<span class="mo-ui-badge mo-ui-warn">' . esc_html__('Unsaved changes', 'mailoptin') . '</span>'
               . '<span class="mo-settings-savebar-end"></span>'
               . '<a class="mo-ui-btn" href="' . esc_url(remove_query_arg(wp_removable_query_args())) . '">' . esc_html__('Discard', 'mailoptin') . '</a>'
               . '<input type="submit" class="mo-ui-btn mo-ui-primary" name="save_' . esc_attr(MAILOPTIN_SETTINGS_DB_OPTION_NAME) . '" value="' . esc_attr__('Save changes', 'mailoptin') . '">'
               . '</div>';
    }

    protected static function styles()
    {
        return '<style>
.mailoptin-settings-wrap.mo-settings-v2{display:grid;grid-template-columns:230px minmax(0,1fr);gap:8px 24px;align-items:start;margin-top:8px;border:0}
.mailoptin-settings-wrap.mo-settings-v2 h2.nav-tab-wrapper{background:transparent;border:0}
.mailoptin-settings-wrap.mo-settings-v2 h2.nav-tab-wrapper a.nav-tab-active{width:auto;border:1px solid #dcdcde}
.mailoptin-settings-wrap.mo-settings-v2 .metabox-holder{background:transparent;padding:0}
.mailoptin-settings-wrap.mo-settings-v2 .mailoptin-tab-settings .postbox{border:1px solid #dcdcde;background:#fff}
.mailoptin-settings-wrap.mo-settings-v2 .metabox-holder h3.hndle{border:0}
.mo-settings-v2 .mo-settings-search{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.mo-settings-v2 .mo-settings-search-note{margin:0;flex:1}
.mo-settings-v2 .nav-tab-wrapper{display:flex;flex-direction:column;float:none;width:auto;margin:0;padding:0;border:0;position:sticky;top:46px}
.mo-settings-v2 .mo-settings-nav-group{color:#646970;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin:14px 12px 4px}
.mo-settings-v2 .mo-settings-nav-group:first-child{margin-top:4px}
.mo-settings-v2 .nav-tab-wrapper .nav-tab{display:flex;justify-content:space-between;align-items:center;float:none;margin:0 0 2px;padding:8px 12px;border:1px solid transparent;border-radius:6px;background:transparent;color:#3c434a;font-size:13px;font-weight:500;line-height:1.4;white-space:normal}
.mo-settings-v2 .nav-tab-wrapper .nav-tab:hover{background:#fff;color:#2271b1}
.mo-settings-v2 .nav-tab-wrapper .nav-tab.nav-tab-active,.mo-settings-v2 .nav-tab-wrapper .nav-tab.nav-tab-active:hover{background:#fff;border-color:#dcdcde;color:#1d2327;font-weight:600;box-shadow:none}
.mo-settings-v2 .mo-settings-matches{background:#fff8c5;color:#8a6100;border-radius:99px;padding:0 7px;font-size:11px;font-weight:600}
.mo-settings-v2 .mailoptin-tab-settings{padding:0;margin:0;min-width:0}
.mo-settings-v2 .postbox{border:1px solid #dcdcde;border-radius:10px;box-shadow:none;margin-bottom:16px;overflow:hidden}
.mo-settings-v2 .postbox-header{border-bottom:1px solid #f0f0f1}
.mo-settings-v2 .postbox .hndle{font-size:15px;padding:14px 22px}
.mo-settings-v2 .postbox .inside{padding:0 22px 8px;margin:0}
.mo-settings-v2 .form-table th{padding:16px 20px 16px 0;width:240px}
.mo-settings-v2 .form-table td{padding:14px 10px}
.mo-settings-v2 .form-table tr+tr{border-top:1px solid #f0f0f1}
.mo-settings-v2 .mo-settings-searching .postbox:not(:has(tr:not([hidden]))){display:none}
.mo-settings-savebar{position:sticky;bottom:12px;z-index:20;display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:10px 14px;box-shadow:0 6px 20px rgba(0,0,0,.08);margin-top:8px}
.mo-settings-savebar[hidden]{display:none}
.mo-settings-savebar-end{margin-left:auto}
@media (max-width:782px){.mo-settings-v2{grid-template-columns:1fr}.mo-settings-v2 .nav-tab-wrapper{position:static;flex-direction:row;flex-wrap:wrap;gap:4px}.mo-settings-v2 .mo-settings-nav-group{display:none}.mo-settings-v2 .form-table th{width:auto;padding-bottom:0}}
</style>';
    }

    /**
     * Settings search and the unsaved-changes bar. Without JavaScript the page works as before:
     * the search box stays hidden and each section has its own Save button.
     */
    protected static function script()
    {
        ob_start();
        ?>
        <script>
            (function () {
                var wrap = document.querySelector('.mo-settings-v2');
                if (!wrap) return;
                var form = wrap.querySelector('.mo-settings-form'),
                    input = document.getElementById('mo-settings-search'),
                    note = wrap.querySelector('.mo-settings-search-note'),
                    bar = wrap.querySelector('.mo-settings-savebar'),
                    holder = wrap.querySelector('.mailoptin-tab-settings'),
                    groups = [].slice.call(wrap.querySelectorAll('.mailoptin-group-wrapper')),
                    rows = [].slice.call(wrap.querySelectorAll('.form-table tr'));

                var dirty = false;
                var markDirty = function (e) {
                    if (e.target === input || dirty) return;
                    dirty = true;
                    bar.hidden = false;
                };
                form.addEventListener('input', markDirty);
                form.addEventListener('change', markDirty);
                form.addEventListener('submit', function () { dirty = false; });
                window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
                bar.querySelector('a').addEventListener('click', function () { dirty = false; });

                input.hidden = false;
                var activeTab = null, restoring = false;

                // Picking a section from the menu ends the search (but not our own click that reopens the tab).
                wrap.querySelector('.nav-tab-wrapper').addEventListener('click', function (e) {
                    if (restoring || !e.target.closest('a.nav-tab') || input.value === '') return;
                    activeTab = null;
                    input.value = '';
                    input.dispatchEvent(new Event('input'));
                }, true);

                input.addEventListener('input', function () {
                    var term = input.value.trim().toLowerCase();
                    rows.forEach(function (row) { row.hidden = false; });
                    wrap.querySelectorAll('.mo-settings-matches').forEach(function (b) { b.hidden = true; });

                    if (term.length < 2) {
                        holder.classList.remove('mo-settings-searching');
                        note.hidden = true;
                        if (activeTab) { restoring = true; activeTab.click(); restoring = false; activeTab = null; }
                        return;
                    }

                    if (!activeTab) activeTab = wrap.querySelector('.nav-tab-wrapper .nav-tab-active');
                    holder.classList.add('mo-settings-searching');
                    var total = 0;

                    groups.forEach(function (group) {
                        var hits = 0;
                        group.querySelectorAll('.form-table tr').forEach(function (row) {
                            var match = row.textContent.toLowerCase().indexOf(term) !== -1;
                            row.hidden = !match;
                            if (match) hits++;
                        });
                        group.style.display = hits ? 'block' : 'none';
                        var badge = wrap.querySelector('#' + group.id + '-tab .mo-settings-matches');
                        if (badge && hits) { badge.textContent = hits; badge.hidden = false; }
                        total += hits;
                    });

                    note.hidden = false;
                    note.textContent = total
                        ? <?php echo wp_json_encode(__('Settings matching "%2$s": %1$d. Clear the search to see everything again.', 'mailoptin')); ?>.replace('%1$d', total).replace('%2$s', input.value.trim())
                        : <?php echo wp_json_encode(__('No settings match "%s".', 'mailoptin')); ?>.replace('%s', input.value.trim());
                });
            })();
        </script>
        <?php
        return ob_get_clean();
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
