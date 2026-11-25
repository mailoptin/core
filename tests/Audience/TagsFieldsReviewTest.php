<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\Subscriber_Filters;
use MailOptin\Core\Admin\SettingsPage\Subscribers_Tags_Fields;
use MailOptin\Core\Audience\CampaignRenderer;
use MailOptin\Core\Audience\PublicEndpoints;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use MailOptin\MailOptinConnect\Connect;
use WP_UnitTestCase;

/**
 * Regression tests for defects found reviewing tags and custom fields.
 */
class TagsFieldsReviewTest extends WP_UnitTestCase
{
    protected $list;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        DBUpdates::get_instance()->update_routine_17();
        DBUpdates::get_instance()->update_routine_20();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        Fields::flush_cache();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->list = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 0]);
    }

    public function tear_down()
    {
        Fields::flush_cache();
        parent::tear_down();
    }

    protected function subscriber($email = 'a@example.com')
    {
        return Subscribers::create(['email' => $email, 'status' => Subscribers::STATUS_SUBSCRIBED]);
    }

    public function test_saving_a_profile_leaves_empty_checkbox_fields_empty()
    {
        $a  = $this->subscriber();
        $id = Fields::create(['type' => 'checkbox', 'label' => 'News']);

        // What the profile form posts for an unticked checkbox with no value yet.
        $html = Subscribers_Tags_Fields::profile_fields_html($a);
        $this->assertStringContainsString('name="fields[' . $id . ']" value=""', $html);

        Subscribers_Tags_Fields::save_profile($a, ['fields' => [$id => '']]);
        $this->assertSame([], Fields::get_values($a));

        // So a form signup can still fill it in.
        Fields::set_values($a, ['news' => 'yes'], true);
        $this->assertSame('1', Fields::get_values($a)[$id]['value_text']);

        // Once it has a value, unticking means No.
        $this->assertStringContainsString('name="fields[' . $id . ']" value="0"', Subscribers_Tags_Fields::profile_fields_html($a));
        Subscribers_Tags_Fields::save_profile($a, ['fields' => [$id => '0']]);
        $this->assertSame('0', Fields::get_values($a)[$id]['value_text']);
    }

    public function test_deleted_fields_show_their_fallback_in_emails()
    {
        $a  = $this->subscriber();
        $id = Fields::create(['type' => 'text', 'label' => 'Company']);
        Fields::set_values($a, ['company' => 'Acme']);
        Fields::delete($id);

        $subscriber = Subscribers::get($a);
        $this->assertSame('Hi friend, ', CampaignRenderer::replace_fallback_tags('Hi {{subscriber.company|friend}}, {{subscriber.company}}', $subscriber, true));
        $this->assertSame('{{subscriber.other_connection_tag}} x', CampaignRenderer::replace_fallback_tags('{{subscriber.other_connection_tag}} {{subscriber.typo|x}}', $subscriber, true));

        // Creating the key again uses its new value.
        Fields::create(['type' => 'text', 'label' => 'Company']);
        Fields::set_values($a, ['company' => 'Beta']);
        $this->assertSame('Beta', CampaignRenderer::replace_fallback_tags('{{subscriber.company|friend}}', $subscriber, true));
    }

    public function test_numeric_tag_names_are_names_not_ids()
    {
        $vip  = Tags::create('vip');
        $year = Tags::create('2024');
        $a    = $this->subscriber();

        Tags::add_to_subscribers(Tags::ensure([(string)$vip, '2024']), [$a]);

        $names = array_values(Tags::for_subscriber($a));
        $this->assertContains('2024', $names);
        $this->assertNotContains('vip', $names, 'Typing a number never applies the tag with that ID.');
        $this->assertSame([$year], [(int)Tags::get_by_name('2024')['id']]);
    }

    public function test_tag_names_and_options_cannot_contain_commas()
    {
        $this->assertSame('Paris France', Tags::get(Tags::create('Paris, France'))['name']);
        $this->assertSame(['Red Blue', 'Green'], Fields::sanitize_options("Red, Blue\nGreen"));
    }

    public function test_merging_or_deleting_a_tag_updates_forms_that_apply_it()
    {
        $old  = Tags::create('customers');
        $new  = Tags::create('customer');
        $gone = Tags::create('gone');

        $optin = OCR::add_optin_campaign('abcdefghij', 'Signup', 'BareMetal', 'inpost');
        $settings = OCR::get_settings();
        $settings[$optin]['integrations'] = wp_json_encode([
            ['connection_service' => 'MailOptinConnect', 'connection_email_list' => $this->list, 'MailOptinConnect_lead_tags' => [(string)$old, (string)$gone]],
            ['connection_service' => 'OtherConnect', 'OtherConnect_lead_tags' => [(string)$old]],
        ]);
        OCR::updateSettings($settings);

        Tags::merge($old, $new);
        Tags::delete($gone);

        $integrations = json_decode(OCR::get_settings()[$optin]['integrations'], true);
        $this->assertSame([(string)$new], $integrations[0]['MailOptinConnect_lead_tags']);
        $this->assertSame([(string)$old], $integrations[1]['OtherConnect_lead_tags'], 'Other connections\' tags are theirs.');
    }

    public function test_form_tag_setting_ids_are_applied_by_id()
    {
        $tag = Tags::create('2024');
        Tags::create((string)$tag); // a tag whose name is the other tag's ID

        Connect::get_instance()->subscribe('form@example.com', 'Form', $this->list, [
            'optin_campaign_id' => 0,
            'integration_data'  => ['MailOptinConnect_lead_tags' => [(string)$tag]],
        ]);

        $this->assertSame(['2024'], array_values(Tags::for_subscriber(Subscribers::get_by_email('form@example.com')['id'])));
    }

    public function test_malformed_filter_params_are_ignored_without_warnings()
    {
        $text = Fields::create(['type' => 'text', 'label' => 'Company']);
        $num  = Fields::create(['type' => 'number', 'label' => 'Orders']);

        $filters = Subscriber_Filters::from_request([
            'tags'      => [[1], 'x', '0'],
            'tag_match' => ['all'],
            'f'         => [
                ['field' => $text, 'op' => 'contains', 'value' => ['x']],
                ['field' => $num, 'op' => 'between', 'value' => '1', 'value2' => ['y']],
                ['field' => [$num], 'op' => 'gt', 'value' => '1'],
                'string',
            ],
        ]);

        $this->assertSame([], $filters['tag_ids']);
        $this->assertSame('any', $filters['tag_match']);
        $this->assertCount(1, $filters['field_conditions']);
        $this->assertSame('', $filters['field_conditions'][0]['value2']);

        Subscribers::query($filters);
        $this->assertNull(Fields::condition_sql(['field_id' => $text, 'op' => 'contains', 'value' => ['x']]));
    }

    public function test_a_value_whose_option_was_removed_does_not_block_saving()
    {
        $a  = $this->subscriber();
        $id = Fields::create(['type' => 'dropdown', 'label' => 'Plan', 'options' => ['A', 'B']]);
        $ms = Fields::create(['type' => 'multiselect', 'label' => 'Plants', 'options' => ['Roses', 'Veg']]);
        Fields::set_values($a, [$id => 'A', $ms => ['Roses', 'Veg']]);
        Fields::update($id, ['options' => "B\nC"]);
        Fields::update($ms, ['options' => "Veg"]);

        $this->assertSame([], Subscribers_Tags_Fields::save_profile($a, ['fields' => [$id => 'A', $ms => ['Roses', 'Veg']]]));
        $this->assertSame('A', Fields::get_values($a)[$id]['value_text']);

        Subscribers_Tags_Fields::save_profile($a, ['fields' => [$id => 'C']]);
        $this->assertSame('C', Fields::get_values($a)[$id]['value_text']);
    }

    public function test_text_is_stored_unescaped_and_escaped_once_on_output()
    {
        $a = $this->subscriber();
        Fields::create(['type' => 'text', 'label' => 'Company']);

        // As typed in the admin, and as it arrives from a form (through sanitize_text_field).
        Fields::set_values($a, ['company' => 'a < b & <b>c</b>']);
        $this->assertSame('a < b & c', Fields::merge_values($a)['company']);
        Fields::set_values($a, ['company' => sanitize_text_field('x < y & z')]);
        $this->assertSame('x < y & z', Fields::merge_values($a)['company']);

        $subscriber = Subscribers::get($a);
        $this->assertSame('x &lt; y &amp; z', CampaignRenderer::replace_fallback_tags('{{subscriber.company}}', $subscriber, true));
        $this->assertSame('x < y & z', CampaignRenderer::replace_fallback_tags('{{subscriber.company}}', $subscriber, false));
    }

    public function test_profile_ignores_tag_ids_that_no_longer_exist()
    {
        global $wpdb;

        $a   = $this->subscriber();
        $tag = Tags::create('vip');
        Tags::delete($tag);

        Subscribers_Tags_Fields::save_profile($a, ['tags_submitted' => '1', 'tag_ids' => [$tag, [1]], 'new_tags' => ['x']]);

        $this->assertSame('0', $wpdb->get_var("SELECT COUNT(*) FROM " . Tags::links_table()));
    }

    public function test_erasing_personal_data_removes_queued_emails()
    {
        global $wpdb;

        $this->subscriber('amara@example.com');
        $wpdb->insert($wpdb->prefix . 'mo_send_queue', ['campaign_log_id' => 1, 'email_campaign_id' => 1, 'email' => 'amara@example.com', 'next_attempt_at' => '2026-01-01 00:00:00']);
        $wpdb->insert($wpdb->prefix . 'mo_send_queue', ['campaign_log_id' => 1, 'email_campaign_id' => 1, 'email' => 'tom@example.com', 'next_attempt_at' => '2026-01-01 00:00:00']);

        \MailOptin\Core\Audience\PersonalData::erase('amara@example.com');

        $this->assertSame(['tom@example.com'], $wpdb->get_col("SELECT email FROM {$wpdb->prefix}mo_send_queue"));
    }

    public function test_web_version_blanks_deleted_fields_too()
    {
        global $wpdb;

        $id = Fields::create(['type' => 'text', 'label' => 'Company']);
        Fields::delete($id);

        $wpdb->insert($wpdb->prefix . 'mo_campaign_log', ['email_campaign_id' => 0, 'title' => 'x', 'content_html' => '', 'content_text' => '', 'status' => 'draft']);
        $log = (int)$wpdb->insert_id;
        CampaignRenderer::save_campaign_content($log, $this->list, '<p>{{subscriber.company}}</p>', '');

        $this->assertSame('<p></p>', PublicEndpoints::web_version_html($log));
    }
}
