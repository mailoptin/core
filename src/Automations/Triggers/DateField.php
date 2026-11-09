<?php

namespace MailOptin\Core\Automations\Triggers;

use MailOptin\Core\Automations\AbstractTrigger;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository;

/**
 * A date in a subscriber's profile comes round, every year: a birthday, a membership anniversary.
 * Checked hourly; a person is started once a year, from the given time of day.
 */
class DateField extends AbstractTrigger
{
    /** People started per automation per hourly check; the rest follow the next hour. */
    const BATCH = 500;

    public function id()
    {
        return 'date_field';
    }

    public function group()
    {
        return __('Dates', 'mailoptin');
    }

    public function label()
    {
        return __('A date in their profile comes round', 'mailoptin');
    }

    public function description()
    {
        return __('Every year on a date custom field, such as Birthday.', 'mailoptin');
    }

    public function is_available()
    {
        return TagsRepository::is_ready();
    }

    public function settings_fields()
    {
        return [
            'field_id'    => [
                'label'   => __('Date field', 'mailoptin'),
                'type'    => 'select',
                'options' => function () {
                    $options = [];
                    foreach (Fields::all() as $field) {
                        if (Fields::TYPE_DATE === $field['type']) $options[$field['id']] = $field['label'];
                    }

                    return $options;
                },
            ],
            'days_before' => [
                'label'       => __('Days before', 'mailoptin'),
                'type'        => 'number',
                'default'     => 0,
                'description' => __('0 for on the day.', 'mailoptin'),
            ],
            'time'        => [
                'label'   => __('From', 'mailoptin'),
                'type'    => 'time',
                'default' => '09:00',
            ],
        ];
    }

    public function listen()
    {
        // Started by scan(), not by an event.
    }

    /**
     * The local date to look for now, or null before the day's start time.
     */
    public static function target_date($settings, $now = null)
    {
        $tz    = wp_timezone();
        $local = (new \DateTimeImmutable('@' . ($now ?: time())))->setTimezone($tz);
        $time  = preg_match('/^(\d{1,2}):(\d{2})$/', (string)($settings['time'] ?? '09:00'), $m) ? [(int)$m[1], (int)$m[2]] : [9, 0];

        if ((int)$local->format('G') * 60 + (int)$local->format('i') < $time[0] * 60 + $time[1]) return null;

        $days = max(0, (int)($settings['days_before'] ?? 0));

        return $local->modify('+' . $days . ' days');
    }

    public function scan($automation)
    {
        global $wpdb;

        $field_id = absint($automation['trigger_settings']['field_id'] ?? 0);
        $target   = self::target_date($automation['trigger_settings']);

        if ( ! $field_id || ! $target) return;

        $month = (int)$target->format('n');
        $day   = (int)$target->format('j');

        // 29 February birthdays are celebrated on 28 February in other years.
        $leap_extra = (2 === $month && 28 === $day && ! $target->format('L')) ? ' OR (MONTH(v.value_date) = 2 AND DAY(v.value_date) = 29)' : '';

        $runs = \MailOptin\Core\Repositories\AutomationsRepository::runs_table();
        $year = $target->format('Y');

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT s.id, s.email FROM ' . Fields::values_table() . ' v INNER JOIN ' . Subscribers::table() . " s ON s.id = v.subscriber_id
            WHERE v.field_id = %d AND s.status = %s AND ((MONTH(v.value_date) = %d AND DAY(v.value_date) = %d)$leap_extra)
            AND NOT EXISTS (SELECT 1 FROM $runs r WHERE r.automation_id = %d AND r.email = s.email AND r.trigger_ref = %s)
            ORDER BY s.id LIMIT %d",
            $field_id, Subscribers::STATUS_SUBSCRIBED, $month, $day, $automation['id'], $year, self::BATCH
        ), 'ARRAY_A');

        foreach ((array)$rows as $row) {
            $this->start($row['email'], $year, ['field_id' => $field_id, 'date' => $target->format('Y-m-d')]);
        }
    }

    public function matches($context, $settings, $audience)
    {
        return absint($settings['field_id'] ?? 0) === absint($context['field_id'] ?? 0);
    }

    public function placeholders()
    {
        return [
            'date.day' => [
                'label'  => __('The date', 'mailoptin'),
                'value'  => function ($context) {
                    return empty($context['date']) ? '' : date_i18n(get_option('date_format'), strtotime($context['date']));
                },
                'sample' => date_i18n(get_option('date_format')),
            ],
        ];
    }
}
