(function (api, $) {
    'use strict';

    $(window).on('load', function () {

        function cta_button_action_toggle(is_show_cta_fields) {

            api('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][cta_button_action]', function (setting) {
                var is_displayed, linkSettingValueToControlActiveState;

                is_displayed = function () {
                    return is_show_cta_fields === true && setting.get() === 'navigate_to_url';
                };

                linkSettingValueToControlActiveState = function (control) {
                    var setActiveState = function () {
                        control.active.set(is_displayed());
                    };

                    control.active.validate = is_displayed;

                    // Set initial active state.
                    setActiveState();

                    setting.bind(setActiveState);
                };

                api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][cta_button_navigation_url]', linkSettingValueToControlActiveState);
            });
        }

        var display_custom_html = false;
        api('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][display_custom_html]', function (setting) {
            display_custom_html = !setting.get();
        })

        api('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][display_only_button]', function (setting) {
            var is_display_optin_fields, is_show_cta_fields, callToActionFieldsToggle, optinFieldsDisplayToggle;

            is_display_optin_fields = function ( control ) {
                var to_display = !setting.get();
                if( 'function' == typeof control ) {
                    if( 'mo_optin_campaign[' + mailoptin_optin_campaign_id + '][display_custom_html]' == control.id ){
                        return to_display && display_custom_html;
                    }

                    if( 'mo_optin_campaign[' + mailoptin_optin_campaign_id + '][display_only_button]' == control.id ){
                        return to_display;
                    }

                    return to_display && !display_custom_html;
                }
                return to_display;
            };

            is_show_cta_fields = function () {
                return setting.get();
            };

            optinFieldsDisplayToggle = function (control) {
                var setActiveState = function () {
                    control.active.set(is_display_optin_fields(control));
                };

                control.active.validate = is_display_optin_fields;

                // Set initial active state.
                setActiveState();

                setting.bind(setActiveState);
            };

            callToActionFieldsToggle = function (control) {
                var setActiveState = function () {
                    control.active.set(is_show_cta_fields());
                    cta_button_action_toggle(is_show_cta_fields());
                };

                control.active.validate = is_show_cta_fields;

                // Set initial active state.
                setActiveState();

                setting.bind(setActiveState);
            };

            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][submit_button_header]', optinFieldsDisplayToggle);

            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][fields]', optinFieldsDisplayToggle);

            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][submit_button]', optinFieldsDisplayToggle);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][submit_button_color]', optinFieldsDisplayToggle);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][submit_button_background]', optinFieldsDisplayToggle);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][submit_button_font]', optinFieldsDisplayToggle);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][custom_html_content]', optinFieldsDisplayToggle);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][display_custom_html]', optinFieldsDisplayToggle);

            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][cta_button_header]', callToActionFieldsToggle);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][cta_button_action]', callToActionFieldsToggle);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][cta_button]', callToActionFieldsToggle);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][cta_button_color]', callToActionFieldsToggle);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][cta_button_background]', callToActionFieldsToggle);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][cta_button_font]', callToActionFieldsToggle);
        });

        api('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][bar_position]', function (setting) {
            var is_displayed, linkSettingValueToControlActiveState;

            is_displayed = function () {
                return setting.get() == 'top';
            };

            linkSettingValueToControlActiveState = function (control) {
                var setActiveState = function () {
                    control.active.set(is_displayed());
                };

                control.active.validate = is_displayed;

                // Set initial active state.
                setActiveState();

                setting.bind(setActiveState);
            };

            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][bar_sticky]', linkSettingValueToControlActiveState);
        });

        api('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][click_launch_status]', function (setting) {
            var is_displayed, linkSettingValueToControlActiveState;

            is_displayed = function () {
                return setting.get();
            };

            linkSettingValueToControlActiveState = function (control) {
                var setActiveState = function () {
                    control.active.set(is_displayed());
                    // hide all display rules sections save for click launch when click launch is activated.
                    api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][load_optin_globally]').active(!is_displayed());
                    api.section('mo_wp_exit_intent_display_rule_section').active(!is_displayed());
                    api.section('mo_wp_x_seconds_display_rule_section').active(!is_displayed());
                    api.section('mo_wp_x_scroll_display_rule_section').active(!is_displayed());
                    api.section('mo_wp_x_page_views_display_rule_section').active(!is_displayed());
                    api.section('mo_wp_page_filter_display_rule_section').active(!is_displayed());
                    api.section('mo_wp_shortcode_template_tag_display_rule_section').active(!is_displayed());
                    api.section('mo_wp_adblock_display_rule_section').active(!is_displayed());
                    api.section('mo_wp_newvsreturn_display_rule_section').active(!is_displayed());
                    api.section('mo_wp_referrer_detection_display_rule_section').active(!is_displayed());
                    api.section('mo_wp_schedule_display_rule_section').active(!is_displayed());
                    api.section('mo_wp_device_targeting_display_rule_section').active(!is_displayed());
                    api.section('mo_wp_user_filter_display_rule_section').active(!is_displayed());
                    if (typeof api.section('mo_wp_polylang_display_rule_section') !== 'undefined') {
                        api.section('mo_wp_polylang_display_rule_section').active(!is_displayed());
                    }
                };

                control.active.validate = is_displayed;

                // Set initial active state.
                setActiveState();

                setting.bind(setActiveState);
            };

            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][click_launch_basic_shortcode]', linkSettingValueToControlActiveState);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][click_launch_advance_shortcode]', linkSettingValueToControlActiveState);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][click_launch_html_code]', linkSettingValueToControlActiveState);
        });

        // contextual display of redirect_url in success panel/section.
        api('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][success_action]', function (setting) {
                var is_redirect_url_value_displayed, is_success_message_displayed,
                    linkSettingValueToControlActiveState1, linkSettingValueToControlActiveState2;

                is_success_message_displayed = function () {
                    return setting.get() === 'success_message';
                };

                is_redirect_url_value_displayed = function () {
                    return setting.get() === 'redirect_url';
                };

                linkSettingValueToControlActiveState1 = function (control) {
                    var setActiveState = function () {
                        control.active.set(is_redirect_url_value_displayed());
                    };

                    control.active.validate = is_redirect_url_value_displayed;
                    // Set initial active state.
                    setActiveState();

                    setting.bind(setActiveState);
                };

                linkSettingValueToControlActiveState2 = function (control) {
                    var setActiveState = function () {
                        control.active.set(is_success_message_displayed());
                    };

                    control.active.validate = is_success_message_displayed;
                    // Set initial active state.
                    setActiveState();

                    setting.bind(setActiveState);
                };

                api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][redirect_url_value]', linkSettingValueToControlActiveState1);
                api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][pass_lead_data_redirect_url]', linkSettingValueToControlActiveState1);
                api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][success_message]', linkSettingValueToControlActiveState2);
            }
        );

        api('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][note_acceptance_checkbox]', function (setting) {
            var is_displayed, linkSettingValueToControlActiveState, controlCloseOptinOnClick;

            is_displayed = function () {
                return setting.get() === true;
            };

            linkSettingValueToControlActiveState = function (control) {
                var setActiveState = function () {
                    control.active.set(is_displayed());
                };

                control.active.validate = is_displayed;

                setActiveState();

                setting.bind(setActiveState);
            };

            controlCloseOptinOnClick = function (control) {
                var setValueState = function () {
                    if (is_displayed() === true) {
                        control.setting.set(false);
                    }
                };

                setValueState();

                setting.bind(setValueState);
            };

            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][note_acceptance_error]', linkSettingValueToControlActiveState);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][note_close_optin_onclick]', controlCloseOptinOnClick);
        });

        api('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][load_optin_index]', function (setting) {
            var is_displayed, linkSettingValueToControlActiveState, controlGlobalLoadOptin;

            is_displayed = function () {
                return !setting.get();
            };

            linkSettingValueToControlActiveState = function (control) {
                var setActiveState = function () {
                    control.active.set(is_displayed());
                };

                control.active.validate = is_displayed;

                // Set initial active state.
                setActiveState();

                setting.bind(setActiveState);
            };

            controlGlobalLoadOptin = function (control) {
                var setValueState = function () {
                    if (setting.get() === true) {
                        control.setting.set(false);
                    }
                };

                setValueState();

                setting.bind(setValueState);
            };

            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][exclusive_post_types_posts_load]', linkSettingValueToControlActiveState);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][post_categories_load]', linkSettingValueToControlActiveState);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][exclusive_post_types_load]', linkSettingValueToControlActiveState);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][posts_never_load]', linkSettingValueToControlActiveState);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][pages_never_load]', linkSettingValueToControlActiveState);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][cpt_never_load]', linkSettingValueToControlActiveState);

            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][load_optin_globally]', controlGlobalLoadOptin);
        });

        api('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][hide_mini_headline]', function (setting) {
            var is_displayed, linkSettingValueToControlActiveState;

            is_displayed = function () {
                return !setting.get();
            };

            linkSettingValueToControlActiveState = function (control) {
                var setActiveState = function () {
                    control.active.set(is_displayed());
                };

                control.active.validate = is_displayed;

                // Set initial active state.
                setActiveState();

                setting.bind(setActiveState);
            };

            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][mini_headline_font_color]', linkSettingValueToControlActiveState);
            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][mini_headline]', linkSettingValueToControlActiveState);
        });

        api('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][note_close_optin_onclick]', function (setting) {
            var is_displayed, controlAcceptanceCheckbox;

            is_displayed = function () {
                return setting.get() === true;
            };

            controlAcceptanceCheckbox = function (control) {
                var setValueState = function () {
                    if (is_displayed() === true) {
                        control.setting.set(false);
                    }
                };

                setValueState();

                setting.bind(setValueState);
            };

            api.control('mo_optin_campaign[' + mailoptin_optin_campaign_id + '][note_acceptance_checkbox]', controlAcceptanceCheckbox);
        });

        // handles click to select on input readonly fields
        $('.mo-click-select').click(function () {
            this.select();
        });

        // handles activation and deactivation of optin
        $('#mo-optin-activate-switch').on('change', function () {
            $.post(ajaxurl, {
                action: 'mailoptin_optin_toggle_active',
                id: mailoptin_optin_campaign_id,
                status: this.checked,
                security: $("input[data-customize-setting-link*='[ajax_nonce]']").val()
            }, function ($response) {
                // do nothing
            });
        });
    });

})(wp.customize, jQuery);