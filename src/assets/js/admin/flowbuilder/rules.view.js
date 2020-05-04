define(["jquery", "backbone"], function ($, Backbone) {
    return Backbone.View.extend({

        el: "#mo-flow-rule-meta-box",

        default_msg_tmpl: wp.template('mo-flows-rules_default'),

        events: {},

        initialize: function () {

        },

        display_default_message: function () {

        },

        render: function () {
            var no_rules_found = true;

            if (no_rules_found) {
                this.$el.find('.aw-rules-container .aw-rule-groups').html(this.default_msg_tmpl())
            }
        }
    });
});