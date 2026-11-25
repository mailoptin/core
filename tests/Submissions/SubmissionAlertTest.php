<?php

namespace MailOptin\Tests\Core\Submissions;

use MailOptin\Core\Admin\SettingsPage\LeadBank;
use MailOptin\Core\Repositories\ConversionSyncRepository as Syncs;
use MailOptin\Core\Submissions\SubmissionAlert;
use WP_UnitTestCase;

class SubmissionAlertTest extends WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();
        SubmissionAlert::reset();
    }

    protected function failure($days_ago = 0, $status = Syncs::STATUS_FAILED, $service = 'MailChimpConnect')
    {
        global $wpdb;

        $id = Syncs::add(wp_rand(1, 999999), $service, 'abc', false, '401: API key is invalid', ['email' => 'x@example.com'], Syncs::STATUS_RETRYING === $status ? Syncs::now(600) : null);
        $wpdb->update(Syncs::table(), ['created_at' => Syncs::now(-$days_ago * DAY_IN_SECONDS)], ['id' => $id]);

        return $id;
    }

    public function test_menu_title_counts_recent_unsent_submissions()
    {
        $this->assertSame('Submissions', SubmissionAlert::menu_title('Submissions'));
    }

    public function test_banner_for_free_and_premium()
    {
        $this->failure();
        $this->failure(0, Syncs::STATUS_RETRYING);
        $this->failure(30); // too old for the alert

        $this->assertSame(2, SubmissionAlert::count());
        $this->assertStringContainsString('<span class="pending-count" aria-hidden="true">2</span>', SubmissionAlert::menu_title('Submissions'));

        $free = SubmissionAlert::banner_html(false);
        $this->assertStringContainsString("2 submissions didn&#039;t reach your email service in the last 7 days.", $free);
        $this->assertStringContainsString('401: API key is invalid', $free);
        $this->assertStringContainsString('Upgrade to review and resend them', $free);
        $this->assertStringNotContainsString('Retry all failed', $free);

        $pro = SubmissionAlert::banner_html(true);
        $this->assertStringContainsString('Retry all failed', $pro);
        $this->assertStringContainsString('mo_submissions_action=retry_all', $pro);
        $this->assertStringContainsString('status=unsent', $pro);
    }

    public function test_no_banner_when_everything_was_sent()
    {
        Syncs::add(1, 'MailChimpConnect', 'abc', true);

        $this->assertSame('', SubmissionAlert::banner_html(true));
    }

    public function test_banner_goes_above_the_submissions_screen_only()
    {
        $this->failure();

        $page = LeadBank::get_instance();
        $this->assertStringStartsWith('<div class="notice notice-error inline mo-submissions-alert"', $page->failure_banner('<p>list</p>', 'mo_leads'));
        $this->assertSame('<p>other</p>', $page->failure_banner('<p>other</p>', 'mailoptin_settings'));

        $_GET['submission'] = 5;
        $this->assertSame('<p>detail</p>', $page->failure_banner('<p>detail</p>', 'mo_leads'), 'Not on a single submission.');
        unset($_GET['submission']);
    }

    public function test_erasure_removes_retry_data_and_stops_retrying()
    {
        $id = $this->failure(0, Syncs::STATUS_RETRYING);
        $conversion_id = Syncs::get($id)['conversion_id'];

        Syncs::forget_retry_data([$conversion_id]);

        $sync = Syncs::get($id);
        $this->assertNull($sync['retry_data']);
        $this->assertSame(Syncs::STATUS_FAILED, $sync['status']);
        $this->assertNull($sync['next_attempt_at']);
    }
}
