<?php

namespace MailOptin\Core\Api;

use MailOptin\Core\Audience\Consent;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\Repositories\TagsRepository as Tags;

/**
 * What the Subscriber API and the mailoptin_*_subscriber() functions do, with the same rules as the
 * admin screens: someone who unsubscribed, bounced or complained can't be added back, and without
 * consent a subscriber is asked to confirm by email.
 *
 * Every method returns data or a \WP_Error whose data carries the HTTP status. A request that fails
 * changes nothing: everything is checked, and missing lists and tags are only created, after that.
 */
class SubscriberService
{
    /** The API key behind the current request, recorded on signup events. */
    public static $key_id = '';

    /**
     * @param string|int $id_or_email
     *
     * @return array|null
     */
    public static function find($id_or_email)
    {
        if (is_int($id_or_email) || (is_string($id_or_email) && ctype_digit($id_or_email))) return Subscribers::get(absint($id_or_email));

        return is_string($id_or_email) ? Subscribers::get_by_email(rawurldecode($id_or_email)) : null;
    }

    public static function not_found()
    {
        return new \WP_Error('mo_subscriber_not_found', __('No subscriber with that ID or email.', 'mailoptin'), ['status' => 404]);
    }

    protected static function error($code, $message, $status = 400, $extra = [])
    {
        return new \WP_Error($code, $message, array_merge(['status' => $status], $extra));
    }

    protected static function text($value)
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /* ---------------------------------------------------------------------------------------------
     * Names, IDs and values from the request
     * ------------------------------------------------------------------------------------------ */

    /**
     * Lists by ID or name. A number is an ID when such a list exists, otherwise a name.
     *
     * @param array $lists
     * @param bool $create_missing unknown names are returned under 'create' instead of being refused
     *
     * @return array|\WP_Error ['ids' => int[], 'create' => names to create once the request is known to be valid]
     */
    public static function plan_lists($lists, $create_missing = false)
    {
        $by_name = [];
        foreach (ListsRepository::get_all() as $list) {
            $by_name[mb_strtolower(trim($list['name']))] = (int)$list['id'];
        }

        $ids = $create = $unknown = [];

        foreach ((array)$lists as $list) {
            if ( ! is_int($list) && ! is_string($list)) {
                $unknown[] = wp_json_encode($list);
                continue;
            }

            $name = trim((string)$list);

            if ('' === $name) {
                $unknown[] = '""';
                continue;
            }

            if (ctype_digit($name) && ListsRepository::get((int)$name)) {
                $ids[] = (int)$name;
            } elseif (isset($by_name[mb_strtolower($name)])) {
                $ids[] = $by_name[mb_strtolower($name)];
            } elseif ($create_missing && ! is_int($list)) {
                $create[mb_strtolower($name)] = $name;
            } else {
                $unknown[] = $name;
            }
        }

        if ( ! empty($unknown)) {
            return self::error('mo_unknown_lists', sprintf(__('Unknown lists: %s. Send "create_missing": true to create lists by name.', 'mailoptin'), implode(', ', $unknown)), 400, ['unknown' => $unknown]);
        }

        return ['ids' => array_values(array_unique($ids)), 'create' => array_values($create)];
    }

    /**
     * Tags by ID or name, as plan_lists().
     *
     * @return array|\WP_Error
     */
    public static function plan_tags($tags, $create_missing = false)
    {
        $tags = (array)$tags;

        if (empty($tags)) return ['ids' => [], 'create' => []];

        if ( ! Tags::is_ready()) return self::error('mo_tags_unavailable', __('Tags aren\'t set up on this site yet.', 'mailoptin'));

        $ids = $create = $unknown = [];

        foreach ($tags as $tag) {
            if ( ! is_int($tag) && ! is_string($tag)) {
                $unknown[] = wp_json_encode($tag);
                continue;
            }

            $name = Tags::sanitize_name((string)$tag);

            if ('' === $name) {
                $unknown[] = '""';
                continue;
            }

            if (ctype_digit($name) && Tags::get((int)$name)) {
                $ids[] = (int)$name;
            } elseif ($existing = Tags::get_by_name($name)) {
                $ids[] = (int)$existing['id'];
            } elseif ($create_missing && ! is_int($tag)) {
                $create[mb_strtolower($name)] = $name;
            } else {
                $unknown[] = $name;
            }
        }

        if ( ! empty($unknown)) {
            return self::error('mo_unknown_tags', sprintf(__('Unknown tags: %s. Send "create_missing": true to create tags by name.', 'mailoptin'), implode(', ', $unknown)), 400, ['unknown' => $unknown]);
        }

        return ['ids' => array_values(array_unique($ids)), 'create' => array_values($create)];
    }

