import {
    Panel,
    PanelBody,
    PanelRow,
    ToggleControl
} from '@wordpress/components';

import {PluginSidebar} from '@wordpress/edit-post';
import {registerPlugin} from '@wordpress/plugins';
import {useSelect, useDispatch} from '@wordpress/data';

const icon = <svg width="150" height="150" viewBox="0 0 11.16 11.16" fillRule="evenodd">
    <path
        d="M.92.79V.8h0 .01l3.11 3.03 1.5 1.44L10.18.8c.04-.04.09-.07.15-.09.17-.07.36-.02.5.1.08.09.14.2.14.32v8.55c0 .44-.36.8-.8.8H.99c-.44 0-.8-.36-.8-.8V1.11h0c0-.24.2-.43.43-.43.12 0 .22.04.3.11zM2.3 5.14c-.3-.64.27-1.27.91-1.17.14.02.28.08.4.18l1.93 1.87 4.53-4.37c.05-.05.12-.08.19-.08a.28.28 0 0 1 .28.28v1.64L6.18 7.81c-.11.09-.21.17-.32.22-.21.12-.39.14-.62.03-.1-.04-.19-.11-.3-.19l-2.4-2.38a1.37 1.37 0 0 1-.24-.35z"/>
</svg>;

function FieldToggle() {

    let metaFieldValue = useSelect(function (select) {
        return select('core/editor').getEditedPostAttribute(
            'meta'
        )['_mo_disable_npp'];
    }, []);

    const editPost = useDispatch('core/editor').editPost;

    return (
        <ToggleControl
            label={mailoptin_globals.disable_notifications_txt}
            checked={'yes' === metaFieldValue}
            onChange={(content) => {
                editPost({
                    meta: {_mo_disable_npp: content ? 'yes' : 'no'},
                });
            }}
        />
    )
}

if (mailoptin_globals.sidebar === '1') {

    registerPlugin('mailoptin-sidebar', {
        render: () => (
            <PluginSidebar name="mailoptin-sidebar" title="MailOptin" icon={icon}>
                <Panel>
                    <PanelBody title="MailOptin" initialOpen={true}>
                        <PanelRow>
                            <FieldToggle/>
                        </PanelRow>
                    </PanelBody>
                </Panel>
            </PluginSidebar>
        )
    });
}