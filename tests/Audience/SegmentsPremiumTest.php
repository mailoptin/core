<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\Subscribers_Segments;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\SegmentsRepository as Segments;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use MailOptin\MailOptinConnect\Connect;

/**
 * Segments with premium. Each test runs in its own process because the premium constant can't be
 * undefined afterwards.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SegmentsPremiumTest extends SegmentsTest
{
    public function set_up()
    {
        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) define('MAILOPTIN_DETACH_LIBSODIUM', true);

        parent::set_up();
    }

    protected function queued_emails($campaign_log_id)
    {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare('SELECT email FROM ' . SendQueueRepository::table() . ' WHERE campaign_log_id = %d ORDER BY email', $campaign_log_id));
    }

    // Covered by the parent class without the constant.
    public function test_campaign_with_a_segment_does_not_send_without_premium()
    {
        $this->assertTrue(Segments::is_available());
    }

    public function test_customizer_shows_an_upsell_without_premium()
    {
        Tags::create('vip');

        $this->assertSame([Segments::SEGMENT_SETTING, Segments::EXCLUDE_TAGS_SETTING, 'MailOptinConnect_audience_count'], $this->control_names($this->campaign()));
    }

    public function test_campaign_sends_to_the_segment_minus_excluded_tags_as_it_is_at_send_time()
    {
        $this->member('a@example.com', ['customer']);
        $this->member('b@example.com', ['customer', 'churned']);
        $this->member('c@example.com');

        $customer = Tags::get_by_name('customer')['id'];
        $churned  = Tags::get_by_name('churned')['id'];
        $segment  = Segments::create('Customers', ['tag_ids' => [$customer]]);

        $campaign_id = $this->campaign([Segments::SEGMENT_SETTING => (string)$segment, Segments::EXCLUDE_TAGS_SETTING => (string)$churned]);

        // Someone who matches after the segment was chosen is included.
        $this->member('d@example.com', ['customer']);

        $this->assertTrue(Connect::get_instance()->send_newsletter($campaign_id, 9101, 'Hi', '<p>Hi</p>', 'Hi')['success']);
        $this->assertSame(['a@example.com', 'd@example.com'], $this->queued_emails(9101));
    }

    public function test_deleted_or_broken_segment_stops_the_campaign()
    {
        $this->member('a@example.com', ['vip']);
        $vip = Tags::get_by_name('vip')['id'];

        $segment     = Segments::create('VIP', ['tag_ids' => [$vip]]);
        $campaign_id = $this->campaign([Segments::SEGMENT_SETTING => (string)$segment]);

        Tags::delete($vip);
        $this->assertSame('mo_segment_broken', Segments::campaign_filters($campaign_id)->get_error_code());

        Segments::delete($segment);
        $this->assertSame('mo_segment_missing', Segments::campaign_filters($campaign_id)->get_error_code());

        $this->assertFalse(Connect::get_instance()->send_newsletter($campaign_id, 9102, 'Hi', '<p>Hi</p>', 'Hi')['success']);
        $this->assertSame([], $this->queued_emails(9102));
    }

    public function test_saving_the_list_filters_as_a_segment()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $vip   = Tags::create('vip');
        $field = Fields::create(['type' => Fields::TYPE_TEXT, 'label' => 'City']);

        $_POST = $_REQUEST = [
            '_wpnonce'  => wp_create_nonce('mo_save_segment'),
            'name'      => 'London VIPs',
            'tags'      => [$vip],
            'tag_match' => 'all',
            'f'         => [['field' => $field, 'op' => 'contains', 'value' => 'Lon\\\'don']],
        ];

        $this->assertSame('segment_saved', Subscribers_Segments::handle_action('save_segment')[0]);

        $segment = Segments::get_by_name('London VIPs');
        $this->assertSame([$vip], $segment['filters']['tag_ids']);
        $this->assertSame('all', $segment['filters']['tag_match']);
        $this->assertSame("Lon'don", $segment['filters']['field_conditions'][0]['value'], 'Slashes are removed once.');

        $_POST = $_REQUEST = ['_wpnonce' => wp_create_nonce('mo_save_segment'), 'name' => 'Nothing'];
        $this->assertSame('error', Subscribers_Segments::handle_action('save_segment')[0]);
        $this->assertNull(Segments::get_by_name('Nothing'));

        $_POST = $_REQUEST = [];
    }

    public function test_segment_in_use_cannot_be_deleted()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $segment = Segments::create('VIP', ['tag_ids' => [Tags::create('vip')]]);
        $this->campaign([Segments::SEGMENT_SETTING => (string)$segment]);

        $_GET = $_REQUEST = ['segment' => $segment, '_wpnonce' => wp_create_nonce('mo_delete_segment_' . $segment)];

        $this->assertSame('error', Subscribers_Segments::handle_action('delete_segment')[0]);
        $this->assertNotNull(Segments::get($segment));

        $_GET = $_REQUEST = [];
    }
}
