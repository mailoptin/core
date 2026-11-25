<?php

namespace MailOptin\Core\Automations\Triggers;

use MailOptin\Core\Automations\AbstractTrigger;
use MailOptin\Core\Repositories\AutomationsRepository;
use MailOptin\Core\Repositories\OptinCampaignsRepository as Campaigns;

/**
 * Someone signs up through an optin campaign: whatever list or service the campaign sends them to
 * (MailOptin's own lists, Mailchimp, Kit…), so it covers campaigns "Someone joins a list" can't see.
 *
 * Add-ons extend it for their own kinds of campaign through filters (Premium's product offers add
 * "they haven't bought it" checks, offer placeholders and stop the run once they buy):
 *
 * - mailoptin_optin_signup_trigger_context ($context, $lead_data): what to remember about the sign-up.
 * - mailoptin_optin_signup_trigger_checks ($checks): extra "Continue only if" choices.
 * - mailoptin_optin_signup_trigger_placeholders ($placeholders): extra {{placeholders}}.
 * - mailoptin_optin_signup_trigger_stop_reason ($reason, $run): a reason to end a run early.
 */
class OptinSignup extends AbstractTrigger
{
    public function id()
    {
        return 'optin_signup';
    }

    public function group()
    {
        return __('Subscribers', 'mailoptin');
    }

    public function label()
    {
        return __('Someone signs up through an optin campaign', 'mailoptin');
    }

    public function description()
    {
        return __('When they submit the form, whatever list or service the campaign sends them to.', 'mailoptin');
    }

    public function tip()
    {
        return __('Send the first email soon, while they remember signing up. For a product offer, add a check so reminders stop once they buy.', 'mailoptin');
    }

    public function default_runs()
    {
        return 'once';
    }

    /** Campaign id => name, for the picker. */
    public static function campaign_choices()
    {
        $choices = [0 => __('Any campaign', 'mailoptin')];

        foreach ((array)Campaigns::get_optin_campaigns() as $campaign) {
            $id = absint($campaign['id'] ?? 0);
            // Split-test variants sign people up under their parent's results; list the parents only.
            if ( ! $id || Campaigns::is_split_test_variant($id)) continue;
            $choices[$id] = (string)($campaign['name'] ?? '');
        }

        return $choices;
    }

    public function settings_fields()
    {
        return [
            'optin_campaign_id' => [
                'label'   => __('Campaign', 'mailoptin'),
                'type'    => 'select',
                'options' => [self::class, 'campaign_choices'],
                'default' => 0,
            ],
            'runs'              => [
                'label'   => __('Runs for each person', 'mailoptin'),
                'type'    => 'radio',
                'options' => ['once' => __('Once', 'mailoptin'), 'every' => __('Every time they sign up', 'mailoptin')],
                'default' => 'once',
            ],
        ];
    }

    public function summary($settings)
    {
        $id = absint($settings['optin_campaign_id'] ?? 0);

        if ( ! $id) return __('Someone signs up through any optin campaign', 'mailoptin');

        $name = Campaigns::get_optin_campaign_name($id);

        return '' !== (string)$name
            ? sprintf(__('Someone signs up through "%s"', 'mailoptin'), $name)
            : __('Someone signs up through a campaign that was deleted', 'mailoptin');
    }

    public function listen()
    {
        add_action('mailoptin_track_conversions', [$this, 'signed_up'], 10, 2);
    }

    /**
     * A sign-up that reached its list or service (failed ones never get here, retried ones do when
     * the retry succeeds).
     */
    public function signed_up($lead_data, $optin_campaign_id)
    {
        $optin_campaign_id = absint($optin_campaign_id);
        $email             = is_array($lead_data) ? (string)($lead_data['email'] ?? '') : '';

        if ( ! $optin_campaign_id || ! is_email($email)) return;

        // Most sites have no automation on this trigger: don't do any more work for them.
        if ( ! AutomationsRepository::live_for_trigger($this->id())) return;

        // A split-test variant counts as its parent campaign.
        $parent = absint(\MailOptin\Core\Repositories\OptinCampaignMeta::get_campaign_meta($optin_campaign_id, 'split_test_parent', true));

        $context = [
            'optin_campaign_id' => $parent ?: $optin_campaign_id,
            'name'              => sanitize_text_field((string)($lead_data['name'] ?? '')),
            'page'              => esc_url_raw((string)($lead_data['conversion_page'] ?? '')),
        ];

        $context = (array)apply_filters('mailoptin_optin_signup_trigger_context', $context, $lead_data);

        $this->start($email, 'optin-' . $context['optin_campaign_id'] . '-' . gmdate('YmdHis'), $context);
    }

    public function matches($context, $settings, $audience)
    {
        $wanted = absint($settings['optin_campaign_id'] ?? 0);

        return ! $wanted || $wanted === absint($context['optin_campaign_id'] ?? 0);
    }

    public function checks()
    {
        return (array)apply_filters('mailoptin_optin_signup_trigger_checks', []);
    }

    public function stop_reason($run)
    {
        return (string)apply_filters('mailoptin_optin_signup_trigger_stop_reason', '', $run);
    }

    public function stops_when()
    {
        return __('They unsubscribe. For a product offer, also when they buy the product or use their code.', 'mailoptin');
    }

    public function placeholders()
    {
        $first_name = function ($context) {
            $name = trim((string)($context['name'] ?? ''));

            return '' === $name ? '' : (string)strtok($name, ' ');
        };

        return [
                'signup.first_name' => ['label' => __('First name from the form', 'mailoptin'), 'value' => $first_name, 'sample' => 'Maya'],
                'signup.campaign'   => [
                    'label'  => __('Campaign name', 'mailoptin'),
                    'value'  => function ($context) {
                        return (string)Campaigns::get_optin_campaign_name(absint($context['optin_campaign_id'] ?? 0));
                    },
                    'sample' => __('Spring newsletter', 'mailoptin'),
                ],
                'signup.page'       => [
                    'label'  => __('Page they signed up on', 'mailoptin'),
                    'value'  => function ($context) {
                        return (string)($context['page'] ?? '');
                    },
                    'sample' => home_url('/'),
                ],
            ] + (array)apply_filters('mailoptin_optin_signup_trigger_placeholders', []);
    }

    public function count_recent($automation)
    {
        $id = absint($automation['trigger_settings']['optin_campaign_id'] ?? 0);

        global $wpdb;
        $table = $wpdb->prefix . \MailOptin\Core\Core::conversions_table_name;
        // date_added is in the site's time.
        $since = date('Y-m-d H:i:s', current_time('timestamp') - 30 * DAY_IN_SECONDS);

        $total = $id
            ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE optin_id IN (" . implode(',', array_map('absint', array_merge([$id], Campaigns::get_split_test_variant_ids($id)))) . ") AND date_added >= %s", $since))
            : (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE date_added >= %s", $since));

        return ['matched' => $total, 'total' => $total, 'label' => __('sign-ups in the last 30 days', 'mailoptin')];
    }
}
