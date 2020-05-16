define(["jquery", "backbone", "rule.view", "util"], function ($, Backbone, RuleView, Util) {
    return Backbone.View.extend({

        el: "#mo-flow-rule-meta-box",

        default_msg_tmpl: wp.template('mo-flows-rules-default'),

        rules_group_tmpl: wp.template('mo-flows-rules-grouping'),

        events: {
            'click #mo-flows-rule-add-btn': 'add_new_rule_group',
            'click .mo-flow-add-rule': 'add_AND_rule'
        },

        initialize: function () {
            var _this = this;
            $('body').on('mo-flows-rule-removed', function () {

                var groups_count = _this.$el.find('.aw-rule-groups .aw-rule-group').length;

                if (groups_count === 0) {
                    _this.display_default_message();
                }
            });
        },

        add_AND_rule: function (e) {

            var instance = new RuleView({
                groupId: $(e.target).parents('.aw-rule-group').data('group-id'),
                ruleId: Util.generateUniqueID()
            });

            instance.render();

            $(e.target).parents('.automatewoo-rule-container').after(instance.$el)
        },

        display_default_message: function () {
            this.$el.find('.aw-rules-container .aw-rule-groups').html(this.default_msg_tmpl())
        },

        insert_rule_child: function (parent, groupId) {
            var ruleId = Util.generateUniqueID(),
                instance = new RuleView({groupId: groupId, ruleId: ruleId});

            instance.render();

            $('.mo-flows-rules-group', parent).html(instance.$el)
        },

        add_new_rule_group: function () {
            var cache, rule_row_html, parent,
                groupId = Util.generateUniqueID();

            rule_row_html = this.rules_group_tmpl({groupId: groupId});

            if ((cache = this.$el.find('.aw-rules-container .aw-rule-groups .aw-rule-group')).length > 0) {
                parent = $(rule_row_html).insertAfter(cache.last());
                this.insert_rule_child(parent, groupId);


            } else {
                this.$el.find('.aw-rules-container .aw-rule-groups').html(rule_row_html);
                parent = this.$el.find('.aw-rules-container .aw-rule-groups .aw-rule-group');
                this.insert_rule_child(parent, groupId);
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