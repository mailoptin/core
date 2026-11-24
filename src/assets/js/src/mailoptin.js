import $ from 'jquery';


import Cookies from 'js.cookie';
import Pikaday from 'pikaday';


import mailoptin_globals from 'mailoptin_globals';
import moModal from 'moModal';
import moExitIntent from 'moExitIntent';
import moScrollTrigger from 'moScrollTrigger';
import mcGroupsValidation from 'mc-groups-validation';
import recaptcha from 'recaptcha';

"use strict";

$.MailOptin = {

    // flag to detect if we've already loaded showads.js file to detect adblock
    is_adblock_script_loaded: false,
    /**
     * Is the current screen customizer preview?
     * @return {boolean}
     */
    is_customize_preview: (mailoptin_globals.is_customize_preview === 'true'),

    /**
     * Two steps: counts a Yes, or ("done") a subscription after one. Sent with sendBeacon, so it never
     * holds the visitor up.
     */
    track_twostep_yes: function (optin_uuid, stage) {
        var url = mailoptin_globals.mailoptin_ajaxurl.toString().replace('%%endpoint%%', 'track_twostep_yes');
        var stat_data = $.extend({optin_uuid: optin_uuid, stage: stage === 'done' ? 'done' : 'yes'}, $.MailOptin.visit_data());

        $.MailOptin.send_beacon(url, stat_data);
    },

    /**
     * The page, the site the visitor came from and their time zone, sent with views and conversions for
     * the statistics (the time zone stands in for their country where nothing better is available).
     */
    visit_data: function () {
        var tz = '';
        try {
            tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch (e) {
        }

        return {conversion_page: window.location.href, referrer: document.referrer || '', tz: tz};
    },

    /** Posts stat_data without holding the visitor up: sendBeacon, else a normal request. */
    send_beacon: function (url, stat_data) {
        if (navigator.sendBeacon) {
            var data = new FormData();
            $.each(stat_data, function (key, value) {
                data.append('stat_data[' + key + ']', value);
            });
            if (navigator.sendBeacon(url, data)) return;
        }

        $.post(url, {stat_data: stat_data});
    },

    /**
     * Counts a click on a message's button. Sent as the page unloads (sendBeacon), so it never holds
     * the visitor up.
     */
    track_message_click: function (optin_uuid) {
        var url = mailoptin_globals.mailoptin_ajaxurl.toString().replace('%%endpoint%%', 'track_message_click');

        $.MailOptin.send_beacon(url, $.extend({optin_uuid: optin_uuid}, $.MailOptin.visit_data()));
    },
    /**
     * Track optin conversion and impression.
     *
     * @param {string} optin_uuid
     */
    track_impression: function (optin_uuid) {
        // bail if this is customizer preview
        if ($.MailOptin.is_customize_preview === true || mailoptin_globals.disable_impression_tracking === 'true') return;

        var stat_data = $.extend({optin_uuid: optin_uuid}, $.MailOptin.visit_data());

        $.post(mailoptin_globals.mailoptin_ajaxurl.toString().replace('%%endpoint%%', 'track_optin_impression'), {stat_data: stat_data});
    }
};

var mailoptin_optin = {

    content_locker_storage: [],

    mailoptin_jq_plugin: function () {
        var self = this;
        $.fn.mailoptin = function (skip_display_checks) {
            skip_display_checks = typeof skip_display_checks !== 'undefined' ? skip_display_checks : false;

            var modal_options,
                $optin_uuid,
                $optin_type,
                $optin_css_id,
                optin_js_config,
                test_mode;

            $optin_uuid = this.attr('id');
            $optin_type = this.attr('data-optin-type');
            $optin_css_id = $optin_uuid + '_' + $optin_type;
            optin_js_config = self.optin_js_config($optin_css_id);

            if (typeof optin_js_config === 'undefined') return;

            test_mode = ($.MailOptin.is_customize_preview === true) ? true : optin_js_config.test_mode;

            // add the close-optin event handler. modal/lightbox has its own so skip.
            // close_optin works out the campaign from the clicked link, so one handler serves every
            // campaign; binding it again (each reopen, each campaign) would close the same one twice.
            if (this.hasClass('mo-optin-form-lightbox') === false && $.MailOptin.closeHandlerBound !== true) {
                $.MailOptin.closeHandlerBound = true;
                $(document).on('click.moOptin', 'a[rel~="moOptin:close"], .mo-close-optin', self.close_optin);
            }

            // remove the close optin event if we're in customizer.
            if ($.MailOptin.is_customize_preview === true) {
                $(document).off('submit.moOptinSubmit', 'form.mo-optin-form');
                $(document).off('click.moOptinSubmit', '.mo-optin-form-submit-button');
                $(document).off('click.moOptin', 'a[rel~="moOptin:close"]');
                $(document).off('click.moOptin', '.mo-close-optin');
            }

            /** lightbox / modal */
            if (this.hasClass('mo-optin-form-lightbox')) {
                modal_options = {
                    optin_uuid: $optin_uuid,
                    bodyClose: optin_js_config.body_close,
                    keyClose: optin_js_config.body_close,
                    test_mode: test_mode,
                    iconClose: optin_js_config.icon_close,
                    onOpen: function () {
                        self.animate_optin_display.call(this, optin_js_config.effects);
                    },
                    onClose: function () {
                        self.set_cookie('exit', $optin_uuid, optin_js_config);
                    }
                };

                if ($.MailOptin.is_customize_preview === true) {
                    modal_options.keyClose = false;
                    modal_options.bodyClose = false;
                    modal_options.test_mode = true;
                }

                // merge modal specific object with that of optin js config
                optin_js_config = $.extend({}, modal_options, optin_js_config);
                self.process_optin_form_display.call(this, optin_js_config, 'lightbox', skip_display_checks);
            }

            /** Notification bar */
            if (this.hasClass('mo-optin-form-bar')) {
                // only one instance of top bar can show at a time.
                if (self.is_flag_optin_type_active(optin_js_config, 'bar')) return;

                self.process_optin_form_display.call(this, optin_js_config, 'bar', skip_display_checks);
            }

            /** Slide INs */
            if (this.hasClass('mo-optin-form-slidein')) {
                // only one instance of slidein type can shown at a time.
                if (self.is_flag_optin_type_active(optin_js_config, 'slidein')) return;
                self.process_optin_form_display.call(this, optin_js_config, 'slidein', skip_display_checks);
            }

            /** Sidebar */
            if (this.hasClass('mo-optin-form-sidebar')) {
                self.process_optin_form_display.call(this, optin_js_config, 'sidebar', skip_display_checks);
            }

            /** Inpost */
            if (this.hasClass('mo-optin-form-inpost')) {
                self.process_optin_form_display.call(this, optin_js_config, 'inpost', skip_display_checks);
            }

            // custom html conversion tracker (once per campaign: a reopen doesn't bind it again)
            $(document).off('click.moConv' + $optin_uuid).on('click.moConv' + $optin_uuid, '.mo-trigger-conversion', function () {
                // set cookie for this option conversion when button is clicked.
                self.set_cookie('success', $optin_uuid, optin_js_config);

                optin_js_config.mo_email = 'CTA';
                self.ga_event_tracking('conversion', optin_js_config);
            });

            // handle CTA button click if activated
            if (self.is_var_defined(optin_js_config, 'cta_display') && optin_js_config.cta_display === true && self.is_var_defined(optin_js_config, 'cta_action')) {
                // if cta action is to navigate
                // Bound once per button: this runs again on every click-launch.
                $(document).off('click.moCta', '#' + $optin_css_id + '_cta_button').on('click.moCta', '#' + $optin_css_id + '_cta_button', function (e) {
                    // A message's button is a link: it goes where it points (a new tab included) by itself.
                    // The click counts as the campaign's conversion and hides it with the success cookie.
                    if (optin_js_config.is_message === true) {
                        if ($.MailOptin.is_customize_preview === true) {
                            e.preventDefault();
                            return false;
                        }
                        self.set_cookie('success', $optin_uuid, optin_js_config);
                        optin_js_config.mo_email = 'CTA';
                        self.ga_event_tracking('conversion', optin_js_config);
                        $.MailOptin.track_message_click($optin_uuid);

                        // A link opened in a new tab leaves this page as it is: close the message here.
                        if ($(this).attr('target') === '_blank') {
                            var message = $(this).parents('.moOptinForm');
                            setTimeout(function () {
                                if (optin_js_config.optin_type === 'lightbox') {
                                    $.MoModalBox.close();
                                } else {
                                    self._close_optin(message);
                                }
                                self.flag_optin_type_close(optin_js_config, optin_js_config.optin_type);
                            }, 0);
                        }
                        return true;
                    }

                    e.preventDefault();
                    var optin_container = $(this).parents('.moOptinForm');

                    if (optin_js_config.cta_action === 'navigate_to_url' && self.is_var_defined(optin_js_config, 'cta_navigate_url')) {
                        // bail if we are in customizer preview.
                        if ($.MailOptin.is_customize_preview === true) return;
                        // set cookie for this option conversion when button is clicked.
                        self.set_cookie('success', $optin_uuid, optin_js_config);
                        optin_js_config.mo_email = 'CTA';
                        self.ga_event_tracking('conversion', optin_js_config);

                        window.location.assign(optin_js_config.cta_navigate_url);
                    } else if (optin_js_config.cta_action === 'reveal_optin_form') {
                        var cache = $('#' + $optin_css_id);
                        cache.find('.mo-optin-form-cta-button, .mo-optin-form-cta-wrapper').hide();
                        cache.find('.mo-optin-fields-wrapper').show();
                        cache.find('.mo-optin-form-submit-button').show();
                        $('#' + $optin_uuid).removeClass('mo-cta-button-flag');
                    } else if ($.inArray(optin_js_config.cta_action, ['close_optin', 'close_optin_reload_page']) !== -1) {
                        $.MoModalBox.close();
                        mailoptin_optin._close_optin(optin_container);

                        if (optin_js_config.cta_action === 'close_optin_reload_page') {
                            window.location.reload();
                        }
                    } else {
                        // An action added by an add-on (Premium: "Add product to cart") is theirs to run.
                        $(document.body).trigger('moOptinCtaAction', [optin_js_config.cta_action, optin_container, optin_js_config, $optin_uuid]);
                    }
                    return false;
                });
            }

            if (!$.MailOptin.is_customize_preview && self.is_adblock_rule_active(optin_js_config) === true && $.MailOptin.is_adblock_script_loaded === false) {
                self.load_adblock_detect_script();
                $.MailOptin.is_adblock_script_loaded = true;
            }
        };

        $.fn.extend({
            animateOptin: function (animationName) {
                var animationEnd = 'webkitAnimationEnd mozAnimationEnd MSAnimationEnd oanimationend animationend';
                this.addClass('MOanimate__animated ' + animationName).one(animationEnd, function () {
                    $(this).removeClass('MOanimate__animated ' + animationName);
                });
            }
        });
    },

    /**
     * Is after x seconds rule active?
     *
     * @param {object} optin_config
     * @returns {boolean}
     */
    is_after_x_seconds_active: function (optin_config) {

        return typeof optin_config.x_seconds_status !== 'undefined' &&
            optin_config.x_seconds_status === true &&
            typeof optin_config.x_seconds_value !== 'undefined';
    },

    /**
     * Is after x seconds rule active?
     *
     * @param {object} optin_config
     * @returns {boolean}
     */
    is_after_x_scroll_active: function (optin_config) {
        return optin_config.x_scroll_status === true && optin_config.x_scroll_value !== undefined;
    },

    /**
     * Is after x page views rule active?
     *
     * @param {object} optin_config
     * @returns {boolean}
     */
    is_after_x_page_views_active: function (optin_config) {
        return optin_config.x_page_views_status === true &&
            optin_config.x_page_views_condition !== undefined &&
            optin_config.x_page_views_value !== undefined;
    },

    /**
     * Is the "when the visitor goes idle" rule active?
     *
     * @param {object} optin_config
     * @returns {boolean}
     */
    is_idle_active: function (optin_config) {
        return optin_config.idle_status === true && optin_config.idle_value > 0;
    },

    /**
     * Call back once the visitor has done nothing (no mouse, scroll, key or touch) for the given seconds.
     * Time with the tab in the background doesn't count: they aren't there to see it.
     *
     * @param {number} seconds
     * @param {function} callback
     */
    on_idle: function (seconds, callback) {
        // Each watcher has its own event namespace, so stopping one leaves the others listening.
        $.MailOptin.idleWatchers = ($.MailOptin.idleWatchers || 0) + 1;
        var ns = '.moIdle' + $.MailOptin.idleWatchers, timer = null, done = false, last = Date.now();
        var stop = function () {
            done = true;
            clearTimeout(timer);
            $(window).off(ns);
            $(document).off(ns);
        };
        // Activity only notes the time (cheap on every mousemove); the timer checks it when it fires.
        var arm = function (ms) {
            clearTimeout(timer);
            timer = setTimeout(function () {
                if (done || document.hidden) return;
                var idle_for = Date.now() - last;
                if (idle_for >= seconds * 1000) {
                    stop();
                    callback();
                } else {
                    arm(seconds * 1000 - idle_for);
                }
            }, ms);
        };

        $(window).on(['mousemove', 'keydown', 'scroll', 'touchstart', 'wheel'].join(ns + ' ') + ns, function () {
            last = Date.now();
        });
        // Time in a background tab doesn't count: start again when they come back.
        $(document).on('visibilitychange' + ns, function () {
            if (done) return;
            last = Date.now();
            if (!document.hidden) arm(seconds * 1000);
        });
        arm(seconds * 1000);
    },

    /**
     * Is exit intent rule active?
     *
     * @param {object} optin_config
     * @returns {boolean}
     */
    is_exit_intent_active: function (optin_config) {
        return optin_config.exit_intent_status === true;
    },

    load_adblock_detect_script: function () {
        var ad = document.createElement('script');
        // lists of terms blocked https://easylist.to/easylist/easylist.txt
        ad.src = mailoptin_globals.public_js + '/ad-m.js';
        ad.async = true;

        // Attempt to append it to the <head>, otherwise append to the document.
        (document.getElementsByTagName('head')[0] || document.documentElement).appendChild(ad);
    },

    /**
     * Is New vs Returning rule active?
     *
     * @param {object} optin_config
     * @returns {boolean}
     */
    is_newvsreturn_rule_active: function (optin_config) {
        // no need checking if optin_config.newvsreturn_status_settings is not empty because
        // newvsreturn_status and newvsreturn_settings config are only exposed if the former is true
        // and latter not empty.
        return optin_config.newvsreturn_status === true;
    },

    /**
     * Is referrer detection active?
     *
     * @param {object} optin_config
     * @returns {boolean}
     */
    is_referrer_detection_rule_active: function (optin_config) {
        return optin_config.referrer_detection_status === true;
    },

    /**
     * Is cookie targeting active?
     *
     * @param {object} optin_config
     * @returns {boolean}
     */
    is_cookie_targeting_rule_active: function (optin_config) {
        return optin_config.cookie_targeting_status === true;
    },

    /**
     * Is Adblock rule active?
     *
     * @param {object} optin_config
     * @returns {boolean}
     */
    is_adblock_rule_active: function (optin_config) {
        // no need checking if optin_config.adblock_settings is not empty because
        // adblock_status and adblock_settings config are only exposed if the former is true
        // and latter not empty.
        return optin_config.adblock_status === true;
    },

    /**
     * Determine if optin should display or not.
     *
     * @param {object} optin_config
     *
     * @returns {boolean}
     */
    is_optin_visible: function (optin_config) {
        if (optin_config.state_after_conversion !== 'optin_form_shown') {
            var $optin_uuid = optin_config.optin_uuid;
            // if global success cookie found, do not display any optin.
            if (optin_config.global_success_cookie > 0 && Cookies.get('mo_global_success_cookie')) return false;
            // if global interaction/exit cookie found, do not display any optin.
            if (optin_config.global_cookie > 0 && Cookies.get('mo_global_cookie')) return false;
            // if success cookie found for this optin, do not display it.
            if (Cookies.get('mo_success_' + $optin_uuid)) return false;
            // if exit cookie found for this optin, do not dispay it.
            if (Cookies.get('mo_' + $optin_uuid)) return false;
        }

        return true;
    },

    /**
     * Determine if optin variants should display or not.
     *
     * @param {object} optin_config
     *
     * @returns {boolean}
     */
    split_test_cookie_test: function (optin_config) {

        if (optin_config.is_split_test === true) {

            var flag = true,
                self = mailoptin_optin,
                optin_campaign_id = optin_config.optin_campaign_id;

            if (optin_campaign_id in optin_config.split_test_variants) {

                $.each(optin_config.split_test_variants, function (index, variant_config) {

                    if (self.is_var_defined(variant_config, 'state_after_conversion') &&
                        variant_config.state_after_conversion !== 'optin_form_shown' &&
                        self.is_optin_visible(variant_config) === false) {
                        flag = false;
                        return false; // break the loop
                    }
                });

                return flag;
            }
        }

        return true;
    },

    /**
     * Handle display/showing of optin form.
     *
     * @param {object} optin_js_config for lightbox, this is modal_options.  others is optin_js_config
     * @param {string} optin_type type of optin
     * @param {boolean} skip_display_checks skip any display/cookie check
     */
    process_optin_form_display: function (optin_js_config, optin_type, skip_display_checks) {
        var self = mailoptin_optin;
        // we did this becos 'this' inside $(window).load will be wrong.
        var _this = this;

        if (self.is_adblock_rule_active(optin_js_config) === true) {
            // we're gonna wait until page is loaded so we can detect if adblock is enabled or not
            $(window).on('load', function () {
                self.rule_base_show_optin_form.call(_this, optin_js_config, optin_type, skip_display_checks);
            });
        } else {
            self.rule_base_show_optin_form.call(_this, optin_js_config, optin_type, skip_display_checks);
        }
    },

    /**
     * The visitor targeting rules (page views, cookie, referrer, new or returning, ad blocker, device):
     * whether this campaign is for this visitor. Used before showing it, and for its floating tab.
     *
     * @param {object} optin_config
     * @returns {boolean}
     */
    passes_targeting: function (optin_config) {
        var self = mailoptin_optin;

        if (self.is_after_x_page_views_active(optin_config)) {
            var x_page_views_condition = optin_config.x_page_views_condition;
            var x_page_views_value = optin_config.x_page_views_value;

            switch (x_page_views_condition) {
                // for each condition, do the inverse return false if comparison is true.
                case 'equals':
                    if (self.get_page_views() !== x_page_views_value) return false;
                    break;
                case 'more_than':
                    if (self.get_page_views() <= x_page_views_value) return false;
                    break;
                case 'less_than':
                    if (self.get_page_views() >= x_page_views_value) return false;
                    break;
                case 'at_least':
                    if (self.get_page_views() < x_page_views_value) return false;
                    break;
                case 'not_more_than':
                    if (self.get_page_views() > x_page_views_value) return false;
                    break;
            }
        }

        if (self.is_cookie_targeting_rule_active(optin_config) === true) {

            var cookie_is_display,
                cookie_targeting_settings = optin_config.cookie_targeting_settings,
                cookie_name = optin_config.cookie_targeting_name,
                cookie_value = optin_config.cookie_targeting_value;

            if (cookie_targeting_settings === 'show') {
                cookie_is_display = false;

                if (mailoptin_optin.is_defined_not_empty(cookie_name) && mailoptin_optin.is_defined_not_empty(cookie_value)) {
                    if (Cookies.get(cookie_name) === cookie_value) {
                        cookie_is_display = true;
                    }
                } else if (mailoptin_optin.is_defined_not_empty(cookie_name)) {
                    if (typeof Cookies.get(cookie_name) !== 'undefined') {
                        cookie_is_display = true;
                    }
                }
            }

            if (cookie_targeting_settings === 'hide') {
                cookie_is_display = true;

                if (mailoptin_optin.is_defined_not_empty(cookie_name) && mailoptin_optin.is_defined_not_empty(cookie_value)) {
                    if (Cookies.get(cookie_name) === cookie_value) {
                        cookie_is_display = false;
                    }
                } else if (mailoptin_optin.is_defined_not_empty(cookie_name)) {
                    if (typeof Cookies.get(cookie_name) !== 'undefined') {
                        cookie_is_display = false;
                    }
                }
            }

            if (!cookie_is_display) return false;
        }

        if (self.is_referrer_detection_rule_active(optin_config) === true) {
            var remove_trailing_slash = function (url) {
                return url.replace(/\/+$/, "");
            };

            var actual_referrer_url = document.referrer.toLowerCase() || false;

            if (!actual_referrer_url) return false;

            var display_type = optin_config.referrer_detection_settings;
            var referrers = optin_config.referrer_detection_values;

            if (display_type === 'show_to') {
                var is_display = false;
                $.each(referrers, function (index, referrer) {
                    referrer = remove_trailing_slash(referrer).toLowerCase();
                    // if list of referrer entered by admin in MailOptin matches actual referral.
                    if (actual_referrer_url.indexOf(referrer) !== -1) {
                        is_display = true;
                        // return false to stop loop.
                        return false;
                    }
                });
            }

            if (display_type === 'hide_from') {
                var is_display = true;
                $.each(referrers, function (index, referrer) {
                    referrer = remove_trailing_slash(referrer);
                    // if list of referrer entered by admin in MailOptin matches actual referral.
                    if (actual_referrer_url.indexOf(referrer) !== -1) {
                        is_display = false;
                        // return false to stop loop.
                        return false;
                    }
                });
            }

            if (!is_display) return false;
        }

        if (self.is_newvsreturn_rule_active(optin_config) === true) {
            if (optin_config.newvsreturn_settings === "is_new" && self.visitor_is_returning()) return false;
            if (optin_config.newvsreturn_settings === "is_returning" && self.visitor_is_new()) return false;
        }

        if (self.is_adblock_rule_active(optin_config) === true) {
            if (optin_config.adblock_settings === "adblock_enabled" && self.isAdblockDisabled()) return false;
            if (optin_config.adblock_settings === "adblock_disabled" && self.isAdblockEnabled()) return false;
        }

        // device detection rule
        if (typeof window.MobileDetect !== "undefined") {
            var mdInstance = new MobileDetect(window.navigator.userAgent);

            if (optin_config.device_targeting_hide_mobile === true) {
                if (mdInstance.phone()) return false;
            }

            if (optin_config.device_targeting_hide_tablet === true) {
                if (mdInstance.tablet()) return false;
            }

            if (optin_config.device_targeting_hide_desktop === true) {
                if (!mdInstance.mobile()) return false;
            }
        }

        return true;
    },

    /**
     * Run through display ruleset and determine which to display
     *
     * @param {object} optin_config for lightbox, this is modal_options.  others is optin_js_config
     * @param {string} optin_type type of optin
     * @param {boolean} skip_display_checks skip any display/cookie check
     */
    rule_base_show_optin_form: function (optin_config, optin_type, skip_display_checks) {
        var self = mailoptin_optin;
        // we did this becos 'this' inside setTimeout() will be wrong.
        var _this = this;

        // if customizer, display immediately.
        if ($.MailOptin.is_customize_preview === true || optin_config.test_mode === true || skip_display_checks === true) {
            return self.display_optin_form.call(_this, optin_config, optin_type, skip_display_checks);
        }

        // return if click launch status is activated for optin but the trigger isn't it.
        if (self.is_var_defined(optin_config, 'click_launch_status') && optin_config.click_launch_status === true && skip_display_checks === false) return;

        // A campaign opened only from its floating tab doesn't open on its own.
        if (self.is_var_defined(optin_config, 'launcher') && optin_config.launcher.only === true && skip_display_checks === false) return;

        if (self.is_optin_visible(optin_config) === false) return;

        if (self.split_test_cookie_test(optin_config) === false) return;

        if (self.passes_targeting(optin_config) === false) return;

        // WooCommerce added to cart
        if (self.is_var_defined(optin_config, 'wc_atc_activate_rule') && optin_config.wc_atc_activate_rule === true) {

            $(document.body).on('added_to_cart', function (event, fragments, cart_hash, $thisbutton) {

                var product_id = $thisbutton.data('product_id');

                if (self.is_var_defined(optin_config, 'wc_atc_products') &&
                    $.inArray(product_id, optin_config.wc_atc_products) === -1
                ) {
                    return false;
                }

                return self.display_optin_form.call(_this, optin_config, optin_type);
            });

            if (self.is_var_defined(mailoptin_globals, 'wc_atc_products')) {

                if (self.is_var_defined(optin_config, 'wc_atc_products')) {

                    /** array intersect @see https://stackoverflow.com/a/1885569/2648410 */
                    var intersect = optin_config.wc_atc_products.filter(function (n) {
                        return mailoptin_globals.wc_atc_products.indexOf(n) !== -1;
                    });

                    if (intersect.length === 0) return false;
                }

                return self.display_optin_form.call(_this, optin_config, optin_type);
            }

            return;
        }

        // When the visitor goes idle: on its own, or as well as the other rules (whichever comes first).
        // Every rule then shows it through auto_show, once per page, and never after it was closed or
        // they subscribed.
        var auto_show = null;
        if (self.is_idle_active(optin_config)) {
            auto_show = function () {
                if (_this.data('mo-auto-shown') === true || self.is_optin_visible(optin_config) === false) return;
                _this.data('mo-auto-shown', true);

                return self.display_optin_form.call(_this, optin_config, optin_type);
            };

            self.on_idle(optin_config.idle_value, auto_show);

            if (self.is_after_x_seconds_active(optin_config) === false &&
                self.is_after_x_scroll_active(optin_config) === false &&
                self.is_exit_intent_active(optin_config) === false) return;
        }

        var wait_seconds = optin_config.x_seconds_value * 1000;
        var optin_scroll_percent = optin_config.x_scroll_value;

        // If all three rules are active, run the below shebang
        if (self.is_after_x_seconds_active(optin_config) === true &&
            self.is_after_x_scroll_active(optin_config) === true &&
            self.is_exit_intent_active(optin_config) === true) {
            setTimeout(function () {
                $.moScrollTrigger('enable');
                $(document).on('moScrollTrigger', function (e, pctScrolled) {
                    if (pctScrolled >= optin_scroll_percent) {
                        $.moExitIntent('enable');
                        $(document).on("moExitIntent", function () {
                            return auto_show ? auto_show() : self.display_optin_form.call(_this, optin_config, optin_type);
                        });
                    }
                });

            }, wait_seconds);

            return;
        }

        // If only "is_after_x_scroll_active" and "is_exit_intent_active" rules are active, run the below shebang
        if (self.is_after_x_scroll_active(optin_config) === true &&
            self.is_exit_intent_active(optin_config) === true) {

            $.moScrollTrigger('enable');
            $(document).on('moScrollTrigger', function (e, pctScrolled) {
                if (pctScrolled >= optin_scroll_percent) {
                    $.moExitIntent('enable');
                    $(document).on("moExitIntent", function () {
                        return auto_show ? auto_show() : self.display_optin_form.call(_this, optin_config, optin_type);
                    });
                }
            });

            return;
        }

        // If only "after_x_seconds" and "after_x_scroll" rules are active, run the below shebang
        if (self.is_after_x_seconds_active(optin_config) === true &&
            self.is_after_x_scroll_active(optin_config) === true) {

            setTimeout(function () {
                $.moScrollTrigger('enable');
                $(document).on('moScrollTrigger', function (e, pctScrolled) {
                    if (_this.hasClass('si-open') === false) {
                        if (pctScrolled >= optin_scroll_percent) {
                            _this.addClass('si-open');
                            return auto_show ? auto_show() : self.display_optin_form.call(_this, optin_config, optin_type);
                        }
                    }
                });

            }, wait_seconds);

            return;
        }

        // If only "after_x_seconds" and "exit intent" rules are active, run the below shebang
        if (self.is_after_x_seconds_active(optin_config) === true &&
            self.is_exit_intent_active(optin_config) === true) {
            setTimeout(function () {
                $.moExitIntent('enable');
                $(document).on("moExitIntent", function () {
                    return auto_show ? auto_show() : self.display_optin_form.call(_this, optin_config, optin_type);
                });

            }, wait_seconds);

            return;
        }

        // If only "after_x_seconds" rules is active, run the below shebang
        if (self.is_after_x_seconds_active(optin_config) === true) {
            setTimeout(function () {
                return auto_show ? auto_show() : self.display_optin_form.call(_this, optin_config, optin_type);
            }, wait_seconds);

            return;
        }

        // If only "after x scroll" rules is active, run the below shebang
        if (self.is_after_x_scroll_active(optin_config)) {
            $.moScrollTrigger('enable');
            $(document).on('moScrollTrigger', function (e, pctScrolled) {
                if (_this.hasClass('si-open') === false) {
                    if (pctScrolled >= optin_scroll_percent) {
                        _this.addClass('si-open');
                        return auto_show ? auto_show() : self.display_optin_form.call(_this, optin_config, optin_type);
                    }
                }
            });

            return;
        }

        // If only "exit intent" rules is active, run the below shebang
        if (self.is_exit_intent_active(optin_config)) {
            $.moExitIntent('enable');
            $(document).on("moExitIntent", function () {
                return auto_show ? auto_show() : self.display_optin_form.call(_this, optin_config, optin_type);
            });

            return;
        }

        return auto_show ? auto_show() : self.display_optin_form.call(_this, optin_config, optin_type);
    },

    /**
     * Optin-type agnostic helper function to display optin form.
     *
     * @param {object} optin_config
     * @param {string} optin_type
     * @param {boolean} skip_display_checks
     */
    display_optin_form: function (optin_config, optin_type, skip_display_checks, gates_passed) {
        // bail if required parameter is undefined
        if (typeof optin_type === 'undefined' || typeof optin_type === 'undefined') return;

        var self = mailoptin_optin;
        var _this = this;

        // Its countdown ended on this page and it's set to stop: no trigger shows it again.
        if (self.countdown_stopped[optin_config.optin_uuid]) return;

        // do cookie checking if we are not in customizer mode and not test mode is active.
        if ($.MailOptin.is_customize_preview === false && optin_config.test_mode === false && skip_display_checks !== true) {
            if (self.is_optin_visible(optin_config) === false) return;

            if (self.split_test_cookie_test(optin_config) === false) return;

            // Add-ons' last say, e.g. checks that need the visitor's cart on a cached page.
            if (gates_passed === true) {
                // While the gate waited, another bar or slide-in may have taken this position.
                if ((optin_type === 'bar' || optin_type === 'slidein') && self.is_flag_optin_type_active(optin_config, optin_type)) return;
            } else {
                var gate = self.display_gate(optin_config, optin_type);

                if (gate === false) return;

                if (gate !== true) {
                    gate.done(function (show) {
                        if (show === true) self.display_optin_form.call(_this, optin_config, optin_type, skip_display_checks, true);
                    });
                    return;
                }
            }
        }

        if (optin_type !== undefined && optin_type === 'lightbox') {
            // trigger optin show event.
            $(document.body).off($.MoModalBox.OPEN + '.moShow').on($.MoModalBox.OPEN + '.moShow', function (e, elm, optin_config) {
                $(this).trigger('moOptin:show', [optin_config.optin_uuid, optin_config, optin_type]);
            });

            this.MoModalBox(optin_config);
            // stop further execution
            return;
        }

        self.animate_optin_display.call(this, optin_config.effects);
        self.flag_optin_type_displayed(optin_config, optin_type);

        if (optin_type === 'bar' && optin_config.bar_position === 'top') {

            var originalMargin = parseFloat($(document.body).css('margin-top')),
                optin_uuid = optin_config.optin_uuid;

            $(window).on('resize.MoBarTop', function () {

                setTimeout(function () {

                    var cache = $('#' + optin_uuid + '_bar'),
                        cache2 = $("#wpadminbar"),
                        mHeight = cache.outerHeight();

                    if ($(window).width() <= 600 && cache2.length > 0) {
                        if ($('#' + optin_uuid + '.mo-optin-form-bar-sticky').length > 0) {
                            mHeight -= cache2.outerHeight();
                        } else {
                            mHeight += cache2.outerHeight();
                        }
                    }

                    mHeight = $.MailOptin.activeBarHeight = originalMargin + mHeight;

                    $(document.body).css('margin-top', originalMargin + mHeight + 'px');

                }, 500)
            });

            // init
            $(window).trigger('resize');
        }

        this.show();

        $(this).trigger('moOptin:show', [optin_config.optin_uuid, optin_config, optin_type]);
    },

    optin_sound_selected: function (optin_config, optin_type) {
        //if we are in customizer, don't execute this to avoid double sound playing
        if ($.MailOptin.is_customize_preview === true) return;

        if (optin_type === 'bar' || optin_type === 'lightbox' || optin_type === 'slidein') {
            var optin_sound_config = typeof optin_config.optin_sound !== 'undefined' ? optin_config.optin_sound : '';
            if ('none' !== optin_sound_config && optin_sound_config !== '') {
                var optin_sound_url = 'custom' !== optin_sound_config ? mailoptin_globals.public_sound + optin_sound_config : optin_config.optin_custom_sound;
                var audio = new Audio(optin_sound_url);
                audio.addEventListener('canplaythrough', function () {
                    this.play()
                        .catch(function (reason) {
                            console.warn('Sound was not able to play when selected. Reason: ' + reason)
                        });
                });
                audio.addEventListener('error', function () {
                    console.error('Error occurred when trying to load popup opening sound.');
                });
            }
        }
    },

    /**
     * Set flag when an optin-type is displayed to prevent multiple optin-type instance showing.
     *
     * @param {object} optin_config
     * @param {string} optin_type
     */
    flag_optin_type_displayed: function (optin_config, optin_type) {
        if (optin_type === 'bar') {
            var bar_position = optin_config.bar_position;
            $.MailOptin['isActiveMOBar_' + bar_position] = true;
        }

        if (optin_type === 'slidein') {
            var slidein_position = optin_config.slidein_position;
            $.MailOptin['isActiveMOSlidein_' + slidein_position] = true;
        }
    },

    /**
     * Set flag when an optin-type is closed.
     *
     * @param {object} optin_config
     * @param {string} optin_type
     */
    flag_optin_type_close: function (optin_config, optin_type) {
        if (optin_type === 'bar') {
            var bar_position = optin_config.bar_position;
            $.MailOptin['isActiveMOBar_' + bar_position] = false;
        }

        if (optin_type === 'slidein') {
            var slidein_position = optin_config.slidein_position;
            $.MailOptin['isActiveMOSlidein_' + slidein_position] = false;
        }
    },

    /**
     * Ask the display gates add-ons register whether a campaign may show. A gate is a function
     * (optin_config, optin_type) returning true, false or a jQuery promise of true/false. Add-ons push
     * gates onto window.mailoptin_display_gates, so the order the scripts load in doesn't matter.
     *
     * @returns {boolean|object} true, false, or a promise of true/false when a gate needs to wait.
     */
    display_gate: function (optin_config, optin_type) {
        var gates = window.mailoptin_display_gates || [], pending = [], i, result;

        for (i = 0; i < gates.length; i++) {
            if (typeof gates[i] !== 'function') continue;

            try {
                result = gates[i](optin_config, optin_type);
            } catch (e) {
                // A broken gate never hides a campaign.
                continue;
            }

            if (result === false) return false;
            if (result && typeof result.then === 'function') pending.push(result);
        }

        if (pending.length === 0) return true;

        var deferred = $.Deferred();

        $.when.apply($, pending).then(function () {
            deferred.resolve($.inArray(false, Array.prototype.slice.call(arguments)) === -1);
        }, function () {
            // A gate that failed (a network error, say) doesn't hide the campaign.
            deferred.resolve(true);
        });

        return deferred.promise();
    },

    is_flag_optin_type_active: function (optin_config, optin_type) {
        if (optin_type === 'bar') {
            var bar_position = optin_config.bar_position;
            return $.MailOptin['isActiveMOBar_' + bar_position] === true;
        }

        if (optin_type === 'slidein') {
            var slidein_position = optin_config.slidein_position;
            return $.MailOptin['isActiveMOSlidein_' + slidein_position] === true;
        }
    },

    /**
     * Closes any displayed optin. well doesn't for modals as they have theirs.
     */
    close_optin: function (e) {
        e.preventDefault();

        var optin_container = $(this).parents('.moOptinForm');
        var optin_uuid = optin_container.attr('id');
        var optin_type = optin_container.attr('data-optin-type');
        var optin_config = mailoptin_optin.optin_js_config(optin_uuid);

        mailoptin_optin._close_optin(optin_container);

        // cleanup for on-scroll optin to prevent from triggering all the time
        optin_container.removeClass('si-open');

        mailoptin_optin.set_cookie('exit', optin_uuid, optin_config);
        mailoptin_optin.flag_optin_type_close(optin_config, optin_type);
    },

    /**
     * Actual func to close non-modal optin forms.
     * @param optin_container
     * @private
     */
    _close_optin: function (optin_container) {
        optin_container.fadeOut(400, function () {
            $(this).trigger('moOptin:close', [this]);

            var optin_uuid = optin_container.attr('id');
            var optin_config = mailoptin_optin.optin_js_config(optin_uuid);

            if (optin_config.optin_type === 'bar' && optin_config.bar_position === 'top') {
                var mt = parseFloat($(document.body).css('margin-top'));
                $(document.body).css('margin-top', mt - $.MailOptin.activeBarHeight + 'px');
                delete $.MailOptin.activeBarHeight;
                $(window).off('resize.MoBarTop');
            }
        });
    },

    /**
     * Track number of page views.
     */
    track_page_views: function () {

        if (mailoptin_globals.is_new_returning_visitors_cookies === 'true') {
            var prev_count = Cookies.get('mo_page_views_counter');
            var count = (prev_count === undefined) ? 0 : prev_count;

            // cookie expiration is missing thus making it a session cookie.
            Cookies.set('mo_page_views_counter', ++count);
        }
    },

    set_visitor_cookies: function () {
        // Set two cookies: persistent visitor and session visitor.
        // If persistent visitor already exists, don't set anything.
        // This is how we determine new vs. returning visitors.
        // basically the session cookie is to keep identifying the visitor until session expires
        // then next visit, they become a returning visitor.
        if (mailoptin_globals.is_new_returning_visitors_cookies === 'true') {
            if (!Cookies.get('mo_has_visited')) {
                Cookies.set('mo_is_new', 'true');
                Cookies.set('mo_has_visited', 'true', {expires: 3999});
            }
        }
    },

    isAdblockEnabled: function () {
        return typeof mailoptin_no_adblock_detected === 'undefined';
    },

    isAdblockDisabled: function () {
        return typeof mailoptin_no_adblock_detected !== 'undefined';
    },

    visitor_is_new: function () {
        return Cookies.get('mo_has_visited') === 'true' && Cookies.get('mo_is_new') === 'true';
    },

    visitor_is_returning: function () {
        return Cookies.get('mo_has_visited') === 'true' && !Cookies.get('mo_is_new');
    },

    /**
     * Get number of page views.
     */
    get_page_views: function () {
        return Number(Cookies.get('mo_page_views_counter'));
    },

    /**
     * Animate optin form display
     */
    animate_optin_display: function (effects) {

        if (typeof effects !== 'undefined' && effects !== '') {

            effects = effects.replace(/\bMOshake\b/, 'MOheadShake')
                .replace(/\bMOlightSpeedIn\b/, 'MOlightSpeedInRight')
                .replace('MO', 'MOanimate__');

            this.find('.mo-optin-form-wrapper').animateOptin(effects);
        }
    },

    /**
     * Return the configuration in Javascript of an optin.
     * @param {string} optin_css_id could be optin campaign ID or css ID (optin id with optin type joined by "_")
     * @returns {object}
     */
    optin_js_config: function (optin_css_id) {
        return window[optin_css_id];
    },

    /** @todo move this into its own class/object such that all methods/functions utilized when conversion happens have access to properties and data without passing the data/properites via arguments
     * @see https://www.phpied.com/3-ways-to-define-a-javascript-class/
     */
    optin_conversion: function () {

        var optin_data, optin_container, $optin_uuid, $optin_type, $optin_css_id, optin_js_config, self;

        self = this;

        // if we are in customizer preview, bail.
        if ($.MailOptin.is_customize_preview === true) return;

        var process_form = function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            optin_container = $(this).parents('.moOptinForm');
            $optin_uuid = optin_container.attr('id');
            $optin_type = optin_container.attr('data-optin-type');
            $optin_css_id = $optin_uuid + '_' + $optin_type;
            optin_js_config = self.optin_js_config($optin_css_id);

            self.hide_optin_error($optin_uuid, optin_container);

            // data variable is only populated if validation passes.
            if (self.validate_optin_form_fields($optin_css_id, optin_js_config, optin_container)) {

                // loop over form fields and create and object with key to the field name and value the field value.
                var all_form_fields_and_values = $('form#' + $optin_css_id + '_form').serializeArray().reduce(function (obj, item) {
                    if (item.name.indexOf('[]') !== -1) {
                        var item_name = item.name.replace('[]', '');
                        if (typeof obj[item_name] === 'undefined') {
                            obj[item_name] = [];
                            obj[item_name].push(item.value);
                        } else {
                            obj[item_name].push(item.value);
                        }
                    } else {
                        obj[item.name] = item.value;
                    }
                    return obj;
                }, {});

                optin_data = $.extend({}, all_form_fields_and_values, {
                    optin_uuid: $optin_uuid,
                    optin_campaign_id: optin_js_config.optin_campaign_id,
                    email: $('input#' + $optin_css_id + '_email_field', optin_container).val(),
                    name: $('input#' + $optin_css_id + '_name_field', optin_container).val(),
                    _mo_timestamp: $('input#' + $optin_css_id + '_honeypot_timestamp').val(),
                    user_agent: navigator.userAgent,
                    conversion_page: window.location.href,
                    referrer: document.referrer || "",
                    _mo_tz: $.MailOptin.visit_data().tz
                });

                self.addProcessingOverlay.call(optin_container);

                self.subscribe_to_email_list(optin_data, optin_container, optin_js_config, $optin_type);
            }
        };

        // this is important so form can be processed when say enter button is pressed to submit form.
        $(document).on('submit.moOptinSubmit', 'form.mo-optin-form', process_form);
        // added this option because there was an issue where form submit event didn't work.
        $(document).on('click.moOptinSubmit', '.mo-optin-form-submit-button', process_form);
    },

    /**
     * Add overlay over optin lighbox/modal that shows spinner and success message.
     *
     */
    addProcessingOverlay: function () {
        this.find('.mo-optin-spinner').show();
    },

    /**
     * Remove overlay over optin lighbox/modal that shows spinner and success message.
     *
     */
    removeProcessingOverlay: function () {
        this.find('.mo-optin-spinner').hide();
    },

    /**
     * Add close icon to processing overlay modal after successful optin.
     */
    addSuccessCloseIcon: function () {
        this.find('.mo-optin-spinner').after('<a href="#" class="mo-optin-success-close" rel="moOptin:close">×</a>');
    },

    /**
     * Remove close icon to processing overlay modal after successful optin.
     */
    removeSuccessCloseIcon: function () {
        this.find('.mo-optin-success-close').remove();
    },

    /**
     * overlay over optin lighbox/modal that shows spinner and success message.
     *
     */
    /**
     * Spin to win: turn the wheel to the slice the site drew (never chosen here), then put the prize where
     * the form was. Text goes in with .text(), so nothing from the response is read as HTML.
     *
     * @param {object} container the campaign
     * @param {object} wheel the prize: slice, type, label, code, url, button, text, cart_token, emailed, already
     */
    wheel_result: function (container, wheel, email) {
        var self = this, $c = $(container), $dial = $c.find('.mo-wheel-dial').first(), $rotor = $dial.find('.mo-wheel-rotor');
        var $form = $c.find('.mo-wheel-form').first(), $result = $c.find('.mo-wheel-result').first();

        var show = function () {
            // The box is a live region that was always there, so its new text is read out; focus moves to it.
            $result.empty().append(self.wheel_result_html(wheel, $c)).addClass('mo-wheel-result-on');
            $form.hide();
            $result.trigger('focus');
            if (wheel.cart_token) self.wheel_apply(wheel.cart_token, $result, email);
        };

        if (wheel.already || wheel.slice === null || wheel.slice === undefined || !$rotor.length) return show();

        var n = parseInt($dial.data('slices'), 10) || 1, pointer = parseInt($dial.data('pointer'), 10) || 0, step = 360 / n;
        // Five full turns, then the slice's middle under the pointer, a little off-centre so it looks natural.
        var jitter = (Math.random() - 0.5) * step * 0.6;
        var target = 360 * 5 + pointer - wheel.slice * step + jitter;
        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var duration = reduced ? 400 : 4800;

        $c.find('.mo-optin-form-submit-button').prop('disabled', true);
        $rotor.css({transition: 'transform ' + duration + 'ms cubic-bezier(.12,.75,.15,1)', transform: 'rotate(' + target + 'deg)'});

        setTimeout(show, duration + 350);
    },

    wheel_result_html: function (wheel, $c) {
        var $box = $('<div class="mo-wheel-result-inner"></div>');
        var add = function (tag, cls, text) {
            if (!text) return null;
            return $('<' + tag + '>').addClass(cls).text(text).appendTo($box);
        };

        if (wheel.already) {
            add('div', 'mo-wheel-result-title', wheel.message);
            return $box;
        }

        if (wheel.type === 'none' || !wheel.type) {
            add('div', 'mo-wheel-result-title', wheel.title);
            add('p', '', wheel.text);
            return $box;
        }

        add('div', 'mo-wheel-eyebrow', wheel.win_label);
        add('div', 'mo-wheel-result-title', wheel.type === 'link' ? wheel.title : wheel.label);
        add('p', '', wheel.text);

        if (wheel.code) {
            var $prize = $('<div class="mo-wheel-prize"></div>').appendTo($box);
            $('<span class="mo-wheel-code"></span>').text(wheel.code).appendTo($prize);
            $('<button type="button" class="mo-wheel-copy"></button>').text(mailoptin_globals.wheel_copy || 'Copy')
                .attr('data-code', wheel.code).attr('data-copied', mailoptin_globals.wheel_copied || 'Copied').appendTo($prize);
        }

        if (wheel.url && /^https?:\/\//i.test(wheel.url)) {
            $('<a class="mo-wheel-action" target="_blank" rel="noopener"></a>').attr('href', wheel.url).text(wheel.button || wheel.url).appendTo($box);
        }

        var lines = [];
        if (wheel.cart_token) lines.push(mailoptin_globals.wheel_in_cart || '');
        if (wheel.emailed) lines.push(mailoptin_globals.wheel_emailed || '');
        lines = $.grep(lines, function (l) { return l !== ''; });
        if (lines.length) add('p', 'mo-wheel-check', lines.join(' ')).css('margin-top', '14px');

        return $box;
    },

    /** Puts a won shop coupon (and a free product) in the cart, then refreshes the mini cart. */
    wheel_apply: function (token, $result, email) {
        $.post(mailoptin_globals.mailoptin_ajaxurl.toString().replace('%%endpoint%%', 'wheel_apply'), {token: token, email: email || ''}, function (res) {
            if (res && res.success && res.data && res.data.applied) {
                $(document.body).trigger('wc_fragment_refresh');
            } else {
                // Not in the cart after all: say so, rather than claim it is.
                $result.find('.mo-wheel-check').text(mailoptin_globals.wheel_not_in_cart || '');
            }
        }, 'json');
    },

    displaySuccessContent: function () {
        // display the success container div.
        this.find('.mo-optin-success-msg').show();
        this.addClass('mo-optin-success-state');
    },

    /**
     * Remove spinner.
     */
    removeSpinner: function () {
        // remove spinner gif icon
        this.find('.mo-optin-spinner').css('background-image', 'none');
    },

    /**
     * Display error message from optin.
     *
     * @param {string} error_message
     */
    displayErrorMessage: function (error_message) {
        this.find('.mo-optin-error').html(error_message).show();
    },

    /**
     * Set conversion / close cookie for campaign.
     *
     * @param {string} type type of cookie to set. Can be exit or success cookie.
     * @param {string} optin_uuid
     * @param {object} optin_js_config
     */
    set_cookie: function (type, optin_uuid, optin_js_config) {
        // default test mode to false.
        var test_mode = optin_js_config.test_mode || false;

        var cookie = optin_js_config.cookie;
        if (type == 'success') {
            cookie = optin_js_config.success_cookie;
        }

        // if type is exit cookie, return empty. if it's success, it get appended to 'mo_' when setting cookie key.
        type = type === 'exit' ? '' : type + '_';

        if (!test_mode) {

            Cookies.set('mo_' + type + optin_uuid, true, {expires: cookie});
            // set either global exit or success cookie depending on the context in which set_cookie() is called.
            // cookie expiration could be optin_js_config.global_cookie or optin_js_config.global_success_cookie
            // no need to check if cookie value is not zero(0) before setting cookie because a cookie set to expires in 0 days
            // returns undefined when tried to be gotten.
            Cookies.set('mo_global_' + type + 'cookie', true, {expires: optin_js_config['global_' + type + 'cookie']});
        }
    },

    /**
     * POST collected optin data to appropriate connected email list.
     *
     * @param {mixed} optin_data
     * @param {object} optin_container jQuery object of the parent div container
     * @param {object} optin_js_config optin JS configuarations
     * @param {object} $optin_type optin optin type of the optin form being looped.
     */
    subscribe_to_email_list: function (optin_data, optin_container, optin_js_config, $optin_type) {
        var self = this;

        $.post(mailoptin_globals.mailoptin_ajaxurl.toString().replace('%%endpoint%%', 'subscribe_to_email_list'),
            {
                optin_data: optin_data
            },
            function (response) {
                if (!$.isEmptyObject(response) && 'success' in response) {
                    if (response.success === true) {

                        $(document.body).trigger('moOptinConversion', [optin_container, optin_js_config, optin_data, response]);

                        // set cookie for this option conversion
                        self.set_cookie('success', optin_data.optin_uuid, optin_js_config);

                        // A prize wheel: turn it to the prize the site drew, then show the prize.
                        if (optin_js_config.is_wheel === true && response.wheel) {
                            self.removeProcessingOverlay.call(optin_container);
                            self.removeSpinner.call(optin_container);
                            self.wheel_result(optin_container, response.wheel, optin_data.email);
                            return;
                        }

                        // do not include success icon if icon_close (close icon automatically attached to lightbox) is set to true.
                        // icon_close config is always false for none lightbox optin forms. see ./Core/src/OptinForms/AbstractOptinForm.php LN497

                        // Because JavaScript treats 0 as loosely equal to false (i.e. 0 == false, but 0 !== false),
                        // to check for the presence of value within array, you need to check if it's not equal to (or greater than) -1.

                        /**@todo revisit this when new optin type is added */
                        if ($.inArray($optin_type, ['lightbox', 'bar', 'slidein']) !== -1 && optin_js_config.icon_close !== true) {
                            self.addSuccessCloseIcon.call(optin_container);
                        }

                        self.displaySuccessContent.call(optin_container);

                        self.removeSpinner.call(optin_container);
                    } else {
                        self.removeProcessingOverlay.call(optin_container);
                        self.removeSuccessCloseIcon.call(optin_container);
                        self.displayErrorMessage.call(optin_container, response.message);
                    }
                } else {
                    self.displayErrorMessage.call(optin_container, optin_js_config.unexpected_error);
                    self.removeProcessingOverlay.call(optin_container);
                    self.removeSuccessCloseIcon.call(optin_container);
                }
            },
            'json'
        );
    },

    /**
     * Validate name and email fields.
     *
     * @param {string} $optin_css_id optin CSS ID
     * @param {object} optin_js_config optin js config
     * @param {object} optin_container
     * @returns {boolean}
     */
    validate_optin_form_fields: function ($optin_css_id, optin_js_config, optin_container) {

        var namefield_error = optin_js_config.name_missing_error,
            emailfield_error = optin_js_config.email_missing_error,
            honeypot_error = optin_js_config.honeypot_error,
            note_acceptance_error = optin_js_config.note_acceptance_error,
            custom_field_required_error = optin_js_config.custom_field_required_error,

            self = this,
            name_field = $('#' + $optin_css_id + '_name_field:visible', optin_container),
            email_field = $('#' + $optin_css_id + '_email_field:visible', optin_container),
            acceptance_checkbox = $('#' + $optin_css_id + ' .mo-acceptance-checkbox', optin_container),

            honeypot_email_field = $('#' + $optin_css_id + '_honeypot_email_field', optin_container).val(),
            honeypot_website_field = $('#' + $optin_css_id + '_honeypot_website_field', optin_container).val(),
            response = true;

        // Throw error if either of the honeypot fields are filled.
        if (honeypot_email_field.length > 0 || honeypot_website_field.length > 0) {
            self.display_optin_error.call(undefined, $optin_css_id, honeypot_error, optin_container);
            response = false;
        }

        $('#' + $optin_css_id + ' .mo-optin-form-custom-field', optin_container).each(function () {
            var cache = $(this),
                field_id = $(this).data('field-id'),
                required_field_bucket = optin_js_config.required_custom_fields,
                cache_value = cache.val();

            if (cache.find('input[type=radio]').length > 0) {
                cache_value = cache.find('input[type=radio]:checked').length === 0 ? '' : cache.find('input[type=radio]:checked').val();
            }

            if (cache.find('input[type=checkbox]').length > 0) {
                cache_value = cache.find('input[type=checkbox]:checked').length === 0 ? '' : cache.find('input[type=checkbox]:checked').val();
            }

            if ($.inArray(field_id, required_field_bucket) !== -1 && cache_value === "") {
                self.display_optin_error.call(cache, $optin_css_id, custom_field_required_error, optin_container);
                response = false;
            }
        });

        // if this is an email field, validate that the email address.
        if (email_field.length > 0) {
            if (self.isValidEmail(email_field.val()) === false) {
                self.display_optin_error.call(email_field, $optin_css_id, emailfield_error, optin_container);
                response = false;
            }
        }

        // if this is a name field, check if the field isn't empty.
        if (optin_js_config.name_field_required === true && name_field.length > 0) {
            if (name_field.val() === "") {
                self.display_optin_error.call(name_field, $optin_css_id, namefield_error, optin_container);
                response = false;
            }
        }

        // we are doing a return here to ensure core validation has passed before hooked validations.
        if (response === false) return response;

        if (acceptance_checkbox.length > 0) {
            if (acceptance_checkbox[0].checked === false) {
                self.display_optin_error.call(undefined, $optin_css_id, note_acceptance_error, optin_container);
                return false;
            }
        }

        var added_validation = $(document.body).triggerHandler('mo_validate_optin_form_fields', [self, $optin_css_id, optin_js_config]);

        if (added_validation === false) response = false;

        return response;
    },

    /**
     * Output an optin error with the field highlighted red.
     *
     * @param {string} $optin_css_id optin CSS ID
     * @param {object} optin_container
     * @param {string} error
     */
    display_optin_error: function ($optin_css_id, error, optin_container) {
        if (this !== undefined) {
            this.css("-webkit-box-shadow", "inset 0px 0px 0px 2px #f45a4a");
            this.css("-moz-box-shadow", "inset 0px 0px 0px 2px #f45a4a");
            this.css("box-shadow", "inset 0px 0px 0px 2px #f45a4a");
        }

        var mo_optin_error_text = $('div#' + $optin_css_id + ' .mo-optin-error', optin_container);
        if (typeof error !== 'undefined' && typeof mo_optin_error_text !== 'undefined' && mo_optin_error_text.length > 0) {
            if (typeof error == "string") {
                mo_optin_error_text.text(error).show();
            }
        }
    },

    /**
     * Hide optin error including removing the red border.
     *
     * @param {string} $optin_css_id optin CSS ID
     * @param {object} optin_container
     */
    hide_optin_error: function ($optin_css_id, optin_container) {
        var input_fields = $('.mo-optin-field', optin_container);
        $('.mo-optin-error', optin_container).hide();
        input_fields.css('-webkit-box-shadow', '');
        input_fields.css('-moz-box-shadow', '');
        input_fields.css('box-shadow', '');
    },

    /**
     * Check if email address is valid.
     *
     * @param {string} email
     * @returns {boolean}
     */
    isValidEmail: function (email) {
        return (new RegExp(/^((([a-z]|\d|[!#\$%&'\*\+\-\/=\?\^_`{\|}~]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])+(\.([a-z]|\d|[!#\$%&'\*\+\-\/=\?\^_`{\|}~]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])+)*)|((\x22)((((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(([\x01-\x08\x0b\x0c\x0e-\x1f\x7f]|\x21|[\x23-\x5b]|[\x5d-\x7e]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(\\([\x01-\x09\x0b\x0c\x0d-\x7f]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF]))))*(((\x20|\x09)*(\x0d\x0a))?(\x20|\x09)+)?(\x22)))@((([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|\d|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.)+(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])|(([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])([a-z]|\d|-|\.|_|~|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])*([a-z]|[\u00A0-\uD7FF\uF900-\uFDCF\uFDF0-\uFFEF])))\.?$/i)).test(email);
    },

    ga_event_tracking: function (action, optin_js_config) {

        action = action || 'impression';

        if (mailoptin_optin.is_var_defined(optin_js_config, 'ga_active') === false) return;

        if (typeof ga === "function") {

            ga(function () {

                var trackingId = ga.getAll()[0].get('trackingId');

                if (mailoptin_optin.is_defined_not_empty(trackingId) === false) return;

                ga('create', trackingId, 'auto', 'moTracker');

                ga('moTracker.send', 'event', optin_js_config.optin_campaign_name, action, optin_js_config.optin_uuid, {
                    nonInteraction: true
                });
            });
        }

        if (typeof gtag === "function") {
            var event_type = 'mailoptin_' + action,
                optin_campaign_name = optin_js_config['optin_campaign_name'],
                event_payload = {
                    "optin_campaign": optin_campaign_name,
                    "optin_uuid": optin_js_config.optin_uuid,
                };

            if (typeof optin_js_config.mo_email !== 'undefined') {
                event_payload['lead_email'] = optin_js_config.mo_email;
            }

            gtag("event", event_type, event_payload);
        }
    },

    /**
     * Handle after conversion/success actions
     * @param e
     * @param optin_container
     * @param optin_js_config
     * @param optin_data
     */
    success_action_after_conversion: function (e, optin_container, optin_js_config, optin_data) {
        var success_action = optin_js_config.success_action;
        var redirect_url_val = optin_js_config.redirect_url_value;
        var success_js_script = optin_js_config.success_js_script;
        var is_success_js_script = typeof success_js_script !== 'undefined' && success_js_script !== '';
        var lead_data = {};

        lead_data.mo_name = lead_data.mo_email = '';

        if (mailoptin_optin.is_defined_not_empty(optin_data.name)) {
            lead_data.mo_name = optin_data.name;
        }

        if (mailoptin_optin.is_defined_not_empty(optin_data.email)) {
            lead_data.mo_email = optin_data.email;
            optin_js_config.mo_email = optin_data.email
        }

        // track GA
        mailoptin_optin.ga_event_tracking('conversion', optin_js_config);

        mailoptin_optin.content_locker_removal(optin_js_config);

        // if we have a JS success script, trigger it.
        if (is_success_js_script === true) {
            if (success_js_script.indexOf('<script') === -1) {
                success_js_script = '<script type="text/javascript">' + success_js_script + '</script>';
            }

            success_js_script = success_js_script.replace(/\[EMAIL\]/gi, lead_data.mo_email).replace(/\[NAME\]/gi, lead_data.mo_name);

            $(optin_container).append(success_js_script);
        }

        if (typeof success_action !== 'undefined' && $.inArray(success_action, ['close_optin', 'redirect_url', 'close_optin_reload_page']) !== -1) {

            setTimeout(function () {
                $.MoModalBox.close();
                mailoptin_optin._close_optin(optin_container);

                if (success_action === 'close_optin_reload_page') {
                    return window.location.reload();
                }

                if (success_action === 'redirect_url' && typeof redirect_url_val !== 'undefined' && redirect_url_val !== '') {
                    if (typeof optin_js_config.pass_lead_data !== 'undefined' && true === optin_js_config.pass_lead_data) {
                        redirect_url_val = mailoptin_optin.add_query_args(redirect_url_val, lead_data);
                    }

                    window.location.assign(redirect_url_val);
                }

            }, 1000);
        }
    },

    /**
     * All event subscription / listener should go here.
     */
    eventSubscription: function () {
        // track impression for optin form other than modals
        $(document.body).on('moOptin:show', function (e, optin_uuid, optin_js_config, optin_type) {

            mailoptin_optin.content_locker_init(optin_js_config);

            $.MailOptin.track_impression(optin_uuid);
            // track GA
            mailoptin_optin.ga_event_tracking('impression', optin_js_config);

            //find and play audio
            mailoptin_optin.optin_sound_selected(optin_js_config, optin_type);
        });

        // success actions
        $(document.body).on('moOptinConversion', this.success_action_after_conversion);
    },

    is_content_locker_enabled: function (optin_config) {
        return mailoptin_optin.is_var_defined(optin_config, 'content_lock_status') &&
            true === optin_config.content_lock_status &&
            mailoptin_optin.is_var_defined(optin_config, 'content_lock_style');
    },

    content_locker_init: function (optin_config) {

        if (!mailoptin_optin.is_content_locker_enabled(optin_config)) return;

        var nextAll2 = false,
            contentSelector = optin_config.content_lock_selector.toString(),
            nextAll = $('#' + optin_config.optin_uuid).nextAll();

        if (contentSelector.length > 0 && $(contentSelector).length > 0) {
            nextAll2 = $(contentSelector);
        }

        if ('removal' === optin_config.content_lock_style) {

            if (nextAll2 !== false) {
                nextAll2.hide();
            }

            nextAll.each(function (index, el) {
                mailoptin_optin.content_locker_storage.push($(el).clone(true));
                $(el).remove();
            });

        } else {

            if (nextAll2 !== false) {
                nextAll2.addClass('mailoptin-content-lock');
            }

            nextAll.each(function (index, el) {
                $(el).addClass('mailoptin-content-lock');
            });
        }
    },

    content_locker_removal: function (optin_config) {

        if (!mailoptin_optin.is_content_locker_enabled(optin_config)) return;

        var optin_container = $('#' + optin_config.optin_uuid),
            nextAll2 = false,
            nextAll = optin_container.nextAll(),
            contentSelector = optin_config.content_lock_selector.toString();

        if (contentSelector.length > 0 && $(contentSelector).length > 0) {
            nextAll2 = $(contentSelector);
        }

        if ('removal' === optin_config.content_lock_style) {

            mailoptin_optin.content_locker_storage.reverse();

            if (nextAll2 !== false) {
                nextAll2.show();
            }

            $.each(mailoptin_optin.content_locker_storage, function (index, el) {
                optin_container.after(el);
            });

        } else {
            nextAll.removeClass('mailoptin-content-lock');

            if (nextAll2 !== false) {
                nextAll2.removeClass('mailoptin-content-lock');
            }
        }
    },

    add_query_args: function (uri, params) {
        var separator = uri.indexOf('?') !== -1 ? '&' : '?';
        for (var key in params) {
            if (params.hasOwnProperty(key)) {
                uri += separator + key + '=' + params[key];
                separator = '&';
            }
        }
        return uri;
    },

    /**
     * Initialize optin event handlers.
     */
    initOptinForms: function () {

        $(".moOptinForm").each(function (index, element) {
            $(element).mailoptin();
        });

        var clickLaunchCallback = function (optin_uuid) {

            if (typeof optin_uuid !== 'undefined') {

                var selector = [
                    "#" + optin_uuid + ".mo-optin-form-lightbox",
                    "#" + optin_uuid + ".mo-optin-form-bar",
                    "#" + optin_uuid + ".mo-optin-form-slidein"
                ];

                $(selector.join(',')).mailoptin(true);
            }
        }

        // click launch trigger
        $(document).on('click', '.mailoptin-click-trigger', function (event) {

            event.preventDefault();

            var optin_uuid = $(this).data('optin-uuid') || $(this).attr('id');

            clickLaunchCallback(optin_uuid);
        });

        // click launch trigger
        $(document).on('click', '[class*="mailoptin-click-trigger-"]', function (event) {

            event.preventDefault();

            // https://stackoverflow.com/a/17367855/2648410
            var optin_uuid, className = this.className.match(/mailoptin-click-trigger-([a-z0-1]+)\s?/i);

            if (null !== className) {
                optin_uuid = className[1];
                clickLaunchCallback(optin_uuid);
            }
        });
    },

    is_scheduled_for_display: function () {

        if ($.MailOptin.is_customize_preview === true) return;

        $('.moOptinForm').each(function () {

            var optin_uuid = $(this).attr('id');
            var optin_js_config = mailoptin_optin.optin_js_config(optin_uuid);

            var schedule_status = optin_js_config.schedule_status;
            var schedule_start = optin_js_config.schedule_start;
            var schedule_end = optin_js_config.schedule_end;
            var schedule_timezone = optin_js_config.schedule_timezone;

            // if we have a JS success script, trigger it.
            if (mailoptin_optin.is_defined_not_empty(schedule_status) &&
                mailoptin_optin.is_defined_not_empty(schedule_start) &&
                mailoptin_optin.is_defined_not_empty(schedule_end) &&
                mailoptin_optin.is_defined_not_empty(schedule_timezone)
            ) {
                var d = new Date(), timezone_offset, now, start, end, result;

                if (schedule_timezone === 'visitors_local_time') {
                    // d.getTimezoneOffset is in minutes. so 60 * 1000 converts it to milliseconds
                    timezone_offset = d.getTimezoneOffset() * 60 * 1000;
                } else {
                    // convert timezone offset in seconds to milliseconds
                    timezone_offset = schedule_timezone * 1000;
                }

                // getTime return time in UTC/GMT
                now = d.getTime();
                // we substracting time offset to convert the time to UTC/GMT
                start = Date.parse(schedule_start + ' GMT') - timezone_offset;
                end = Date.parse(schedule_end + ' GMT') - timezone_offset;

                // return true of optin should display or false otherwise
                result = (now >= start && now <= end);

                if (result === false) {
                    $(this).remove();
                } else {
                    $(this).mailoptin();
                }
            }
        });
    },

    /**
     * Check if value is defined
     *
     * @param {mixed} varObj
     * @param {string|int} objKey
     *
     * @returns {boolean}
     */
    is_var_defined: function (varObj, objKey) {
        return (typeof varObj[objKey] !== 'undefined');
    },

    /**
     * Check if value is defined and not empty.
     *
     * @param {mixed} val
     *
     * @returns {boolean}
     */
    is_defined_not_empty: function (val) {
        return (typeof val !== 'undefined' && val !== '');
    },

    init_date_picker: function () {
        $('.mo-optin-form-custom-field.date-field').each(function () {
            var currentYr = (new Date()).getFullYear();
            var range = 150;
            var minYear = currentYr - range;
            new Pikaday({
                field: this,
                minDate: new Date(minYear, 0),
                maxDate: new Date(currentYr + range, 0),
                yearRange: range + range,
                toString: function (date, format) {
                    var day = ('0' + date.getDate()).slice(-2);
                    var month = ('0' + (date.getMonth() + 1)).slice(-2);
                    var year = date.getFullYear();
                    return year + '-' + month + '-' + day;
                }
            });
        });
    },

    /**
     * Two steps: a yes-or-no question first, then the form. It uses what every email form has (its
     * headline, text, fields and submit button), so templates need nothing of their own: the question
     * goes in the headline and text, the fields and note are hidden, and a copy of the submit button is
     * the Yes button. Yes brings the form back as it was, with a progress bar on top.
     */
    init_twostep: function () {
        var self = this;

        $('.moOptinForm').each(function () {
            var uuid = $(this).attr('id'), config = self.optin_js_config(uuid);

            if (config && config.twostep) self.add_twostep($(this), uuid, config);
        });
    },

    add_twostep: function ($optin, uuid, config) {
        var t = config.twostep, preview = $.MailOptin.is_customize_preview === true;

        if ($optin.data('mo-twostep')) return;

        var $form = $optin.find('form.mo-optin-form').first(),
            $fields = $form.find('.mo-optin-fields-wrapper').first(),
            $submit = $form.find('.mo-optin-form-submit-button').first();

        // Not a form this knows how to split: leave it as it is.
        if (!$form.length || !$fields.length || !$submit.length) return;

        $optin.data('mo-twostep', true);

        if (!document.getElementById('mo-twostep-css')) {
            $('head').append('<style id="mo-twostep-css">' +
                '.mo-twostep-hidden{display:none!important}' +
                '.mo-twostep-question{display:flex;flex-direction:column;align-items:center;gap:12px;margin:18px auto 6px;max-width:380px;width:100%;box-sizing:border-box}' +
                '.mo-twostep-yes-holder{display:block!important;width:100%;margin:0!important;padding:0!important;border:0!important;background:none!important;box-shadow:none!important}' +
                '.mo-twostep-yes-holder .mo-twostep-yes{display:block!important;width:100%!important;max-width:none!important;margin:0!important;float:none!important;position:relative!important;top:auto!important;right:auto!important;bottom:auto!important;left:auto!important;transform:none!important;box-sizing:border-box;text-align:center;-webkit-appearance:none;appearance:none}' +
                // The No link: quieter than Yes, still readable (4.5:1 on the template's own text colour), with a
                // 44px tap target and a visible focus ring.
                '.mo-twostep-no{display:inline-flex;align-items:center;min-height:44px;padding:0 12px;box-sizing:border-box;font-size:14px;opacity:.8;text-decoration:underline!important;color:inherit;cursor:pointer;box-shadow:none!important}' +
                '.mo-twostep-no:focus-visible{outline:2px solid currentColor;outline-offset:2px;opacity:1}' +
                '.mo-twostep-yes:focus-visible{outline:2px solid var(--mo-twostep-ring,currentColor)!important;outline-offset:3px!important}' +
                '.mo-twostep-progress{margin:0 0 16px;font-size:13px;font-weight:600;line-height:1.3;text-align:left;color:inherit}' +
                '.mo-twostep-progress-labels{display:flex;justify-content:space-between;gap:12px;margin-bottom:6px;opacity:.8}' +
                '.mo-twostep-progress-track{height:8px;border-radius:999px;background:rgba(127,127,127,.22);overflow:hidden}' +
                '.mo-twostep-progress-fill{height:100%;width:50%;border-radius:999px}' +
                '</style>');
        }

        // Computed colours resolve while a pop-up is still hidden, so this works before it shows.
        var text_colour = function ($el) {
            return $el.length ? window.getComputedStyle($el[0]).color : '';
        };
        var $headline = $form.find('.mo-optin-form-headline').first(),
            $text = $form.find('.mo-optin-form-description').first(),
            $hide = $fields.add($form.find('.mo-optin-form-note, .mo-optin-form-cta-button').not($fields.find('*'))),
            // The template's own nodes, moved aside and put back on Yes, so their handlers and state survive.
            saved = {headline: $headline.contents().detach(), text: $text.contents().detach()},
            // The template's own text colour, for the No link and the progress labels: the form's colour
            // can be dark on a dark template.
            ink = text_colour($form.find('.mo-optin-form-description').first()) || text_colour($form.find('.mo-optin-form-headline').first()),
            created_headline = false;

        // Step 1: the question in the template's own headline and text.
        if (!$headline.length) {
            $headline = $('<div class="mo-optin-form-headline mo-twostep-title"></div>').prependTo($form);
            created_headline = true;
        }
        $headline.text(t.question);
        if ($text.length) {
            if (t.text) $text.text(t.text); else $text.addClass('mo-twostep-hidden');
        }

        // Yes: a copy of the template's submit button, wearing its look. Many templates style the button by
        // its id or its place in the form, which a copy can't keep, so the look is copied from the real one.
        // The copy drops the submit button's class, so nothing that binds to submit buttons (reCAPTCHA v3,
        // the Customizer's label preview) treats Yes as one. The click stops at Yes, so it never submits.
        var look = {}, cs = window.getComputedStyle($submit[0]);
        $.each(['background-color', 'background-image', 'color', 'font-family', 'font-size', 'font-weight', 'font-style',
            'text-transform', 'letter-spacing', 'line-height', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'border-top', 'border-right', 'border-bottom', 'border-left', 'border-radius', 'box-shadow', 'text-shadow', 'height', 'min-height'], function (i, prop) {
            look[prop] = cs.getPropertyValue(prop);
        });

        $hide.addClass('mo-twostep-hidden');

        var $yes = $submit.clone(false).removeAttr('id').removeAttr('name').removeAttr('style').attr('type', 'button')
            .removeClass('mo-optin-form-submit-button').addClass('mo-twostep-yes');
        if ($yes.is('input')) $yes.val(t.yes); else $yes.text(t.yes);
        $.each(look, function (prop, value) {
            if (value !== '') $yes[0].style.setProperty(prop, value, 'important');
        });
        $yes[0].style.setProperty('cursor', 'pointer', 'important');
        // At least a 44px tap target.
        if (!(parseFloat(look['min-height']) >= 44)) $yes[0].style.setProperty('min-height', '44px', 'important');

        // The focus ring takes the button's own colour, so it shows against the background around it.
        var $question = $('<div class="mo-twostep-question"></div>').css('--mo-twostep-ring', look['background-color'] || '')
            .append($('<div class="mo-twostep-yes-holder"></div>').append($yes));

        if (t.no) {
            $question.append($('<a href="#" rel="moOptin:close" class="mo-twostep-no"></a>').text(t.no).css('color', ink || ''));
        }

        // Where the form sits: every template already places its fields in the right column or row.
        $fields.before($question);

        var observer = null;

        // keep_current: the Customizer preview changed the headline or text, so keep that, not the saved copy.
        var to_form = function (keep_current) {
            if (observer) observer.disconnect();
            $question.remove();
            if (keep_current !== true) {
                if (created_headline) $headline.remove(); else $headline.empty().append(saved.headline);
                if ($text.length) $text.empty().append(saved.text);
            }
            if ($text.length) $text.removeClass('mo-twostep-hidden');
            $hide.removeClass('mo-twostep-hidden');

            if (t.progress) {
                var fill = window.getComputedStyle($submit[0]).backgroundColor;
                if (!fill || fill === 'transparent' || fill === 'rgba(0, 0, 0, 0)') fill = '#1f7a4d';

                var $bar = $('<div class="mo-twostep-progress" role="progressbar" aria-valuemin="0" aria-valuemax="2" aria-valuenow="2"></div>').attr('aria-valuetext', t.step).css('color', ink || '')
                    .append($('<div class="mo-twostep-progress-labels"></div>').append($('<span></span>').text(t.almost), $('<span></span>').text(t.step)))
                    .append($('<div class="mo-twostep-progress-track"></div>').append($('<div class="mo-twostep-progress-fill"></div>').css('background', fill)));
                $form.prepend($bar);
            }

            // A pop-up re-centres for its new height.
            $(window).trigger('resize');

            setTimeout(function () {
                var $email = $form.find('.mo-optin-form-email-field:visible').first();
                if (!$email.length) $email = $form.find('input:visible').not('[type=hidden]').first();
                $email.trigger('focus');
            }, 60);
        };

        // In the Customizer preview, editing the headline or text shows the form, with the edit in place.
        if (preview && window.MutationObserver) {
            observer = new MutationObserver(function () {
                to_form(true);
            });
            $headline.add($text).each(function () {
                observer.observe(this, {childList: true, characterData: true, subtree: true});
            });
        }

        $yes.on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            $optin.data('mo-twostep-said-yes', true);
            to_form();

            if (!preview) $.MailOptin.track_twostep_yes(uuid, 'yes');

            $(document.body).trigger('moOptinTwoStepYes', [uuid, config]);
        });

        // A subscription after Yes: counted, for "said yes; subscribed".
        $(document.body).on('moOptinConversion', function (e, container) {
            if (preview || !$optin.data('mo-twostep-said-yes')) return;
            if ($(container).is('#' + uuid) || $(container).closest('#' + uuid).length) {
                $optin.data('mo-twostep-said-yes', false);
                $.MailOptin.track_twostep_yes(uuid, 'done');
            }
        });
    },

    /**
     * Floating tabs: a button or tab that opens its campaign (pop-up, slide-in or bar). It hides while
     * the campaign is open, comes back when it closes, and goes once they subscribe.
     */
    init_launchers: function () {
        var self = this;

        $('.moOptinForm').each(function () {
            var uuid = $(this).attr('id'), config = self.optin_js_config(uuid);

            if (config && config.launcher && $.inArray(config.optin_type, ['lightbox', 'slidein', 'bar']) !== -1) {
                self.add_launcher($(this), uuid, config);
            }
        });
    },

    add_launcher: function ($optin, uuid, config) {
        var self = this, l = config.launcher, preview = $.MailOptin.is_customize_preview === true;

        if ($('.mo-launcher[data-optin-uuid="' + uuid + '"]').length) return;

        if (!document.getElementById('mo-launcher-css')) {
            $('head').append('<style id="mo-launcher-css">' +
                '.mo-launcher{position:fixed;z-index:99998;margin:0;border:0;cursor:pointer;font:600 15px/1.2 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;letter-spacing:0;text-transform:none;box-shadow:0 8px 24px rgba(0,0,0,.18);transition:opacity .2s,visibility .2s;white-space:nowrap;max-width:calc(100vw - 32px);overflow:hidden;text-overflow:ellipsis}' +
                '.mo-launcher:focus-visible{outline:3px solid #2271b1;outline-offset:2px}' +
                '.mo-launcher-hidden{opacity:0;visibility:hidden;pointer-events:none}' +
                '.mo-launcher-button{padding:12px 20px;border-radius:999px}' +
                '.mo-launcher-button.mo-launcher-bottom-right{right:24px;bottom:24px}.mo-launcher-button.mo-launcher-bottom-left{left:24px;bottom:24px}' +
                '.mo-launcher-button.mo-launcher-right{right:24px;top:50%;transform:translateY(-50%)}.mo-launcher-button.mo-launcher-left{left:24px;top:50%;transform:translateY(-50%)}' +
                '.mo-launcher-tab{padding:10px 18px;font-size:14px;box-shadow:0 4px 14px rgba(0,0,0,.15)}' +
                '.mo-launcher-tab.mo-launcher-bottom-right{right:24px;bottom:0;border-radius:6px 6px 0 0}.mo-launcher-tab.mo-launcher-bottom-left{left:24px;bottom:0;border-radius:6px 6px 0 0}' +
                '.mo-launcher-tab.mo-launcher-right,.mo-launcher-tab.mo-launcher-left{top:50%;writing-mode:vertical-rl;transform:translateY(-50%) rotate(180deg);max-height:calc(100vh - 32px);max-width:none}' +
                '.mo-launcher-tab.mo-launcher-right{right:0;border-radius:0 6px 6px 0}.mo-launcher-tab.mo-launcher-left{left:0;border-radius:6px 0 0 6px}' +
                '.mo-launcher-bar-top,.mo-launcher-bar-bottom{right:24px;padding:8px 16px;font-size:14px;box-shadow:0 4px 14px rgba(0,0,0,.15)}' +
                '.mo-launcher-bar-top{top:0;border-radius:0 0 6px 6px}.mo-launcher-bar-bottom{bottom:0;border-radius:6px 6px 0 0}' +
                '.admin-bar .mo-launcher-bar-top{top:32px}' +
                '@media (max-width:782px){.admin-bar .mo-launcher-bar-top{top:46px}}' +
                '@media (max-width:600px){.admin-bar .mo-launcher-bar-top{top:0}}' +
                '@media (max-width:767px){.mo-launcher-no-mobile{display:none!important}.mo-launcher-button.mo-launcher-bottom-right{right:16px;bottom:16px}.mo-launcher-button.mo-launcher-bottom-left{left:16px;bottom:16px}}' +
                '</style>');
        }

        var place = config.optin_type === 'bar' ? 'mo-launcher-bar-' + (config.bar_position === 'bottom' ? 'bottom' : 'top') :
            'mo-launcher-' + (l.style === 'tab' ? 'tab' : 'button') + ' mo-launcher-' + String(l.position || 'bottom_right').replace('_', '-');

        // Only where the campaign itself could show: the same visitor targeting rules.
        if (!preview && self.passes_targeting(config) === false) return;

        var $tab = $('<button type="button"></button>')
            .addClass('mo-launcher mo-launcher-hidden ' + place + (l.mobile === false ? ' mo-launcher-no-mobile' : ''))
            .attr('data-optin-uuid', uuid)
            .attr('aria-haspopup', config.optin_type === 'lightbox' ? 'dialog' : null)
            .attr('aria-controls', uuid)
            .attr('aria-expanded', 'false')
            .text(l.text)
            .css({background: l.background, color: l.color});

        $tab.appendTo(document.body);

        // In the Customizer the campaign stays open: the tab sits on top to be seen, and a bar's tab
        // just past the bar so it doesn't cover the bar's close button.
        if (preview) {
            $tab.css('z-index', 2147483647);
            if (config.optin_type === 'bar') $tab.css(config.bar_position === 'bottom' ? 'bottom' : 'top', $optin.find('.mo-optin-form-wrapper').first().outerHeight() || 0);
        }

        // Campaigns that open on load are already showing when their tab is made.
        var open = $optin.is(':visible'), closed_here = false;
        var subscribed = function () {
            if (config.global_success_cookie > 0 && Cookies.get('mo_global_success_cookie')) return true;
            if (Cookies.get('mo_success_' + uuid)) return true;
            // A split test: subscribing to any variant counts.
            var found = false;
            $.each(config.split_test_variants || {}, function (id, variant) {
                if (variant && variant.optin_uuid && Cookies.get('mo_success_' + variant.optin_uuid)) found = true;
            });
            return found;
        };
        var refresh = function () {
            // The Customizer shows it, so its look can be set.
            var show = preview || (!open && !subscribed() && (l.when !== 'after_close' || closed_here || !!Cookies.get('mo_' + uuid)));
            $tab.toggleClass('mo-launcher-hidden', !show).attr('aria-expanded', open ? 'true' : 'false');
        };

        var opened_from_tab = false;

        $tab.on('click', function (e) {
            e.preventDefault();
            if (preview) return;
            opened_from_tab = true;
            $('#' + uuid + '.mo-optin-form-lightbox, #' + uuid + '.mo-optin-form-bar, #' + uuid + '.mo-optin-form-slidein').mailoptin(true);
        });

        // Slide-ins and bars say moOptin:show on the campaign; pop-ups say moOptin:open there.
        $optin.on('moOptin:show moOptin:open', function () {
            open = true;
            refresh();
            // Opened from the tab: move the keyboard into it.
            if (opened_from_tab) {
                setTimeout(function () {
                    var target = $optin.find('input:visible, a[href]:visible, button:visible').first();
                    if (target.length) target.trigger('focus');
                }, 450);
            }
        });

        $optin.on('moOptin:close', function () {
            open = false;
            closed_here = true;
            // Closing sets its cookies just after: look again then, and give the keyboard back to the tab.
            setTimeout(refresh, 50);
            // After the pop-up has finished closing (it moves focus itself as it goes).
            if (opened_from_tab) {
                setTimeout(function () {
                    if (!$tab.hasClass('mo-launcher-hidden')) $tab.trigger('focus');
                }, 450);
            }
            opened_from_tab = false;
        });

        $(document.body).on('moOptinConversion', function (e, container) {
            if ($(container).is('#' + uuid) || $(container).closest('#' + uuid).length) $tab.remove();
        });

        refresh();
    },

    /**
     * Countdowns (see Countdown.php): work out each campaign's deadline, drop a campaign whose
     * countdown has ended and is set to stop it, and keep the rest ticking.
     */
    /** Campaigns whose countdown ended and stopped them, by uuid. */
    countdown_stopped: {},

    countdown_init: function () {
        var self = this, preview = $.MailOptin.is_customize_preview === true;

        $('.moOptinForm').each(function () {
            var $form = $(this), uuid = $form.attr('id'), config = self.optin_js_config(uuid);

            if (!config || !config.countdown) return;

            var cd = config.countdown, end = self.countdown_deadline(uuid, cd, preview);

            // Ended before this page loaded (a cached page, or a visitor's own countdown that ran
            // out on an earlier visit) and set to stop the campaign.
            if (end !== null && Date.now() >= end && cd.on_end === 'stop' && !preview) {
                self.countdown_stopped[uuid] = true;
                $form.remove();
                return;
            }

            var tick = function () {
                var $countdown = $form.find('.mo-countdown[data-mo-countdown]');
                // Gone, or already showing how it ended (the server wrote that): nothing to tick.
                if (!$countdown.length || $countdown.hasClass('mo-countdown-ended')) return false;

                // "After each visitor first sees it": the clock starts when the campaign is on screen.
                if (end === null) {
                    // A date the browser couldn't read: leave the server's numbers and stop ticking.
                    if (cd.mode !== 'evergreen') return false;
                    if (!self.countdown_on_screen($form)) return true;
                    end = self.countdown_start(uuid, cd, preview);
                }

                var left = Math.max(0, Math.round((end - Date.now()) / 1000));

                if (left === 0 && cd.mode === 'daily') {
                    end = self.countdown_next_midnight(cd);
                    left = Math.round((end - Date.now()) / 1000);
                }

                if (left === 0) {
                    self.countdown_end_reached($form, $countdown, cd, preview);
                    return false;
                }

                self.countdown_render($countdown, left, cd);
                return true;
            };

            if (!cd.own_color) self.countdown_fit_color($form);

            if (tick()) {
                var timer = setInterval(function () {
                    if (!tick()) clearInterval(timer);
                }, 1000);
            }
        });
    },

    /** The deadline in ms, or null while an evergreen countdown hasn't started. */
    countdown_deadline: function (uuid, cd, preview) {
        if (cd.mode === 'evergreen') {
            var start = preview ? null : this.countdown_stored(uuid);
            return start ? start + cd.duration * 1000 : null;
        }

        if (cd.mode === 'daily') return this.countdown_next_midnight(cd);

        // In the visitor's own time zone: the date and time as numbers, read on the visitor's clock.
        if (cd.end_local) {
            var p = cd.end_local, local = new Date(p[0], p[1] - 1, p[2], p[3], p[4], 0).getTime();
            return isNaN(local) ? null : local;
        }

        return cd.end ? cd.end * 1000 : null;
    },

    /**
     * The site's next midnight. The server sends the next few (so a clock change is already in them);
     * a page cached longer than that steps on a day at a time.
     */
    countdown_next_midnight: function (cd) {
        var now = Date.now(), ends = cd.ends || [], end = 0, i;
        for (i = 0; i < ends.length; i++) {
            end = ends[i] * 1000;
            if (end > now) return end;
        }
        end = end || now;
        while (end <= now) end += 86400000;
        return end;
    },

    /** Any part of the campaign inside the window. */
    countdown_on_screen: function ($form) {
        if (!$form.is(':visible')) return false;
        var r = $form[0].getBoundingClientRect();
        return r.bottom > 0 && r.right > 0 && r.top < (window.innerHeight || document.documentElement.clientHeight) && r.left < (window.innerWidth || document.documentElement.clientWidth);
    },

    /**
     * The server picks a theme colour for the countdown without knowing what's behind it. Here we can
     * see: if it reads poorly on its background, use whichever of the theme's own text colours
     * (headline, description, note) reads best there, else near-black or white. Not over an image.
     */
    countdown_fit_color: function ($form) {
        var el = $form.find('.mo-countdown[data-mo-countdown]')[0];
        if (!el || !window.getComputedStyle) return;

        var parse = function (value) {
            var m = String(value).match(/[\d.]+/g);
            if (!m || m.length < 3) return null;
            return {r: +m[0], g: +m[1], b: +m[2], a: m.length > 3 ? +m[3] : 1};
        };
        var luminance = function (c) {
            var f = function (v) {
                v /= 255;
                return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
            };
            return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
        };
        var ratio = function (a, b) {
            var x = luminance(a), y = luminance(b);
            return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
        };

        // The first solid background behind it; none at all means the page's white.
        var bg = {r: 255, g: 255, b: 255, a: 1}, e, style, c;
        for (e = el; e && e.nodeType === 1; e = e.parentElement) {
            style = window.getComputedStyle(e);
            if (style.backgroundImage && style.backgroundImage !== 'none') return;
            c = parse(style.backgroundColor);
            if (c && c.a >= 0.5) {
                bg = c;
                break;
            }
        }

        var current = parse(window.getComputedStyle(el).color);
        if (current && ratio(current, bg) >= 4.5) return;

        var candidates = [], best = null, bestRatio = 0;
        $.each(['.mo-optin-form-headline', '.mo-optin-form-description', '.mo-optin-form-note'], function (i, selector) {
            var found = $form.find(selector)[0];
            if (found) candidates.push(window.getComputedStyle(found).color);
        });
        candidates.push('rgb(31, 42, 36)', 'rgb(255, 255, 255)');

        $.each(candidates, function (i, value) {
            var color = parse(value), r;
            if (!color || color.a < 0.9) return;
            r = ratio(color, bg);
            if (r >= 4.5 && (best === null || bestRatio < 4.5)) {
                best = value;
                bestRatio = r;
                return false;
            }
            if (r > bestRatio) {
                best = value;
                bestRatio = r;
            }
        });

        if (best) el.style.color = best;
    },

    countdown_stored: function (uuid) {
        try {
            var value = parseInt(window.localStorage.getItem('mo_countdown_' + uuid), 10);
            return isNaN(value) ? null : value;
        } catch (e) {
            return null;
        }
    },

    countdown_start: function (uuid, cd, preview) {
        var now = Date.now();
        if (!preview) {
            try {
                window.localStorage.setItem('mo_countdown_' + uuid, String(now));
            } catch (e) {
                // Storage blocked (private browsing): the countdown runs for this page view only.
            }
        }
        return now + cd.duration * 1000;
    },

    countdown_render: function ($countdown, left, cd) {
        var days = Math.floor(left / 86400),
            hours = Math.floor(left % 86400 / 3600),
            minutes = Math.floor(left % 3600 / 60),
            seconds = left % 60,
            two = function (n) {
                return (n < 10 ? '0' : '') + n;
            },
            values = {days: days, hours: hours, minutes: minutes, seconds: seconds};

        $countdown.find('.mo-countdown-unit').each(function () {
            var unit = $(this).attr('data-unit');
            $(this).find('.mo-countdown-number').text(two(values[unit]));
            if (unit === 'days') $(this).prop('hidden', cd.hide_days && days === 0);
        });

        var $compact = $countdown.find('.mo-countdown-compact');
        if ($compact.length) {
            var time = two(hours) + ':' + two(minutes) + ':' + two(seconds);
            $compact.text(cd.hide_days && days === 0 ? time : days + 'd ' + time);
        }

        // Screen readers get a sentence, which changes once a minute, not every second.
        var sentence = this.countdown_sentence(left, cd.texts);
        var $sr = $countdown.find('.mo-countdown-sr');
        if ($sr.text() !== sentence) $sr.text(sentence);
    },

    countdown_sentence: function (left, texts) {
        var days = Math.floor(left / 86400), hours = Math.floor(left % 86400 / 3600), minutes = Math.floor(left % 3600 / 60), parts = [];
        var word = function (pair, n) {
            return (n === 1 ? pair[0] : pair[1]).replace('%s', n);
        };
        if (days) parts.push(word(texts.day, days));
        if (hours) parts.push(word(texts.hour, hours));
        if (minutes) parts.push(word(texts.minute, minutes));
        return parts.length ? texts.ends_in.replace('%s', parts.join(', ')) : texts.soon;
    },

    countdown_end_reached: function ($form, $countdown, cd, preview) {
        if (cd.on_end === 'message' && cd.message) {
            $countdown.removeAttr('role').removeAttr('data-mode').addClass('mo-countdown-ended');
            $countdown.empty().append($('<p class="mo-countdown-message"></p>').text(cd.message));
            return;
        }

        // In the Customizer the campaign stays, so its editor can still see it: stop at zero.
        if (cd.on_end === 'stop' && preview) {
            this.countdown_render($countdown, 0, cd);
            return;
        }

        if (cd.on_end === 'stop') {
            var uuid = $form.attr('id'), config = this.optin_js_config(uuid) || {};
            this.countdown_stopped[uuid] = true;

            // A pop-up stays in the page closed (its wrapper belongs to the modal); nothing reopens it.
            if ($form.hasClass('mo-optin-form-lightbox')) {
                if ($.MoModalBox && $.MoModalBox.isActive && $form.is(':visible')) $.MoModalBox.close();
                return;
            }
            if ($form.hasClass('mo-optin-form-bar') || $form.hasClass('mo-optin-form-slidein')) {
                // Free its spot so another bar or slide-in can take it.
                if ($form.is(':visible')) this._close_optin($form);
                this.flag_optin_type_close(config, config.optin_type);
                return;
            }
            $form.remove();
            return;
        }

        $countdown.remove();
    },

    /**
     * Initialize class
     */
    init: function () {
        var _this = this;
        // don't wait for dom to be loaded first. start tracking asap.
        _this.track_page_views();
        _this.set_visitor_cookies();
        $(function () {
            _this.eventSubscription();
            _this.mailoptin_jq_plugin();
            _this.is_scheduled_for_display();
            _this.countdown_init();
            // Before they show: a two-step campaign opens on its question.
            _this.init_twostep();
            _this.initOptinForms();
            _this.init_launchers();
            _this.optin_conversion();
            _this.init_date_picker();

            $(document.body).trigger('mo-mailoptinjs-loaded')
        });
    }
};

moModal();
moExitIntent();
moScrollTrigger();
mcGroupsValidation();
recaptcha();

mailoptin_optin.init();


// Message templates: the Copy button next to a discount code.
$(function () {
    $(document).on('click', '.mo-wheel-copy', function (e) {
        e.preventDefault();
        var button = $(this), code = String(button.attr('data-code') || ''), label = button.text();
        var done = function () {
            button.text(button.attr('data-copied') || label);
            setTimeout(function () { button.text(label); }, 2000);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(code).then(done, function () {});
        } else {
            var $t = $('<textarea readonly style="position:absolute;left:-9999px"></textarea>').val(code).appendTo('body');
            $t[0].select();
            try { document.execCommand('copy'); done(); } catch (err) {}
            $t.remove();
        }
    });

    $(document).on('click', '.mo-msg-copy', function (e) {
        e.preventDefault();
        var button = $(this), code = String(button.data('mo-msg-copy') || ''), label = button.text();

        // On a code template, copying the code is the click: counted once per message per page.
        var message = button.parents('.moOptinForm'), uuid = message.attr('id');
        if (uuid && !message.data('mo-msg-counted') && !$.MailOptin.is_customize_preview) {
            message.data('mo-msg-counted', true);
            var config = mailoptin_optin.optin_js_config(uuid);
            if (config) mailoptin_optin.set_cookie('success', uuid, config);
            $.MailOptin.track_message_click(uuid);
        }

        var done = function () {
            button.text(button.data('copied') || label);
            setTimeout(function () { button.text(label); }, 2000);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(code).then(done, function () {});
        } else {
            var field = $('<input type="text">').val(code).css({position: 'fixed', top: '-100px'}).appendTo('body');
            field[0].select();
            try { document.execCommand('copy'); done(); } catch (err) {}
            field.remove();
        }
    });
});
