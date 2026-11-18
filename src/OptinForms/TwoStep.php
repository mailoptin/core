<?php

namespace MailOptin\Core\OptinForms;

use MailOptin\Core\Repositories\OptinCampaignMeta;
use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;

/**
 * Two steps (Premium): a yes-or-no question first, then the form.
 *
 * Works on every email-collection template without changing them: mailoptin.js shows the question in
 * the template's own headline and text, hides the fields and note, and makes the template's submit
 * button the Yes button. Saying yes brings the template back as it was, with a progress bar on top.
 *
 * Premium adds the Customizer section; the page gets the settings only with Premium on.
 */
class TwoStep
{
    const YES_META = 'twostep_yes';
    /** Of those who said yes, how many subscribed. */
    const DONE_META = 'twostep_done';

    public function __construct()
    {
        add_filter('mo_optin_js_config', [$this, 'js_config'], 10, 2);
        add_action('mailoptin_ajax_track_twostep_yes', [$this, 'track_yes']);
    }

    /** Whether a campaign can have two steps: forms that collect an email, not messages or offers. */
    public static function supported($optin_campaign_id)
    {
        $optin_campaign_id = absint($optin_campaign_id);

        if ( ! $optin_campaign_id) return false;

        if ( ! in_array(OCR::get_optin_campaign_type($optin_campaign_id), ['lightbox', 'slidein', 'inpost', 'sidebar'], true)) return false;

        if (Messages::is_campaign($optin_campaign_id)) return false;

        return (bool)apply_filters('mailoptin_twostep_supported', true, $optin_campaign_id);
    }

    public static function enabled($form)
    {
        if ( ! apply_filters('mailoptin_premium_two_step', defined('MAILOPTIN_DETACH_LIBSODIUM'))) return false;

        if ($form->get_customizer_value('twostep_status') !== true) return false;

        // A campaign that is only a button has nothing to ask first.
        if ($form->get_customizer_value('display_only_button') === true) return false;

        return self::supported($form->optin_campaign_id);
    }

    public function js_config($data, $form)
    {
        if ( ! isset($form->optin_campaign_id) || ! self::enabled($form)) return $data;

        $text = function ($key, $default) use ($form) {
            $value = trim(sanitize_text_field((string)$form->get_customizer_value($key)));

            return '' !== $value ? $value : $default;
        };

        $data['twostep'] = [
            'question' => $text('twostep_question', __('Want our best posts in your inbox?', 'mailoptin')),
            // These two may be empty: no text under the question, no "No" link.
            'text'     => trim(sanitize_text_field((string)$form->get_customizer_value('twostep_text'))),
            'yes'      => $text('twostep_yes', __('Yes, sign me up', 'mailoptin')),
            'no'       => trim(sanitize_text_field((string)$form->get_customizer_value('twostep_no'))),
            'progress' => $form->get_customizer_value('twostep_progress') !== false,
            'step'     => __('Step 2 of 2', 'mailoptin'),
            'almost'   => __('Almost there', 'mailoptin'),
        ];

        return $data;
    }

    /**
     * A Yes, or ("done") a subscription after a Yes: each counted once per visitor (IP address) per
     * campaign per day, as the endpoint is public. Sent by mailoptin.js with navigator.sendBeacon.
     */
    public function track_yes()
    {
        $payload = isset($_REQUEST['stat_data']) && is_array($_REQUEST['stat_data']) ? \MailOptin\Core\sanitize_data($_REQUEST['stat_data']) : [];
        $uuid    = isset($payload['optin_uuid']) && is_string($payload['optin_uuid']) ? $payload['optin_uuid'] : '';
        $id      = '' !== $uuid ? absint(OCR::get_optin_campaign_id_by_uuid($uuid)) : 0;

        if ( ! $id || ! self::supported($id) || ! OCR::is_activated($id)) wp_die('', '', ['response' => 400]);

        $stage = isset($payload['stage']) && 'done' === $payload['stage'] ? 'done' : 'yes';

        $ip  = function_exists('MailOptin\Core\get_ip_address') ? (string)\MailOptin\Core\get_ip_address() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $key = 'mo_twostep_' . $stage . '_' . md5($id . '|' . $ip);
        if (get_transient($key)) wp_die('', '', ['response' => 204]);
        set_transient($key, 1, DAY_IN_SECONDS);

        self::add_count($id, 'done' === $stage ? self::DONE_META : self::YES_META);

        do_action('mailoptin_twostep_' . $stage, $id);

        wp_die('', '', ['response' => 204]);
    }

    /** One more, counted in SQL so two at once aren't lost. */
    public static function add_count($optin_campaign_id, $meta_key)
    {
        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->optin_campaignmeta} SET meta_value = meta_value + 1 WHERE optin_campaign_id = %d AND meta_key = %s",
            $optin_campaign_id,
            $meta_key
        ));

        if ( ! $updated) OptinCampaignMeta::add_campaign_meta($optin_campaign_id, $meta_key, 1, true);

        wp_cache_delete($optin_campaign_id, 'optin_campaign_meta');
    }

    public static function yes_count($optin_campaign_id)
    {
        return absint(OptinCampaignMeta::get_campaign_meta(absint($optin_campaign_id), self::YES_META, true));
    }

    public static function done_count($optin_campaign_id)
    {
        return absint(OptinCampaignMeta::get_campaign_meta(absint($optin_campaign_id), self::DONE_META, true));
    }

    public static function get_instance()
    {
        static $instance = null;

        if (null === $instance) $instance = new self();

        return $instance;
    }
}
