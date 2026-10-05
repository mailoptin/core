<?php

namespace MailOptin\Tests\Core;

use MailOptin\Core\AjaxHandler;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use WP_UnitTestCase;

class AjaxHandlerTest extends WP_UnitTestCase
{
    public $optin_campaign_id;

    public function tearDown()
    {
        if ( ! empty($this->optin_campaign_id)) {
            global $wpdb;

            $wpdb->delete(OptinCampaignsRepository::campaigns_table(), ['id' => $this->optin_campaign_id]);
        }

        parent::tearDown();
    }

    public function testAjaxPayloadsMustResolveToAnExistingCampaign()
    {
        global $wpdb;

        $uuid = substr(md5(microtime(true) . mt_rand()), 0, 10);

        $wpdb->insert(
            OptinCampaignsRepository::campaigns_table(),
            [
                'name'        => 'Ajax handler test ' . $uuid,
                'uuid'        => $uuid,
                'optin_class' => 'test',
                'optin_type'  => 'test',
                'activated'   => 'yes'
            ]
        );

        $this->optin_campaign_id = $wpdb->insert_id;

        $this->assertSame(0, AjaxHandler::get_valid_optin_subscription_campaign_id('not an array'));
        $this->assertSame(0, AjaxHandler::get_valid_optin_subscription_campaign_id(['email' => 'invalid-email']));
        $this->assertSame(0, AjaxHandler::get_valid_optin_subscription_campaign_id([
            'email'             => 'subscriber@example.com',
            'optin_campaign_id' => 999999999
        ]));

        $this->assertSame($this->optin_campaign_id, AjaxHandler::get_valid_optin_subscription_campaign_id([
            'email'             => 'subscriber@example.com',
            'optin_campaign_id' => $this->optin_campaign_id
        ]));

        $this->assertSame($this->optin_campaign_id, AjaxHandler::get_valid_optin_subscription_campaign_id([
            'email'     => 'subscriber@example.com',
            'optin_uuid' => $uuid
        ]));

        $this->assertSame(0, AjaxHandler::get_valid_optin_impression_campaign_id('not an array'));
        $this->assertSame(0, AjaxHandler::get_valid_optin_impression_campaign_id([]));
        $this->assertSame(0, AjaxHandler::get_valid_optin_impression_campaign_id(['optin_uuid' => []]));
        $this->assertSame(0, AjaxHandler::get_valid_optin_impression_campaign_id(['optin_uuid' => 'unknown-campaign']));
        $this->assertSame($this->optin_campaign_id, AjaxHandler::get_valid_optin_impression_campaign_id(['optin_uuid' => $uuid]));
    }
}