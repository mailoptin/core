<?php

namespace MailOptin\Core\Repositories;

class FlowsRepository extends AbstractRepository
{
    /**
     * Check if an flow name already exist.
     *
     * @param string $name
     *
     * @return bool
     */
    public static function name_exist($name)
    {
        $campaign_name = sanitize_text_field($name);
        $table         = parent::flows_table();
        $result        = parent::wpdb()->get_var(
            parent::wpdb()->prepare("SELECT name FROM $table WHERE name = '%s'", $campaign_name)
        );

        return ! empty($result);
    }

    /**
     * Add new flow to database.
     *
     * @param string $name
     * @param string $campaign_type
     * @param string $class
     *
     * @return false|int
     */
    public static function add_flow($name, $campaign_type, $class)
    {
        $response = parent::wpdb()->insert(
            parent::flows_table(),
            array(
                'name'           => stripslashes($name),
                'campaign_type'  => $campaign_type,
                'template_class' => $class
            ),
            array(
                '%s',
                '%s',
                '%s'
            )
        );

        return ! $response ? $response : parent::wpdb()->insert_id;
    }

    /**
     * Get name or title of flow.
     *
     * @param int $flow_id
     *
     * @return string
     */
    public static function get_flow_name($flow_id)
    {
        $table = parent::flows_table();

        return parent::wpdb()->get_var("SELECT name FROM $table WHERE id = '$flow_id'");
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
}