    /**
     * Create what a plan says is missing, and return all the IDs.
     */
    protected static function create_lists($plan)
    {
        foreach ($plan['create'] as $name) {
            $id = ListsRepository::create(['name' => sanitize_text_field($name)]);
            if ($id) $plan['ids'][] = $id;
        }

        return array_values(array_unique($plan['ids']));
    }

    protected static function create_tags($plan)
    {
        return array_values(array_unique(array_merge($plan['ids'], Tags::ensure($plan['create']))));
    }

    /**
     * One existing list or tag, for the path of a request: 404 when there is no such list or tag.
     *
     * @return int|\WP_Error
     */
    public static function one_list($list)
    {
        $plan = self::plan_lists([$list]);

        return is_wp_error($plan) || empty($plan['ids']) ? self::error('mo_unknown_lists', __('No list with that ID or name.', 'mailoptin'), 404) : $plan['ids'][0];
    }

    public static function one_tag($tag)
    {
        $plan = self::plan_tags([$tag]);

        return is_wp_error($plan) || empty($plan['ids']) ? self::error('mo_unknown_tags', __('No tag with that ID or name.', 'mailoptin'), 404) : $plan['ids'][0];
    }

    /**
     * Checks custom field values without saving them. Fields are never created through the API.
     *
     * @param array $fields merge key or field ID => value
     *
     * @return array|\WP_Error the values, keyed by field ID
     */
    public static function check_fields($fields)
    {
        if (empty($fields)) return [];

        if ( ! is_array($fields)) return self::error('mo_invalid_fields', __('"fields" must be an object of merge key => value.', 'mailoptin'));

        if ( ! Tags::is_ready()) return self::error('mo_fields_unavailable', __('Custom fields aren\'t set up on this site yet.', 'mailoptin'));

        $values = $unknown = $errors = [];

        foreach ($fields as $key => $raw) {
            $field = is_int($key) || ctype_digit((string)$key) ? Fields::get((int)$key) : Fields::get_by_key((string)$key);

            if ( ! $field) {
                $unknown[] = (string)$key;
                continue;
            }

            if ( ! is_scalar($raw) && ! is_array($raw) && null !== $raw) {
                $errors[$field['merge_key']] = __('Not a valid value.', 'mailoptin');
                continue;
            }

            $check = Fields::normalize_value($field, $raw);

            if (is_wp_error($check)) {
                $errors[$field['merge_key']] = $check->get_error_message();
                continue;
            }

            $values[(int)$field['id']] = $raw;
        }

        if ( ! empty($unknown)) {
            return self::error('mo_unknown_fields', sprintf(__('Unknown custom fields: %s. Create them under Subscribers > Custom fields first.', 'mailoptin'), implode(', ', $unknown)), 400, ['unknown' => $unknown]);
        }

        if ( ! empty($errors)) {
            return self::error('mo_invalid_field_values', __('Some custom field values are not valid.', 'mailoptin'), 400, ['fields' => $errors]);
        }

        return $values;
    }

    /**
     * Who can't be added to these lists: they unsubscribed (from everything, or from one of these
     * lists), bounced or complained. Only they can sign up again.
     *
     * @param int[] $list_ids lists that already exist
     */
    protected static function refused($email, $subscriber = null, $list_ids = [])
    {
        $reason = SuppressionRepository::get_reason($email);

        if ($subscriber && in_array($subscriber['status'], [Subscribers::STATUS_BOUNCED, Subscribers::STATUS_COMPLAINED], true)) {
            $reason = $subscriber['status'];
        }

        if ( ! $reason && $subscriber) {
            foreach ($list_ids as $list_id) {
                $membership = Subscribers::get_membership($subscriber['id'], $list_id);
                if ($membership && Subscribers::STATUS_UNSUBSCRIBED === $membership['status']) $reason = SuppressionRepository::REASON_UNSUBSCRIBED;
            }
        }

        if ( ! $reason) return null;

        $messages = [
            SuppressionRepository::REASON_BOUNCED    => __('Emails to this address bounced, so it can\'t be added to lists.', 'mailoptin'),
            SuppressionRepository::REASON_COMPLAINED => __('This address marked our email as spam, so it can\'t be added to lists.', 'mailoptin'),
        ];

        return self::error('mo_subscriber_refused', $messages[$reason] ?? __('This address unsubscribed. Only they can sign up again, through a form.', 'mailoptin'), 409, ['reason' => $reason]);
    }

    /* ---------------------------------------------------------------------------------------------
     * Changes
     * ------------------------------------------------------------------------------------------ */

