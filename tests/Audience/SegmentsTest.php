<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\Subscriber_Filters;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\EmailCampaignRepository;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SegmentsRepository as Segments;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use MailOptin\MailOptinConnect\CampaignAudience;
use MailOptin\MailOptinConnect\Connect;
use WP_UnitTestCase;

/**
 * Saved segments without premium: they can be stored, but a campaign never sends with one, and
 * never falls back to the whole list either.
 */
class SegmentsTest extends WP_UnitTestCase
{
    protected $list_id;

    public function set_up()
    {
        parent::set_up();

        DBUpdates::get_instance()->update_routine_17();
        DBUpdates::get_instance()->update_routine_20();
        DBUpdates::get_instance()->update_routine_21();
        update_option('mo_db_ver', DBUpdates::DB_VER);

        Fields::flush_cache();

        // tests/bootstrap.php boots the plugin after plugins_loaded, so set up what sending needs.
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        if (is_null(\MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process)) {
            \MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process = new \MailOptin\Core\AsyncHandler\BGProcess();
        }
        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        $this->list_id = ListsRepository::create(['name' => 'Newsletter']);
    }

    public function tear_down()
    {
        Fields::flush_cache();
        delete_option(MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME);

        parent::tear_down();
    }

    protected function member($email, $tags = [], $membership = Subscribers::STATUS_SUBSCRIBED)
    {
        $id = Subscribers::create(['email' => $email, 'status' => $membership]);
        Subscribers::set_membership($id, $this->list_id, $membership);
        if ($tags) Tags::add_to_subscribers(Tags::ensure($tags), [$id]);

        return $id;
    }

    protected function campaign($settings = [])
    {
        $id = EmailCampaignRepository::add_email_campaign('Spring sale', EmailCampaignRepository::NEWSLETTER, 'Lucid');

        update_option(MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME, [$id => $settings + ['connection_service' => 'MailOptinConnect', 'connection_email_list' => $this->list_id]]);

        return $id;
    }

    protected function recipients($filters)
    {
        return wp_list_pluck(Subscribers::get_list_recipients($this->list_id, 0, 100, $filters), 'email');
    }

    public function test_segment_needs_a_unique_name_and_a_filter()
    {
        $vip = Tags::create('vip');

        $this->assertSame('mo_segment_name', Segments::create('  ', ['tag_ids' => [$vip]])->get_error_code());
        $this->assertSame('mo_segment_empty', Segments::create('Nobody', [])->get_error_code());

        $id = Segments::create('VIPs', ['tag_ids' => [$vip], 'tag_match' => 'bogus']);
        $this->assertIsInt($id);
        $this->assertSame('any', Segments::get($id)['filters']['tag_match']);
        $this->assertSame('mo_segment_exists', Segments::create('vips', ['tag_ids' => [$vip]])->get_error_code());

        $this->assertTrue(Segments::update($id, 'VIP customers'));
        $this->assertSame([$vip], Segments::get($id)['filters']['tag_ids'], 'Renaming keeps the filters.');
    }

