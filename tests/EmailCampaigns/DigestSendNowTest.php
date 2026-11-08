<?php

namespace MailOptin\Tests\Core\EmailCampaigns;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use MailOptin\Core\Admin\SettingsPage\Digest_Send_Page;
use MailOptin\Core\Admin\SettingsPage\Email_Campaign_List;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\EmailCampaigns\PostsEmailDigest\PostsEmailDigest;
use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use WP_UnitTestCase;

/**
 * Sending a Posts Digest by hand, and how that fits with its schedule.
 */
class DigestSendNowTest extends WP_UnitTestCase
{
    protected $list_id;
    protected $campaign_id;

    /** @var PostsEmailDigest */
    protected $digest;

    public function set_up()
    {
        parent::set_up();

        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) define('MAILOPTIN_DETACH_LIBSODIUM', true);
        add_filter('mailoptin_enable_post_email_digest', '__return_true');

        foreach ([17, 20, 21, 22] as $version) DBUpdates::get_instance()->{'update_routine_' . $version}();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        DBUpdates::get_instance()->update_routine_7();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        if (is_null(\MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process)) {
            \MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process = new \MailOptin\Core\AsyncHandler\BGProcess();
        }
        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        update_option('timezone_string', 'Europe/London');
        update_option('start_of_week', 1);
        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, ['from_name' => 'Acme Garden Supply', 'from_email' => 'hello@acmegarden.co']);

        $this->list_id     = ListsRepository::create(['name' => 'Newsletter']);
        $this->campaign_id = ER::add_email_campaign('Weekly roundup', ER::POSTS_EMAIL_DIGEST, 'Lucid');
        $this->digest      = PostsEmailDigest::get_instance();

        ER::update_settings_by_id($this->campaign_id, [
            'activate_email_campaign' => true,
            'email_campaign_subject'  => 'This week in the garden',
            'connection_service'      => 'MailOptinConnect',
            'connection_email_list'   => (string)$this->list_id,
            'custom_post_type'        => 'post',
            'item_number'             => 5,
            'schedule_interval'       => 'every_week',
            'schedule_day'            => Carbon::MONDAY,
            'schedule_time'           => 9,
        ]);

        // The last digest went out on Monday 3 November 2026, 9:00.
        EmailCampaignMeta::update_meta_data($this->campaign_id, 'last_processed_at', '2026-11-03 09:00:00');

        foreach (['maya@example.org', 'tom@example.org'] as $email) {
            $id = Subscribers::create(['email' => $email, 'status' => Subscribers::STATUS_SUBSCRIBED]);
            Subscribers::set_membership($id, $this->list_id, Subscribers::STATUS_SUBSCRIBED);
        }

        // Friday 7 November 2026, 15:00.
        $this->clock(CarbonImmutable::create(2026, 11, 7, 15, 0, 0, 'Europe/London'));
    }

    public function tear_down()
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        \MailOptin\Core\Repositories\CampaignSettingsRepository::delete_all(\MailOptin\Core\Repositories\CampaignSettingsRepository::TYPE_EMAIL);
        delete_option('mo_digest_send_lock_' . $this->campaign_id);
        $_POST = $_GET = $_REQUEST = [];

        parent::tear_down();
    }

    protected function clock(CarbonImmutable $now)
    {
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    protected function post($title, $date)
    {
        return self::factory()->post->create(['post_title' => $title, 'post_date' => $date, 'post_status' => 'publish']);
    }

    protected function queued()
    {
        global $wpdb;

        return (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . SendQueueRepository::table());
    }

    protected function logs()
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . \MailOptin\Core\Core::campaign_log_table_name . ' WHERE email_campaign_id = %d', $this->campaign_id), ARRAY_A);
    }

    protected function meta($key)
    {
        return EmailCampaignMeta::get_meta_data($this->campaign_id, $key);
    }

    public function test_sends_only_the_ticked_posts_to_the_list()
    {
        $tulips = $this->post('Planting tulips in November', '2026-11-07 10:00:00');
        $shrubs = $this->post('Five shrubs that survive frost', '2026-11-05 10:00:00');
        $closed = $this->post('Shop closed for stocktake', '2026-11-04 10:00:00');
        $this->post('An old post', '2026-10-01 10:00:00');

        $this->assertSame([$tulips, $shrubs, $closed], wp_list_pluck($this->digest->post_collection($this->campaign_id), 'ID'));

        $log_id = $this->digest->send_now($this->campaign_id, ['post_ids' => [$tulips, $shrubs]]);

        $this->assertIsInt($log_id);
        $logs = $this->logs();
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('Planting tulips in November', $logs[0]['content_html']);
        $this->assertStringContainsString('Five shrubs that survive frost', $logs[0]['content_html']);
        $this->assertStringNotContainsString('Shop closed for stocktake', $logs[0]['content_html']);
        $this->assertSame('This week in the garden', $logs[0]['title']);
        $this->assertSame(2, $this->queued());

        // The next digest starts after this one.
        $this->assertSame('2026-11-07 15:00:00', $this->meta('last_processed_at'));
        $this->assertSame([], $this->digest->post_collection($this->campaign_id));
    }

    public function test_posts_the_digest_wouldnt_send_are_refused()
    {
        $this->post('Planting tulips in November', '2026-11-07 10:00:00');
        $old = $this->post('An old post', '2026-10-01 10:00:00');
        $draft = self::factory()->post->create(['post_title' => 'Draft', 'post_status' => 'draft', 'post_date' => '2026-11-06 10:00:00']);

        $result = $this->digest->send_now($this->campaign_id, ['post_ids' => [$old, $draft]]);

        $this->assertWPError($result);
        $this->assertSame('mo_digest_no_posts', $result->get_error_code());
        $this->assertSame([], $this->logs());
        $this->assertSame(0, $this->queued());
    }

    public function test_the_latest_posts_can_be_sent_when_nothing_is_new()
    {
        $old = $this->post('An old post', '2026-10-01 10:00:00');

        $this->assertSame([], $this->digest->post_collection($this->campaign_id));
        $this->assertWPError($this->digest->send_now($this->campaign_id, ['post_ids' => [$old]]));

        $this->assertIsInt($this->digest->send_now($this->campaign_id, ['post_ids' => [$old], 'include_latest' => true]));
        $this->assertSame(2, $this->queued());
    }

    public function test_a_send_in_progress_blocks_another()
    {
        $tulips = $this->post('Planting tulips in November', '2026-11-07 10:00:00');

        $this->assertTrue($this->digest->lock($this->campaign_id));

        $result = $this->digest->send_now($this->campaign_id, ['post_ids' => [$tulips]]);
        $this->assertWPError($result);
        $this->assertSame('mo_digest_busy', $result->get_error_code());

        // The schedule waits too.
        $this->clock(CarbonImmutable::create(2026, 11, 9, 9, 10, 0, 'Europe/London'));
        $this->digest->run_job();
        $this->assertSame([], $this->logs());

        // A lock left by a send that died is taken over after ten minutes.
        update_option('mo_digest_send_lock_' . $this->campaign_id, time() - 11 * MINUTE_IN_SECONDS);
        $this->assertIsInt($this->digest->send_now($this->campaign_id, ['post_ids' => [$tulips]]));
        $this->assertFalse(get_option('mo_digest_send_lock_' . $this->campaign_id));
    }

    public function test_next_scheduled_send()
    {
        $this->assertSame('2026-11-09 09:00', $this->digest->next_scheduled_at($this->campaign_id)->format('Y-m-d H:i'));

        ER::update_settings_by_id($this->campaign_id, ['schedule_interval' => 'every_day', 'schedule_time' => 18]);
        $this->assertSame('2026-11-07 18:00', $this->digest->next_scheduled_at($this->campaign_id)->format('Y-m-d H:i'));

        // A slot that passed under an hour ago still counts: the hourly job can still send it.
        ER::update_settings_by_id($this->campaign_id, ['schedule_time' => 14]);
        $this->assertSame('2026-11-07 14:00', $this->digest->next_scheduled_at($this->campaign_id)->format('Y-m-d H:i'));

        // Unless it already went out.
        EmailCampaignMeta::update_meta_data($this->campaign_id, PostsEmailDigest::LAST_SCHEDULED_META, '2026-11-07 14:05:00');
        $this->assertSame('2026-11-08 14:00', $this->digest->next_scheduled_at($this->campaign_id)->format('Y-m-d H:i'));

        ER::update_settings_by_id($this->campaign_id, ['schedule_interval' => 'every_month', 'schedule_month_date' => 3, 'schedule_time' => 9]);
        $this->assertSame('2026-12-03 09:00', $this->digest->next_scheduled_at($this->campaign_id)->format('Y-m-d H:i'));

        ER::update_settings_by_id($this->campaign_id, ['schedule_interval' => PostsEmailDigest::SCHEDULE_MANUAL]);
        $this->assertNull($this->digest->next_scheduled_at($this->campaign_id));
        $this->assertTrue($this->digest->is_manual_only($this->campaign_id));
    }

    public function test_skipping_the_next_scheduled_digest()
    {
        $tulips = $this->post('Planting tulips in November', '2026-11-07 10:00:00');
        $this->digest->send_now($this->campaign_id, ['post_ids' => [$tulips], 'skip_next' => true]);
        $this->assertSame('2026-11-09 09:00:00', $this->meta(PostsEmailDigest::SKIP_UNTIL_META));

        // A post after it would have gone out on Monday: the hand-sent digest took Monday's place.
        $this->post('Pruning roses', '2026-11-07 16:00:00');
        $this->clock(CarbonImmutable::create(2026, 11, 9, 9, 10, 0, 'Europe/London'));
        $this->digest->run_job();
        $this->assertCount(1, $this->logs());

        // The week after, it goes out as usual, with the posts since the hand-sent one.
        $this->clock(CarbonImmutable::create(2026, 11, 16, 9, 10, 0, 'Europe/London'));
        $this->digest->run_job();
        $logs = $this->logs();
        $this->assertCount(2, $logs);
        $this->assertStringContainsString('Pruning roses', $logs[1]['content_html']);
        $this->assertStringNotContainsString('Planting tulips', $logs[1]['content_html']);
        $this->assertSame('', (string)$this->meta(PostsEmailDigest::SKIP_UNTIL_META));
    }

    public function test_keeping_the_next_scheduled_digest()
    {
        ER::update_settings_by_id($this->campaign_id, ['schedule_interval' => 'every_day', 'schedule_time' => 18]);
        // The schedule last sent it yesterday.
        EmailCampaignMeta::update_meta_data($this->campaign_id, 'last_processed_at', '2026-11-06 18:00:00');

        $tulips = $this->post('Planting tulips in November', '2026-11-07 10:00:00');
        $this->digest->send_now($this->campaign_id, ['post_ids' => [$tulips], 'skip_next' => false]);
        $this->assertSame('', (string)$this->meta(PostsEmailDigest::SKIP_UNTIL_META));

        // The schedule's own record is unchanged, so today's 18:00 digest still goes, with the newer post.
        $this->assertSame('2026-11-06 18:00:00', $this->meta(PostsEmailDigest::LAST_SCHEDULED_META));
        $this->post('Pruning roses', '2026-11-07 16:00:00');
        $this->clock(CarbonImmutable::create(2026, 11, 7, 18, 10, 0, 'Europe/London'));
        $this->digest->run_job();

        $logs = $this->logs();
        $this->assertCount(2, $logs);
        $this->assertStringContainsString('Pruning roses', $logs[1]['content_html']);
        $this->assertStringNotContainsString('Planting tulips', $logs[1]['content_html']);
        $this->assertSame('2026-11-07', substr($this->meta(PostsEmailDigest::LAST_SCHEDULED_META), 0, 10));

        // And not twice.
        $this->clock(CarbonImmutable::create(2026, 11, 7, 18, 40, 0, 'Europe/London'));
        $this->post('Bulb lasagne', '2026-11-07 18:20:00');
        $this->digest->run_job();
        $this->assertCount(2, $this->logs());
    }

    public function test_digests_from_before_this_still_use_their_last_send()
    {
        // No last_scheduled_at: the schedule's last send is last_processed_at, as it always was.
        ER::update_settings_by_id($this->campaign_id, ['schedule_interval' => 'every_day', 'schedule_time' => 9]);
        EmailCampaignMeta::update_meta_data($this->campaign_id, 'last_processed_at', '2026-11-07 09:00:00');
        $this->post('Planting tulips in November', '2026-11-07 10:00:00');

        $this->clock(CarbonImmutable::create(2026, 11, 7, 9, 30, 0, 'Europe/London'));
        $this->digest->run_job();
        $this->assertSame([], $this->logs());

        $this->assertSame('2026-11-08 09:00', $this->digest->next_scheduled_at($this->campaign_id)->format('Y-m-d H:i'));
    }

    public function test_manual_only_digests_are_never_sent_by_the_schedule()
    {
        ER::update_settings_by_id($this->campaign_id, ['schedule_interval' => PostsEmailDigest::SCHEDULE_MANUAL]);
        $tulips = $this->post('Planting tulips in November', '2026-11-07 10:00:00');

        foreach ([[2026, 11, 9, 9, 10], [2026, 11, 10, 0, 10], [2026, 12, 1, 9, 10]] as $t) {
            $this->clock(CarbonImmutable::create($t[0], $t[1], $t[2], $t[3], $t[4], 0, 'Europe/London'));
            $this->digest->run_job();
        }
        $this->assertSame([], $this->logs());

        $this->assertIsInt($this->digest->send_now($this->campaign_id, ['post_ids' => [$tulips]]));
        $this->assertCount(1, $this->logs());
    }

    public function test_the_emails_list_shows_send_now_and_the_next_send()
    {
        $this->post('Planting tulips in November', '2026-11-07 10:00:00');
        $item = ['id' => $this->campaign_id, 'name' => 'Weekly roundup', 'campaign_type' => ER::POSTS_EMAIL_DIGEST];
        $list = Email_Campaign_List::get_instance();

        $this->assertStringContainsString('view=send-digest', $list->column_name($item));
        $next = $list->column_next_send($item);
        $this->assertStringContainsString('1 post waiting', $next);
        $this->assertStringContainsString(wp_date(get_option('date_format'), CarbonImmutable::create(2026, 11, 9, 9, 0, 0, 'Europe/London')->getTimestamp()), $next);

        $other = ['id' => 999, 'name' => 'Alert', 'campaign_type' => ER::NEW_PUBLISH_POST];
        $this->assertStringNotContainsString('send-digest', $list->column_name($other));
        $this->assertSame('&mdash;', $list->column_next_send($other));

        // Without Premium there's no Send now.
        remove_filter('mailoptin_enable_post_email_digest', '__return_true');
        $this->assertStringNotContainsString('send-digest', $list->column_name($item));
    }

    public function test_send_page_form()
    {
        $tulips = $this->post('Planting tulips in November', '2026-11-07 10:00:00');
        $shrubs = $this->post('Five shrubs that survive frost', '2026-11-05 10:00:00');

        $_GET = $_REQUEST = ['page' => MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG, 'view' => Digest_Send_Page::VIEW, 'id' => $this->campaign_id];
        set_current_screen('dashboard');

        $html = Digest_Send_Page::html();
        $this->assertStringContainsString('Planting tulips in November', $html);
        $this->assertStringContainsString('Five shrubs that survive frost', $html);
        $this->assertStringContainsString('Send to 2 subscribers', $html);
        $this->assertStringContainsString('Newsletter · 2 subscribers', $html);
        $this->assertStringContainsString('name="skip_next" value="1" checked', $html);

        // A test email goes to one address and sends nothing to the list.
        $sent = [];
        add_filter('pre_wp_mail', function ($null, $atts) use (&$sent) {
            $sent[] = $atts;

            return true;
        }, 10, 2);
        $this->assertTrue(Digest_Send_Page::send_test($this->campaign_id, [get_post($shrubs)], 'maya@example.org'));
        $this->assertCount(1, $sent);
        $this->assertSame('[Test] This week in the garden', $sent[0]['subject']);
        $this->assertStringContainsString('Five shrubs', $sent[0]['message']);
        $this->assertStringNotContainsString('Planting tulips', $sent[0]['message']);
        $this->assertSame([], $this->logs());

        // Nothing new: the page offers the latest posts instead of a send button.
        $this->digest->send_now($this->campaign_id, ['post_ids' => [$tulips, $shrubs]]);
        $html = Digest_Send_Page::html();
        $this->assertStringContainsString('No posts have been published since the last digest', $html);
        $this->assertStringContainsString('latest=1', $html);
        $this->assertStringNotContainsString('value="send"', $html);
    }
}
