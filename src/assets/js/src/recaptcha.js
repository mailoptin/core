define(["jquery"], function ($) {
    window.moFormRecaptchaLoadCallback = function () {
        $('.mo-g-recaptcha').each(function (index, el) {
            if ($(el).attr('data-type') === 'v3') {
                var $form = $(this).parents('form.mo-optin-form');
            } else {
                grecaptcha.render(el, {
                    'sitekey': $(el).attr('data-sitekey'),
                    'theme': $(el).attr('data-theme'),
                    'size': $(el).attr('data-size')
                });
            }
        });
    }
});