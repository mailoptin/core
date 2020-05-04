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
        (new TriggerView()).render();
        (new RulesView()).render();
    });
});