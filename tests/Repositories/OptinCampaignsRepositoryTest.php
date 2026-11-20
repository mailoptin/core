<?php

namespace MailOptin\Tests\Core\Repositories;

use MailOptin\Core\AjaxHandler;
use MailOptin\Core\Connections\AbstractConnect;
use MailOptin\Core\OptinForms\ConversionDataBuilder;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use WP_UnitTestCase;

class OptinCampaignsRepositoryTest extends WP_UnitTestCase
{
    public function testDecodeJsonListAcceptsStoredArrayAndJsonString()
    {
        $list = [
            [
                'connection_service'    => 'MailChimpConnect',
                'connection_email_list' => 'list-1',
            ],
        ];

        $this->assertSame($list, OptinCampaignsRepository::decode_json_list($list));
        $this->assertSame($list, OptinCampaignsRepository::decode_json_list(wp_json_encode($list)));
        $this->assertSame($list, OptinCampaignsRepository::decode_json_list((object)[
            (object)[
                'connection_service'    => 'MailChimpConnect',
                'connection_email_list' => 'list-1',
            ],
        ]));
        $this->assertSame([], OptinCampaignsRepository::decode_json_list(''));
        $this->assertSame([], OptinCampaignsRepository::decode_json_list('not-json'));
        $this->assertSame([], OptinCampaignsRepository::decode_json_list(false));
        $this->assertSame($list, OptinCampaignsRepository::decode_json_list([
            (object)[
                'connection_service'    => 'MailChimpConnect',
                'connection_email_list' => 'list-1',
            ],
        ]));
    }

    public function testOptinConversionDoesNotFatalWhenIntegrationsAreAlreadyAnArray()
    {
        $id = OptinCampaignsRepository::add_optin_campaign(
            wp_generate_password(10, false),
            'Integrations ' . wp_generate_password(6, false),
            'BareMetal',
            'lightbox'
        );

        $settings      = OptinCampaignsRepository::get_settings();
        $settings[$id] = [
            'integrations' => [
                (object)[
                    'connection_service'    => 'MailChimpConnect',
                    'connection_email_list' => '',
                ],
            ],
        ];
        OptinCampaignsRepository::updateSettings($settings);

        $builder                            = new ConversionDataBuilder();
        $builder->payload                   = [];
        $builder->optin_campaign_id         = $id;
        $builder->email                     = 'lead@example.com';
        $builder->is_timestamp_check_active = false;

        $response = AjaxHandler::do_optin_conversion($builder);

        $this->assertSame(
            AbstractConnect::ajax_failure(AjaxHandler::no_email_provider_or_list_error()),
            $response
        );
    }
}
