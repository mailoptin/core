<?php

namespace MailOptin\Core\Api;

use function MailOptin\Core\get_capability;

/**
 * API keys for the Subscriber API.
 *
 * A key reads "mo_live_<id>_<secret>". Only the id and an HMAC of the secret are stored, so a copy of the
 * database doesn't give out working keys; the full key is shown once, when it's created. Keys are few
 * (one per tool), so they live in one option.
 */
class ApiKeys
{
    const OPTION = 'mo_api_keys';

    /** When and from where each key was last used: kept apart, so recording it can't undo a create or revoke. */
    const USED_OPTION = 'mo_api_keys_used';

    const PREFIX = 'mo_live_';

    const ACCESS_READ = 'read';
    const ACCESS_WRITE = 'write';

    /** How often "last used" is written, so busy keys don't rewrite the option on every request. */
    const LAST_USED_EVERY = MINUTE_IN_SECONDS;

    /**
     * @return array key id => [id, name, access, user_id, created_at, last_used_at, last_used_ip, hash]
     */
    public static function all()
    {
        $keys = get_option(self::OPTION, []);
        $used = get_option(self::USED_OPTION, []);

        if ( ! is_array($keys)) return [];

        foreach ($keys as $id => $key) {
            $keys[$id]['last_used_at'] = (int)($used[$id]['at'] ?? 0);
            $keys[$id]['last_used_ip'] = (string)($used[$id]['ip'] ?? '');
        }

        return $keys;
    }

    protected static function stored()
    {
        $keys = get_option(self::OPTION, []);

        return is_array($keys) ? $keys : [];
    }

    protected static function save_all($keys)
    {
        update_option(self::OPTION, $keys, false);
    }

    public static function get($id)
    {
        return self::all()[$id] ?? null;
    }

    protected static function hash($secret)
    {
        return hash_hmac('sha256', $secret, wp_salt('auth'));
    }

    /**
     * @return array|\WP_Error ['key' => full key, shown once, 'record' => the stored record]
     */
    public static function create($name, $access, $user_id)
    {
        $name   = sanitize_text_field($name);
        $access = self::ACCESS_READ === $access ? self::ACCESS_READ : self::ACCESS_WRITE;

        if ('' === $name) return new \WP_Error('mo_api_key_name', __('Give the key a name.', 'mailoptin'));

        $keys = self::stored();

        do {
            $id = wp_generate_password(8, false);
        } while (isset($keys[$id]));

        $secret = wp_generate_password(32, false);

        $keys[$id] = [
            'id'           => $id,
            'name'         => mb_substr($name, 0, 100),
            'access'       => $access,
            'user_id'      => absint($user_id),
            'created_at'   => time(),
            'hash'         => self::hash($secret),
        ];

        self::save_all($keys);

        return ['key' => self::PREFIX . $id . '_' . $secret, 'record' => $keys[$id] + ['last_used_at' => 0, 'last_used_ip' => '']];
    }

    public static function revoke($id)
    {
        $keys = self::stored();

        if ( ! isset($keys[$id])) return false;

        unset($keys[$id]);
        self::save_all($keys);

        $used = get_option(self::USED_OPTION, []);
        if (is_array($used) && isset($used[$id])) {
            unset($used[$id]);
            update_option(self::USED_OPTION, $used, false);
        }

        return true;
    }

    /**
     * The key's record when the key is genuine and its creator can still manage MailOptin.
     *
     * @param string $key
     *
     * @return array|null
     */
    public static function verify($key)
    {
        if ( ! is_string($key) || ! preg_match('/^' . preg_quote(self::PREFIX, '/') . '([A-Za-z0-9]{8})_([A-Za-z0-9]{32})$/', $key, $m)) return null;

        $record = self::get($m[1]);

        // Compared even for an unknown id, so a wrong id and a wrong secret take the same time.
        $expected = $record['hash'] ?? self::hash('');

        if ( ! hash_equals($expected, self::hash($m[2])) || ! $record) return null;

        // A key stops working when the person who made it can no longer manage MailOptin.
        $user = get_userdata($record['user_id']);
        if ( ! $user || ! user_can($user, self::capability_for($user))) return null;

        return $record;
    }

    /**
     * The capability MailOptin admin screens check, for a given user rather than the current one.
     */
    protected static function capability_for($user)
    {
        return user_can($user, 'manage_mailoptin') ? 'manage_mailoptin' : 'manage_options';
    }

    public static function touch($id, $ip)
    {
        $used = get_option(self::USED_OPTION, []);
        if ( ! is_array($used)) $used = [];

        if (time() - (int)($used[$id]['at'] ?? 0) < self::LAST_USED_EVERY) return;

        $used[$id] = ['at' => time(), 'ip' => (string)$ip];

        // Only keys that still exist.
        update_option(self::USED_OPTION, array_intersect_key($used, self::stored()), false);
    }

    /**
     * Shown in lists instead of the key: "mo_live_k7Qm2xPa_••••".
     */
    public static function masked($record)
    {
        return self::PREFIX . $record['id'] . '_••••';
    }

    public static function can_manage()
    {
        return current_user_can(get_capability());
    }
}
