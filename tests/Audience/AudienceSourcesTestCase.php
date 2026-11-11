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
}
