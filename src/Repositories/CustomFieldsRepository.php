<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Custom fields of built-in list subscribers, shared by all lists, and each subscriber's values.
 *
 * A field's merge key is set when it is created and never changes, because emails and forms refer
 * to it. Values are kept as text, plus a number (number, checkbox) or date (date) to filter on.
 */
class CustomFieldsRepository extends AbstractRepository
{
    const TYPE_TEXT = 'text';
    const TYPE_NUMBER = 'number';
    const TYPE_DATE = 'date';
    const TYPE_DROPDOWN = 'dropdown';
    const TYPE_CHECKBOX = 'checkbox';
    const TYPE_MULTISELECT = 'multiselect';
    const TYPE_URL = 'url';
    const TYPE_PHONE = 'phone';

    const MAX_TEXT_LENGTH = 1000;
    const MAX_KEY_LENGTH = 64;

    // Merge keys of deleted fields, so emails that still use them don't show the raw tag.
    const DELETED_KEYS_OPTION = 'mo_deleted_custom_field_keys';

    public static function table()
    {
        return self::wpdb()->prefix . Core::custom_fields_table_name;
    }

    public static function values_table()
    {
        return self::wpdb()->prefix . Core::field_values_table_name;
    }

    /**
     * @return array type => label
     */
    public static function types()
    {
        return [
            self::TYPE_TEXT        => __('Text', 'mailoptin'),
            self::TYPE_NUMBER      => __('Number', 'mailoptin'),
            self::TYPE_DATE        => __('Date', 'mailoptin'),
            self::TYPE_DROPDOWN    => __('Dropdown', 'mailoptin'),
            self::TYPE_CHECKBOX    => __('Checkbox', 'mailoptin'),
            self::TYPE_MULTISELECT => __('Multi-select', 'mailoptin'),
            self::TYPE_URL         => __('URL', 'mailoptin'),
            self::TYPE_PHONE       => __('Phone', 'mailoptin'),
        ];
    }

    public static function has_options($type)
    {
        return in_array($type, [self::TYPE_DROPDOWN, self::TYPE_MULTISELECT], true);
    }

    /**
     * Merge keys a custom field can't use: the subscriber's built-in details, and words kept for later.
     */
    public static function reserved_keys()
    {
        return apply_filters('mo_custom_field_reserved_keys', ['email', 'first_name', 'last_name', 'name', 'full_name', 'id', 'status', 'lists', 'list', 'tags', 'source', 'ip_address', 'created_at', 'unsubscribe', 'webversion']);
    }

    /**
     * A merge key from a label: "Favourite plant family" becomes "favourite_plant_family".
     */
    public static function key_from_label($label)
    {
        $key = strtolower(remove_accents((string)$label));
        $key = trim(preg_replace('/[^a-z0-9]+/', '_', $key), '_');
        $key = substr($key, 0, self::MAX_KEY_LENGTH);

        // A key must start with a letter so it reads as a word in {{subscriber.key}}.
        if ('' !== $key && ! ctype_alpha($key[0])) $key = substr('field_' . $key, 0, self::MAX_KEY_LENGTH);

        return rtrim($key, '_');
    }

    public static function is_valid_key($key)
    {
        return is_string($key) && (bool)preg_match('/^[a-z][a-z0-9_]{0,' . (self::MAX_KEY_LENGTH - 1) . '}$/', $key);
    }

    /**
     * @return array fields in display order, options decoded
     */
    public static function all()
    {
        if (null !== self::$all_cache) return self::$all_cache;

        $table = self::table();
        $rows  = self::wpdb()->get_results("SELECT * FROM $table ORDER BY position, id", 'ARRAY_A');

        return self::$all_cache = array_map([__CLASS__, 'prepare'], (array)$rows);
    }

    protected static $all_cache = null;

    public static function flush_cache()
    {
        self::$all_cache = null;
    }

    protected static function prepare($row)
    {
        $row['id']                  = (int)$row['id'];
        $row['required']            = (bool)$row['required'];
        $row['subscriber_editable'] = (bool)$row['subscriber_editable'];
        $row['options']             = self::has_options($row['type']) ? array_values(array_filter((array)json_decode((string)$row['options'], true), 'is_string')) : [];

        return $row;
    }

