define(["jquery"], function ($) {

    function re_init_js_scripts(container) {
        $('.mo-tooltipster', container).tooltipster({theme: 'tooltipster-borderless'});
        $('.mo-flow-field-select2', container).select2();
    }

    return {
        re_init_js_scripts: re_init_js_scripts,
        generateUniqueID: function () {
            return Math.random().toString(36).substring(2) + Date.now().toString(36);
        }
    }
});