/**
 * @var {object} mailoptin_globals
 */
var includes = [
    'jquery',
    'trigger.view',
    'rules.view'
];

define(includes, function ($, TriggerView, RulesView) {
    $(window).on('load', function () {
        if (typeof mo_automate_flows_db_data != 'undefined') {
            (new TriggerView()).render();
            (new RulesView()).render();
        }
    });
});