    public static function get($field_id)
    {
        foreach (self::all() as $field) {
            if ($field['id'] === (int)$field_id) return $field;
        }

        return null;
    }

    public static function get_by_key($merge_key)
    {
        foreach (self::all() as $field) {
            if ($field['merge_key'] === $merge_key) return $field;
        }

        return null;
    }

    /**
     * @param string $type
     * @param string|array $options one per line, or a list
     *
     * @return string[] trimmed, unique, without empty ones
     */
    public static function sanitize_options($options)
    {
        if ( ! is_array($options)) $options = preg_split('/\r\n|\r|\n/', (string)$options);

        $clean = [];

        foreach ($options as $option) {
            // Commas separate options in bulk edits and form submissions, so an option can't contain one.
            $option = trim(preg_replace('/\s+/', ' ', str_replace(',', ' ', sanitize_text_field((string)$option))));

            if ('' !== $option && ! in_array(mb_strtolower($option), array_map('mb_strtolower', $clean), true)) {
                $clean[] = mb_substr($option, 0, 200);
            }
        }

        return $clean;
    }

    /**
     * @param array $data label, merge_key (optional: made from the label), type, options, required, subscriber_editable
     *
     * @return int|\WP_Error new field ID
     */
    public static function create($data)
    {
        $type  = $data['type'] ?? '';
        $label = trim(sanitize_text_field($data['label'] ?? ''));
        $key   = isset($data['merge_key']) && '' !== trim($data['merge_key']) ? trim($data['merge_key']) : self::key_from_label($label);

        if ( ! isset(self::types()[$type])) return new \WP_Error('mo_field_type', __('Choose a field type.', 'mailoptin'));

        if ('' === $label) return new \WP_Error('mo_field_label', __('Enter a label.', 'mailoptin'));

        if ( ! self::is_valid_key($key)) {
            return new \WP_Error('mo_field_key', __('The merge tag can only use lowercase letters, numbers and underscores, and must start with a letter.', 'mailoptin'));
        }

        if (in_array($key, self::reserved_keys(), true)) {
            return new \WP_Error('mo_field_key_reserved', sprintf(__('{{subscriber.%s}} is built in. Choose another merge tag.', 'mailoptin'), $key));
        }

        if (self::get_by_key($key)) {
            return new \WP_Error('mo_field_key_taken', sprintf(__('Another field already uses {{subscriber.%s}}.', 'mailoptin'), $key));
        }

        $options = self::has_options($type) ? self::sanitize_options($data['options'] ?? []) : [];

        if (self::has_options($type) && empty($options)) return new \WP_Error('mo_field_options', __('Add at least one option.', 'mailoptin'));

        $table = self::table();

        $inserted = self::wpdb()->insert($table, [
            'label'               => mb_substr($label, 0, 200),
            'merge_key'           => $key,
            'type'                => $type,
            'options'             => empty($options) ? null : wp_json_encode($options),
            'required'            => empty($data['required']) ? 0 : 1,
            // Subscribers only see a field in the preference centre once the admin allows it.
            'subscriber_editable' => empty($data['subscriber_editable']) ? 0 : 1,
            'position'            => 1 + (int)self::wpdb()->get_var("SELECT MAX(position) FROM $table"),
            'created_at'          => Subscribers::now(),
        ]);

        $field_id = $inserted ? (int)self::wpdb()->insert_id : 0;

        self::flush_cache();

        if ($inserted) update_option(self::DELETED_KEYS_OPTION, array_values(array_diff((array)get_option(self::DELETED_KEYS_OPTION, []), [$key])), false);

        return $inserted ? $field_id : new \WP_Error('mo_field_not_saved', __('The field could not be saved.', 'mailoptin'));
    }

