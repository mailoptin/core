define(["jquery", "backbone", "action.view"], function ($, Backbone, ActionView) {
    return Backbone.View.extend({

        el: "#mo-flow-action-meta-box",

        default_msg_tmpl: wp.template('mo-flows-actions-default'),

        events: {
            'click #mo-flows-add-action': 'add_new_action',
            'click #mo-flows-edit-action': 'edit_action'
        },

        initialize: function () {
            var _this = this;

            $('body').on('mo-flows-action-removed', function () {

                var action_count = _this.$el.find('.aw-actions-container .automatewoo-action').length;

                if (action_count === 0) {
                    _this.display_default_message();
                }
            });
        },

        display_default_message: function () {
            this.$el.find('.aw-actions-container').html(this.default_msg_tmpl());
        },

        edit_action: function () {

        },
        
        add_new_action: function (e) {
            e.preventDefault();

            this.$el.find('.mo-flows-actions-default-msg').remove();

            var instance = new ActionView();

            instance.render();

            this.$el.find('.aw-actions-container').append(instance.$el);
        },

        render: function () {
            if (true) {
                this.display_default_message();
            }
        }
    });
});