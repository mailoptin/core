/**
 * Retrieves the translation of text.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-i18n/
 */
import {__} from '@wordpress/i18n';

/**
 * React hook that is used to mark the block wrapper element.
 * It provides all the necessary props like the class name.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/#useblockprops
 */
import {useBlockProps} from '@wordpress/block-editor';

/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * Those files can contain any CSS code that gets applied to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */
import './editor.scss';

function FormSelectField() {

    return (
        <div className="mailoptin-form-block-select-wrap">
            <p>{__('Select an optin campaign to display', 'mailoptin')}</p>
            {
                moBlockOptinCampaigns?.optins && moBlockOptinCampaigns.optins.length > 0 ?
                    (__('No optin campaign found. Please create one', 'mailoptin')) : (

                        <div>
                            <select>
                                <option value={''}>{__('Select Optin', 'mailoptin')}</option>
                                {Object.entries(moBlockOptinCampaigns.optins).map(([id, label]) => {
                                    return <option value={id}>{label}</option>
                                })}
                            </select>
                        </div>
                    )}

        </div>
    );
}

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#edit
 *
 * @return {Element} Element to render.
 */
export default function Edit() {
    return (
        <div {...useBlockProps()}>
            <FormSelectField/>
        </div>
    );
}
