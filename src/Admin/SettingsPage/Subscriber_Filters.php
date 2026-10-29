<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\TagsRepository as Tags;

/**
 * Tag and custom field filters of the subscribers screen, kept in the URL so filtered views can be
 * bookmarked and paginated: tags[]=1&tag_match=any&f[0][field]=3&f[0][op]=gt&f[0][value]=5
 */
class Subscriber_Filters
{
    /**
     * @param array $request usually $_GET
     *
     * @return array tag_ids, tag_match, field_conditions (each field_id, op, value, value2), all sanitized
     */
    public static function from_request($request)
    {
        $filters = ['tag_ids' => [], 'tag_match' => 'any', 'field_conditions' => []];

        if ( ! Tags::is_ready()) return $filters;

        $filters['tag_ids'] = array_values(array_unique(array_filter(array_map('absint', (array)($request['tags'] ?? [])))));

        $match = sanitize_key($request['tag_match'] ?? 'any');
        if (in_array($match, ['any', 'all', 'none'], true)) $filters['tag_match'] = $match;

        foreach ((array)($request['f'] ?? []) as $condition) {
            if ( ! is_array($condition)) continue;

            $field = Fields::get(absint($condition['field'] ?? 0));
            $op    = sanitize_key($condition['op'] ?? '');

            if ( ! $field || ! isset(Fields::operators($field['type'])[$op])) continue;

            $value = wp_unslash($condition['value'] ?? '');
            $value = is_array($value) ? array_map('sanitize_text_field', $value) : sanitize_text_field((string)$value);

            $filters['field_conditions'][] = [
                'field_id' => $field['id'],
                'op'       => $op,
                'value'    => $value,
                'value2'   => sanitize_text_field((string)wp_unslash($condition['value2'] ?? '')),
            ];
        }

        return $filters;
    }

    public static function is_active($filters)
    {
        return ! empty($filters['tag_ids']) || ! empty($filters['field_conditions']);
    }

    /**
     * The filters as query args, for links and hidden inputs.
     */
    public static function to_query($filters)
    {
        $query = [];

        if ( ! empty($filters['tag_ids'])) {
            $query['tags']      = $filters['tag_ids'];
            $query['tag_match'] = $filters['tag_match'];
        }

        foreach (array_values($filters['field_conditions']) as $i => $condition) {
            $query['f'][$i] = array_filter([
                'field'  => $condition['field_id'],
                'op'     => $condition['op'],
                'value'  => $condition['value'],
                'value2' => $condition['value2'],
            ], function ($value) {
                return '' !== $value && [] !== $value;
            });
        }

        return $query;
    }

    /**
     * Hidden inputs that carry the filters through another GET form, like search.
     */
    public static function hidden_inputs($filters, $skip_tags = false)
    {
        $query = self::to_query($filters);

        if ($skip_tags) unset($query['tags'], $query['tag_match']);

        $html = '';

        foreach (explode('&', http_build_query($query)) as $pair) {
            if ('' === $pair) continue;

            list($name, $value) = array_map('urldecode', array_pad(explode('=', $pair, 2), 2, ''));

            $html .= sprintf('<input type="hidden" name="%s" value="%s">', esc_attr($name), esc_attr($value));
        }

        return $html;
    }

    /**
     * Filter chips, each with a link that removes it.
     *
     * @param string $base_url the current view without tag or field filters
     */
    public static function chips_html($filters, $base_url)
    {
        $chips = [];

        if ( ! empty($filters['tag_ids'])) {
            $names = array_intersect_key(Tags::names(), array_flip($filters['tag_ids']));
            $label = [
                'any'  => __('Tag is any of', 'mailoptin'),
                'all'  => __('Tag is all of', 'mailoptin'),
                'none' => __('Tag is none of', 'mailoptin'),
            ][$filters['tag_match']];

            $without = $filters;
            $without['tag_ids'] = [];

            $chips[] = self::chip($label, implode(', ', $names), add_query_arg(self::to_query($without), $base_url));
        }

        foreach ($filters['field_conditions'] as $i => $condition) {
            $field   = Fields::get($condition['field_id']);
            $without = $filters;
            unset($without['field_conditions'][$i]);

            $chips[] = self::chip(
                $field['label'] . ' ' . Fields::operators($field['type'])[$condition['op']],
                self::value_label($field, $condition),
                add_query_arg(self::to_query($without), $base_url)
            );
        }

        return implode('', $chips);
    }

    protected static function chip($label, $value, $remove_url)
    {
        return sprintf(
            '<span class="mo-filter-chip">%s%s <a href="%s" aria-label="%s">&times;</a></span>',
            esc_html($label),
            '' === $value ? '' : ' <b>' . esc_html($value) . '</b>',
            esc_url($remove_url),
            esc_attr__('Remove filter', 'mailoptin')
        );
    }

    protected static function value_label($field, $condition)
    {
        if (in_array($condition['op'], ['empty', 'not_empty', 'checked', 'unchecked'], true)) return '';

        $value = is_array($condition['value']) ? implode(', ', $condition['value']) : $condition['value'];

        if ('between' === $condition['op']) {
            return sprintf(__('%1$s and %2$s', 'mailoptin'), $value, $condition['value2']);
        }

        return $value;
    }
}
