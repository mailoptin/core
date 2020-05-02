define(["jquery", "backbone"], function ($, Backbone) {
    return Backbone.View.extend({

        el: "#mo_trigger_box",

        events: {
            "change #mo-flow-trigger": "change_trigger_description"
        },

        initialize: function () {
            this.$el.find('#mo-flow-trigger').change();
        },

        change_trigger_description: function (e) {
            var cache = $(e.target);
            var trigger = cache.val();
            var trigger_category = cache.find(':selected').data('flow-category');

            this.$el.find('#mo-flow-trigger-description').text(mo_automate_flows_triggers[trigger_category][trigger]['description']);
        }
    });
});