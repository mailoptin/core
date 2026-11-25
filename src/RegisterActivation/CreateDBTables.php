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
  KEY campaign_log_id (campaign_log_id)
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
}