    /**
     * Add a subscriber, or update the one with this email.
     *
     * @param array $data email, first_name, last_name, lists, tags, fields, consent {given, text}, create_missing
     *
     * @return array|\WP_Error ['subscriber' => formatted, 'created' => bool, 'confirmation_sent' => bool]
     */
    public static function upsert($data)
    {
        $data  = (array)$data;
        $email = Subscribers::normalize_email(self::text($data['email'] ?? ''));

        if ( ! is_email($email)) return self::error('mo_invalid_email', __('A valid "email" is required.', 'mailoptin'));

        $create_missing = ! empty($data['create_missing']);
        $existing       = Subscribers::get_by_email($email);

        // Everything is checked before anything is saved or created.
        $lists = self::plan_lists($data['lists'] ?? [], $create_missing);
        if (is_wp_error($lists)) return $lists;

        $tags = self::plan_tags($data['tags'] ?? [], $create_missing);
        if (is_wp_error($tags)) return $tags;

        $fields = self::check_fields($data['fields'] ?? []);
        if (is_wp_error($fields)) return $fields;

        $wants_lists = ! empty($lists['ids']) || ! empty($lists['create']);

        if ( ! $existing && ! $wants_lists) {
            return self::error('mo_no_lists', __('A new subscriber needs at least one list in "lists".', 'mailoptin'));
        }

        if ($wants_lists && ($refused = self::refused($email, $existing, $lists['ids']))) return $refused;

        $names        = self::names($data);
        $consent      = is_array($data['consent'] ?? null) ? $data['consent'] : [];
        $has_consent  = ! empty($consent['given']);
        $consent_text = sanitize_textarea_field(self::text($consent['text'] ?? ''));

        // Valid: now missing lists and tags can be created.
        $list_ids = self::create_lists($lists);
        $tag_ids  = self::create_tags($tags);

        // Only lists that change: one they're already on (or already asked to confirm, without consent)
        // is left alone, so a repeated request doesn't send the confirmation email again.
        $changing = array_values(array_filter($list_ids, function ($list_id) use ($existing, $has_consent) {
            $membership = $existing ? Subscribers::get_membership($existing['id'], $list_id) : null;

            if ( ! $membership) return true;

            return Subscribers::STATUS_PENDING === $membership['status'] ? $has_consent : Subscribers::STATUS_SUBSCRIBED !== $membership['status'];
        }));

        $confirmation_sent = false;

        if ( ! empty($changing)) {
            $result = Subscription::add_by_admin($email, $changing, $names, $has_consent, [
                'source'         => 'api',
                'consent_method' => Consent::METHOD_API,
                'consent_text'   => $has_consent && '' !== $consent_text ? $consent_text : null,
                'meta'           => array_filter(['api_key' => self::$key_id, 'consent_text' => $consent_text]),
            ]);

            if (is_wp_error($result)) return self::error($result->get_error_code(), $result->get_error_message());
            if (Subscription::ADMIN_REFUSED_UNSUBSCRIBED === $result) return self::refused($email) ?: self::error('mo_subscriber_refused', __('This address can\'t be added to lists.', 'mailoptin'), 409);

            $confirmation_sent = Subscription::ADMIN_CONFIRMATION_SENT === $result;
        }

        $subscriber = Subscribers::get_by_email($email);

        // Names for a subscriber whose lists didn't change; always touched, so polling sees list changes.
        Subscribers::update($subscriber['id'], empty($changing) ? $names : []);

        self::apply_tags_and_fields($subscriber['id'], $tag_ids, $fields);

        return [
            'subscriber'        => self::format(Subscribers::get($subscriber['id'])),
            'created'           => ! $existing,
            'confirmation_sent' => $confirmation_sent,
        ];
    }

    /**
     * Change names and custom field values.
     *
     * @return array|\WP_Error formatted subscriber
     */
    public static function update($subscriber, $data)
    {
        $data   = (array)$data;
        $fields = self::check_fields($data['fields'] ?? []);
        if (is_wp_error($fields)) return $fields;

        $names = [];
        foreach (['first_name', 'last_name'] as $key) {
            if (array_key_exists($key, $data)) $names[$key] = mb_substr(sanitize_text_field(self::text($data[$key])), 0, 100);
        }

        // Always touched, so tools polling updated_since see the change.
        Subscribers::update($subscriber['id'], $names);

        self::apply_tags_and_fields($subscriber['id'], [], $fields);

        return self::format(Subscribers::get($subscriber['id']));
    }

    /**
     * @return array|\WP_Error
     */
    public static function add_tags($subscriber, $tags, $create_missing = false)
    {
        $plan = self::plan_tags($tags, $create_missing);
        if (is_wp_error($plan)) return $plan;

        self::apply_tags_and_fields($subscriber['id'], self::create_tags($plan), []);

        return self::format(Subscribers::get($subscriber['id']));
    }

