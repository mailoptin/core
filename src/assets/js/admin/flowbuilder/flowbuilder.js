/**
 * @var {object} mailoptin_globals
 */
define(["jquery", "trigger.view", "rules.view", "actions.view", "util"], function ($, TriggerView, RulesView, ActionsView, Util) {

    $('body').on('mo-flows-field-change', function (e, container) {
        Util.re_init_js_scripts(container);
    });

    $(window).on('load', function () {
        if (typeof mo_automate_flows_db_data != 'undefined') {
            (new TriggerView()).render();
            (new RulesView()).render();
            (new ActionsView()).render();
        }
    });
});