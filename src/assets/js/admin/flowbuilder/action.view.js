define(["jquery", "backbone"], function ($, Backbone) {
    return Backbone.View.extend({

        className: 'automatewoo-action',

        template: wp.template('mo-flows-action-settings'),

        events: {
            'click .mo-flows-action-delete': 'delete_action'
        },

        initialize(options) {
            this.options = options;
        },

        delete_action: function (e) {
            e.preventDefault();

            this.remove();

            $('body').trigger('mo-flows-action-removed');
        },

        render: function () {

            this.$el.html(this.template({
                title: 'Hello'
            }));
        }
    });
});