    /**
     * @return array|\WP_Error
     */
    public static function remove_tag($subscriber, $tag)
    {
        $tag_id = self::one_tag($tag);
        if (is_wp_error($tag_id)) return $tag_id;

        Tags::remove_from_subscribers([$tag_id], [$subscriber['id']]);
        Subscribers::update($subscriber['id'], []);

        return self::format(Subscribers::get($subscriber['id']));
    }

    /**
     * @return array|\WP_Error ['subscriber' => formatted, 'confirmation_sent' => bool]
     */
    public static function add_lists($subscriber, $lists, $consent = [], $create_missing = false)
    {
        if (empty($lists)) return self::error('mo_no_lists', __('Send the lists to add them to in "lists".', 'mailoptin'));

        return self::upsert([
            'email'          => $subscriber['email'],
            'lists'          => $lists,
            'consent'        => $consent,
            'create_missing' => $create_missing,
        ]);
    }

    /**
     * Take someone off a list, as the admin screens do: not an unsubscribe, and nothing is suppressed.
     *
     * @return array|\WP_Error
     */
    public static function remove_list($subscriber, $list)
    {
        $list_id = self::one_list($list);
        if (is_wp_error($list_id)) return $list_id;

        Subscription::remove_by_admin($subscriber['id'], $list_id);
        Subscribers::update($subscriber['id'], []);

        return self::format(Subscribers::get($subscriber['id']));
    }

    /**
     * A real unsubscribe, from one list, or from everything when $list is null (then the address is
     * suppressed). A list that can't be found is an error, never "everything".
     *
     * @return array|\WP_Error
     */
    public static function unsubscribe($subscriber, $list = null)
    {
        $list_id = 0;

        if (null !== $list) {
            $list_id = self::one_list($list);
            if (is_wp_error($list_id)) return $list_id;
        }

        Subscription::unsubscribe($subscriber['id'], $list_id);
        Subscribers::update($subscriber['id'], []);

        return self::format(Subscribers::get($subscriber['id']));
    }

    public static function delete($subscriber)
    {
        Subscribers::delete($subscriber['id']);

        return true;
    }

    protected static function names($data)
    {
        return array_filter([
            'first_name' => mb_substr(sanitize_text_field(self::text($data['first_name'] ?? '')), 0, 100),
            'last_name'  => mb_substr(sanitize_text_field(self::text($data['last_name'] ?? '')), 0, 100),
        ]);
    }

    protected static function apply_tags_and_fields($subscriber_id, $tag_ids, $fields)
    {
        if ( ! empty($tag_ids)) Tags::add_to_subscribers($tag_ids, [$subscriber_id]);
        if ( ! empty($fields)) Fields::set_values($subscriber_id, $fields);
        if ( ! empty($tag_ids) || ! empty($fields)) Subscribers::update($subscriber_id, []);
    }

    /* ---------------------------------------------------------------------------------------------
     * Output
     * ------------------------------------------------------------------------------------------ */

    public static function time($mysql)
    {
        return empty($mysql) ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($mysql . ' UTC'));
    }

    /**
     * The subscriber as the API returns it.
     */
    public static function format($subscriber)
    {
        $id = (int)$subscriber['id'];

        $lists = [];
        foreach (Subscribers::get_memberships($id) as $membership) {
            $list = ListsRepository::get($membership['list_id']);
            if ($list) $lists[] = ['id' => (int)$list['id'], 'name' => $list['name'], 'status' => $membership['status']];
        }

        $fields = [];
        if (Tags::is_ready()) {
            foreach (Fields::get_values($id) as $field_id => $row) {
                $field = Fields::get($field_id);
                if ($field) $fields[$field['merge_key']] = Fields::form_value($field, $row['value_text']);
            }
        }

        return [
            'id'           => $id,
            'email'        => $subscriber['email'],
            'first_name'   => $subscriber['first_name'],
            'last_name'    => $subscriber['last_name'],
            'status'       => $subscriber['status'],
            'lists'        => $lists,
            'tags'         => Tags::is_ready() ? array_values(Tags::for_subscriber($id)) : [],
            'fields'       => (object)$fields,
            'consent'      => [
                'at'     => self::time($subscriber['consent_at']),
                'method' => $subscriber['consent_method'],
                'text'   => (string)$subscriber['consent_text'],
            ],
            'confirmed_at' => self::time($subscriber['confirmed_at']),
            'created_at'   => self::time($subscriber['created_at']),
            'updated_at'   => self::time($subscriber['updated_at']),
        ];
    }
}
