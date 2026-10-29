<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\Campaign_Report;
use MailOptin\Core\Admin\SettingsPage\Subscriber_Filters;
use MailOptin\Core\Audience\Tracking;
use MailOptin\Core\Repositories\CampaignActivityRepository as Activity;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Reports and engagement filters without premium.
 */
class TrackingReportTest extends TrackingTest
{
    protected function engaged()
    {
        $link = Activity::link_id($this->log_id, 'https://example.com/guide', 'Guide');

        $clicker = $this->sent_row('clicker@example.com');
        $opener  = $this->sent_row('opener@example.com');
        $quiet   = $this->sent_row('quiet@example.com');

        Tracking::click_token($this->click_token($clicker, $link));
        Tracking::open_token($this->open_token($opener));

        return [(int)$clicker['recipient_id'], (int)$opener['recipient_id'], (int)$quiet['recipient_id'], $link];
    }

    protected function people($group, $link_id = 0)
    {
        return wp_list_pluck(Activity::people($this->log_id, $group, $link_id)['items'], 'email');
    }

    public function test_people_groups_split_who_was_sent_the_campaign()
    {
        list(, , $quiet, $link) = $this->engaged();

        $this->assertSame(['clicker@example.com'], $this->people('clicked'));
        $this->assertSame(['clicker@example.com'], $this->people('clicked', $link));
        $this->assertSame(['opener@example.com'], $this->people('opened_not_clicked'));
        $this->assertSame(['quiet@example.com'], $this->people('not_opened'));
        $this->assertSame([], $this->people('unsubscribed'));
        $this->assertSame([], $this->people('bogus'));

        Subscribers::log_event($quiet, Subscribers::EVENT_UNSUBSCRIBED, [], $this->log_id);
        $this->assertSame(['quiet@example.com'], $this->people('unsubscribed'));
        $this->assertSame(1, Activity::unsubscribed_count($this->log_id));
    }

    public function test_engagement_filters_need_premium()
    {
        $this->engaged();

        $filters = Subscriber_Filters::from_request(['e' => [['what' => 'clicked', 'campaign' => $this->log_id, 'days' => 0]]]);

        $this->assertSame([], $filters['engagement']);
    }

    public function test_report_hides_who_opened_without_premium()
    {
        $this->engaged();

        $_GET = ['report' => $this->log_id];
        $html = Campaign_Report::html();

        $this->assertStringContainsString('Get MailOptin Premium', $html);
        $this->assertStringNotContainsString('people=clicked', $html);

        $_GET = ['report' => $this->log_id, 'people' => 'clicked'];
        $this->assertStringNotContainsString('clicker@example.com', Campaign_Report::html());

        $_GET = [];
    }

    public function test_engagement_condition_sql()
    {
        list($clicker, $opener, $quiet) = $this->engaged();

        $ids = function ($condition) {
            global $wpdb;

            return array_map('intval', $wpdb->get_col('SELECT s.id FROM ' . Subscribers::table() . ' s WHERE ' . Activity::condition_sql($condition) . ' ORDER BY s.id'));
        };

        $this->assertSame([$clicker, $opener], $ids(['what' => 'opened', 'campaign' => 0, 'days' => 90]));
        $this->assertSame([$clicker], $ids(['what' => 'clicked', 'campaign' => $this->log_id, 'days' => 0]));
        $this->assertSame([$quiet], $ids(['what' => 'not_opened', 'campaign' => $this->log_id, 'days' => 0]));
        $this->assertSame([$opener, $quiet], $ids(['what' => 'not_clicked', 'campaign' => 0, 'days' => 30]));
        $this->assertSame([], $ids(['what' => 'clicked', 'campaign' => $this->log_id + 1, 'days' => 0]));

        // Someone never sent anything didn't "not open" it.
        Subscribers::create(['email' => 'never@example.com', 'status' => Subscribers::STATUS_SUBSCRIBED]);
        $this->assertSame([$quiet], $ids(['what' => 'not_opened', 'campaign' => 0, 'days' => 0]));

        $this->assertNull(Activity::condition_sql(['what' => 'opened', 'campaign' => 0, 'days' => 7]), 'Only the offered periods.');
        $this->assertNull(Activity::condition_sql(['what' => 'drop table', 'campaign' => 0, 'days' => 0]));
    }

    public function test_group_counts_match_the_lists_after_a_subscriber_is_deleted()
    {
        list($clicker, $opener) = $this->engaged();

        Subscribers::delete($opener);

        $counts = Activity::engagement_counts($this->log_id);

        foreach (['clicked', 'opened_not_clicked', 'not_opened'] as $group) {
            $this->assertSame(Activity::people($this->log_id, $group)['total'], $counts[$group], $group);
        }

        $this->assertSame(1, $counts['not_opened'], 'The deleted opener is not counted as not opening.');
    }

    public function test_segments_cannot_use_didnt_open()
    {
        $result = \MailOptin\Core\Repositories\SegmentsRepository::create('Non-openers', ['engagement' => [['what' => 'not_opened', 'campaign' => $this->log_id, 'days' => 0]]]);

        $this->assertSame('mo_segment_not_opened', $result->get_error_code());
    }
}
