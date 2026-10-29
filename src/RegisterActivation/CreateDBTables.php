<?php

namespace MailOptin\Core\RegisterActivation;


use MailOptin\Core\Core;

class CreateDBTables
{
    public static function make()
    {
        global $wpdb;

        $collate = '';
        if ($wpdb->has_cap('collation')) {
            $collate = $wpdb->get_charset_collate();
        }

        $optin_campaigns_table = $wpdb->prefix . Core::optin_campaigns_table_name;
        $email_campaigns_table = $wpdb->prefix . Core::email_campaigns_table_name;
        $email_campaign_log_table = $wpdb->prefix . Core::campaign_log_table_name;
        $optin_conversions_table = $wpdb->prefix . Core::conversions_table_name;
        $optin_campaign_meta_table = $wpdb->prefix . Core::optin_campaign_meta_table_name;
        $campaign_log_meta_table = $wpdb->prefix . Core::campaign_log_meta_table_name;

        $sqls[] = "CREATE TABLE IF NOT EXISTS $optin_campaign_meta_table (
                  meta_id bigint(20) NOT NULL AUTO_INCREMENT,
                  PRIMARY KEY  (meta_id),
                  optin_campaign_id bigint(20) NOT NULL,
                  meta_key varchar(255) NULL,
                  meta_value longtext NULL
				) $collate;
				";
        $sqls[] = "CREATE TABLE IF NOT EXISTS $campaign_log_meta_table (
                  meta_id bigint(20) NOT NULL AUTO_INCREMENT,
                  PRIMARY KEY  (meta_id),
                  campaign_log_id bigint(20) NOT NULL,
                  meta_key varchar(255) NULL,
                  meta_value longtext NULL
				) $collate;
				";
        $sqls[] = "CREATE TABLE IF NOT EXISTS $optin_campaigns_table (
                  id bigint(20) NOT NULL AUTO_INCREMENT,
                  name varchar(50) NOT NULL,
                  uuid char(10) NOT NULL,
                  optin_class varchar(50) NOT NULL,
                  optin_type varchar(50) NOT NULL,
                  activated char(6) NOT NULL,
                  PRIMARY KEY (id),
                  UNIQUE KEY name (name),
                  UNIQUE KEY uuid (uuid)
                ) $collate;
				";

        $sqls[] = "CREATE TABLE IF NOT EXISTS $optin_conversions_table (
                  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                  optin_id int(8) unsigned NOT NULL DEFAULT '0',
                  optin_type varchar(50) NOT NULL DEFAULT '',
                  name varchar(128) DEFAULT '',
                  email varchar(128) NOT NULL DEFAULT '',
                  user_agent varchar(150) DEFAULT '',
                  conversion_page varchar(256) DEFAULT '',
                  referrer varchar(256) DEFAULT '',
                  date_added datetime NOT NULL DEFAULT '1000-01-01 00:00:00',
                  PRIMARY KEY (id)
                ) $collate;
				";

        $sqls[] = "CREATE TABLE IF NOT EXISTS $email_campaign_log_table (
                  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                  email_campaign_id bigint(20) unsigned NOT NULL,
                  title text NOT NULL,
                  content_html text NOT NULL,
                  content_text longtext NOT NULL,
                  status varchar(20) NOT NULL DEFAULT 'draft',
                  status_time datetime DEFAULT NULL,
                  note text,
                  PRIMARY KEY (id)
                ) $collate;
				";

        $sqls[] = "CREATE TABLE IF NOT EXISTS $email_campaigns_table (
                  id bigint(20) NOT NULL AUTO_INCREMENT,
                  name varchar(50) NOT NULL,
                  campaign_type varchar(40) NOT NULL,
                  template_class varchar(50) NOT NULL,
                  PRIMARY KEY (id),
                  UNIQUE KEY name (name)
                ) $collate;
				";

        $sqls = array_merge($sqls, self::audience_tables_sql($collate));
        $sqls[] = self::conversion_syncs_table_sql($collate);
        $sqls = array_merge($sqls, self::tags_fields_tables_sql($collate));
        $sqls[] = self::segments_table_sql($collate);

        $sqls = apply_filters('mo_create_database_tables', $sqls, $collate);

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        foreach ($sqls as $sql) {
            dbDelta($sql);
        }
    }

    /**
     * Tables for built-in lists, subscribers and the send queue.
     *
     * Plain "CREATE TABLE" (no IF NOT EXISTS) so dbDelta can diff and alter them in later updates.
     * Datetime columns hold UTC.
     *
     * @param string $collate
     *
     * @return array
     */
    public static function audience_tables_sql($collate)
    {
        global $wpdb;

        $subscribers_table      = $wpdb->prefix . Core::subscribers_table_name;
        $lists_table            = $wpdb->prefix . Core::lists_table_name;
        $list_subscribers_table = $wpdb->prefix . Core::list_subscribers_table_name;
        $suppressions_table     = $wpdb->prefix . Core::suppressions_table_name;
        $events_table           = $wpdb->prefix . Core::subscriber_events_table_name;
        $send_queue_table       = $wpdb->prefix . Core::send_queue_table_name;

        $sqls[] = "CREATE TABLE $subscribers_table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(191) NOT NULL,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  source varchar(50) NOT NULL DEFAULT '',
  source_optin_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ip_address varchar(45) NOT NULL DEFAULT '',
  consent_text text NULL,
  consent_method varchar(30) NOT NULL DEFAULT '',
  consent_at datetime NULL,
  confirmed_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  KEY status (status)
) $collate;";

        $sqls[] = "CREATE TABLE $lists_table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(200) NOT NULL,
  description text NULL,
  double_optin tinyint(1) NOT NULL DEFAULT 1,
  welcome_email tinyint(1) NOT NULL DEFAULT 0,
  welcome_subject varchar(255) NOT NULL DEFAULT '',
  welcome_body longtext NULL,
  welcome_campaign_log_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $collate;";

        $sqls[] = "CREATE TABLE $list_subscribers_table (
  list_id bigint(20) unsigned NOT NULL,
  subscriber_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  subscribed_at datetime NOT NULL,
  unsubscribed_at datetime NULL,
  PRIMARY KEY  (list_id,subscriber_id),
  KEY subscriber_id (subscriber_id)
) $collate;";

        $sqls[] = "CREATE TABLE $suppressions_table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email_hash char(64) NOT NULL,
  reason varchar(20) NOT NULL,
  source varchar(50) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email_hash (email_hash)
) $collate;";

        $sqls[] = "CREATE TABLE $events_table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subscriber_id bigint(20) unsigned NOT NULL,
  type varchar(30) NOT NULL,
  campaign_log_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY subscriber_id (subscriber_id),
  KEY campaign_log_id (campaign_log_id),
  KEY type_created (type,created_at)
) $collate;";

        $sqls[] = "CREATE TABLE $send_queue_table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_log_id bigint(20) unsigned NOT NULL,
  email_campaign_id bigint(20) unsigned NOT NULL,
  email varchar(191) NOT NULL,
  recipient_type varchar(30) NOT NULL DEFAULT 'subscriber',
  recipient_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  next_attempt_at datetime NOT NULL,
  claimed_by varchar(32) NOT NULL DEFAULT '',
  claimed_until datetime NULL,
  sent_at datetime NULL,
  error text NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY campaign_email (campaign_log_id,email),
  KEY claim (campaign_log_id,status,next_attempt_at),
  KEY sent_at (status,sent_at)
) $collate;";

        return $sqls;
    }

    /**
     * One row per integration a submission (conversion) was sent to: whether it got there and,
     * while it hasn't, what's needed to try again. Datetimes are UTC.
     */
    public static function conversion_syncs_table_sql($collate)
    {
        global $wpdb;

        $table = $wpdb->prefix . Core::conversion_syncs_table_name;

        return "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversion_id bigint(20) unsigned NOT NULL,
  connection_service varchar(100) NOT NULL,
  connection_email_list varchar(191) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  last_error text NULL,
  next_attempt_at datetime NULL,
  locked_until datetime NULL,
  retry_data longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY conversion_id (conversion_id),
  KEY due (status,next_attempt_at),
  KEY status_created (status,created_at)
) $collate;";
    }

    /**
     * Tags and custom fields of built-in list subscribers. Custom fields are shared by all lists.
     * A field value is kept as text, and also as a number or date for the types that have one, so
     * filters can compare them.
     */
    public static function tags_fields_tables_sql($collate)
    {
        global $wpdb;

        $tags_table   = $wpdb->prefix . Core::tags_table_name;
        $links_table  = $wpdb->prefix . Core::subscriber_tags_table_name;
        $fields_table = $wpdb->prefix . Core::custom_fields_table_name;
        $values_table = $wpdb->prefix . Core::field_values_table_name;

        $sqls[] = "CREATE TABLE $tags_table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY name (name)
) $collate;";

        $sqls[] = "CREATE TABLE $links_table (
  subscriber_id bigint(20) unsigned NOT NULL,
  tag_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (subscriber_id,tag_id),
  KEY tag_id (tag_id)
) $collate;";

        $sqls[] = "CREATE TABLE $fields_table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  label varchar(200) NOT NULL,
  merge_key varchar(64) NOT NULL,
  type varchar(20) NOT NULL,
  options longtext NULL,
  required tinyint(1) NOT NULL DEFAULT 0,
  subscriber_editable tinyint(1) NOT NULL DEFAULT 0,
  position int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY merge_key (merge_key)
) $collate;";

        $sqls[] = "CREATE TABLE $values_table (
  subscriber_id bigint(20) unsigned NOT NULL,
  field_id bigint(20) unsigned NOT NULL,
  value_text text NOT NULL,
  value_number decimal(20,4) NULL,
  value_date date NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (subscriber_id,field_id),
  KEY field_number (field_id,value_number),
  KEY field_date (field_id,value_date)
) $collate;";

        return $sqls;
    }

    /**
     * Saved segments: named tag and custom field filters, applied within a list when a campaign sends.
     * filters is JSON with tag_ids, tag_match and field_conditions.
     */
    public static function segments_table_sql($collate)
    {
        global $wpdb;

        $table = $wpdb->prefix . Core::segments_table_name;

        return "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  filters longtext NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY name (name)
) $collate;";
    }
}
