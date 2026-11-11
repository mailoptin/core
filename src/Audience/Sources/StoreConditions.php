<?php

namespace MailOptin\Core\Audience\Sources;

use MailOptin\Core\Repositories\SegmentsRepository;

/**
 * Segment and filter conditions on what people bought and what they're members of, asked of the
 * store or membership plugin when used. Each condition applies to an email address column
 * (a subscriber's, or a customer's when a broadcast goes to customers who aren't subscribers).
 *
 * Shape: what, source, and per what:
 *   bought / not_bought: target (product|category|tag), ids
 *   spent:               op (gt|lt), amount
 *   orders:              op (gt|lt|eq), count
 *   last_order:          op (within|not_within), days
 *   member:              option (a role, level or course; '' for any)
 */
class StoreConditions
{
    /** Need Premium, like segments; "member" doesn't. */
    const PREMIUM = ['bought', 'not_bought', 'spent', 'orders', 'last_order'];

    public static function whats()
    {
        return [
            'bought'     => __('Has bought', 'mailoptin'),
            'not_bought' => __('Has never bought', 'mailoptin'),
            'spent'      => __('Total spent', 'mailoptin'),
            'orders'     => __('Number of orders', 'mailoptin'),
            'last_order' => __('Last order', 'mailoptin'),
            'member'     => __('Is one of', 'mailoptin'),
        ];
    }

    public static function is_premium($what)
    {
        return in_array($what, self::PREMIUM, true);
    }

    /** Whether this site can filter by $what now. */
    public static function can_use($what)
    {
        return ! self::is_premium($what) || SegmentsRepository::is_available();
    }

    /** Active sources with purchases (stores). */
    public static function store_sources()
    {
        return array_filter(Sources::active(), function (AbstractSource $source) {
            return ! empty($source->purchase_targets());
        });
    }

    /**
     * @return array|null the condition with only its own keys, sanitized; null when it isn't one.
     *                    A source that isn't active is kept (so the segment shows a problem rather
     *                    than quietly matching more people).
     */
    public static function clean($condition)
    {
        if ( ! is_array($condition)) return null;

        $what   = is_scalar($condition['what'] ?? '') ? sanitize_key($condition['what'] ?? '') : '';
        $source = is_scalar($condition['source'] ?? '') ? sanitize_key($condition['source'] ?? '') : '';

        if ( ! isset(self::whats()[$what]) || '' === $source || ! preg_match(Sources::ID_PATTERN, $source)) return null;

        $clean = ['what' => $what, 'source' => $source];

        switch ($what) {
            case 'bought':
            case 'not_bought':
                $target = is_scalar($condition['target'] ?? '') ? sanitize_key($condition['target'] ?? '') : '';
                $ids    = array_values(array_unique(array_filter(array_map('absint', array_filter((array)($condition['ids'] ?? []), 'is_scalar')))));

                if ( ! in_array($target, ['product', 'category', 'tag'], true) || empty($ids)) return null;

                return $clean + ['target' => $target, 'ids' => array_slice($ids, 0, 200)];

            case 'spent':
                $op = in_array($condition['op'] ?? '', ['gt', 'lt'], true) ? $condition['op'] : 'gt';

                return $clean + ['op' => $op, 'amount' => max(0, round((float)($condition['amount'] ?? 0), 2))];

            case 'orders':
                $op = in_array($condition['op'] ?? '', ['gt', 'lt', 'eq'], true) ? $condition['op'] : 'gt';

                return $clean + ['op' => $op, 'count' => absint($condition['count'] ?? 0)];

            case 'last_order':
                $op = in_array($condition['op'] ?? '', ['within', 'not_within'], true) ? $condition['op'] : 'within';

                return $clean + ['op' => $op, 'days' => max(1, min(3650, absint($condition['days'] ?? 30)))];

            case 'member':
                $option = is_scalar($condition['option'] ?? '') ? sanitize_text_field((string)($condition['option'] ?? '')) : '';

                return $clean + ['option' => $option];
        }

        return null;
    }

    /**
     * Why a condition can't be applied as it is; '' when it can. A condition that can't be
     * applied would otherwise be skipped and match more people.
     */
    public static function problem($condition)
    {
        $source = Sources::get($condition['source']);

        if ( ! $source || ! $source->is_active()) {
            return sprintf(__('It filters by %s, which isn\'t active.', 'mailoptin'), $source ? $source->label() : $condition['source']);
        }

        if ('member' === $condition['what']) {
            if ( ! $source->is_valid_option($condition['option'])) {
                return sprintf(__('It filters by a %1$s of %2$s that no longer exists.', 'mailoptin'), strtolower($source->option_label()), $source->label());
            }

            return '';
        }

        if (empty($source->purchase_targets())) return sprintf(__('%s has no purchases to filter by.', 'mailoptin'), $source->label());

        if ( ! self::can_use($condition['what'])) return __('It filters by purchases, which needs MailOptin Premium.', 'mailoptin');

        return '';
    }

