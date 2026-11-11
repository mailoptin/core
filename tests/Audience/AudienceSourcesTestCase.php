<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\Broadcast_Page;
use MailOptin\Core\Audience\PersonalData;
use MailOptin\Core\Audience\Sources\AbstractSource;
use MailOptin\Core\Audience\Sources\ContactRenderer;
use MailOptin\Core\Audience\Sources\RegisteredUsers;
use MailOptin\Core\Audience\Sources\Sources;
use MailOptin\Core\Audience\Sources\WooCustomers;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\Core\Repositories\AudienceConsentsRepository as Consents;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SegmentsRepository;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\SendQueue\QueueRunner;
use WP_UnitTestCase;

/**
 * Set-up and helpers for the customers, users and members tests.
 */
abstract class AudienceSourcesTestCase extends WP_UnitTestCase
{
    protected $list_id;
    protected $campaign_id;

    public function set_up()
    {
        parent::set_up();

        foreach ([17, 20, 21, 22, 25] as $version) DBUpdates::get_instance()->{'update_routine_' . $version}();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        Consents::reset_ready();

        DBUpdates::get_instance()->update_routine_7();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        if (is_null(\MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process)) {
            \MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process = new \MailOptin\Core\AsyncHandler\BGProcess();
        }
        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, ['from_name' => 'Acme Garden Supply', 'from_email' => 'hello@acmegarden.co']);
        delete_option(Sources::OPTION);
        Sources::reset();

        // Only the users the test makes: the bootstrap's admin is left out with a role nobody else has.
        $this->list_id     = ListsRepository::create(['name' => 'Newsletter']);
        $this->campaign_id = ER::add_email_campaign('Customers note', ER::NEWSLETTER, 'Lucid');

        Broadcasts::save($this->campaign_id, ['email_campaign_title' => 'Hi {{subscriber.first_name|friend}}', 'connection_service' => 'MailOptinConnect', 'connection_email_list' => '']);

        reset_phpmailer_instance();
    }

    public function tear_down()
    {
        \MailOptin\Core\Repositories\CampaignSettingsRepository::delete_all(\MailOptin\Core\Repositories\CampaignSettingsRepository::TYPE_EMAIL);
        $_POST = $_GET = $_REQUEST = [];
        Sources::reset();

        parent::tear_down();
    }

    protected function user($email, $role = 'author', $first = '')
    {
        return self::factory()->user->create(['user_email' => $email, 'role' => $role, 'first_name' => $first]);
    }

    protected function choose($selection)
    {
        Broadcasts::save($this->campaign_id, [Sources::CAMPAIGN_SETTING => Sources::encode_selection($selection)]);
    }

    protected function emails(AbstractSource $source, $option = '', $narrow = [])
    {
        return wp_list_pluck(Sources::recipients($source, $option, $narrow, '', 1000), 'email');
    }

    protected function mode($id, $mode)
    {
        Sources::save_settings([$id => ['mode' => $mode, 'checkbox_text' => 'Email me news']]);
    }

    protected function queue_rows()
    {
        global $wpdb;

        return $wpdb->get_results('SELECT email, recipient_type, recipient_id FROM ' . SendQueueRepository::table() . ' ORDER BY email', 'ARRAY_A');
    }

    protected function send()
    {
        $result = Broadcasts::send($this->campaign_id);
        $this->assertNotWPError($result);

        global $wpdb;
        $log_id = (int)$wpdb->get_var('SELECT MAX(campaign_log_id) FROM ' . SendQueueRepository::table());
        QueueRunner::get_instance()->process($log_id);

        return $log_id;
    }

    /**
     * WooCommerce's analytics tables with a few customers and orders, and an active Woo source
     * (WooCommerce itself isn't loaded in tests).
     *
     * Customers: maya (2 paid orders, 40 + 10, recent; bought product 47), guest (one 20 order,
     * recent, product 49), old (one 9 order 30 months ago, product 47), pending (unpaid only).
     */
    protected function woo_store()
    {
        global $wpdb;

        $wpdb->query("CREATE TABLE {$wpdb->prefix}wc_customer_lookup (customer_id bigint(20) NOT NULL, user_id bigint(20) NULL, first_name varchar(255) NOT NULL DEFAULT '', last_name varchar(255) NOT NULL DEFAULT '', email varchar(100) NULL)");
        $wpdb->query("CREATE TABLE {$wpdb->prefix}wc_order_stats (order_id bigint(20) NOT NULL, parent_id bigint(20) NOT NULL DEFAULT 0, date_created datetime NOT NULL, date_created_gmt datetime NOT NULL, total_sales double NOT NULL DEFAULT 0, status varchar(200) NOT NULL, customer_id bigint(20) NOT NULL)");
        $wpdb->query("CREATE TABLE {$wpdb->prefix}wc_order_product_lookup (order_item_id bigint(20) NOT NULL, order_id bigint(20) NOT NULL, product_id bigint(20) NOT NULL, variation_id bigint(20) NOT NULL DEFAULT 0)");

        $customers = [[1, 5, 'Maya', 'O', 'Maya@Example.com'], [2, null, 'Gus', 'T', 'guest@example.com'], [3, 6, 'Old', 'C', 'old@example.com'], [4, 7, 'Pen', 'D', 'pending@example.com']];
        foreach ($customers as $c) {
            $wpdb->insert("{$wpdb->prefix}wc_customer_lookup", ['customer_id' => $c[0], 'user_id' => $c[1], 'first_name' => $c[2], 'last_name' => $c[3], 'email' => $c[4]]);
        }

        $recent = gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS);
        $old    = gmdate('Y-m-d H:i:s', strtotime('-30 months'));
        $orders = [[10, 0, $recent, 'wc-completed', 1, 40], [11, 0, $recent, 'wc-processing', 1, 10], [12, 10, $recent, 'wc-completed', 1, 5], [13, 0, $recent, 'wc-completed', 2, 20], [14, 0, $old, 'wc-completed', 3, 9], [15, 0, $recent, 'wc-pending', 4, 9]];
        foreach ($orders as $o) {
            $wpdb->insert("{$wpdb->prefix}wc_order_stats", ['order_id' => $o[0], 'parent_id' => $o[1], 'date_created' => $o[2], 'date_created_gmt' => $o[2], 'status' => $o[3], 'customer_id' => $o[4], 'total_sales' => $o[5]]);
        }

        foreach ([[1, 10, 47, 0], [2, 11, 50, 51], [3, 13, 49, 0], [4, 14, 47, 0], [5, 15, 47, 0]] as $i) {
            $wpdb->insert("{$wpdb->prefix}wc_order_product_lookup", ['order_item_id' => $i[0], 'order_id' => $i[1], 'product_id' => $i[2], 'variation_id' => $i[3]]);
        }

        $woo = new class extends \MailOptin\Core\Audience\Sources\WooCustomers {
            public function is_active() { return true; }
        };
        Sources::add($woo);
        Sources::save_settings(['woo' => ['mode' => 'everyone', 'months' => '0', 'guests' => '1']]);

        return $woo;
    }
}
