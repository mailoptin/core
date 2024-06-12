import {__} from '@wordpress/i18n';
import {
    SelectControl,
    Notice,
    Panel,
    PanelBody,
    PanelRow,
    Spinner
} from '@wordpress/components';

import {
    useBlockProps,
    InspectorControls,
} from '@wordpress/block-editor';

import ServerSideRender from '@wordpress/server-side-render';

const optin_options = Object.entries(moBlockOptinCampaigns.optins).map(([id, label]) => {
    return {label, value: id}
});

function EditorContent({attributes, setAttributes}) {
    return (
        <div className="mailoptin-form-block-select-wrap">
            {
                optin_options.length === 0 ?
                    <Notice status="error" isDismissible={false}>
                        {__('No optin campaign found. Please create one', 'mailoptin')}
                    </Notice> :
                    <OptinSelectField attributes={attributes} setAttributes={setAttributes}/>
            }
        </div>
    );
}

function OptinSelectField({attributes, setAttributes}) {
    return <SelectControl
        label={__('Select an optin campaign', 'mailoptin')}
        options={[
            {label: "––––"},
            ...optin_options
        ]}
        onChange={(id) => setAttributes({id})}
        value={attributes.id}
    />
}

export default function Edit({attributes, setAttributes}) {
    return (
        <div {...useBlockProps()}>
            <InspectorControls key="setting">
                <Panel>
                    <PanelBody initialOpen={true} title={__('Optin Campaign', 'mailoptin')}>
                        <PanelRow>
                            <OptinSelectField attributes={attributes} setAttributes={setAttributes}/>
                        </PanelRow>
                    </PanelBody>
                </Panel>
            </InspectorControls> {
            (optin_options.length === 0 || !attributes.id) ?
                <EditorContent setAttributes={setAttributes} attributes={attributes}/> :
                <ServerSideRender
                    LoadingResponsePlaceholder={Spinner}
                    block="mailoptin/email-optin"
                    attributes={attributes}
                />
        }
        </div>
    );
}
