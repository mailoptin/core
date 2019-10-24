(function (api, $) {
    wp.customize.controlConstructor["mailoptin-email-content"] = wp.customize.Control.extend({

        ready: function () {
            "use strict";

            var _this = this;

            var toggleAllWidget = function (e) {
                e.preventDefault();
                var $button = $(this);
                $button.blur();

                $('.mo-email-content-widget').each(function () {
                    var parent = $(this);
                    if ($button.hasClass('mo-expand')) {
                        $('.mo-email-content-widget-content', parent).slideDown(function () {
                            parent.addClass('mo-email-content-widget-expanded');
                        });

                    } else {
                        $('.mo-email-content-widget-content', parent).slideUp(function () {
                            parent.removeClass('mo-email-content-widget-expanded');
                        });
                    }
                });

                if ($button.hasClass('mo-expand')) {
                    $button.text($button.data('collapse-text')).removeClass('mo-expand').addClass('mo-collapse');
                } else {
                    $button.text($button.data('expand-text')).removeClass('mo-collapse').addClass('mo-expand');
                }
            };

            wp.customize.section('mailoptin_newsletter_content', function (section) {
                section.expanded.bind(function (isExpanded) {
                    if (isExpanded) {
                        $('.mo-email-content-elements-wrapper').hide();
                        $('.mo-email-content-wrapper').find('.mo-email-content-widget-wrapper').show();
                    }
                });
            });

            $(document).on('click', '.mo-email-content-expand-collapse-all', toggleAllWidget);
            $(document).on('click', '.mo-email-content-widget-action', this.toggleWidget);
            $(document).on('click', '.mo-add-new-email-element', this.add_new_element);
            $(document).on('click', '.mo-email-content-elements-back a', this.go_back);
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
            $(this).parents('.mo-email-content-elements-wrapper').hide();
            $(this).parents('.mo-email-content-wrapper').find('.mo-email-content-widget-wrapper').show();
        },

        add_new_element: function (e) {
            e.preventDefault();
            $(this).parents('.mo-email-content-widget-wrapper').hide();
            $(this).parents('.mo-email-content-wrapper').find('.mo-email-content-elements-wrapper').show();
        },

        toggleWidget: function (e) {
            e.preventDefault();
            var parent = $(this).parents('.mo-email-content-widget');
            $('.mo-email-content-widget-content', parent).slideToggle(function () {
                parent.toggleClass('mo-email-content-widget-expanded');
            });
        }

    });

})(wp.customize, jQuery);