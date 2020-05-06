/**
 * @var {object} mailoptin_globals
 */
var includes = [
    'jquery',
    'trigger.view',
    'rules.view',
    'util'
];

define(includes, function ($, TriggerView, RulesView, Util) {

    $('body').on('mo-flows-field-change', function (e, container) {
        Util.re_init_js_scripts(container);
    });
    
    $(window).on('load', function () {
        if (typeof mo_automate_flows_db_data != 'undefined') {
            (new TriggerView()).render();
            (new RulesView()).render();
        }
    });
});