<?php

namespace MailOptin\Core\Audience\Sources;

/**
 * The site's registered users, optionally of one role.
 */
class RegisteredUsers extends AbstractSource
{
    const FIELD = 'mo_marketing_consent';

    public function id()
    {
        return 'users';
    }

    public function label()
    {
        return __('Registered users', 'mailoptin');
    }

    public function is_active()
    {
        return true;
    }

    public function admin_url()
    {
        return admin_url('users.php');
    }

    public function admin_link_label()
    {
        return __('View in Users', 'mailoptin');
    }

    public function options()
    {
        return function_exists('wp_roles') ? array_map('translate_user_role', wp_roles()->get_names()) : [];
    }

    public function option_label()
    {
        return __('Role', 'mailoptin');
    }

    public function any_option_label()
    {
        return __('Any role', 'mailoptin');
    }

    public function checkbox_place()
    {
        return __('on the registration form', 'mailoptin');
    }

    public function people_sql($option, $settings, $email = null)
    {
        global $wpdb;

        // Users of this site: on a network, the users table is shared and roles are per site.
        $caps_key = $wpdb->get_blog_prefix() . 'capabilities';

        $where  = ["u.user_email <> ''"];
        $params = [$caps_key];

        if ('' !== (string)$option && array_key_exists($option, $this->options())) {
            $where[]  = 'cap.meta_value LIKE %s';
            $params[] = '%' . $wpdb->esc_like('"' . $option . '"') . '%';
        }

        if (null !== $email) {
            $where[]  = 'u.user_email = %s';
            $params[] = $email;
        }

        return [
            "SELECT LOWER(u.user_email) AS email, MAX(fn.meta_value) AS first_name, MAX(ln.meta_value) AS last_name
            FROM $wpdb->users u
            INNER JOIN $wpdb->usermeta cap ON cap.user_id = u.ID AND cap.meta_key = %s
            LEFT JOIN $wpdb->usermeta fn ON fn.user_id = u.ID AND fn.meta_key = 'first_name'
            LEFT JOIN $wpdb->usermeta ln ON ln.user_id = u.ID AND ln.meta_key = 'last_name'
            WHERE " . implode(' AND ', $where) . ' GROUP BY LOWER(u.user_email)',
            $params,
        ];
    }

    public function register_checkbox($checkbox_text)
    {
        add_action('register_form', function () use ($checkbox_text) {
            printf(
                '<p class="mo-marketing-consent"><label><input type="checkbox" name="%s" value="1"> %s</label></p><br>',
                esc_attr(self::FIELD),
                esc_html($checkbox_text)
            );
        });

        add_action('user_register', function ($user_id) use ($checkbox_text) {
            // Only the registration form posts this box; users added by an admin or a plugin didn't tick anything.
            if (empty($_POST[self::FIELD]) || 'register' !== ($_REQUEST['action'] ?? '')) return;

            $user = get_userdata($user_id);

            if ($user) Sources::agree($this->id(), $user->user_email, $checkbox_text, __('Registration', 'mailoptin'));
        });
    }

    public function profile_summary($email)
    {
        $user = get_user_by('email', $email);

        if ( ! $user) return [];

        $roles = array_map(function ($role) {
            $names = wp_roles()->get_names();

            return isset($names[$role]) ? translate_user_role($names[$role]) : $role;
        }, (array)$user->roles);

        return [
            __('Username', 'mailoptin')   => $user->user_login,
            __('Role', 'mailoptin')       => implode(', ', $roles),
            __('Registered', 'mailoptin') => date_i18n(get_option('date_format'), strtotime($user->user_registered)),
        ];
    }
}
