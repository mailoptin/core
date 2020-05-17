define(["jquery", "backbone"], function ($, Backbone) {
    return Backbone.View.extend({

        el: "#mo-flow-trigger-meta-box",

        trigger_settings_tmpl: wp.template('mo-flows-trigger-settings'),

        events: {
            "change #mo-flow-trigger": "trigger_selection_changed"
        },

        trigger_selection_changed: function (e) {

            this.show_trigger_settings(
                $(e.target).val()
            );
        },

        show_trigger_settings: function (trigger_id) {

            var bucket, triggerSettings = [];

            $('.mo-trigger-settings', this.$el).remove();
            this.$el.find('#mo-flow-trigger-description').text('');

            if (trigger_id === "") return;

            if (typeof trigger_id == 'undefined') return;

            try {
                triggerSettings = mo_automate_flows_triggers[trigger_id]['trigger_settings'];
            } catch (e) {
            }

            this.$el.find('#mo-flow-trigger-select-row').after(this.trigger_settings_tmpl({
                triggerSettings: triggerSettings
            }));

            bucket = _.findWhere(mo_automate_flows_triggers, {id: trigger_id});
            if (typeof bucket != 'undefined') {
                this.$el.find('#mo-flow-trigger-description').text(bucket.description);
            }

            this.$el.trigger('mo-flows-field-change', [this.$el]);
        },

        render: function () {

            if (typeof mo_automate_flows_db_data.trigger_name !== 'undefined') {
                this.show_trigger_settings(mo_automate_flows_db_data.trigger_name);
            }
        }
    });
});