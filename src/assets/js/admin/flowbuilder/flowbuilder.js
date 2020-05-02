/**
 * @var {object} mailoptin_globals
 */
var includes = [
    'jquery',
    'trigger.view'
];

define(includes, function ($, TriggerView) {
    $(window).on('load', function () {
        new TriggerView();
    });
});