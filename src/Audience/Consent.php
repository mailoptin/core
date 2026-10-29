<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\Repositories\OptinCampaignsRepository;

/**
 * What a subscriber agreed to and how, saved with them so it can be shown later.
 */
class Consent
{
    const METHOD_CHECKBOX = 'checkbox';
    const METHOD_CHECKBOX_NOT_TICKED = 'checkbox_not_ticked';
    const METHOD_NOTE = 'note';
    const METHOD_NONE = 'none';
    const METHOD_ADMIN = 'admin';
    const METHOD_IMPORT = 'import';
    const METHOD_IMPORT_CONFIRM = 'import_confirm';

    /**
     * Read the consent wording from an optin form. With the Acceptance Checkbox on, the form's Note
     * is the checkbox label, so it's the text the person agreed to.
     *
     * @param int $optin_campaign_id
     * @param array $payload submitted form fields
     *
     * @return array consent_text and consent_method
     */
    public static function from_form($optin_campaign_id, $payload)
    {
        if ( ! $optin_campaign_id) return ['consent_text' => '', 'consent_method' => self::METHOD_NONE];

        $hidden = OptinCampaignsRepository::get_merged_customizer_value($optin_campaign_id, 'hide_note');
        $text   = $hidden ? '' : self::plain_text(OptinCampaignsRepository::get_merged_customizer_value($optin_campaign_id, 'note'));

        if ('' === $text) return ['consent_text' => '', 'consent_method' => self::METHOD_NONE];

        if (OptinCampaignsRepository::get_merged_customizer_value($optin_campaign_id, 'note_acceptance_checkbox')) {
            $ticked = 'yes' === ($payload['mo-acceptance'] ?? '');

            return ['consent_text' => $text, 'consent_method' => $ticked ? self::METHOD_CHECKBOX : self::METHOD_CHECKBOX_NOT_TICKED];
        }

        return ['consent_text' => $text, 'consent_method' => self::METHOD_NOTE];
    }

    public static function plain_text($html)
    {
        $text = wp_strip_all_tags(do_shortcode((string)$html), true);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode($text, ENT_QUOTES, 'UTF-8')));
    }

    public static function method_label($method)
    {
        $labels = [
            self::METHOD_CHECKBOX            => __('Ticked the checkbox', 'mailoptin'),
            // Only possible when the form was submitted without the browser's check, for example by a script.
            self::METHOD_CHECKBOX_NOT_TICKED => __('Checkbox not ticked', 'mailoptin'),
            self::METHOD_NOTE                => __('Shown under the form, no checkbox', 'mailoptin'),
            self::METHOD_NONE                => __('No consent text on the form', 'mailoptin'),
            self::METHOD_ADMIN               => __('Added by an admin', 'mailoptin'),
            self::METHOD_IMPORT              => __('Imported by an admin who confirmed they have permission', 'mailoptin'),
            self::METHOD_IMPORT_CONFIRM      => __('Imported; asked to confirm by email', 'mailoptin'),
        ];

        return $labels[$method] ?? '';
    }
}
