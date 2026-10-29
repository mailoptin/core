<?php

namespace MailOptin\Core\Audience;

/**
 * Signed links sent to subscribers: confirm, unsubscribe and view in browser.
 */
class Tokens
{
    public static function make($action, $parts)
    {
        return hash_hmac('sha256', $action . '|' . implode('|', $parts), wp_salt('auth'));
    }

    public static function check($action, $parts, $token)
    {
        return is_string($token) && '' !== $token && hash_equals(self::make($action, $parts), $token);
    }

    /**
     * @param int[]|null $list_ids the lists the email asks about; the link confirms only those.
     *                             Links without lists (sent before this existed) confirm every pending list.
     */
    public static function confirm_url($subscriber, $list_ids = null)
    {
        if (null === $list_ids) {
            return add_query_arg([
                'mo_confirm' => $subscriber['id'],
                'mo_token'   => self::make('confirm', [$subscriber['id'], $subscriber['email']]),
            ], home_url('/'));
        }

        $lists = self::list_param($list_ids);

        return add_query_arg([
            'mo_confirm' => $subscriber['id'],
            'mo_lists'   => $lists,
            'mo_token'   => self::make('confirm', [$subscriber['id'], $subscriber['email'], $lists]),
        ], home_url('/'));
    }

    /**
     * "3.7.12": sorted list IDs, as signed in confirm links.
     */
    public static function list_param($list_ids)
    {
        $list_ids = array_values(array_unique(array_filter(array_map('absint', (array)$list_ids))));
        sort($list_ids);

        return implode('.', $list_ids);
    }

    public static function unsubscribe_url($subscriber, $list_id = 0, $campaign_log_id = 0)
    {
        return add_query_arg([
            'mo_unsub' => $subscriber['id'],
            'mo_list'  => absint($list_id),
            'mo_cl'    => absint($campaign_log_id),
            'mo_token' => self::make('unsubscribe', [$subscriber['id'], $subscriber['email'], absint($list_id), absint($campaign_log_id)]),
        ], home_url('/'));
    }

    public static function web_version_url($campaign_log_id)
    {
        return add_query_arg([
            'mo_web'   => $campaign_log_id,
            'mo_token' => self::make('web', [$campaign_log_id]),
        ], home_url('/'));
    }
}