    public function test_list_recipients_apply_tag_field_and_exclude_filters()
    {
        $country = Fields::get(Fields::create(['type' => Fields::TYPE_DROPDOWN, 'label' => 'Country', 'options' => ['UK', 'US']]));

        $a = $this->member('a@example.com', ['customer']);
        $this->member('b@example.com', ['customer', 'churned']);
        $this->member('c@example.com');
        $this->member('pending@example.com', ['customer'], Subscribers::STATUS_PENDING);
        Fields::set_values($a, ['country' => 'UK']);

        $customer = Tags::get_by_name('customer')['id'];
        $churned  = Tags::get_by_name('churned')['id'];

        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $this->recipients([]));
        $this->assertSame(['a@example.com', 'b@example.com'], $this->recipients(['tag_ids' => [$customer]]), 'Pending members never receive campaigns.');
        $this->assertSame(['a@example.com'], $this->recipients(['tag_ids' => [$customer], 'exclude_tag_ids' => [$churned]]));
        $this->assertSame(['a@example.com'], $this->recipients(['field_conditions' => [['field_id' => $country['id'], 'op' => 'is', 'value' => ['UK'], 'value2' => '']]]));
        $this->assertSame(1, Subscribers::count_list_recipients($this->list_id, ['tag_ids' => [$customer], 'exclude_tag_ids' => [$churned]]));
    }

    public function test_deleted_tags_and_fields_make_a_segment_unusable_instead_of_wider()
    {
        $vip   = Tags::create('vip');
        $field = Fields::create(['type' => Fields::TYPE_DROPDOWN, 'label' => 'Plan', 'options' => ['Gold', 'Free']]);

        $by_tag   = Segments::create('VIP', ['tag_ids' => [$vip]]);
        $by_field = Segments::create('Gold', ['field_conditions' => [['field_id' => $field, 'op' => 'is', 'value' => ['Gold'], 'value2' => '']]]);

        $this->assertSame([], Segments::problems(Segments::get($by_tag)));
        $this->assertSame([], Segments::problems(Segments::get($by_field)));

        Tags::delete($vip);
        Fields::update($field, ['options' => "Free"]);

        $this->assertNotEmpty(Segments::problems(Segments::get($by_tag)));
        $this->assertSame([$vip], Segments::get($by_tag)['filters']['tag_ids'], 'The deleted tag is kept, not dropped.');
        $this->assertNotEmpty(Segments::problems(Segments::get($by_field)), 'A removed dropdown option would otherwise drop the condition.');

        // The Segments tab still shows them.
        $this->assertStringContainsString('(deleted tag)', Subscriber_Filters::chips_html(Segments::get($by_tag)['filters'], null));
        Fields::delete($field);
        Fields::flush_cache();
        $this->assertStringContainsString('Deleted or changed field', Subscriber_Filters::chips_html(Segments::get($by_field)['filters'], null));
    }

    public function test_merging_a_tag_updates_segments_and_campaign_exclusions()
    {
        $old  = Tags::create('old');
        $new  = Tags::create('new');
        $gone = Tags::create('gone');

        $segment  = Segments::create('Old', ['tag_ids' => [$old, $new]]);
        $campaign = $this->campaign([Segments::EXCLUDE_TAGS_SETTING => "$old,$gone"]);

        Tags::merge($old, $new);

        $this->assertSame([$new], Segments::get($segment)['filters']['tag_ids']);
        $this->assertSame([$gone, $new], Segments::excluded_tag_ids($campaign));

        Tags::delete($gone);

        $this->assertSame([$new], Segments::excluded_tag_ids($campaign));
    }

    public function test_used_by_lists_campaigns_with_the_segment()
    {
        $segment  = Segments::create('VIP', ['tag_ids' => [Tags::create('vip')]]);
        $campaign = $this->campaign([Segments::SEGMENT_SETTING => (string)$segment]);

        $this->assertSame([$campaign => 'Spring sale'], Segments::used_by($segment));
        $this->assertSame([], Segments::used_by($segment + 1));
    }

    public function test_campaign_with_a_segment_does_not_send_without_premium()
    {
        $this->member('a@example.com', ['vip']);
        $this->member('b@example.com');

        $segment     = Segments::create('VIP', ['tag_ids' => [Tags::get_by_name('vip')['id']]]);
        $campaign_id = $this->campaign([Segments::SEGMENT_SETTING => (string)$segment]);

        $this->assertSame('mo_segment_premium', Segments::campaign_filters($campaign_id)->get_error_code());

        $result = Connect::get_instance()->send_newsletter($campaign_id, 9001, 'Hi', '<p>Hi</p>', 'Hi');

        $this->assertFalse($result['success']);
        $this->assertSame(0, array_sum(SendQueueRepository::counts(9001)), 'Nobody is queued, rather than the whole list.');
    }

    public function test_campaign_without_audience_settings_sends_to_the_list()
    {
        $this->member('a@example.com');

        $campaign_id = $this->campaign();

        $this->assertSame([], Segments::campaign_filters($campaign_id));
        $this->assertTrue(Connect::get_instance()->send_newsletter($campaign_id, 9002, 'Hi', '<p>Hi</p>', 'Hi')['success']);
        $this->assertSame(1, array_sum(SendQueueRepository::counts(9002)));
    }

    protected function control_names($campaign_id)
    {
        global $wp_customize;

        require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
        $wp_customize = new \WP_Customize_Manager();

        $customizer = (object)['campaign_settings_section_id' => 'campaign_settings', 'email_campaign_id' => $campaign_id];

        return array_keys((new CampaignAudience())->controls([], $wp_customize, 'mo_email_campaigns[' . $campaign_id . ']', $customizer));
    }

    public function test_customizer_shows_an_upsell_without_premium()
    {
        Tags::create('vip');

        $campaign_id = $this->campaign();
        $controls    = $this->control_names($campaign_id);

        $this->assertSame([Segments::SEGMENT_SETTING], $controls);
    }

    public function test_customizer_keeps_the_controls_while_a_segment_is_set_so_it_can_be_cleared()
    {
        $vip         = Tags::create('vip');
        $segment     = Segments::create('VIP', ['tag_ids' => [$vip]]);
        $campaign_id = $this->campaign([Segments::SEGMENT_SETTING => (string)$segment]);

        $this->assertSame([Segments::SEGMENT_SETTING, Segments::EXCLUDE_TAGS_SETTING, 'MailOptinConnect_audience_count'], $this->control_names($campaign_id));
    }
}
