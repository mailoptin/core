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
     */
    public static function erase($email, $page = 1)
    {
        $subscriber = Subscribers::get_by_email($email);

        if ( ! $subscriber) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        return ['items_removed' => Subscribers::delete($subscriber['id']), 'items_retained' => false, 'messages' => [], 'done' => true];
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
