import {__} from '@wordpress/i18n';
import {useBlockProps} from '@wordpress/block-editor';
import {SelectControl} from '@wordpress/components';
import './editor.scss';

const optin_options = Object.entries(moBlockOptinCampaigns.optins).map(([id, label]) => {
    return {label, value: id}
});

function FormSelectField({attributes, setAttributes}) {

    return (
        <div className="mailoptin-form-block-select-wrap">
            {
                optin_options.length === 0 ?
                    (__('No optin campaign found. Please create one', 'mailoptin')) :
                    <SelectControl
                        label={__('Select an optin campaign to display', 'mailoptin')}
                        options={[
                            {label: "––––"},
                            ...optin_options
                        ]}
                        onChange={(id) => setAttributes({id})}
                        value={attributes.id}
                    />
            }
        </div>
    );
}

export default function Edit({attributes, setAttributes}) {
    return (
        <div {...useBlockProps()}>
            {
                (!attributes.id) ?
                    <FormSelectField setAttributes={setAttributes} attributes={attributes}/> :
                    'hello'
            }
        </div>
    );
}
