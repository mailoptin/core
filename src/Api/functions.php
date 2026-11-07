<?php
/**
 * Subscriber API for other plugins on the same site: the same rules as the REST API, without HTTP.
 * Each returns the subscriber as the REST API does, or a WP_Error.
 */

use MailOptin\Core\Api\SubscriberService;

if ( ! function_exists('mailoptin_add_subscriber')) {
    /**
     * Add a subscriber, or update the one with this email.
     *
     * @param array $data email, first_name, last_name, lists (IDs or names), tags, fields (merge key => value),
     *                    consent ['given' => bool, 'text' => string], create_missing (create unknown lists and tags)
     *
     * Without consent, they are asked to confirm by email. Someone who unsubscribed, bounced or complained
     * can't be added back (a WP_Error with status 409).
     *
     * @return array|WP_Error
     */
    function mailoptin_add_subscriber($data)
    {
        $result = SubscriberService::upsert((array)$data);

        return is_wp_error($result) ? $result : $result['subscriber'] + ['created' => $result['created'], 'confirmation_sent' => $result['confirmation_sent']];
    }

    /**
     * @param int|string $id_or_email
     *
     * @return array|null
     */
    function mailoptin_get_subscriber($id_or_email)
    {
        $subscriber = SubscriberService::find($id_or_email);

        return $subscriber ? SubscriberService::format($subscriber) : null;
    }

    /**
     * @param int|string $id_or_email
     * @param string|string[]|int[] $tags names or IDs
     * @param bool $create_missing create tags that don't exist yet
     *
     * @return array|WP_Error
     */
    function mailoptin_tag_subscriber($id_or_email, $tags, $create_missing = true)
    {
        $subscriber = SubscriberService::find($id_or_email);

        return $subscriber ? SubscriberService::add_tags($subscriber, (array)$tags, $create_missing) : SubscriberService::not_found();
    }

    /**
     * @return array|WP_Error
     */
    function mailoptin_untag_subscriber($id_or_email, $tag)
    {
        $subscriber = SubscriberService::find($id_or_email);

        return $subscriber ? SubscriberService::remove_tag($subscriber, $tag) : SubscriberService::not_found();
    }

    /**
     * A real unsubscribe: from one list (ID or name), or from everything when $list is null.
     *
     * @return array|WP_Error
     */
    function mailoptin_unsubscribe_subscriber($id_or_email, $list = null)
    {
        $subscriber = SubscriberService::find($id_or_email);

        return $subscriber ? SubscriberService::unsubscribe($subscriber, $list) : SubscriberService::not_found();
    }
}
