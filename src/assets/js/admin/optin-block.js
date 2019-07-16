(function(blocks, editor, __, element, components, wp) {
    var el = element.createElement;
    var InspectorControls = editor.InspectorControls;
    var SelectControl = components.SelectControl;
    var Toggle = components.ToggleControl;
    var withSelect = wp.data.withSelect;
    var withDispatch = wp.data.withDispatch;
    var compose = wp.compose.compose;

    blocks.registerBlockType('mailoptin/email-optin', {
        title: __('MailOptin', 'mailoptin'),
        icon: MailOptinBlocks.icon,
        category: 'embed',
        attributes: {
            id: {
                type: 'number',
                default: MailOptinBlocks.defaultForm,
            },
        },
        edit: function(props) {

            var attributes = props.attributes;

            return [
                el(InspectorControls, { key: 'controls' },
                    el(SelectControl, {
                        value: attributes.id,
                        label: __('Select Form', 'mailoptin'),
                        type: 'select',
                        options: MailOptinBlocks.formOptions,
                        onChange: function(value) {
                            props.setAttributes({ id: value });
                        }
                    }),

                ),

                el('div', {
                    className: props.className,
                    dangerouslySetInnerHTML: { __html: MailOptinBlocks.templates[attributes.id].template },
                })
            ]
        },

        save: function(props) {
            var id = props.attributes.id;
            return el('div', {
                    className: props.className
                },
                MailOptinBlocks.templates[id].value
            )

        },
    });

    $(function () {
        var registerPlugin = wp.plugins.registerPlugin;
        var PluginSidebar  = wp.editPost.PluginSidebar;
        var el             = wp.element.createElement;

        var disableNotifications = compose(
            withDispatch( function( dispatch ) {
                return {
                    setMetaFieldValue: function( value ) {
                        console.log(value)
                        dispatch( 'core/editor' ).editPost(
                            { meta: { _mo_disable_npp: value  ? 'yes' : 'no'} }
                        );
                    }
                }
            } ),
            withSelect( function( select ) {
                return {
                    metaFieldValue: select( 'core/editor' )
                        .getEditedPostAttribute( 'meta' )
                        [ '_mo_disable_npp' ]
                }
            } )
        )( function( props ) {
            return el( Toggle, {
                label: mailoptin_globals.disable_notifications_txt,
                checked: 'yes' == props.metaFieldValue ? true : false,
                onChange: function( content ) {
                    props.setMetaFieldValue( content );
                },
        } );
        } );

        registerPlugin( 'mailoptin-sidebar', {
            render: function() {
                return el( PluginSidebar,
                    {
                        name: 'mailoptin-sidebar',
                        icon: 'email',
                        title: 'MailOptin',
                    },
                    el( 'div',
                        { className: 'mailoptin-disable-notifications' },
                        el( disableNotifications )
                )
                );
            },
        } );
    });


})(
    window.wp.blocks,
    window.wp.editor,
    window.wp.i18n.__,
    window.wp.element,
    window.wp.components,
    window.wp
);
