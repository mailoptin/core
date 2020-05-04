define(["jquery", "backbone"], function ($, Backbone) {
    return Backbone.View.extend({

        el: "#mo-flow-trigger-meta-box",

        events: {
            "change #mo-flow-trigger": "change_trigger_description",
            "mo-flows-field-change": "re_init_js_scripts"
        },

        initialize: function () {
            this.$el.find('#mo-flow-trigger').change();
        },

        change_trigger_description: function (e) {
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
                trigger_settings_tmpl = wp.template('mo-flows-trigger-' + trigger_id);
            this.$el.find('#mo-flow-trigger-select-row').after(trigger_settings_tmpl({
                flows_db_data: mo_automate_flows_db_data
            }));

            if (typeof trigger_id != 'undefined') {
                bucket = _.findWhere(mo_automate_flows_triggers, {id: trigger_id});
                if (typeof bucket != 'undefined') {
                    this.$el.find('#mo-flow-trigger-description').text(bucket.description);
                }
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