<?php

namespace MailOptin\Tests\Core;

use MailOptin\Core\AjaxHandler;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use WP_UnitTestCase;

class AjaxHandlerTest extends WP_UnitTestCase
{
    public $optin_campaign_id;
    public $rate_limit_filter;
    public $rate_limit_window_filter;
    public $rate_limit_transient_keys = [];

    public function tearDown()
    {
        if ( ! empty($this->rate_limit_filter)) {
            remove_filter('mailoptin_optin_subscription_rate_limit', $this->rate_limit_filter);
        }

        if ( ! empty($this->rate_limit_window_filter)) {
            remove_filter('mailoptin_optin_subscription_rate_limit_window', $this->rate_limit_window_filter);
        }

        foreach ($this->rate_limit_transient_keys as $transient_key) {
            delete_transient($transient_key);
        }

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

    public function testSubscriptionRateLimitIsAppliedPerValidClientIp()
    {
        $ip_address       = '192.0.2.' . mt_rand(1, 254);
        $other_ip_address = '198.51.100.' . mt_rand(1, 254);

        $this->rate_limit_transient_keys = [
            'mailoptin_subscription_rate_limit_' . md5($ip_address),
            'mailoptin_subscription_rate_limit_' . md5($other_ip_address)
        ];

        $this->rate_limit_filter = function () {
            return 2;
        };

        $this->rate_limit_window_filter = function () {
            return MINUTE_IN_SECONDS;
        };

        add_filter('mailoptin_optin_subscription_rate_limit', $this->rate_limit_filter);
        add_filter('mailoptin_optin_subscription_rate_limit_window', $this->rate_limit_window_filter);

        $this->assertFalse(AjaxHandler::is_optin_subscription_rate_limited($ip_address));
        $this->assertFalse(AjaxHandler::is_optin_subscription_rate_limited($ip_address));
        $this->assertTrue(AjaxHandler::is_optin_subscription_rate_limited($ip_address));
        $this->assertFalse(AjaxHandler::is_optin_subscription_rate_limited($other_ip_address));
        $this->assertFalse(AjaxHandler::is_optin_subscription_rate_limited('not-an-ip-address'));
    }
}