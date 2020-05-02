<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;

class FlowsRepository extends AbstractRepository
{
    /**
     * Add new flow to database.
     *
     * @param string $name
     * @param string $campaign_type
     * @param string $class
     *
     * @return false|int
     */
    public static function add_flow($name, $status, $flow_data)
    {
        $response = parent::wpdb()->insert(
            parent::flows_table(),
            array(
                'title'  => $name,
                'status' => $status,
            ),
            array(
                '%s',
                '%s'
            )
        );

        if ($response && is_int(parent::wpdb()->insert_id)) {

            $flow_id = parent::wpdb()->insert_id;

            self::add_meta_data($flow_id, 'flow_data', $flow_data);

            return $flow_id;
        }

        return false;
    }

    /**
     * Update flow
     *
     * @param int $flow_id
     * @param string $name
     * @param string $campaign_type
     * @param string $class
     *
     * @return false|int
     */
    public static function update_flow($flow_id, $name, $status, $flow_data)
    {
        $response = parent::wpdb()->update(
            parent::flows_table(),
            ['title' => $name, 'status' => $status],
            ['id' => $flow_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($response) {

            self::update_meta_data($flow_id, 'flow_data', $flow_data);

            return true;
        }

        return false;
    }

    /**
     * Get name or title of flow.
     *
     * @param int $flow_id
     *
     * @return string
     */
    public static function get_flow_title($flow_id)
    {
        $table = parent::flows_table();

        return parent::wpdb()->get_var("SELECT title FROM $table WHERE id = '$flow_id'");
    }

    /**
     * @param int $flow_id
     *
     * @return string
     */
    public static function get_flow_status($flow_id)
    {
        $table = parent::flows_table();

        return parent::wpdb()->get_var("SELECT status FROM $table WHERE id = '$flow_id'");
    }

    /**
     * Array of flow IDs
     *
     * @return array
     */
    public static function get_flow_ids()
    {
        $table = parent::flows_table();

        return parent::wpdb()->get_col("SELECT id FROM $table");
    }

    /**
     * Array of flows
     *
     * @return array
     */
    public static function get_flows()
    {
        $table = parent::flows_table();

        return parent::wpdb()->get_results("SELECT * FROM $table", 'ARRAY_A');
    }

    /**
     * Get flow by campaign ID.
     *
     * @param int $flow_id
     *
     * @return mixed
     */
    public static function get_flow_by_id($flow_id)
    {
        $table = parent::flows_table();

        return parent::wpdb()->get_row(
            parent::wpdb()->prepare("SELECT * FROM $table WHERE id = %d", $flow_id),
            'ARRAY_A'
        );
    }

    /**
     * Delete campaign by ID
     *
     * @param int $flow_id
     *
     * @return false|int
     */
    public static function delete_flow_by_id($flow_id)
    {
        $table = parent::flows_table();

        return parent::wpdb()->delete(
            $table,
            array('id' => $flow_id),
            array('%d')
        );
    }

    /**
     * Activate flow.
     *
     * @param int $flow_id
     *
     * @return bool
     */
    public static function activate_flow($flow_id)
    {
        // update the "activate_flow" setting to true
        $all_settings                            = self::get_settings();
        $all_settings[$flow_id]['activate_flow'] = true;

        return self::updateSettings($all_settings);
    }

    /**
     * Deactivate flow.
     *
     * @param int $flow_id
     *
     * @return bool
     */
    public static function deactivate_flow($flow_id)
    {
        // update the "activate_flow" setting to true
        $all_settings                            = self::get_settings();
        $all_settings[$flow_id]['activate_flow'] = false;
        self::updateSettings($all_settings);
    }

    /**
     * Add meta data field to flow.
     *
     * @param int $flow_id
     * @param string $meta_key
     * @param string $meta_value
     * @param bool $unique
     *
     * @return int|false Meta ID on success, false on failure.
     */
    public static function add_meta_data($flow_id, $meta_key, $meta_value, $unique = false)
    {
        return add_metadata('automate_flow', $flow_id, $meta_key, $meta_value, $unique);
    }

    /**
     * Update flow meta field based on campaign ID.
     *
     * @param int $flow_id
     * @param string $meta_key
     * @param string $meta_value
     * @param string $prev_value
     *
     * @return int|bool Meta ID if the key didn't exist, true on successful update, false on failure.
     */
    public static function update_meta_data($flow_id, $meta_key, $meta_value, $prev_value = '')
    {
        return update_metadata('automate_flow', $flow_id, $meta_key, $meta_value, $prev_value);
    }

    /**
     * Remove metadata matching criteria from a flow.
     *
     * @param int $flow_id
     * @param string $meta_key
     * @param string $meta_value
     * @param bool $delete_all
     *
     * @return bool True on success, false on failure.
     */
    public static function delete_meta_data($flow_id, $meta_key, $meta_value = '', $delete_all = false)
    {
        return delete_metadata('automate_flow', $flow_id, $meta_key, $meta_value, $delete_all);
    }


    /**
     * Delete all meta data belonging to an flow.
     *
     * @param $flow_id
     *
     * @return bool
     */
    public static function delete_all_meta_data($flow_id)
    {
        if ( ! isset($flow_id)) return false;

        global $wpdb;

        return $wpdb->delete(
            $wpdb->prefix . Core::flows_meta_table_name,
            array('flow_id' => $flow_id)
        );
    }

    /**
     * Retrieve post meta field for a flow.
     *
     * @param int $flow_id
     * @param string $meta_key
     * @param bool $single
     *
     * @return mixed
     */
    public static function get_meta_data($flow_id, $meta_key = '', $single = true)
    {
        return get_metadata('automate_flow', $flow_id, $meta_key, $single);
    }
}