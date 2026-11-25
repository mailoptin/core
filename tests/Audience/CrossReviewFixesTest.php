<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Audience\DeliveryEvents;
use MailOptin\Core\Audience\PersonalData;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Core;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\MailOptinConnect\Connect as BuiltInConnect;
use MailOptin\Core\Repositories\AbstractCampaignLogMeta;
use MailOptin\Core\Repositories\CampaignActivityRepository as Activity;
use MailOptin\Core\Repositories\CampaignSettingsRepository as Settings;
use MailOptin\Core\Repositories\ConversionSyncRepository as Syncs;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SendQueueRepository as Queue;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\SendQueue\QueueRunner;
use MailOptin\Core\Submissions\SubmissionSync;
use WP_UnitTestCase;

/**
 * Fixes from the review across all releases.
 */
class CrossReviewFixesTest extends WP_UnitTestCase
{
    protected $list_id;

    public function set_up()
    {
        parent::set_up();

        foreach ([17, 20, 21, 22] as $version) DBUpdates::get_instance()->{'update_routine_' . $version}();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        DBUpdates::get_instance()->update_routine_7();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        Activity::flush_cache();
        if (is_null(\MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process)) {
            \MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process = new \MailOptin\Core\AsyncHandler\BGProcess();
        }
        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });
        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, ['from_name' => 'Acme Garden Supply', 'from_email' => 'hello@acmegarden.co']);

        $this->list_id = ListsRepository::create(['name' => 'Newsletter']);
    }

    public function tear_down()
    {
        Settings::delete_all(Settings::TYPE_EMAIL);
        delete_option(QueueRunner::BLOCKED_OPTION);
        $_POST = $_GET = $_REQUEST = [];

        parent::tear_down();
    }

    protected function member($email, $status = Subscribers::STATUS_SUBSCRIBED)
    {
        $id = Subscribers::create(['email' => $email, 'status' => $status]);
        Subscribers::set_membership($id, $this->list_id, Subscribers::STATUS_SUBSCRIBED === $status ? Subscribers::STATUS_SUBSCRIBED : Subscribers::STATUS_PENDING);

        return $id;
    }

    protected function log($email_campaign_id = 7, $status = 'draft')
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . Core::campaign_log_table_name, ['email_campaign_id' => $email_campaign_id, 'title' => 'Notes', 'content_html' => '<p>Hi</p>', 'content_text' => 'Hi', 'status' => $status]);

        return (int)$wpdb->insert_id;
    }

    protected function log_status($log_id)
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}mo_campaign_log WHERE id = %d", $log_id));
    }

    /* ---------------------------------------------------------------------------------------------
     * Subscribers
     * ------------------------------------------------------------------------------------------ */

    public function test_an_old_confirmation_link_does_not_lift_a_complaint()
    {
        $id = $this->member('kim@example.org', Subscribers::STATUS_PENDING);
        Subscribers::update($id, ['status' => Subscribers::STATUS_COMPLAINED]);
        SuppressionRepository::add('kim@example.org', SuppressionRepository::REASON_COMPLAINED);

        Subscription::confirm($id);

        $this->assertSame(Subscribers::STATUS_COMPLAINED, Subscribers::get($id)['status']);
        $this->assertSame(SuppressionRepository::REASON_COMPLAINED, SuppressionRepository::get_reason('kim@example.org'));
    }

    public function test_confirmation_emails_never_go_to_suppressed_addresses()
    {
        reset_phpmailer_instance();

        $bounced = $this->member('bounced@example.org', Subscribers::STATUS_PENDING);
        Subscribers::update($bounced, ['status' => Subscribers::STATUS_BOUNCED]);
        $this->assertFalse(Subscription::send_confirmation($bounced, '', true));

        // Someone who unsubscribed from everything: an admin can't resend, but signing up again themselves can.
        $left = $this->member('left@example.org', Subscribers::STATUS_PENDING);
        SuppressionRepository::add('left@example.org', SuppressionRepository::REASON_UNSUBSCRIBED);
        $this->assertFalse(Subscription::send_confirmation($left, '', true));
        $this->assertTrue(Subscription::send_confirmation($left, '203.0.113.9'));

        $sent = tests_retrieve_phpmailer_instance()->get_sent();
        $this->assertSame('left@example.org', $sent->to[0][0]);
    }

    public function test_an_admin_removing_someone_from_a_list_is_not_their_unsubscribe()
    {
        $id = $this->member('pat@example.org');

        $this->assertTrue(Subscription::remove_by_admin($id, $this->list_id));

        $this->assertNull(Subscribers::get_membership($id, $this->list_id));
        $this->assertFalse(SuppressionRepository::is_suppressed('pat@example.org'));
        $this->assertSame(Subscribers::STATUS_SUBSCRIBED, Subscribers::get($id)['status']);

        // They can be added again, by an admin or an import.
        $this->assertNotSame(Subscription::ADMIN_REFUSED_UNSUBSCRIBED, Subscription::add_by_admin('pat@example.org', [$this->list_id], [], true));
        $this->assertSame(Subscribers::STATUS_SUBSCRIBED, Subscribers::get_membership($id, $this->list_id)['status']);
    }

    public function test_erasure_removes_the_address_where_no_subscriber_is_left()
    {
        global $wpdb;

        $id = $this->member('gone@example.org');
        Queue::enqueue($this->log(), 7, [['email' => 'gone@example.org', 'recipient_type' => 'subscriber', 'recipient_id' => $id]]);
        Subscribers::delete($id);

        // Only the columns every install has: later ones come from database updates.
        $wpdb->insert($wpdb->prefix . Core::conversions_table_name, ['optin_id' => 1, 'optin_type' => 'sidebar', 'name' => 'Gone', 'email' => 'gone@example.org', 'date_added' => current_time('mysql')]);
        $conversion = (int)$wpdb->insert_id;
        Syncs::add($conversion, 'MailChimpConnect', 'abc', false, 'Timed out', ['email' => 'gone@example.org', 'name' => 'Gone'], Syncs::now(60));

        $result = PersonalData::erase('Gone@Example.org');

        $this->assertTrue($result['items_removed']);
        $this->assertSame('0', $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Queue::table() . ' WHERE email = %s', 'gone@example.org')));
        $this->assertNull(Syncs::for_conversion($conversion)[0]['retry_data']);
    }

    public function test_signing_up_again_lifts_only_a_lead_bank_unsubscribe()
    {
        SuppressionRepository::add('lee@example.org', SuppressionRepository::REASON_UNSUBSCRIBED, 'mo_leadbank_unsubscribe');
        SuppressionRepository::add('ann@example.org', SuppressionRepository::REASON_UNSUBSCRIBED, 'unsubscribe_link');
        SuppressionRepository::add('bob@example.org', SuppressionRepository::REASON_BOUNCED, 'mo_leadbank_unsubscribe');

        foreach (['lee@example.org', 'ann@example.org', 'bob@example.org'] as $email) {
            SuppressionRepository::remove_unsubscribe_from($email, ['mo_leadbank_unsubscribe']);
        }

        $this->assertFalse(SuppressionRepository::is_suppressed('lee@example.org'));
        $this->assertTrue(SuppressionRepository::is_suppressed('ann@example.org'));
        $this->assertTrue(SuppressionRepository::is_suppressed('bob@example.org'));
    }

    /* ---------------------------------------------------------------------------------------------
     * Sending
     * ------------------------------------------------------------------------------------------ */

    public function test_resending_a_queued_campaign_whose_list_is_gone_does_not_mark_it_failed()
    {
        $campaign = ER::add_email_campaign('Notes', ER::NEWSLETTER, 'Lucid');
        Broadcasts::save($campaign, ['connection_service' => 'MailOptinConnect', 'connection_email_list' => '999']);
        $log = $this->log($campaign, 'processed');
        AbstractCampaignLogMeta::add_campaignlog_meta($log, BuiltInConnect::ENQUEUED_META, 1);

        $result = BuiltInConnect::get_instance()->send_newsletter($campaign, $log, 'Notes', '<p>Hi</p>', 'Hi');

        $this->assertTrue($result['success']);
        $this->assertNotSame('failed', $this->log_status($log));
    }

    public function test_a_campaign_with_nothing_to_send_completes_at_once()
    {
        $log = $this->log();

        QueueRunner::get_instance()->start($log, 7, []);

        $this->assertSame(QueueRunner::LOG_STATUS_PROCESSED, $this->log_status($log));
        $this->assertFalse(QueueRunner::get_instance()->is_sending($log));
    }

    public function test_saving_settings_after_a_stop_sends_the_held_emails_now()
    {
        global $wpdb;

        $log = $this->log();
        Queue::enqueue($log, 7, [['email' => 'a@example.org', 'recipient_type' => 'test', 'recipient_id' => 0], ['email' => 'b@example.org', 'recipient_type' => 'test', 'recipient_id' => 0]]);
        $later = Queue::now(900);
        $wpdb->query($wpdb->prepare('UPDATE ' . Queue::table() . " SET next_attempt_at = %s, error = 'Bad token' WHERE email = 'a@example.org'", $later));
        $wpdb->query($wpdb->prepare('UPDATE ' . Queue::table() . " SET next_attempt_at = %s, error = 'Timed out' WHERE email = 'b@example.org'", $later));
        QueueRunner::block('Bad token');

        QueueRunner::unblock();

        $this->assertLessThanOrEqual(Queue::now(), $wpdb->get_var('SELECT next_attempt_at FROM ' . Queue::table() . " WHERE email = 'a@example.org'"));
        // Waiting out a retry delay of its own: unchanged.
        $this->assertSame($later, $wpdb->get_var('SELECT next_attempt_at FROM ' . Queue::table() . " WHERE email = 'b@example.org'"));
    }

    public function test_a_broadcast_being_queued_is_not_shown_as_sent()
    {
        $campaign = ER::add_email_campaign('Notes', ER::NEWSLETTER, 'Lucid');
        $this->log($campaign, 'draft');

        $this->assertSame(Broadcasts::STATUS_SENDING, Broadcasts::status($campaign)['status']);
    }

    /* ---------------------------------------------------------------------------------------------
     * Submissions and tracking
     * ------------------------------------------------------------------------------------------ */

    public function test_a_retry_that_dies_still_uses_up_an_attempt()
    {
        global $wpdb;

        $sync = Syncs::add(1, 'MailChimpConnect', 'abc', false, 'Timed out', ['email' => 'x@example.org'], Syncs::now(-60));

        // Each try dies before recording a result: only the claim happens.
        for ($i = 0; $i < count(SubmissionSync::retry_delays()); $i++) {
            $this->assertTrue(Syncs::claim($sync));
            $wpdb->query($wpdb->prepare('UPDATE ' . Syncs::table() . ' SET locked_until = NULL WHERE id = %d', $sync));
        }

        $result = SubmissionSync::retry($sync);

        $this->assertWPError($result);
        $this->assertSame('mo_sync_gave_up', $result->get_error_code());
        $this->assertSame(Syncs::STATUS_FAILED, Syncs::get($sync)['status']);
    }

    public function test_old_soft_bounces_do_not_count_towards_a_new_one()
    {
        global $wpdb;

        $id = $this->member('soft@example.org');

        foreach ([200, 150, 120] as $days) {
            Subscribers::log_event($id, Subscribers::EVENT_SOFT_BOUNCED);
            $wpdb->query($wpdb->prepare('UPDATE ' . Subscribers::events_table() . ' SET created_at = %s WHERE id = %d', gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS), $wpdb->insert_id));
        }
        Subscribers::log_event($id, Subscribers::EVENT_SOFT_BOUNCED);

        $this->assertSame(1, DeliveryEvents::soft_bounces_in_a_row($id));
    }

    /* ---------------------------------------------------------------------------------------------
     * Campaign settings storage
     * ------------------------------------------------------------------------------------------ */

    public function test_rows_written_after_the_move_are_brought_across()
    {
        delete_option(Settings::READY_OPTION);
        update_option(MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME, [1 => ['email_campaign_title' => 'One'], 2 => ['email_campaign_title' => 'Two']]);
        $this->assertTrue(Settings::migrate());

        // The rows stay, so an older MailOptin still works after a downgrade...
        $this->assertSame('One', maybe_unserialize($GLOBALS['wpdb']->get_var("SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'mo_email_campaigns'"))[1]['email_campaign_title']);

        // ...and edits it made there come back with the upgrade. Campaign 2 was changed since in the table.
        Settings::update(Settings::TYPE_EMAIL, 2, ['email_campaign_title' => 'Two, edited']);
        $GLOBALS['wpdb']->update($GLOBALS['wpdb']->options, ['option_value' => serialize([1 => ['email_campaign_title' => 'One, while downgraded'], 2 => ['email_campaign_title' => 'Two']])], ['option_name' => 'mo_email_campaigns']);

        $this->assertSame(1, Settings::sync_changed_rows());
        Settings::flush_cache();

        $this->assertSame('One, while downgraded', ER::get_settings_by_id(1)['email_campaign_title']);
        $this->assertSame('Two, edited', ER::get_settings_by_id(2)['email_campaign_title']);
        $this->assertSame(0, Settings::sync_changed_rows());
    }

    public function test_a_move_another_request_holds_is_retried_later()
    {
        delete_option(Settings::READY_OPTION);
        delete_option(Settings::ERROR_OPTION);
        add_option(Settings::LOCK_OPTION, time(), '', 'no');

        $this->assertFalse(Settings::migrate());
        $this->assertNotEmpty(get_option(Settings::ERROR_OPTION), 'Without a reason, the hourly retry would never run.');

        // A stale lock is taken over.
        update_option(Settings::LOCK_OPTION, time() - HOUR_IN_SECONDS, false);
        $this->assertTrue(Settings::migrate());
        $this->assertFalse(get_option(Settings::ERROR_OPTION));
    }
}
