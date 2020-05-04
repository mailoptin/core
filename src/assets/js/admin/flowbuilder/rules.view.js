define(["jquery", "backbone"], function ($, Backbone) {
    return Backbone.View.extend({

        el: "#mo-flow-rule-meta-box",

        default_msg_tmpl: wp.template('mo-flows-rules-default'),

        rules_group_tmpl: wp.template('mo-flows-rules-grouping'),

        events: {
            'click #mo-flows-rule-add-btn': 'add_rule_group'
        },

        display_default_message: function () {
            this.$el.find('.aw-rules-container .aw-rule-groups').html(this.default_msg_tmpl())
        },

        add_rule_group: function () {
            var cache;

            if ((cache = this.$el.find('.aw-rules-container .aw-rule-groups .aw-rule-group')).length > 0) {
                cache.last().after(this.rules_group_tmpl())
            } else {
                this.$el.find('.aw-rules-container .aw-rule-groups').html(this.rules_group_tmpl())
            }
        },

        render: function () {
            var no_rules_found = true;

            if (no_rules_found) {
                this.display_default_message()
            }
        }
    });
});