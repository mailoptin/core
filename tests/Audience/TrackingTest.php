<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Audience\Tracking;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\CampaignActivityRepository as Activity;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use WP_UnitTestCase;

class TrackingTest extends WP_UnitTestCase
{
    protected $log_id;

    public function set_up()
    {
        parent::set_up();

        foreach ([17, 20, 21, 22] as $version) DBUpdates::get_instance()->{'update_routine_' . $version}();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        delete_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME);
        Activity::flush_cache();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();

        global $wpdb;
        $wpdb->insert($wpdb->prefix . \MailOptin\Core\Core::campaign_log_table_name, [
            'email_campaign_id' => 1, 'title' => 'October planting notes', 'content_html' => '<p>Hi</p>', 'content_text' => 'Hi', 'status' => 'processed', 'status_time' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->log_id = (int)$wpdb->insert_id;
    }

    protected function sent_row($email)
    {
        $subscriber_id = Subscribers::create(['email' => $email, 'status' => Subscribers::STATUS_SUBSCRIBED]);

        SendQueueRepository::enqueue($this->log_id, 1, [['email' => $email, 'recipient_type' => 'subscriber', 'recipient_id' => $subscriber_id]]);

        global $wpdb;
        $table = SendQueueRepository::table();
        $wpdb->query($wpdb->prepare("UPDATE $table SET status = 'sent', sent_at = %s WHERE email = %s", gmdate('Y-m-d H:i:s'), $email));

        // As if its email went out with tracking on, as prepare_html() marks it.
        Activity::mark_tracked($this->log_id, Activity::METRIC_OPEN_TRACKED);
        Activity::mark_tracked($this->log_id, Activity::METRIC_CLICK_TRACKED);

        return SendQueueRepository::get($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE email = %s", $email)));
    }

    protected function settings($values)
    {
        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, $values);
    }

    public function test_links_are_rewritten_except_merge_tag_and_non_web_links()
    {
        $row  = $this->sent_row('a@example.com');
        $html = '<html><body><a href="https://shop.example.com/bulbs?a=1&amp;b=2">Shop <b>bulbs</b></a>
            <a href="{{unsubscribe}}">Unsubscribe</a> <a href="mailto:hi@example.com">Mail</a> <a href="#top">Top</a>
            <a class="btn" href=\'https://example.com/guide\'><img src="x.png" alt="The guide"></a>
            <a href="https://example.com/?e={{subscriber.email}}">Personal</a></body></html>';

        $out = Tracking::prepare_html($html, $row, $this->log_id);

        $this->assertSame(2, substr_count($out, 'mo_click='));
        $this->assertStringContainsString('href="{{unsubscribe}}"', $out);
        $this->assertStringContainsString('href="mailto:hi@example.com"', $out);
        $this->assertStringContainsString('href="#top"', $out);
        $this->assertStringContainsString('e={{subscriber.email}}', $out, 'Links with merge tags are left alone.');
        $this->assertSame(1, substr_count($out, 'mo_open='));
        $this->assertMatchesRegularExpression('#<img src="[^"]*mo_open=[^"]*"[^>]*/></body>#', $out);

        $links = wp_list_pluck(Activity::links($this->log_id), 'label', 'url');
        $this->assertSame(['https://shop.example.com/bulbs?a=1&b=2' => 'Shop bulbs', 'https://example.com/guide' => 'The guide'], $links);
    }

    public function test_tracking_can_be_turned_off()
    {
        $this->settings([Tracking::OPENS_SETTING => 'off', Tracking::CLICKS_SETTING => 'off']);

        $html = '<a href="https://example.com/">Go</a>';

        $this->assertSame($html, Tracking::prepare_html($html, $this->sent_row('a@example.com'), $this->log_id));
    }

    protected function click_token($row, $link_id)
    {
        parse_str((string)wp_parse_url(Tracking::click_url($row, $link_id), PHP_URL_QUERY), $query);

        return $query['mo_click'];
    }

    protected function open_token($row)
    {
        parse_str((string)wp_parse_url(Tracking::open_url($row), PHP_URL_QUERY), $query);

        return $query['mo_open'];
    }

    public function test_opens_and_clicks_are_counted_once_per_person_and_in_total()
    {
        $a    = $this->sent_row('a@example.com');
        $b    = $this->sent_row('b@example.com');
        $link = Activity::link_id($this->log_id, 'https://example.com/guide', 'Guide');

        $this->assertTrue(Tracking::open_token($this->open_token($a)));
        $this->assertTrue(Tracking::open_token($this->open_token($a)));
        $this->assertSame('https://example.com/guide', Tracking::click_token($this->click_token($a, $link))['url']);
        Tracking::click_token($this->click_token($a, $link));
        Tracking::click_token($this->click_token($b, $link));

        $this->assertSame(['sent' => 0, 'opens' => 2, 'openers' => 2, 'clicks' => 3, 'clickers' => 2, 'pruned' => 0, 'open_tracked' => 1, 'click_tracked' => 1], Activity::stats($this->log_id), 'A click counts as an open for people whose images were blocked.');

        $link_row = Activity::get_link($link);
        $this->assertSame(['3', '2'], [$link_row['clicks'], $link_row['people']]);

        $this->assertSame(['clicked' => 2, 'opened_not_clicked' => 0, 'not_opened' => 0, 'unsubscribed' => 0], Activity::engagement_counts($this->log_id));

        $types = wp_list_pluck(Subscribers::get_events($a['recipient_id']), 'type');
        $this->assertSame(1, count(array_keys($types, Subscribers::EVENT_OPENED)));
        $this->assertSame(1, count(array_keys($types, Subscribers::EVENT_CLICKED)));
    }

    public function test_forged_or_mismatched_tokens_are_refused()
    {
        $a     = $this->sent_row('a@example.com');
        $link  = Activity::link_id($this->log_id, 'https://example.com/guide', 'Guide');
        $other = Activity::link_id($this->log_id + 1, 'https://evil.example/', 'Other campaign');

        $this->assertFalse(Tracking::open_token($a['id'] . '.0000000000000000'));
        $this->assertNull(Tracking::click_token($a['id'] . '.' . $link . '.0000000000000000'));
        $this->assertNull(Tracking::click_token($this->click_token($a, $other)), 'A link from another campaign is refused, even signed.');
        $this->assertNull(Tracking::click_token('garbage'));

        $this->assertSame(0, Activity::stats($this->log_id)['clicks']);
    }

    public function test_links_keep_working_but_stop_counting_when_tracking_is_off_or_subscriber_deleted()
    {
        $a    = $this->sent_row('a@example.com');
        $link = Activity::link_id($this->log_id, 'https://example.com/guide', 'Guide');

        $this->settings([Tracking::CLICKS_SETTING => 'off']);
        $this->assertNotNull(Tracking::click_token($this->click_token($a, $link)));
        $this->assertSame(0, Activity::stats($this->log_id)['clicks']);

        $this->settings([]);
        Subscribers::delete($a['recipient_id']);
        $this->assertNotNull(Tracking::click_token($this->click_token($a, $link)));
        $this->assertSame(0, Activity::stats($this->log_id)['clicks']);
    }

    public function test_deleting_a_subscriber_keeps_totals_but_not_their_activity()
    {
        $a = $this->sent_row('a@example.com');
        Tracking::open_token($this->open_token($a));

        Subscribers::delete($a['recipient_id']);

        $this->assertSame(1, Activity::stats($this->log_id)['openers']);
        $this->assertSame(0, Activity::subscriber_summary($a['recipient_id'])['opened']);
    }

    public function test_prune_deletes_old_campaigns_activity_and_keeps_totals()
    {
        global $wpdb;

        $a = $this->sent_row('a@example.com');
        Tracking::open_token($this->open_token($a));

        $old = gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare('UPDATE ' . Activity::table() . ' SET first_at = %s, last_at = %s', $old, $old));

        // Forever keeps it.
        $this->settings([Tracking::RETENTION_SETTING => '0']);
        Tracking::get_instance()->prune();
        $this->assertSame(1, Activity::subscriber_summary($a['recipient_id'])['opened']);

        $this->settings([]);
        Tracking::get_instance()->prune();

        $this->assertSame(0, Activity::subscriber_summary($a['recipient_id'])['opened']);
        $this->assertNotContains(Subscribers::EVENT_OPENED, wp_list_pluck(Subscribers::get_events($a['recipient_id']), 'type'));
        $this->assertSame(1, Activity::stats($this->log_id)['openers']);
        $this->assertTrue(Activity::is_pruned($this->log_id));

        // Opening again later doesn't count the same person twice.
        Tracking::open_token($this->open_token($a));
        $this->assertSame(1, Activity::stats($this->log_id)['openers']);

        // Nor does everyone it was sent to now look like they didn't open it.
        $ids = $wpdb->get_col('SELECT s.id FROM ' . Subscribers::table() . ' s WHERE ' . Activity::condition_sql(['what' => 'not_opened', 'campaign' => 0, 'days' => 0]));
        $this->assertSame([], $ids);
    }

