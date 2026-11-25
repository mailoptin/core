<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Audience\CampaignRenderer;
use MailOptin\Core\Audience\PublicEndpoints;
use MailOptin\Core\Audience\WelcomeEmail;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use MailOptin\MailOptinConnect\Connect;
use WP_UnitTestCase;

class TagsFieldsFormsTest extends WP_UnitTestCase
{
    protected $list;

    public function set_up()
    {
        parent::set_up();

        // tests/bootstrap.php boots the plugin after plugins_loaded has fired.
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        DBUpdates::get_instance()->update_routine_7();
        DBUpdates::get_instance()->update_routine_17();
        DBUpdates::get_instance()->update_routine_20();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        Fields::flush_cache();

        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        $this->list = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 0]);

        Fields::create(['type' => Fields::TYPE_TEXT, 'label' => 'Company']);
        Fields::create(['type' => Fields::TYPE_DROPDOWN, 'label' => 'Zone', 'options' => ['6a', '7b']]);
        Fields::create(['type' => Fields::TYPE_NUMBER, 'label' => 'Orders']);
    }

    public function tear_down()
    {
        Fields::flush_cache();
        parent::tear_down();
    }

    protected function signup($email, $extras)
    {
        return Connect::get_instance()->subscribe($email, 'Amara Okafor', $this->list, $extras);
    }

    public function test_tags_control_lists_tags_and_links_to_them()
    {
        $controls = Connect::get_instance()->integration_customizer_controls([]);
        $this->assertSame('custom_content', $controls[0]['field']);

        $vip      = Tags::create('<b>vip</b>');
        $controls = Connect::get_instance()->integration_customizer_controls([]);

        $this->assertSame('chosen_select', $controls[0]['field']);
        $this->assertSame('MailOptinConnect_lead_tags', $controls[0]['name']);
        $this->assertSame([$vip => 'vip'], $controls[0]['choices']);
        $this->assertStringContainsString('view=tags', $controls[0]['description']);

        // Free: an upsell where premium's custom field mapping would be.
        $this->assertSame('MailOptinConnect_upgrade_notice', $controls[1]['name']);
        $this->assertStringContainsString('MailOptin Premium', $controls[1]['content']);
    }

    public function test_mapping_form_fields_needs_premium()
    {
        // Lite: MailOptin's own form builder has no custom form fields, so there's nothing to map.
        $this->assertFalse(defined('MAILOPTIN_DETACH_LIBSODIUM'));
        $this->assertSame([], Connect::get_instance()->get_optin_fields($this->list));
        $this->assertNotContains(Connect::OPTIN_CUSTOM_FIELD_SUPPORT, Connect::features_support());

        $vip = Tags::create('vip');
        Connect::get_instance()->subscribe('amara@example.com', 'Amara', $this->list, [
            'optin_campaign_id'          => 0,
            'form_custom_field_mappings' => ['company' => 'c'],
            'c'                          => 'Okafor Landscaping',
            'form_tags'                  => ['vip'],
        ]);

        $id = Subscribers::get_by_email('amara@example.com')['id'];
        $this->assertSame('', Fields::merge_values($id)['company']);
        $this->assertSame([$vip => 'vip'], Tags::for_subscriber($id), 'Tags from forms are free.');
    }

    public function test_signup_without_a_form_still_works()
    {
        $this->assertTrue($this->signup('tom@example.com', [])['success']);
        $this->assertSame([], Tags::for_subscriber(Subscribers::get_by_email('tom@example.com')['id']));
    }

    public function test_campaign_merge_tags_include_custom_fields_with_fallbacks()
    {
        $id = Subscribers::create(['email' => 'amara@example.com', 'first_name' => '', 'status' => Subscribers::STATUS_SUBSCRIBED]);
        Fields::set_values($id, ['company' => 'Okafor & Sons', 'zone' => '7b']);

        $content = 'Hi {{subscriber.first_name|there}} from {{subscriber.company}} in {{subscriber.zone|unknown}}, {{subscriber.orders|no}} orders. {{subscriber.nope}} {{subscriber.email}}';

        $this->assertSame(
            'Hi there from Okafor &amp; Sons in 7b, no orders. {{subscriber.nope}} amara@example.com',
            CampaignRenderer::replace_fallback_tags($content, Subscribers::get($id), true)
        );
        $this->assertSame(
            'Hi there from Okafor & Sons in 7b, no orders. {{subscriber.nope}} amara@example.com',
            CampaignRenderer::replace_fallback_tags($content, Subscribers::get($id), false)
        );
    }

    public function test_queued_email_is_personalized_with_custom_fields()
    {
        global $wpdb;

        $id = Subscribers::create(['email' => 'amara@example.com', 'first_name' => 'Amara', 'status' => Subscribers::STATUS_SUBSCRIBED]);
        Subscribers::set_membership($id, $this->list, Subscribers::STATUS_SUBSCRIBED);
        Fields::set_values($id, ['company' => 'Okafor Landscaping']);

        $wpdb->insert($wpdb->prefix . 'mo_campaign_log', ['email_campaign_id' => 0, 'title' => 'News for {{subscriber.company}}', 'content_html' => '', 'content_text' => '', 'status' => 'draft']);
        $log_id = (int)$wpdb->insert_id;

        CampaignRenderer::save_campaign_content($log_id, $this->list, '<p>{{subscriber.company|friend}}</p>', '{{subscriber.company}}');

        $content = CampaignRenderer::get_instance()->personalize('', ['recipient_type' => 'subscriber', 'recipient_id' => $id], $log_id);

        $this->assertSame('News for Okafor Landscaping', $content['subject']);
        $this->assertSame('<p>Okafor Landscaping</p>', $content['html']);
        $this->assertSame('Okafor Landscaping', $content['text']);

        // The web version anyone can open shows fallbacks instead of personal values.
        $this->assertSame('<p>friend</p>', PublicEndpoints::web_version_html($log_id));
    }

    public function test_personal_data_export_and_erase_include_tags_and_fields()
    {
        $id = Subscribers::create(['email' => 'amara@example.com', 'first_name' => 'Amara', 'status' => Subscribers::STATUS_SUBSCRIBED]);
        Subscribers::set_membership($id, $this->list, Subscribers::STATUS_SUBSCRIBED);
        Fields::set_values($id, ['company' => 'Okafor Landscaping']);
        Tags::add_to_subscribers(Tags::ensure(['vip']), [$id]);

        $exporters = apply_filters('wp_privacy_personal_data_exporters', []);
        $export    = call_user_func($exporters['mailoptin-subscribers']['callback'], 'Amara@Example.com', 1);
        $values    = wp_list_pluck($export['data'][0]['data'], 'value', 'name');

        $this->assertSame('amara@example.com', $values['Email']);
        $this->assertSame('Newsletter (subscribed)', $values['Lists']);
        $this->assertSame('vip', $values['Tags']);
        $this->assertSame('Okafor Landscaping', $values['Company']);

        $erasers = apply_filters('wp_privacy_personal_data_erasers', []);
        $erased  = call_user_func($erasers['mailoptin-subscribers']['callback'], 'amara@example.com', 1);

        $this->assertTrue($erased['items_removed']);
        $this->assertNull(Subscribers::get($id));
        $this->assertSame([], Fields::get_values($id));
        $this->assertSame([], Tags::for_subscriber($id));
        $this->assertSame([], call_user_func($exporters['mailoptin-subscribers']['callback'], 'amara@example.com', 1)['data']);
    }
}
