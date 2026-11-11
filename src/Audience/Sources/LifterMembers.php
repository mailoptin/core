<?php

namespace MailOptin\Core\Audience\Sources;

/**
 * LifterLMS students enrolled in a membership or course (any, or one).
 */
class LifterMembers extends AbstractSource
{
    public function id()
    {
        return 'lifterlms';
    }

    public function label()
    {
        return __('LifterLMS students', 'mailoptin');
    }

    public function is_active()
    {
        global $wpdb;

        return class_exists('LifterLMS') && self::table_exists($wpdb->prefix . 'lifterlms_user_postmeta');
    }

    public function admin_url()
    {
        return admin_url('admin.php?page=llms-reporting&tab=students');
    }

    public function admin_link_label()
    {
        return __('View in LifterLMS', 'mailoptin');
    }

    public function options()
    {
        $options = [];

        foreach (['llms_membership' => __('Membership: %s', 'mailoptin'), 'llms_course' => __('Course: %s', 'mailoptin')] as $post_type => $format) {
            foreach (get_posts(['post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC']) as $post) {
                $options[$post->ID] = sprintf($format, $post->post_title);
            }
        }

        return $options;
    }

    public function option_label()
    {
        return __('Membership or course', 'mailoptin');
    }

    public function any_option_label()
    {
        return __('Enrolled in anything', 'mailoptin');
    }

    public function default_mode()
    {
        return self::MODE_EVERYONE;
    }

    public function people_sql($option, $settings)
    {
        global $wpdb;

        $meta = $wpdb->prefix . 'lifterlms_user_postmeta';

        // LifterLMS adds a _status row each time enrollment changes; the latest one counts.
        $latest = "SELECT user_id, post_id, MAX(meta_id) AS meta_id FROM $meta WHERE meta_key = '_status'";
        $params = [];

        if (absint($option)) {
            $latest   .= ' AND post_id = %d';
            $params[] = absint($option);
        }

        $latest .= ' GROUP BY user_id, post_id';

        return [
            "SELECT LOWER(u.user_email) AS email, MAX(fn.meta_value) AS first_name, MAX(ln.meta_value) AS last_name
            FROM ($latest) l
            INNER JOIN $meta st ON st.meta_id = l.meta_id AND st.meta_value = 'enrolled'
            INNER JOIN $wpdb->users u ON u.ID = l.user_id
            LEFT JOIN $wpdb->usermeta fn ON fn.user_id = u.ID AND fn.meta_key = 'first_name'
            LEFT JOIN $wpdb->usermeta ln ON ln.user_id = u.ID AND ln.meta_key = 'last_name'
            WHERE u.user_email <> '' GROUP BY LOWER(u.user_email)",
            $params,
        ];
    }
}