    public function test_links_are_matched_exactly_and_trimmed()
    {
        $row = $this->sent_row('a@example.com');
        $out = Tracking::prepare_html('<a data-href="https://other.example/" href=" https://example.com/real ">Real</a>', $row, $this->log_id);

        $this->assertStringContainsString('data-href="https://other.example/"', $out, 'Only the real href is rewritten.');
        $this->assertStringContainsString('mo_click=', $out);
        $this->assertSame(['https://example.com/real'], wp_list_pluck(Activity::links($this->log_id), 'url'));
    }

    public function test_links_keep_working_after_the_campaign_log_is_deleted()
    {
        $a    = $this->sent_row('a@example.com');
        $link = Activity::link_id($this->log_id, 'https://example.com/a|b?q=O\'Reilly', 'Guide');
        Tracking::open_token($this->open_token($a));

        (new \MailOptin\Core\Admin\SettingsPage\Campaign_Log_List($GLOBALS['wpdb']))->delete_a_campaign_log($this->log_id);

        $this->assertSame('https://example.com/a|b?q=O\'Reilly', Tracking::click_token($this->click_token($a, $link))['url'], 'Stored as written, for the redirect.');
        $this->assertSame(0, Activity::stats($this->log_id)['clicks']);
        $this->assertSame(0, Activity::subscriber_summary($a['recipient_id'])['opened']);
    }

