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
        parse_str((string)wp_parse_url(Tracking::click_url($row['id'], $link_id), PHP_URL_QUERY), $query);

        return $query['mo_click'];
    }

    protected function open_token($row)
    {
        parse_str((string)wp_parse_url(Tracking::open_url($row['id']), PHP_URL_QUERY), $query);

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

        $this->assertSame(['opens' => 2, 'openers' => 2, 'clicks' => 3, 'clickers' => 2], Activity::stats($this->log_id), 'A click counts as an open for people whose images were blocked.');

        $link_row = Activity::get_link($link);
        $this->assertSame(['3', '2'], [$link_row['clicks'], $link_row['people']]);

        $this->assertSame(['clicked' => 2, 'opened_not_clicked' => 0, 'not_opened' => 0], Activity::engagement_counts($this->log_id));

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

    public function test_prune_deletes_old_activity_and_keeps_totals()
    {
        global $wpdb;

        $a = $this->sent_row('a@example.com');
        Tracking::open_token($this->open_token($a));

        $old = gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare('UPDATE ' . Activity::table() . ' SET first_at = %s, last_at = %s', $old, $old));
        $wpdb->query($wpdb->prepare('UPDATE ' . Subscribers::events_table() . ' SET created_at = %s WHERE type = %s', $old, Subscribers::EVENT_OPENED));

        Tracking::get_instance()->prune();

        $this->assertSame(0, Activity::subscriber_summary($a['recipient_id'])['opened']);
        $this->assertNotContains(Subscribers::EVENT_OPENED, wp_list_pluck(Subscribers::get_events($a['recipient_id']), 'type'));
        $this->assertSame(1, Activity::stats($this->log_id)['openers']);

        // Forever keeps it.
        Tracking::open_token($this->open_token($a));
        $wpdb->query($wpdb->prepare('UPDATE ' . Activity::table() . ' SET last_at = %s', $old));
        $this->settings([Tracking::RETENTION_SETTING => '0']);
        Tracking::get_instance()->prune();
        $this->assertSame(1, Activity::subscriber_summary($a['recipient_id'])['opened']);
    }
}
