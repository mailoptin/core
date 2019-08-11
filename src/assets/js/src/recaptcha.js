define(["jquery"], function ($) {
    window.moFormRecaptchaLoadCallback = function () {
        console.log('loaded :D');
        $('.mo-g-recaptcha').each(function (index, el) {
            grecaptcha.render(el, {
                'sitekey': jQuery(el).attr('data-sitekey'),
                'theme': jQuery(el).attr('data-theme'),
                'size': jQuery(el).attr('data-size')
            });
        });
    }
});