    /**
     * Change a field. The merge key and type stay as they are: emails, forms and stored values depend on them.
     *
     * @param array $data label, options, required, subscriber_editable
     *
     * @return true|\WP_Error
     */
    public static function update($field_id, $data)
    {
        $field = self::get($field_id);

        if ( ! $field) return new \WP_Error('mo_field_not_found', __('That field no longer exists.', 'mailoptin'));

        $update = [];

        if (isset($data['label'])) {
            $label = trim(sanitize_text_field($data['label']));
            if ('' === $label) return new \WP_Error('mo_field_label', __('Enter a label.', 'mailoptin'));
            $update['label'] = mb_substr($label, 0, 200);
        }

        if (isset($data['options']) && self::has_options($field['type'])) {
            $options = self::sanitize_options($data['options']);
            if (empty($options)) return new \WP_Error('mo_field_options', __('Add at least one option.', 'mailoptin'));
            $update['options'] = wp_json_encode($options);
        }

        if (isset($data['required'])) $update['required'] = $data['required'] ? 1 : 0;
        if (isset($data['subscriber_editable'])) $update['subscriber_editable'] = $data['subscriber_editable'] ? 1 : 0;

        if ( ! empty($update)) self::wpdb()->update(self::table(), $update, ['id' => $field['id']]);

        self::flush_cache();

        return true;
    }

    /**
     * Delete a field and every subscriber's value for it.
     */
    public static function delete($field_id)
    {
        $field = self::get($field_id);

        // Emails may still use its merge tag; they show the fallback, or nothing, from now on.
        if ($field) {
            $deleted = (array)get_option(self::DELETED_KEYS_OPTION, []);
            update_option(self::DELETED_KEYS_OPTION, array_values(array_unique(array_merge($deleted, [$field['merge_key']]))), false);
        }

        self::wpdb()->delete(self::values_table(), ['field_id' => $field_id], ['%d']);
        $deleted = (bool)self::wpdb()->delete(self::table(), ['id' => $field_id], ['%d']);

        self::flush_cache();

        do_action('mo_custom_field_deleted', (int)$field_id);

        return $deleted;
    }

    /**
     * @param int[] $field_ids in the new order; fields left out keep their place after them
     */
    public static function reorder($field_ids)
    {
        $field_ids = array_values(array_unique(array_filter(array_map('absint', (array)$field_ids))));
        $rest      = array_diff(wp_list_pluck(self::all(), 'id'), $field_ids);

        foreach (array_merge($field_ids, $rest) as $position => $field_id) {
            if (self::get($field_id)) self::wpdb()->update(self::table(), ['position' => $position], ['id' => $field_id]);
        }

        self::flush_cache();
    }