    public function test_clicks_dont_count_as_opens_when_opens_are_not_tracked()
    {
        $this->settings([Tracking::OPENS_SETTING => 'off']);

        $a    = $this->sent_row('a@example.com');
        $link = Activity::link_id($this->log_id, 'https://example.com/', 'Home');
        Tracking::click_token($this->click_token($a, $link));

        $stats = Activity::stats($this->log_id);
        $this->assertSame([0, 1], [$stats['openers'], $stats['clickers']]);
    }

    public function test_deleting_all_logs_removes_tracking_activity()
    {
        $a = $this->sent_row('a@example.com');
        Tracking::open_token($this->open_token($a));

        (new \MailOptin\Core\Admin\SettingsPage\Campaign_Log_List($GLOBALS['wpdb']))->delete_all_campaign_log();

        $this->assertSame(0, Activity::subscriber_summary($a['recipient_id'])['opened']);
        $this->assertSame(0, Activity::stats($this->log_id)['openers']);
    }

    public function test_a_token_for_a_reused_queue_row_is_not_credited_to_its_new_owner()
    {
        global $wpdb;

        $a     = $this->sent_row('a@example.com');
        $token = $this->open_token($a);
        $b     = Subscribers::create(['email' => 'b@example.com', 'status' => Subscribers::STATUS_SUBSCRIBED]);

        // The row ID now belongs to someone else, as after the queue is emptied and IDs restart.
        $wpdb->update(SendQueueRepository::table(), ['recipient_id' => $b, 'email' => 'b@example.com'], ['id' => $a['id']]);

        Tracking::open_token($token);

        $this->assertSame(0, Activity::stats($this->log_id)['openers']);
    }

    public function test_welcome_emails_keep_counting_after_old_activity_is_deleted()
    {
        global $wpdb;

        \MailOptin\Core\Repositories\AbstractCampaignLogMeta::update_campaignlog_meta($this->log_id, 'mo_welcome_list_id', 1);

        $a = $this->sent_row('a@example.com');
        Tracking::open_token($this->open_token($a));

        $old = gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare('UPDATE ' . Activity::table() . ' SET first_at = %s, last_at = %s', $old, $old));

        Tracking::get_instance()->prune();

        $this->assertFalse(Activity::is_pruned($this->log_id));
        $this->assertSame(0, Activity::subscriber_summary($a['recipient_id'])['opened']);

        $b = $this->sent_row('b@example.com');
        Tracking::open_token($this->open_token($b));
        $this->assertSame(2, Activity::stats($this->log_id)['openers'], 'New opens still count.');
    }
}
