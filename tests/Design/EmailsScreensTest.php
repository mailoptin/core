<?php

namespace MailOptin\Core\Tests\Design;

use MailOptin\Core\Admin\SettingsPage\Campaign_Log_List;
use MailOptin\Core\Admin\SettingsPage\Email_Campaign_List;
use MailOptin\Core\Repositories\EmailCampaignRepository;

/**
 * The Emails area after the Refactoring UI pass.
 */
class EmailsScreensTest extends \WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        set_current_screen('toplevel_page_mailoptin-emails');
    }

    public function test_post_notifications_list_has_plain_columns_and_row_links()
    {
        global $wpdb;
        $list = new Email_Campaign_List($wpdb);

        $this->assertSame(['cb', 'name', 'campaign_type', 'next_send', 'activated'], array_keys($list->get_columns()), 'No "Actions" column of icon buttons; digests show their next send.');

        $id   = EmailCampaignRepository::add_email_campaign('<b>Weekly</b> & more', 'new_publish_post', 'Lucid');
        $html = $list->column_name(['id' => $id, 'name' => '<b>Weekly</b> & more']);

        $this->assertStringContainsString('&lt;b&gt;Weekly&lt;/b&gt; &amp; more', $html, 'The name is escaped.');
        preg_match_all("/<span class='([a-z]+)'>/", $html, $m);
        $this->assertSame(['edit', 'clone', 'delete'], $m[1], 'Edit first, Delete last.');
        $this->assertStringContainsString('class="mo-delete-prompt"', $html, 'Delete still asks first.');

        $switch = $list->column_activated(['id' => $id, 'name' => 'Weekly']);
        $this->assertStringNotContainsString('%3$s', $switch, 'No stray printf placeholder in the switch.');
        $this->assertStringContainsString('screen-reader-text', $switch, 'The switch has a name.');
    }

    public function test_logs_name_their_preview_links_and_statuses()
    {
        global $wpdb;
        $logs = new Campaign_Log_List($wpdb);

        $this->assertArrayNotHasKey('content_text', $logs->get_columns(), 'HTML and plain text share one Preview column.');

        $html = $logs->column_content_html(['id' => 7]);
        $this->assertStringContainsString('>HTML</a>', $html);
        $this->assertStringContainsString('>Plain text</a>', $html);
        $this->assertStringNotContainsString('dashicons-visibility', $html, 'No unlabelled eye icons.');

        $row = $logs->column_subject(['id' => 3, 'title' => 'New <b>posts</b>', 'status' => 'failed', 'email_campaign_id' => 0]);
        $this->assertStringContainsString('New &lt;b&gt;posts&lt;/b&gt;', $row, 'The subject is escaped.');
        $this->assertStringContainsString('>Resend</a>', $row, 'The Resend link reads "Resend".');

        $status = (new \ReflectionMethod($logs, 'queue_progress_html'));
        $status->setAccessible(true);
        $this->assertSame('Completed', $status->invoke($logs, ['id' => 999999, 'status' => 'processed']), 'A stored "processed" reads Completed.');
        $this->assertSame('Queued', $status->invoke($logs, ['id' => 999999, 'status' => 'queued']));
    }

    public function test_the_emails_view_hook_does_nothing_on_another_page()
    {
        // View hooks are shared by every MailOptin page; "view=add-new" typed on Optin Campaigns used to
        // reach the Emails callback before that page had set itself up, and crash.
        $_GET = ['page' => MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_SLUG, 'view' => 'add-new'];
        $emails = new \MailOptin\Core\Admin\SettingsPage\EmailCampaigns();

        ob_start();
        $emails->settings_admin_page_callback();
        $this->assertSame('', ob_get_clean());
        $_GET = [];
    }
}
