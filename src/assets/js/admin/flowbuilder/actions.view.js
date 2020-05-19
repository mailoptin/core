define(["jquery", "backbone", "action.view"], function ($, Backbone, ActionView) {
    return Backbone.View.extend({

        el: "#mo-flow-action-meta-box",

        default_msg_tmpl: wp.template('mo-flows-actions-default'),

        events: {
            'click #mo-flows-add-action': 'add_new_action'
        },

        add_new_action: function (e) {
            e.preventDefault();

            this.$el.find('.mo-flows-actions-default-msg').remove();

            var instance = new ActionView();
            instance.render();

            this.$el.find('.aw-actions-container').append(instance.$el);
        },

        display_default_message: function () {
            this.$el.find('.aw-actions-container').html(this.default_msg_tmpl());
        },

        render: function () {
            if (true) {
                this.display_default_message();
            }
        }
    });
});