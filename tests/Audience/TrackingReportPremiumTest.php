<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\Campaign_Report;
use MailOptin\Core\Admin\SettingsPage\Subscriber_Filters;
use MailOptin\Core\Repositories\SegmentsRepository as Segments;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;

/**
 * Reports and engagement filters with premium. Each test runs in its own process because the
 * premium constant can't be undefined afterwards.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TrackingReportPremiumTest extends TrackingReportTest
{
    public function set_up()
    {
        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) define('MAILOPTIN_DETACH_LIBSODIUM', true);

        parent::set_up();
    }

    public function test_engagement_filters_need_premium()
    {
        list($clicker) = $this->engaged();

        $filters = Subscriber_Filters::from_request(['e' => [['what' => 'clicked', 'campaign' => (string)$this->log_id, 'days' => '0'], ['what' => 'nope']]]);

        $this->assertSame([['what' => 'clicked', 'campaign' => $this->log_id, 'days' => 0]], $filters['engagement']);
        $this->assertSame([$clicker], array_map('intval', wp_list_pluck(Subscribers::query($filters)['items'], 'id')));

        // Round trip through the URL, as the list, export and segments use it.
        $this->assertSame($filters, Subscriber_Filters::from_request(Subscriber_Filters::to_query($filters)));
        $this->assertStringContainsString('Clicked', Subscriber_Filters::chips_html($filters, null));
    }

    public function test_report_hides_who_opened_without_premium()
    {
        $this->engaged();

        $_GET = ['report' => $this->log_id];
        $this->assertStringContainsString('people=clicked', Campaign_Report::html());

        $_GET = ['report' => $this->log_id, 'people' => 'not_opened'];
        $html = Campaign_Report::html();
        $this->assertStringContainsString('quiet@example.com', $html);
        $this->assertStringContainsString('Opens are an estimate', $html, 'The didn\'t-open view warns about accuracy.');

        $_GET = [];
    }

    public function test_engagement_segment_is_applied_when_sending()
    {
        list($clicker, $opener) = $this->engaged();

        $segment = Segments::create('Clicked the guide', ['engagement' => [['what' => 'clicked', 'campaign' => $this->log_id, 'days' => 0]]]);

        $this->assertIsInt($segment);
        $this->assertSame([], Segments::problems(Segments::get($segment)));

        $list_id = \MailOptin\Core\Repositories\ListsRepository::create(['name' => 'All']);
        foreach ([$clicker, $opener] as $id) Subscribers::set_membership($id, $list_id, Subscribers::STATUS_SUBSCRIBED);

        $filters = Segments::filters_for($segment, []);
        $this->assertSame(['clicker@example.com'], wp_list_pluck(Subscribers::get_list_recipients($list_id, 0, 100, $filters), 'email'));
    }

    public function test_tag_everyone_in_a_group()
    {
        list($clicker, $opener) = $this->engaged();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $_POST = $_REQUEST = [
            'mo_report_action' => 'tag',
            'report'           => $this->log_id,
            'people'           => 'clicked',
            '_wpnonce'         => wp_create_nonce('mo_report_tag_' . $this->log_id),
            'tags'             => 'engaged, guide-readers',
        ];

        add_filter('wp_redirect', function () {
            throw new \RuntimeException('redirected');
        });

        try {
            Campaign_Report::get_instance()->handle_actions();
        } catch (\RuntimeException $e) {
            $this->assertSame('redirected', $e->getMessage());
        }

        $this->assertSame(['engaged', 'guide-readers'], array_values(Tags::for_subscriber($clicker)));
        $this->assertSame([], Tags::for_subscriber($opener));

        $_POST = $_REQUEST = [];
    }
}
