<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use MailOptin\MailOptinConnect\Connect;

/**
 * Mapping form fields to custom fields, a premium feature. Each test runs in its own process
 * because the premium constant can't be undefined afterwards.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TagsFieldsFormsPremiumTest extends TagsFieldsFormsTest
{
    public function set_up()
    {
        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) define('MAILOPTIN_DETACH_LIBSODIUM', true);

        parent::set_up();
    }

    // Covered by the parent class without the constant.
    public function test_mapping_form_fields_needs_premium()
    {
        $this->assertTrue(Connect::has_custom_form_fields());
    }

    public function test_tags_control_lists_tags_and_links_to_them()
    {
        Tags::create('vip');

        $names = wp_list_pluck(Connect::get_instance()->integration_customizer_controls([]), 'name');
        $this->assertSame(['MailOptinConnect_lead_tags'], $names, 'No upsell with premium.');
    }

    public function test_connection_offers_custom_fields_for_mapping()
    {
        $this->assertSame(['company' => 'Company', 'zone' => 'Zone', 'orders' => 'Orders'], Connect::get_instance()->get_optin_fields($this->list));
        $this->assertContains(Connect::OPTIN_CUSTOM_FIELD_SUPPORT, Connect::features_support());
    }

    public function test_signup_saves_mapped_fields_and_form_tags()
    {
        $vip = Tags::create('vip');

        $response = $this->signup('amara@example.com', [
            'optin_campaign_id'          => 0,
            'form_custom_field_mappings' => ['company' => 'mo_company', 'zone' => 'mo_zone', 'orders' => 'mo_orders', 'missing' => 'mo_x'],
            'form_tags'                  => ['VIP', 'new-tag'],
            'mo_company'                 => '<i>Okafor</i> Landscaping',
            'mo_zone'                    => '7B',
            'mo_orders'                  => 'many',
        ]);

        $this->assertTrue($response['success']);

        $subscriber = Subscribers::get_by_email('amara@example.com');
        $values     = Fields::merge_values($subscriber['id']);

        $this->assertSame('Okafor Landscaping', $values['company']);
        $this->assertSame('7b', $values['zone']);
        // An invalid value doesn't block the signup; it's noted on the timeline.
        $this->assertSame('', $values['orders']);
        $this->assertSame(['new-tag', 'vip'], array_values(Tags::for_subscriber($subscriber['id'])));

        $event = Subscribers::get_events($subscriber['id'])[0];
        $meta  = json_decode($event['meta'], true);
        $this->assertSame(['new-tag', 'vip'], $meta['tags']);
        $this->assertSame('7B', $meta['fields']['zone']);
        $this->assertNotEmpty($meta['field_errors']);
    }

    public function test_a_signup_does_not_overwrite_existing_values()
    {
        $this->signup('amara@example.com', ['form_custom_field_mappings' => ['company' => 'c'], 'c' => 'Okafor Landscaping']);
        $this->signup('amara@example.com', ['form_custom_field_mappings' => ['company' => 'c', 'zone' => 'z'], 'c' => 'Spoofed Inc', 'z' => '6a']);

        $values = Fields::merge_values(Subscribers::get_by_email('amara@example.com')['id']);

        $this->assertSame('Okafor Landscaping', $values['company']);
        $this->assertSame('6a', $values['zone']);
    }
}
