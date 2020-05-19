define(["jquery", "backbone"], function ($, Backbone) {
    return Backbone.View.extend({

        className: 'automatewoo-action',

        template: wp.template('mo-flows-action-settings'),

        events: {},

        initialize(options) {
            this.options = options;
        },

        render: function () {

            this.$el.html(this.template({
                title: 'Hello'
            }));
        }
    });
});