define(["jquery", "backbone"], function ($, Backbone) {
    return Backbone.View.extend({

        el: "#mo-flow-rule-meta-box",

        default_msg_tmpl: wp.template('mo-flows-rules-default'),

        rules_group_tmpl: wp.template('mo-flows-rules-grouping'),

        rules_group_compare_tmpl: wp.template('mo-flows-rule-compare'),

        rules_group_value_tmpl: wp.template('mo-flows-rule-value'),

        events: {
            'click #mo-flows-rule-add-btn': 'add_new_rule_group',
            'click #mo-flows-rule-add-btn': 'add_AND_rule',
            'change .mo-flow-rule-select': 'add_rule_compare_values'
        },

        display_default_message: function () {
            this.$el.find('.aw-rules-container .aw-rule-groups').html(this.default_msg_tmpl({}))
        },

        default_rule_row_state: function (container) {

            $('.aw-rule-field-compare', container).html(this.rules_group_compare_tmpl({}));
            $('.aw-rule-field-value', container).html(this.rules_group_value_tmpl({isDisabled: true}));
        },

        add_AND_rule: function () {

        },

        add_new_rule_group: function () {
            var cache, cache2;

            if ((cache = this.$el.find('.aw-rules-container .aw-rule-groups .aw-rule-group')).length > 0) {

                cache2 = $(this.rules_group_tmpl({})).insertAfter(cache.last());
                this.default_rule_row_state(cache2);

            } else {
                cache2 = this.$el.find('.aw-rules-container .aw-rule-groups').html(this.rules_group_tmpl({}));
                this.default_rule_row_state(cache2);
            }
        },

        add_rule_compare_values: function (e) {

            var compareOptions = [],
                fieldOptions = [],
                selected_rule = $(e.target).val(),
                selected_rule_row = $(e.target).parents('.automatewoo-rule-container');

            if (selected_rule === "") this.default_rule_row_state(selected_rule_row);

            if (typeof mo_automate_flows_rules[selected_rule] == "undefined") return;

            if (typeof mo_automate_flows_rules[selected_rule]['compare'] != "undefined") {
                compareOptions = mo_automate_flows_rules[selected_rule]['compare'];
            }

            if (typeof mo_automate_flows_rules[selected_rule]['value'] != "undefined") {
                fieldOptions = mo_automate_flows_rules[selected_rule]['value'];
            }

            selected_rule_row.find('.aw-rule-field-compare').html(this.rules_group_compare_tmpl({
                compareOptions: compareOptions
            }));

            selected_rule_row.find('.aw-rule-field-value').html(this.rules_group_value_tmpl({
                valueField: mo_automate_flows_rules[selected_rule]['value_field'],
                fieldName: '[rule_options][rule_group_94][rule_96][value]', // @todo set the accessor field
                fieldOptions: fieldOptions
            }));

            selected_rule_row.trigger('mo-flows-field-change', [this.$el]);
        },

        render: function () {
            var no_rules_found = true;

            if (no_rules_found) {
                this.display_default_message()
            }
        }
    });
});