/**
 * The template picker's "Start from" thumbnails: each starter layout drawn in the chosen template,
 * from the server (the code that sends the email), swapped in when the template changes.
 */
(function ($) {
    'use strict';

    $(function () {
        $('.mo-ee-picker').each(function () {
            var $form = $(this), $starters = $form.find('.mo-ee-starters');
            if (!$starters.length) return;

            var cache = {}, current = '';

            function show(template) {
                var html = cache[template];
                $starters.find('.mo-ee-starter-shot').each(function () {
                    var $shot = $(this), doc = html ? html[$shot.data('starter')] : null;
                    $shot.removeClass('is-loading').find('iframe').remove();
                    if (!doc) return;
                    // srcdoc in a sandbox with no scripts: a picture of the email, nothing more.
                    $('<iframe sandbox="" tabindex="-1" aria-hidden="true" loading="lazy"></iframe>').attr('srcdoc', doc).appendTo($shot);
                });
            }

            function load() {
                var template = $form.find('input[name="template"]:checked').val() || '';
                $form.toggleClass('is-code', 'HTML' === template || '' === template);
                if (!template || 'HTML' === template || template === current) return;
                current = template;
                if (cache[template]) return show(template);

                $starters.find('.mo-ee-starter-shot').addClass('is-loading').find('iframe').remove();
                $.post(window.ajaxurl, {
                    action: 'mo_email_starter_previews',
                    nonce: $starters.data('nonce'),
                    context: $starters.data('context'),
                    template: template,
                    automation_id: $starters.data('automation') || 0
                }).done(function (res) {
                    if (!res || !res.success) return;
                    cache[template] = res.data;
                    if (current === template) show(template);
                }).always(function () {
                    if (current === template && !cache[template]) $starters.find('.mo-ee-starter-shot').removeClass('is-loading');
                });
            }

            $form.on('change', 'input[name="template"]', load);
            load();
        });
    });
})(jQuery);