    /**
     * The condition as SQL on the address in $email_expr.
     *
     * @return array|null [sql, params]; null when it can't be applied (see problem())
     */
    public static function sql($condition, $email_expr = 's.email')
    {
        $condition = self::clean($condition);

        if ( ! $condition || '' !== self::problem($condition)) return null;

        $source = Sources::get($condition['source']);

        if ('member' === $condition['what']) {
            list($people, $params) = $source->people_sql($condition['option'], Sources::settings($source->id()));

            return ['EXISTS (SELECT 1 FROM (' . $people . ') m WHERE ' . Sources::as_our_email('m.email') . ' = ' . $email_expr . ')', $params];
        }

        $orders = $source->orders_sql($email_expr);

        if ( ! $orders) return null;

        list($from, $params, $columns) = $orders;

        switch ($condition['what']) {
            case 'bought':
            case 'not_bought':
                $items = $source->items_sql($columns['id'], $condition['target'], $condition['ids']);

                if ( ! $items) return null;

                return [
                    ('not_bought' === $condition['what'] ? 'NOT ' : '') . "EXISTS (SELECT 1 $from AND {$items[0]})",
                    array_merge($params, $items[1]),
                ];

            case 'spent':
                return ["(SELECT COALESCE(SUM({$columns['total']}), 0) $from) " . ('lt' === $condition['op'] ? '<' : '>') . ' %f', array_merge($params, [$condition['amount']])];

            case 'orders':
                return ["(SELECT COUNT(*) $from) " . ['gt' => '>', 'lt' => '<', 'eq' => '='][$condition['op']] . ' %d', array_merge($params, [$condition['count']])];

            case 'last_order':
                $since  = gmdate('Y-m-d H:i:s', time() - $condition['days'] * DAY_IN_SECONDS);
                $recent = "EXISTS (SELECT 1 $from AND {$columns['date']} >= %s)";

                if ('within' === $condition['op']) return [$recent, array_merge($params, [$since])];

                // Ordered before, but not in the last N days.
                return ["(EXISTS (SELECT 1 $from) AND NOT $recent)", array_merge($params, $params, [$since])];
        }

        return null;
    }

    /**
     * "Has bought Product: Tulip bulbs, Daffodil mix (WooCommerce customers)"
     */
    public static function label($condition)
    {
        $source = Sources::get($condition['source']);
        $name   = $source ? $source->label() : $condition['source'];

        switch ($condition['what']) {
            case 'bought':
            case 'not_bought':
                $targets = $source ? $source->purchase_targets() : [];
                $choices = $source ? $source->purchase_choices($condition['target']) : [];
                $names   = array_map(function ($id) use ($choices) {
                    return $choices[$id] ?? sprintf('#%d', $id);
                }, $condition['ids']);

                return sprintf('%s %s: %s (%s)', self::whats()[$condition['what']], strtolower($targets[$condition['target']] ?? $condition['target']), implode(', ', $names), $name);

            case 'spent':
                $amount = function_exists('wc_price') && 'woo' === $condition['source'] ? wp_strip_all_tags(wc_price($condition['amount'])) : number_format_i18n($condition['amount'], 2);

                return sprintf('lt' === $condition['op'] ? __('Total spent less than %1$s (%2$s)', 'mailoptin') : __('Total spent more than %1$s (%2$s)', 'mailoptin'), $amount, $name);

            case 'orders':
                $format = ['gt' => __('More than %1$d orders (%2$s)', 'mailoptin'), 'lt' => __('Fewer than %1$d orders (%2$s)', 'mailoptin'), 'eq' => __('Exactly %1$d orders (%2$s)', 'mailoptin')][$condition['op']];

                return sprintf($format, $condition['count'], $name);

            case 'last_order':
                return sprintf(
                    'within' === $condition['op'] ? __('Ordered in the last %1$d days (%2$s)', 'mailoptin') : __('Ordered before, but not in the last %1$d days (%2$s)', 'mailoptin'),
                    $condition['days'],
                    $name
                );

            case 'member':
                if ('' === $condition['option'] || ! $source) return sprintf(__('Is one of the %s', 'mailoptin'), $name);

                $options = $source->options();

                return sprintf(__('%1$s: %2$s', 'mailoptin'), $name, $options[$condition['option']] ?? $condition['option']);
        }

        return '';
    }
}
