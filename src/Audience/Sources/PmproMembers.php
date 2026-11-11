<?php

namespace MailOptin\Core\Audience\Sources;

/**
 * Paid Memberships Pro members with an active membership, optionally of one level.
 */
class PmproMembers extends AbstractSource
{
    const FIELD = 'mo_marketing_consent';

    public function id()
    {
        return 'pmpro';
    }

    public function label()
    {
        return __('Paid Memberships Pro members', 'mailoptin');
    }

    public function is_active()
    {
        global $wpdb;

        return defined('PMPRO_VERSION') && self::table_exists($wpdb->prefix . 'pmpro_memberships_users');
    }

    public function admin_url()
    {
        return admin_url('admin.php?page=pmpro-memberslist');
    }

    public function admin_link_label()
    {
        return __('View in Memberships', 'mailoptin');
    }

    public function options()
    {
        global $wpdb;

        if ( ! $this->is_active()) return [];

        $levels = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}pmpro_membership_levels ORDER BY name", 'ARRAY_A');

        return wp_list_pluck((array)$levels, 'name', 'id');
    }

    public function option_label()
    {
        return __('Membership level', 'mailoptin');
    }

    public function any_option_label()
    {
        return __('Any active membership', 'mailoptin');
    }

    /** Members usually expect news about their membership. */
    public function default_mode()
    {
        return self::MODE_EVERYONE;
    }

    public function checkbox_place()
    {
        return __('at membership checkout', 'mailoptin');
    }

    public function people_sql($option, $settings)
    {
        global $wpdb;

        $members = $wpdb->prefix . 'pmpro_memberships_users';

        $where  = ["m.status = 'active'", "u.user_email <> ''"];
        $params = [];

        if (absint($option)) {
            $where[]  = 'm.membership_id = %d';
            $params[] = absint($option);
        }

        return [
            "SELECT LOWER(u.user_email) AS email, MAX(fn.meta_value) AS first_name, MAX(ln.meta_value) AS last_name
            FROM $members m INNER JOIN $wpdb->users u ON u.ID = m.user_id
            LEFT JOIN $wpdb->usermeta fn ON fn.user_id = u.ID AND fn.meta_key = 'first_name'
            LEFT JOIN $wpdb->usermeta ln ON ln.user_id = u.ID AND ln.meta_key = 'last_name'
            WHERE " . implode(' AND ', $where) . ' GROUP BY LOWER(u.user_email)',
            $params,
        ];
    }

    public function register_checkbox($checkbox_text)
    {
        add_action('pmpro_checkout_before_submit_button', function () use ($checkbox_text) {
            printf(
                '<div class="pmpro_checkout-field mo-marketing-consent"><label><input type="checkbox" name="%s" value="1"> %s</label></div>',
                esc_attr(self::FIELD),
                esc_html($checkbox_text)
            );
        }, 7);

        add_action('pmpro_after_checkout', function ($user_id) use ($checkbox_text) {
            // Nonce checked by Paid Memberships Pro's checkout.
            if (empty($_REQUEST[self::FIELD])) return;

            $user = get_userdata($user_id);

            if ($user) Sources::agree($this->id(), $user->user_email, $checkbox_text, __('Membership checkout', 'mailoptin'));
        });
    }

    public function profile_summary($email)
    {
        global $wpdb;

        $user = get_user_by('email', $email);

        if ( ! $user || ! $this->is_active()) return [];

        $levels = $wpdb->get_col($wpdb->prepare(
            "SELECT l.name FROM {$wpdb->prefix}pmpro_memberships_users m INNER JOIN {$wpdb->prefix}pmpro_membership_levels l ON l.id = m.membership_id WHERE m.user_id = %d AND m.status = 'active'",
            $user->ID
        ));

        return empty($levels) ? [] : [__('Membership', 'mailoptin') => implode(', ', $levels)];
    }
}