    /**
     * Turn a submitted value into what's stored.
     *
     * @param array $field
     * @param mixed $raw from a form or the admin; multi-select takes a list or comma-separated text
     *
     * @return array|null|\WP_Error ['text' => ..., 'number' => ..., 'date' => ...]; null when empty
     */
    public static function normalize_value($field, $raw)
    {
        if (is_array($raw) && self::TYPE_MULTISELECT !== $field['type']) $raw = implode(', ', array_filter(array_map('strval', $raw), 'strlen'));

        if ( ! is_array($raw)) $raw = trim((string)$raw);

        if ('' === $raw || [] === $raw) return null;

        $label = $field['label'];

        switch ($field['type']) {
            case self::TYPE_NUMBER:
                $number = str_replace(' ', '', $raw);
                // 1,000 and 1,000.50 use commas between thousands; 2,5 uses one as the decimal point.
                if (false !== strpos($number, '.') || preg_match('/^-?\d{1,3}(,\d{3})+$/', $number)) {
                    $number = str_replace(',', '', $number);
                } else {
                    $number = str_replace(',', '.', $number);
                }
                if ( ! is_numeric($number) || abs((float)$number) >= 1e16) {
                    return new \WP_Error('mo_field_number', sprintf(__('%s must be a number.', 'mailoptin'), $label));
                }
                $number = round((float)$number, 4);

                return ['text' => (string)(0.0 === fmod($number, 1) ? (int)$number : $number), 'number' => $number, 'date' => null];

            case self::TYPE_DATE:
                $time = strtotime($raw);
                if (false === $time || ! preg_match('/\d/', $raw)) {
                    return new \WP_Error('mo_field_date', sprintf(__('%s must be a date.', 'mailoptin'), $label));
                }
                $date = gmdate('Y-m-d', $time);

                return ['text' => $date, 'number' => null, 'date' => $date];

            case self::TYPE_CHECKBOX:
                $on = in_array(strtolower($raw), ['1', 'on', 'yes', 'true', 'y', 'checked'], true) || strtolower($raw) === strtolower(__('Yes', 'mailoptin'));
                if ( ! $on && ! in_array(strtolower($raw), ['0', 'off', 'no', 'false', 'n'], true) && strtolower($raw) !== strtolower(__('No', 'mailoptin'))) {
                    return new \WP_Error('mo_field_checkbox', sprintf(__('%s must be yes or no.', 'mailoptin'), $label));
                }

                return ['text' => $on ? '1' : '0', 'number' => $on ? 1 : 0, 'date' => null];

            case self::TYPE_DROPDOWN:
                $option = self::match_option($field, $raw);
                if (null === $option) {
                    return new \WP_Error('mo_field_option', sprintf(__('%s must be one of its options.', 'mailoptin'), $label));
                }

                return ['text' => $option, 'number' => null, 'date' => null];

            case self::TYPE_MULTISELECT:
                $chosen = is_array($raw) ? $raw : explode(',', $raw);
                $picked = [];
                foreach ($chosen as $choice) {
                    $option = self::match_option($field, trim((string)$choice));
                    if (null === $option) {
                        if ('' === trim((string)$choice)) continue;
                        return new \WP_Error('mo_field_option', sprintf(__('%s must be one of its options.', 'mailoptin'), $label));
                    }
                    $picked[] = $option;
                }
                // Kept in the field's own option order.
                $picked = array_values(array_intersect($field['options'], $picked));

                return empty($picked) ? null : ['text' => wp_json_encode($picked), 'number' => null, 'date' => null];

            case self::TYPE_URL:
                $url = esc_url_raw(preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw) ? $raw : 'https://' . $raw, ['http', 'https']);
                if ( ! filter_var($url, FILTER_VALIDATE_URL) || false === strpos((string)wp_parse_url($url, PHP_URL_HOST), '.')) {
                    return new \WP_Error('mo_field_url', sprintf(__('%s must be a web address.', 'mailoptin'), $label));
                }

                return ['text' => mb_substr($url, 0, self::MAX_TEXT_LENGTH), 'number' => null, 'date' => null];

            case self::TYPE_PHONE:
                $phone = preg_replace('/\s+/', ' ', $raw);
                if ( ! preg_match('/^\+?[0-9 ().\-\/]{3,30}$/', $phone) || ! preg_match('/[0-9]{3}/', preg_replace('/\D/', '', $phone))) {
                    return new \WP_Error('mo_field_phone', sprintf(__('%s must be a phone number.', 'mailoptin'), $label));
                }

                return ['text' => $phone, 'number' => null, 'date' => null];

            default:
                return ['text' => mb_substr(self::plain_text($raw), 0, self::MAX_TEXT_LENGTH), 'number' => null, 'date' => null];
        }
    }

    /**
     * Text as typed, without tags or line breaks. Stored unescaped; it's escaped where it's shown.
     * Form submissions arrive through sanitize_text_field(), which encodes "<" and "&".
     */
    public static function plain_text($raw)
    {
        $text = wp_strip_all_tags(wp_check_invalid_utf8((string)$raw), true);

        return trim(preg_replace('/\s+/', ' ', wp_specialchars_decode($text, ENT_NOQUOTES)));
    }

    /**
     * @return string|null the option as the field spells it, ignoring case
     */
    protected static function match_option($field, $value)
    {
        foreach ($field['options'] as $option) {
            if (0 === strcasecmp($option, trim((string)$value))) return $option;
        }

        return null;
    }

    /**
     * Save values for a subscriber.
     *
     * @param int $subscriber_id
     * @param array $values field ID or merge key => raw value; an empty value clears the field
     * @param bool $only_empty only fill fields the subscriber has no value for (a form signup)
     *
     * @return array ['changed' => [field_id => [old text|null, new text|null]], 'errors' => [field_id => message]]
     */
    public static function set_values($subscriber_id, $values, $only_empty = false)
    {
        $subscriber_id = absint($subscriber_id);
        $current       = self::get_values($subscriber_id);
        $result        = ['changed' => [], 'errors' => []];

        foreach ((array)$values as $id_or_key => $raw) {
            $field = is_int($id_or_key) || ctype_digit((string)$id_or_key) ? self::get($id_or_key) : self::get_by_key((string)$id_or_key);

            if ( ! $field) continue;

            $old = $current[$field['id']]['value_text'] ?? null;

            if ($only_empty && null !== $old) continue;

            // The stored value sent back unchanged, even if it's no longer valid (an option that was removed).
            if (null !== $old && self::form_value($field, $old) === (is_array($raw) ? array_values($raw) : (string)$raw)) continue;

            $value = self::normalize_value($field, $raw);

            if (is_wp_error($value)) {
                $result['errors'][$field['id']] = $value->get_error_message();
                continue;
            }

            if (null === $value) {
                if ($only_empty || null === $old) continue;

                self::wpdb()->delete(self::values_table(), ['subscriber_id' => $subscriber_id, 'field_id' => $field['id']]);
                $result['changed'][$field['id']] = [$old, null];
                continue;
            }

            if ($old === $value['text']) continue;

            self::wpdb()->replace(self::values_table(), [
                'subscriber_id' => $subscriber_id,
                'field_id'      => $field['id'],
                'value_text'    => $value['text'],
                'value_number'  => $value['number'],
                'value_date'    => $value['date'],
                'updated_at'    => Subscribers::now(),
            ]);

            $result['changed'][$field['id']] = [$old, $value['text']];
        }

        return $result;
    }

    /**
     * Set one field to the same value for many subscribers, for the bulk action.
     *
     * @return int|\WP_Error subscribers changed
     */
    public static function set_value_for_subscribers($field_id, $raw, $subscriber_ids)
    {
        $field          = self::get($field_id);
        $subscriber_ids = array_values(array_filter(array_map('absint', (array)$subscriber_ids)));

        if ( ! $field) return new \WP_Error('mo_field_not_found', __('That field no longer exists.', 'mailoptin'));

        $value = self::normalize_value($field, $raw);

        if (is_wp_error($value)) return $value;

        if (empty($subscriber_ids)) return 0;

        $table = self::values_table();
        $in    = implode(',', $subscriber_ids);

        if (null === $value) {
            return (int)self::wpdb()->query(self::wpdb()->prepare("DELETE FROM $table WHERE field_id = %d AND subscriber_id IN ($in)", $field['id']));
        }

        $wpdb = self::wpdb();
        $tail = sprintf(
            '%s, %s, %s)',
            null === $value['number'] ? 'NULL' : $wpdb->prepare('%s', (string)$value['number']),
            null === $value['date'] ? 'NULL' : $wpdb->prepare('%s', $value['date']),
            $wpdb->prepare('%s', Subscribers::now())
        );

        $rows = [];
        foreach ($subscriber_ids as $subscriber_id) {
            $rows[] = $wpdb->prepare('(%d, %d, %s, ', $subscriber_id, $field['id'], $value['text']) . $tail;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $wpdb->query("REPLACE INTO $table (subscriber_id, field_id, value_text, value_number, value_date, updated_at) VALUES " . implode(',', $chunk));
        }

        return count($subscriber_ids);
    }

    /**
     * @return array field_id => value row
     */
    public static function get_values($subscriber_id)
    {
        return self::values_for_subscribers([$subscriber_id])[(int)$subscriber_id] ?? [];
    }

    /**
     * @param int[] $subscriber_ids
     * @param int[]|null $field_ids only these fields
     *
     * @return array subscriber_id => [field_id => value row]
     */
    public static function values_for_subscribers($subscriber_ids, $field_ids = null)
    {
        $subscriber_ids = array_filter(array_map('absint', (array)$subscriber_ids));

        if (empty($subscriber_ids) || ! TagsRepository::is_ready()) return [];

        $table = self::values_table();
        $sql   = "SELECT * FROM $table WHERE subscriber_id IN (" . implode(',', $subscriber_ids) . ')';

        if (is_array($field_ids)) {
            $field_ids = array_filter(array_map('absint', $field_ids));
            if (empty($field_ids)) return [];
            $sql .= ' AND field_id IN (' . implode(',', $field_ids) . ')';
        }

        $grouped = [];
        foreach (self::wpdb()->get_results($sql, 'ARRAY_A') as $row) {
            $grouped[(int)$row['subscriber_id']][(int)$row['field_id']] = $row;
        }

        return $grouped;
    }

    /**
     * A stored value as people read it: "Yes", "June 2, 1988", "Roses, Vegetables".
     *
     * @param array $field
     * @param string|null $text value_text
     */
    public static function display_value($field, $text)
    {
        if (null === $text || '' === $text) return '';

        switch ($field['type']) {
            case self::TYPE_CHECKBOX:
                return '1' === (string)$text ? __('Yes', 'mailoptin') : __('No', 'mailoptin');
            case self::TYPE_DATE:
                return date_i18n(get_option('date_format'), strtotime($text . ' 00:00:00 UTC'), true);
            case self::TYPE_MULTISELECT:
                return implode(', ', (array)json_decode($text, true));
            default:
                return (string)$text;
        }
    }

    /**
     * Values for an edit form: multi-select as a list, everything else as text.
     */
    public static function form_value($field, $text)
    {
        if (self::TYPE_MULTISELECT === $field['type']) return null === $text ? [] : (array)json_decode($text, true);

        return null === $text ? '' : (string)$text;
    }

    /**
     * @return array merge_key => value as people read it, for every field (empty when not set)
     */
    public static function merge_values($subscriber_id)
    {
        $values = $subscriber_id ? self::get_values($subscriber_id) : [];
        $merge  = [];

        foreach ((array)get_option(self::DELETED_KEYS_OPTION, []) as $key) {
            if (is_string($key)) $merge[$key] = '';
        }

        foreach (self::all() as $field) {
            $merge[$field['merge_key']] = self::display_value($field, $values[$field['id']]['value_text'] ?? null);
        }

        return $merge;
    }

    /**
     * @return array field_id => number of subscribers with a value
     */
    public static function value_counts()
    {
        $table = self::values_table();

        return array_map('intval', wp_list_pluck(self::wpdb()->get_results("SELECT field_id, COUNT(*) AS total FROM $table GROUP BY field_id", 'ARRAY_A'), 'total', 'field_id'));
    }

    /**
     * Comparisons a filter can use on a field of this type.
     *
     * @return array operator => label
     */
    public static function operators($type)
    {
        $set     = ['is' => __('is', 'mailoptin'), 'is_not' => __('is not', 'mailoptin')];
        $empty   = ['not_empty' => __('is set', 'mailoptin'), 'empty' => __('is empty', 'mailoptin')];
        $text    = ['contains' => __('contains', 'mailoptin'), 'not_contains' => __('does not contain', 'mailoptin')];

        switch ($type) {
            case self::TYPE_NUMBER:
                return $set + ['gt' => __('is more than', 'mailoptin'), 'lt' => __('is less than', 'mailoptin'), 'between' => __('is between', 'mailoptin')] + $empty;
            case self::TYPE_DATE:
                return $set + ['after' => __('is after', 'mailoptin'), 'before' => __('is before', 'mailoptin'), 'between' => __('is between', 'mailoptin')] + $empty;
            case self::TYPE_CHECKBOX:
                return ['checked' => __('is checked', 'mailoptin'), 'unchecked' => __('is not checked', 'mailoptin')];
            case self::TYPE_DROPDOWN:
                return ['is' => __('is any of', 'mailoptin'), 'is_not' => __('is none of', 'mailoptin')] + $empty;
            case self::TYPE_MULTISELECT:
                return ['has_any' => __('has any of', 'mailoptin'), 'has_none' => __('has none of', 'mailoptin')] + $empty;
            default:
                return $set + $text + $empty;
        }
    }

    /**
     * SQL for one filter condition on subscribers aliased "s".
     *
     * @param array $condition field_id, op, value (a list for dropdown and multi-select), value2 (end of a range)
     *
     * @return string|null null when the condition is incomplete or invalid, so it is ignored
     */
    public static function condition_sql($condition)
    {
        $field = self::get($condition['field_id'] ?? 0);
        $op    = $condition['op'] ?? '';

        if ( ! $field || ! isset(self::operators($field['type'])[$op])) return null;

        $wpdb   = self::wpdb();
        $table  = self::values_table();
        $exists = $wpdb->prepare("EXISTS (SELECT 1 FROM $table v WHERE v.subscriber_id = s.id AND v.field_id = %d", $field['id']);
        $value  = $condition['value'] ?? '';

        if ('empty' === $op) return "NOT $exists)";
        if ('not_empty' === $op) return "$exists)";
        if ('checked' === $op) return "$exists AND v.value_number = 1)";
        if ('unchecked' === $op) return "NOT $exists AND v.value_number = 1)";

        if (in_array($field['type'], [self::TYPE_DROPDOWN, self::TYPE_MULTISELECT], true)) {
            $options = array_values(array_intersect($field['options'], array_map('strval', (array)$value)));

            if (empty($options)) return null;

            if (self::TYPE_DROPDOWN === $field['type']) {
                $in  = implode(',', array_map(function ($option) use ($wpdb) {
                    return $wpdb->prepare('%s', $option);
                }, $options));
                $sql = "$exists AND v.value_text IN ($in))";
            } else {
                // Stored as a JSON list, so look for the quoted option.
                $likes = implode(' OR ', array_map(function ($option) use ($wpdb) {
                    return $wpdb->prepare('v.value_text LIKE %s', '%' . $wpdb->esc_like(wp_json_encode($option)) . '%');
                }, $options));
                $sql   = "$exists AND ($likes))";
            }

            return in_array($op, ['is_not', 'has_none'], true) ? "NOT $sql" : $sql;
        }

        $column = self::TYPE_NUMBER === $field['type'] ? 'value_number' : (self::TYPE_DATE === $field['type'] ? 'value_date' : 'value_text');

        $normalize = function ($raw) use ($field) {
            $normalized = self::normalize_value($field, $raw);

            if (null === $normalized || is_wp_error($normalized)) return null;

            return self::TYPE_NUMBER === $field['type'] ? $normalized['number'] : (self::TYPE_DATE === $field['type'] ? $normalized['date'] : $normalized['text']);
        };

        if (is_array($value)) return null;

        if (in_array($op, ['contains', 'not_contains'], true)) {
            if ('' === trim((string)$value)) return null;
            $sql = $exists . $wpdb->prepare(' AND v.value_text LIKE %s)', '%' . $wpdb->esc_like(trim((string)$value)) . '%');

            return 'not_contains' === $op ? "NOT $sql" : $sql;
        }

        $first = $normalize($value);

        if (null === $first) return null;

        $placeholder = self::TYPE_NUMBER === $field['type'] ? '%f' : '%s';

        switch ($op) {
            case 'is':
                return $exists . $wpdb->prepare(" AND v.$column = $placeholder)", $first);
            case 'is_not':
                return 'NOT ' . $exists . $wpdb->prepare(" AND v.$column = $placeholder)", $first);
            case 'gt':
            case 'after':
                return $exists . $wpdb->prepare(" AND v.$column > $placeholder)", $first);
            case 'lt':
            case 'before':
                return $exists . $wpdb->prepare(" AND v.$column < $placeholder)", $first);
            case 'between':
                $second = $normalize($condition['value2'] ?? '');
                if (null === $second) return null;
                if ($second < $first) list($first, $second) = [$second, $first];

                return $exists . $wpdb->prepare(" AND v.$column BETWEEN $placeholder AND $placeholder)", $first, $second);
        }

        return null;
    }

    public static function delete_values_for_subscribers($subscriber_ids)
    {
        $subscriber_ids = array_filter(array_map('absint', (array)$subscriber_ids));

        if (empty($subscriber_ids) || ! TagsRepository::is_ready()) return 0;

        return (int)self::wpdb()->query('DELETE FROM ' . self::values_table() . ' WHERE subscriber_id IN (' . implode(',', $subscriber_ids) . ')');
    }
}
