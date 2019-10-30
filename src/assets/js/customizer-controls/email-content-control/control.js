(function (api, $) {

    wp.customize.controlConstructor["mailoptin-email-content"] = wp.customize.Control.extend({

        ready: function () {
            "use strict";

            var _this = this;

            wp.customize.section('mailoptin_newsletter_content', function (section) {
                section.expanded.bind(function (isExpanded) {
                    if (isExpanded) {
                        $('.mo-email-content-elements-wrapper').hide();
                        $('.mo-email-content-widget.mo-email-content-element-settings').hide();
                        $('.mo-email-content-wrapper').find('.mo-email-content-widget-wrapper').show();
                    } else {
                        $('body').removeClass('mo-email-content-element-settings-open');
                    }
                });
            });

            this.dimension_field_init();

            $.fn.color_picker_init = function () {
                console.log('color picker init');
                $(this).find('.mo-color-picker-hex').wpColorPicker();
            };

            $.fn.tinymce_field_init = function () {
                var options = {mode: 'tmce'};
                options.mceInit = {
                    "theme": "modern",
                    "skin": "lightgray",
                    "language": "en",
                    "formats": {
                        "alignleft": [
                            {
                                "selector": "p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li",
                                "styles": {"textAlign": "left"},
                                "deep": false,
                                "remove": "none"
                            },
                            {
                                "selector": "img,table,dl.wp-caption",
                                "classes": ["alignleft"],
                                "deep": false,
                                "remove": "none"
                            }
                        ],
                        "aligncenter": [
                            {
                                "selector": "p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li",
                                "styles": {"textAlign": "center"},
                                "deep": false,
                                "remove": "none"
                            },
                            {
                                "selector": "img,table,dl.wp-caption",
                                "classes": ["aligncenter"],
                                "deep": false,
                                "remove": "none"
                            }
                        ],
                        "alignright": [
                            {
                                "selector": "p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li",
                                "styles": {"textAlign": "right"},
                                "deep": false,
                                "remove": "none"
                            },
                            {
                                "selector": "img,table,dl.wp-caption",
                                "classes": ["alignright"],
                                "deep": false,
                                "remove": "none"
                            }
                        ],
                        "strikethrough": {"inline": "del", "deep": true, "split": true}
                    },
                    "relative_urls": false,
                    "remove_script_host": false,
                    "convert_urls": false,
                    "browser_spellcheck": true,
                    "fix_list_elements": true,
                    "entities": "38,amp,60,lt,62,gt",
                    "entity_encoding": "raw",
                    "keep_styles": false,
                    "paste_webkit_styles": "font-weight font-style color",
                    "preview_styles": "font-family font-size font-weight font-style text-decoration text-transform",
                    "wpeditimage_disable_captions": false,
                    "wpeditimage_html5_captions": false,
                    "plugins": "charmap,hr,media,paste,tabfocus,textcolor,fullscreen,wordpress,wpeditimage,wpgallery,wplink,wpdialogs,wpview,image",
                    "content_css": moWPEditor_globals.includes_url + "css/dashicons.css?ver=3.9," + moWPEditor_globals.includes_url + "js/mediaelement/mediaelementplayer.min.css?ver=3.9," + moWPEditor_globals.includes_url + "js/mediaelement/wp-mediaelement.css?ver=3.9," + moWPEditor_globals.includes_url + "js/tinymce/skins/wordpress/wp-content.css?ver=3.9",
                    "selector": "#moWPEditor",
                    "resize": "vertical",
                    "menubar": false,
                    "wpautop": true,
                    "indent": false,
                    "toolbar1": "formatselect,bold,italic,strikethrough,bullist,numlist,blockquote,hr,alignleft,aligncenter,alignright,alignjustify,link,unlink,underline,wp_adv",
                    "toolbar2": "forecolor,removeformat,charmap,outdent,indent,undo,redo",
                    "toolbar3": "",
                    "toolbar4": "",
                    "tabfocus_elements": ":prev,:next",
                    "body_class": "moWPEditor",
                    'branding': false
                };

                $('.mo-email-content-field-tinymce').each(function () {
                    var id = $(this).attr('id');
                    $('#' + id).mo_wp_editor(options);

                    tinymce.get(id).on('keyup change undo redo SetContent', function () {
                        this.save();
                    });
                });

                return this;
            };

            $(document).on('click', '.element-bar .mo-email-content-widget-title, .element-bar .mo-email-content-widget-action', this.revealSettings);
            $(document).on('click', '.mo-add-new-email-element', this.add_new_element);
            $(document).on('click', '.mo-email-content-go-back a', this.go_back);
            $(document).on('keyup change search', '.mo-email-content-elements-wrapper .search-form input', this.search_elements);

            $(document).on('click', '.mo-email-content-modal-motabs .motabs .motab', this.toggle_settings_tab);

            // $(document).on('click', '.mo-email-content-delete', this.remove_field);
        },

        revealSettings: function (e) {
            e.preventDefault();
            $(this).parents('.mo-email-content-widget-wrapper').hide();
            $('body').addClass('mo-email-content-element-settings-open');

            $('#mo-email-content-settings-area').remove();
            var template = wp.template('mo-email-content-element-' + $(this).data('element-type'));

            $('.mo-email-content-widget.mo-email-content-element-settings').append(template()).show().tinymce_field_init().color_picker_init();

            $('.mo-email-content-modal-motabs .motabs .motab').eq(0).click();
        },

        toggle_settings_tab: function () {
            $('.mo-email-content-modal-motabs .motabs .motab').removeClass('is-active');
            $(this).addClass('is-active');
            $('.mo-email-content-blocks').hide();
            $('.mo-email-content-widget-form .' + $(this).data('tab-id')).show();
        },

        search_elements: function (e) {
            var term = this.value;
            var cache = $('.mo-email-content-elements-wrapper li.element--box');
            if (term === '') {
                cache.show();
            } else {
                cache.hide().each(function () {
                    var content = $(this).text().replace(/\s/g, '');

                    if (new RegExp('^(?=.*' + term + ').+', 'i').test(content) === true) {
                        $(this).show();
                    }
                });
            }
        },

        go_back: function (e) {
            e.preventDefault();
            $('.mo-email-content-elements-wrapper').hide();
            $('.mo-email-content-widget.mo-email-content-element-settings').hide();
            $('body').removeClass('mo-email-content-element-settings-open');

            $('.mo-email-content-widget-wrapper').show();
        },

        add_new_element: function (e) {
            e.preventDefault();
            $(this).parents('.mo-email-content-widget-wrapper').hide();
            $(this).parents('.mo-email-content-wrapper').find('.mo-email-content-elements-wrapper').show("slide", {direction: "right"}, 300);
        },

        dimension_field_init: function () {
            // Connected button
            $(document).on('click', '.mo-border-connected', function () {

                // Remove connected class
                $(this).parent().parent('.mo-border-wrapper').find('input').removeClass('connected').attr('data-element-connect', '');

                // Remove class
                $(this).parent('.mo-border-input-item-link').removeClass('disconnected');

            });

            // Disconnected button
            $(document).on('click', '.mo-border-disconnected', function () {

                // Set up variables
                var elements = $(this).data('element-connect');

                // Add connected class
                $(this).parent().parent('.mo-border-wrapper').find('input').addClass('connected').attr('data-element-connect', elements);

                // Add class
                $(this).parent('.mo-border-input-item-link').addClass('disconnected');

            });

            // Values connected inputs
            $(document).on('input', '.mo-border-input-item .connected', function () {

                var dataElement = $(this).attr('data-element-connect'),
                    currentFieldValue = $(this).val();

                $(this).parent().parent('.mo-border-wrapper').find('.connected[ data-element-connect="' + dataElement + '" ]').each(function (key, value) {
                    $(this).val(currentFieldValue).change();
                });

            });
        }
    });

})(wp.customize, jQuery);