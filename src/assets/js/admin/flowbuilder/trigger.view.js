define(["jquery", "backbone"], function ($, Backbone) {
    return Backbone.View.extend({

        el: "#mo-flow-trigger-meta-box",

        trigger_settings_tmpl: wp.template('mo-flows-trigger-settings'),

        events: {
            "change #mo-flow-trigger": "trigger_selection_changed",
            "mo-flows-field-change": "re_init_js_scripts"
        },

        initialize: function () {
            this.$el.find('#mo-flow-trigger').change();
        },

        trigger_selection_changed: function (e) {
            var cache = $(e.target),
                trigger_id = cache.val();

            $('.mo-trigger-settings', this.$el).remove();
            this.$el.find('#mo-flow-trigger-description').text('');


            if (trigger_id === "") return;

            this.show_trigger_settings(trigger_id);

            this.$el.trigger('mo-flows-field-change', [this.$el]);
        },

        show_trigger_settings: function (trigger_id) {
            var bucket,
                triggerSettings = [];

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
        },

        re_init_js_scripts: function () {
            $('.mo-tooltipster', this.$el).tooltipster({theme: 'tooltipster-borderless'});
            $('.mo-flow-field-select2', this.$el).select2();
        },

        render: function () {

            if (typeof mo_automate_flows_db_data.trigger_name !== 'undefined') {
                var trigger_id = mo_automate_flows_db_data.trigger_name;
                console.log(trigger_id)
            }
        }
    });
});