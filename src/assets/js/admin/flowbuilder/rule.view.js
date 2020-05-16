define(["jquery", "backbone"], function ($, Backbone) {
    return Backbone.View.extend({

        className: 'automatewoo-rule-container',

        template: wp.template('mo-flows-rule-row'),

        rules_group_tmpl: wp.template('mo-flows-rules-grouping'),

        rules_group_compare_tmpl: wp.template('mo-flows-rule-compare'),

        rules_group_value_tmpl: wp.template('mo-flows-rule-value'),

        events: {
            'change .mo-flow-rule-select': 'add_rule_compare_values',
            'click .mo-flow-remove-rule': 'remove_rule'
        },

        remove_rule: function (e) {
            var rules_in_group_count = $(e.target).parents('.mo-flows-rules-group').find('.automatewoo-rule-container').length,
                group_container = $(e.target).parents('.aw-rule-group');

            this.remove(); // removes view

            if (rules_in_group_count === 1) {
                group_container.remove();
            }

            $('body').trigger('mo-flows-rule-removed');
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
            this.$el.html(this.template());
            $('.aw-rule-field-compare', this.$el).html(this.rules_group_compare_tmpl({}));
            $('.aw-rule-field-value', this.$el).html(this.rules_group_value_tmpl({isDisabled: true}));
        }
    });
});