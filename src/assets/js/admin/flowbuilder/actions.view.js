define(["jquery", "backbone"], function ($, Backbone) {
    return Backbone.View.extend({

        el: "#mo-flow-action-meta-box",

        default_msg_tmpl: wp.template('mo-flows-actions-default'),

        events: {},

        display_default_message: function () {
            this.$el.html(this.default_msg_tmpl());
        },

        render: function () {
            if (true) {
                this.display_default_message();
            }
        }
    });
});