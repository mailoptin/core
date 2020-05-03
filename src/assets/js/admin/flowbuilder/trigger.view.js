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
            var trigger_settings_template,
                cache = $(e.target),
                trigger_id = cache.val(),
                trigger_category = cache.find(':selected').data('flow-category');

            $('.mo-trigger-settings', this.$el).remove();
            this.$el.find('#mo-flow-trigger-description').text('');


            if (trigger_id === "") return;

            trigger_settings_template = wp.template('mo-flows-trigger-' + trigger_id);
            this.$el.find('#mo-flow-trigger-select-row').after(trigger_settings_template());

            if (typeof trigger_category != 'undefined') {
                this.$el.find('#mo-flow-trigger-description').text(mo_automate_flows_triggers[trigger_category][trigger_id]['description']);
            }

            this.$el.trigger('mo-flows-field-change', [this.$el]);
        },

        re_init_js_scripts: function () {
            $('.mo-tooltipster', this.$el).tooltipster({theme: 'tooltipster-borderless'});
            $('.mo-flow-field-select2', this.$el).select2();
        },

        render: function () {
            // code default view structure here
            console.log('rendered')
        }
    });
});