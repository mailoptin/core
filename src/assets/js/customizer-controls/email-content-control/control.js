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

            var tinymce_field_init = function () {
                $('.mo-email-content-field-tinymce').mo_wp_editor({mode: 'tmce'});
            };

            var revealSettings = function (e) {
                e.preventDefault();
                $(this).parents('.mo-email-content-widget-wrapper').hide();
                $('body').addClass('mo-email-content-element-settings-open');

                $('#mo-email-content-settings-area').remove();
                var template = wp.template('mo-email-content-element-' + $(this).data('element-type'));

                $('.mo-email-content-widget.mo-email-content-element-settings').append(template()).show("slide", {direction: "right"}, 300);

                tinymce_field_init();
            };

            $(document).on('click', '.element-bar .mo-email-content-widget-title, .element-bar .mo-email-content-widget-action', revealSettings);
            $(document).on('click', '.mo-add-new-email-element', this.add_new_element);
            $(document).on('click', '.mo-email-content-go-back a', this.go_back);
            $(document).on('keyup change search', '.mo-email-content-elements-wrapper .search-form input', this.search_elements);

            // $(document).on('click', '.mo-email-content-delete', this.remove_field);
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
    });

})(wp.customize, jQuery);