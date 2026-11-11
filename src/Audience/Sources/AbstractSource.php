<?php

namespace MailOptin\Core\Audience\Sources;

/**
 * Where a broadcast can find people besides its list: a store's customers, the site's users, a
 * membership plugin's members. Nothing is copied: who is in it is asked of the plugin when the
 * email is counted or sent.
 *
 * A source says who its people are with people_sql(): a query returning one row per address,
 * with columns email (lower case), first_name and last_name. Sources::recipients() narrows it to
 * who may get marketing.
 */
abstract class AbstractSource
{
    const MODE_AGREED = 'agreed';
    const MODE_EVERYONE = 'everyone';

    /** Short and stable: it's saved with broadcasts and consent records. Up to 23 lower-case letters, digits, _ and -. */
    abstract public function id();

    abstract public function label();

    /** Whether its plugin is active. */
    abstract public function is_active();

    /** The plugin's own page listing these people. */
    abstract public function admin_url();

    /** Link text for admin_url(), like "View in WooCommerce". */
    abstract public function admin_link_label();

    /**
     * One row per address: email (lower case), first_name, last_name.
     *
     * @param string $option one of options()'s keys; '' for everyone
     * @param array $settings this source's saved settings (see settings())
     * @param string|null $email only this (lower-case) address: filter on it before grouping, so
     *                           looking up one person doesn't read everyone
     *
     * @return array [sql, params]
     */
    abstract public function people_sql($option, $settings, $email = null);

    /**
     * Narrowing choices for the audience picker, like roles or membership levels.
     *
     * @return array value => label; empty for none
     */
    public function options()
    {
        return [];
    }

    /**
     * Whether a saved option still exists. One that doesn't (a deleted role or level) must never
     * be read as "any": the broadcast would go to everyone.
     */
    public function is_valid_option($option)
    {
        return '' === (string)$option || array_key_exists((string)$option, $this->options());
    }

    /** What options() narrows by, like "Role". */
    public function option_label()
    {
        return '';
    }

    /** Label for '' in options(), like "Any role". */
    public function any_option_label()
    {
        return __('Any', 'mailoptin');
    }

    /** Who can get broadcasts until the site owner chooses. */
    public function default_mode()
    {
        return self::MODE_AGREED;
    }

    /**
     * Where the consent checkbox shows, like "at checkout"; empty when this source adds none and
     * only existing subscribers count as agreed.
     */
    public function checkbox_place()
    {
        return '';
    }

    public function default_checkbox_text()
    {
        return __('Email me news and offers. You can unsubscribe any time.', 'mailoptin');
    }

    /** Extra settings this source shows on Settings › Customers and members, with defaults. */
    public function setting_defaults()
    {
        return [];
    }

    /**
     * Fields for setting_defaults(), drawn inside the source's section.
     *
     * @param array $settings
     * @param string $name_prefix input name prefix, like "sources[woo]"
     *
     * @return string HTML
     */
    public function settings_fields_html($settings, $name_prefix)
    {
        return '';
    }

    /**
     * @param array $posted this source's posted fields
     *
     * @return array setting_defaults()'s keys, cleaned
     */
    public function sanitize_settings($posted)
    {
        return [];
    }

    /**
     * Add the consent checkbox where people sign up or buy, and record their answer with
     * Sources::agree(). Called on init when the source is active and set to "only people who agreed".
     */
    public function register_checkbox($checkbox_text)
    {
    }

    /**
     * What the plugin knows about this person, for their subscriber profile.
     *
     * @return array label => value, or empty when they aren't one of these people
     */
    public function profile_summary($email)
    {
        return [];
    }

    /**
     * The months-ago cutoff as a UTC datetime, or '' for no cutoff.
     */
    protected static function cutoff($months)
    {
        $months = absint($months);

        return $months ? gmdate('Y-m-d H:i:s', strtotime("-$months months")) : '';
    }

    /** "IN (...)" placeholders and params for a list that may be empty. */
    protected static function in_list($values)
    {
        $values = array_values((array)$values);

        if (empty($values)) $values = ['__mailoptin_none__'];

        return ['IN (' . implode(',', array_fill(0, count($values), '%s')) . ')', $values];
    }

    protected static function table_exists($table)
    {
        global $wpdb;

        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    }
}
