<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;

/**
 * Built-in list subscribers in WordPress's Export and Erase Personal Data tools (Tools menu).
 */
class PersonalData
{
    public function __construct()
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser']);
    }

    public function register_exporter($exporters)
    {
        $exporters['mailoptin-subscribers'] = [
            'exporter_friendly_name' => __('MailOptin Subscribers', 'mailoptin'),
            'callback'               => [__CLASS__, 'export'],
        ];

        return $exporters;
    }

    public function register_eraser($erasers)
    {
        $erasers['mailoptin-subscribers'] = [
            'eraser_friendly_name' => __('MailOptin Subscribers', 'mailoptin'),
            'callback'             => [__CLASS__, 'erase'],
        ];

        return $erasers;
    }

    public static function export($email, $page = 1)
    {
        $subscriber = Subscribers::get_by_email($email);

        if ( ! $subscriber) return ['data' => [], 'done' => true];

        $lists = [];
        foreach (Subscribers::get_memberships($subscriber['id']) as $membership) {
            $list    = ListsRepository::get($membership['list_id']);
            $lists[] = sprintf('%s (%s)', $list['name'] ?? '#' . $membership['list_id'], $membership['status']);
        }

        $data = [
            ['name' => __('Email', 'mailoptin'), 'value' => $subscriber['email']],
            ['name' => __('First name', 'mailoptin'), 'value' => $subscriber['first_name']],
            ['name' => __('Last name', 'mailoptin'), 'value' => $subscriber['last_name']],
            ['name' => __('Status', 'mailoptin'), 'value' => $subscriber['status']],
            ['name' => __('Lists', 'mailoptin'), 'value' => implode(', ', $lists)],
            ['name' => __('Signup IP address', 'mailoptin'), 'value' => $subscriber['ip_address']],
            ['name' => __('Consent', 'mailoptin'), 'value' => trim($subscriber['consent_method'] . ' ' . $subscriber['consent_text'])],
            ['name' => __('Consent date', 'mailoptin'), 'value' => (string)$subscriber['consent_at']],
            ['name' => __('Added', 'mailoptin'), 'value' => $subscriber['created_at']],
        ];

        if (Tags::is_ready()) {
            $data[] = ['name' => __('Tags', 'mailoptin'), 'value' => implode(', ', Tags::for_subscriber($subscriber['id']))];

            $values = Fields::get_values($subscriber['id']);
            foreach (Fields::all() as $field) {
                if (isset($values[$field['id']])) {
                    $data[] = ['name' => $field['label'], 'value' => Fields::display_value($field, $values[$field['id']]['value_text'])];
                }
            }
        }

        if (\MailOptin\Core\Repositories\CampaignActivityRepository::is_ready()) {
            $activity = \MailOptin\Core\Repositories\CampaignActivityRepository::subscriber_summary($subscriber['id']);

            if ($activity['opened'] || $activity['clicked']) {
                $data[] = ['name' => __('Email activity', 'mailoptin'), 'value' => sprintf(
                    __('Opened %1$d of %2$d emails sent, clicked a link in %3$d. Last opened %4$s.', 'mailoptin'),
                    $activity['opened'], $activity['sent'], $activity['clicked'], (string)$activity['last_opened']
                )];
            }
        }

        $data = array_values(array_filter($data, function ($item) {
            return '' !== trim((string)$item['value']);
        }));

        return [
            'data' => [[
                'group_id'    => 'mailoptin-subscribers',
                'group_label' => __('MailOptin Subscribers', 'mailoptin'),
                'item_id'     => 'mailoptin-subscriber-' . $subscriber['id'],
                'data'        => $data,
            ]],
            'done' => true,
        ];
    }

    /**
     * Deletes the subscriber with their lists, history, tags and custom fields. If they had
     * unsubscribed, the suppression list keeps a one-way hash of the address so they aren't emailed again.
     *
     * The address is also removed where it's kept without a subscriber: an admin may have deleted
     * the subscriber already, or an import may have skipped the row.
     */
    public static function erase($email, $page = 1)
    {
        $email   = Subscribers::normalize_email($email);
        $removed = false;

        if ('' === $email) return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];

        global $wpdb;

        // Queued and sent emails keep the address for delivery records.
        $removed = (bool)$wpdb->delete($wpdb->prefix . \MailOptin\Core\Core::send_queue_table_name, ['email' => $email]) || $removed;

        // Import reports list problem rows with the address and everything else on them.
        $removed = SubscriberImport::forget_email($email) > 0 || $removed;

        // Form submissions waiting to be sent to an integration keep the whole form, to retry it.
        // A retry after this would sign the person up again.
        $conversions = \MailOptin\Core\Repositories\OptinConversionsRepository::get_conversions_by_email($email);

        if ( ! empty($conversions)) {
            $removed = \MailOptin\Core\Repositories\ConversionSyncRepository::forget_retry_data(wp_list_pluck($conversions, 'id')) > 0 || $removed;
        }

        $subscriber = Subscribers::get_by_email($email);

        if ($subscriber) $removed = Subscribers::delete($subscriber['id']) || $removed;

        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    /**
     * @return PersonalData
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}
