define(["jquery", "backbone"], function ($, Backbone) {
    return Backbone.View.extend({

        el: "#mo-flow-rule-meta-box",

        default_msg_tmpl: wp.template('mo-flows-rules-default'),

        rule_group_tmpl: wp.template('mo-flows-rules-default'),

        events: {
            'click #mo-flows-rule-add-btn': 'add_rule_group'
        },

        display_default_message: function () {
            this.$el.find('.aw-rules-container .aw-rule-groups').html(this.default_msg_tmpl())
        },

        add_rule_group: function () {

        },

        render: function () {
            var no_rules_found = true;

            if (no_rules_found) {
                this.display_default_message()
            }
        }
    });
});