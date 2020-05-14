define(["jquery", "backbone", "rule.view"], function ($, Backbone, RuleView) {
    return Backbone.View.extend({

        el: "#mo-flow-rule-meta-box",

        default_msg_tmpl: wp.template('mo-flows-rules-default'),

        rules_group_tmpl: wp.template('mo-flows-rules-grouping'),

        events: {
            'click #mo-flows-rule-add-btn': 'add_new_rule_group'
        },

        display_default_message: function () {
            this.$el.find('.aw-rules-container .aw-rule-groups').html(this.default_msg_tmpl())
        },

        add_new_rule_group: function () {
            var cache, rule_row_html, ruleViewInstance;

            (ruleViewInstance = new RuleView()).render();

            rule_row_html = this.rules_group_tmpl();

            ruleViewInstance.delegateEvents();

            if ((cache = this.$el.find('.aw-rules-container .aw-rule-groups .aw-rule-group')).length > 0) {
                $(rule_row_html).insertAfter(cache.last());

            } else {
                this.$el.find('.aw-rules-container .aw-rule-groups').html(rule_row_html);
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