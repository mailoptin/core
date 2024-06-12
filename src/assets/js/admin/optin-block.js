(function (blocks, editor, __, element, components, wp, $) {
    var Toggle = components.ToggleControl;
    var Panel = components.Panel;
    var PanelBody = components.PanelBody;
    var PanelRow = components.PanelRow;
    var withSelect = wp.data.withSelect;
    var withDispatch = wp.data.withDispatch;
    var compose = wp.compose.compose;

    $(function () {

        //Maybe abort early
        if (mailoptin_globals.sidebar == 0) {
            return;
        }

        var registerPlugin = wp.plugins.registerPlugin;
        var PluginSidebar = wp.editPost.PluginSidebar;
        var el = wp.element.createElement;

        var disableNotificationsEl = compose(
            //saves a given meta field for the post
            withDispatch(function (dispatch) {
                return {
                    setMetaFieldValue: function (field, value) {
                        var toEdit = {meta: {}};
                        toEdit.meta[field] = value;
                        dispatch('core/editor').editPost(toEdit);
                    }
                }
            }),

            //retrieves a given meta field for the post
            withSelect(function (select) {
                var metas = select('core/editor').getEditedPostAttribute('meta');
                return {
                    disableNotifications: typeof metas !== 'undefined' && typeof metas['_mo_disable_npp'] !== 'undefined' ?
                        metas['_mo_disable_npp'] : false
                }
            })

        )(function (props) {

            //Toggle disable notifications
            return el(Toggle, {
                label: mailoptin_globals.disable_notifications_txt,
                checked: 'yes' == props.disableNotifications ? true : false,
                onChange: function (content) {
                    var val = content ? 'yes' : 'no';
                    props.setMetaFieldValue('_mo_disable_npp', val);
                },
            });
        });

        //Register the new sidebar
        registerPlugin('mailoptin-sidebar', {
            render: function () {
                return el(PluginSidebar,
                    {
                        name: 'mailoptin-sidebar',
                        icon: 'email',
                        title: 'MailOptin',
                    },
                    el(Panel, {},
                        el(PanelBody,
                            {
                                title: 'MailOptin',
                                initialOpen: true,
                            },
                            el(PanelRow, {}, el(disableNotificationsEl))
                        )
                    )
                );
            },
        });
    });


})(
    window.wp.blocks,
    window.wp.blockEditor,
    window.wp.i18n.__,
    window.wp.element,
    window.wp.components,
    window.wp,
    jQuery
);
