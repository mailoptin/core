(function ($) {
    $(window).on('load', function () {
        var datetime_container = $(".mo-date-picker");
        datetime_container.datetimepicker({
            widgetPositioning: {
                horizontal: 'left',
                vertical: 'bottom'
            },
            sideBySide: false,
            minDate: moment(),
            icons: {
                time: 'dashicons dashicons-clock',
                date: 'dashicons dashicons-calendar-alt',
                up: 'dashicons dashicons-arrow-up-alt2',
                down: 'dashicons dashicons-arrow-down-alt2',
                previous: 'dashicons dashicons-arrow-left-alt2',
                next: 'dashicons dashicons-arrow-right-alt2',
                today: 'dashicons dashicons-screenoptions',
                clear: 'dashicons dashicons-trash'
            }
        });

        datetime_container.on('dp.change', function () {
            $(this).trigger('change');
        });
    });

})(jQuery);