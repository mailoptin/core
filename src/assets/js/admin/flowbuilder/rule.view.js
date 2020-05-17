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

        initialize(options) {
            this.options = options;
        },

        getFieldName: function (type) {
            return 'mo_flow_data[rule_options][' + this.options.groupId + '][' + this.options.ruleId + '][' + type + ']';
        },

        getSavedValue: function (type) {
            if (typeof this.options.ruleValues !== 'undefined') {
                return this.options.ruleValues[type];
            }
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

        default_rule_row_state: function () {

            $('.aw-rule-field-compare', this.$el).html(this.rules_group_compare_tmpl({
                fieldName: this.getFieldName('compare')
            }));

            $('.aw-rule-field-value', this.$el).html(this.rules_group_value_tmpl({
                isDisabled: true,
                fieldName: this.getFieldName('value')
            }));
        },

        add_rule_compare_values: function (e) {

            var selected_rule = $(e.target).val();

            if (selected_rule === "") this.default_rule_row_state();

            if (typeof mo_automate_flows_rules[selected_rule] == "undefined") return;

            this.set_compare_values_fields(selected_rule);

            this.$el.trigger('mo-flows-field-change', [this.$el]);
        },

        set_compare_values_fields: function (ruleName) {

            var compareOptions = [], fieldOptions = [];

            if (typeof mo_automate_flows_rules[ruleName]['compare'] != "undefined") {
                compareOptions = mo_automate_flows_rules[ruleName]['compare'];
            }

            if (typeof mo_automate_flows_rules[ruleName]['value'] != "undefined") {
                fieldOptions = mo_automate_flows_rules[ruleName]['value'];
            }

            this.$el.find('.aw-rule-field-compare').html(this.rules_group_compare_tmpl({
                compareOptions: compareOptions,
                fieldName: this.getFieldName('compare'),
                dbValue: this.getSavedValue('compare')
            }));

            this.$el.find('.aw-rule-field-value').html(this.rules_group_value_tmpl({
                valueField: mo_automate_flows_rules[ruleName]['value_field'],
                fieldName: this.getFieldName('value'),
                fieldOptions: fieldOptions
            }));
        },

        render: function () {
            var _this = this;

            this.$el.html(this.template({
                fieldName: this.getFieldName('name'),
                dbValue: this.getSavedValue('name')
            }));

            if (typeof this.options.ruleValues != 'undefined' && !_.isEmpty(this.options.ruleValues)) {

                this.set_compare_values_fields(this.options.ruleValues.name);
                // using setTimeout so we wait for template to form html.
                setTimeout(function () {
                    _this.$el.trigger('mo-flows-field-change', [_this.$el]);
                }, 150);

            } else {
                this.default_rule_row_state();
            }
        }
    });
});