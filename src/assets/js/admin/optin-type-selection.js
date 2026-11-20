(function ($) {
    $(document).ready(function () {
        // The objective picked above the templates ("" for All); kept when the optin type changes.
        var objective = '';

        var showObjective = function () {
            var list = $('.mailoptin-optin-themes');
            var links = list.find('.mo-optin-objectives a');

            // This optin type may not offer the objective picked on another one.
            if (objective !== '' && links.filter('[data-objective="' + objective + '"]').length === 0) objective = '';

            links.removeClass('current').filter('[data-objective="' + objective + '"]').addClass('current');
            list.find('.mailoptin-optin-theme').each(function () {
                $(this).toggle(objective === '' || $(this).attr('data-objective') === objective);
            });
            list.find('.mo-objective-upsell').each(function () {
                this.hidden = $(this).attr('data-objective') !== objective;
            });
        };

        $(document).on('click', '.mo-optin-objectives a', function (e) {
            e.preventDefault();
            objective = $(this).attr('data-objective') || '';
            showObjective();
        });

        $('.mo-select-optin-type').on('click', function (e) {
            e.preventDefault();
            // remove all active tab class.
            $('.mo-select-optin-type').removeClass('mailoptin-type-active');
            $(this).addClass('mailoptin-type-active');
            // show spinner
            $('.mailoptin-new-toolbar .mo-dash-spinner').css('visibility', 'visible');

            var ajaxData = {
                action: 'mailoptin_optin_type_selection',
                nonce: mailoptin_globals.nonce,
                'optin-type': $(this).attr('data-optin-type').trim()
            };

            $.post(ajaxurl, ajaxData, function (response) {
                    if (typeof response === 'string') {
                        $('.mailoptin-optin-themes').replaceWith(response);
                        showObjective();
                    }
                    $('.mailoptin-new-toolbar .mo-dash-spinner').css('visibility', 'hidden');
                }
            );

        });
    });
})(jQuery);
