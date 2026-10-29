<?php
/**
 * Copyright (C) 2016  Agbonghama Collins <me@w3guy.com>
 */

namespace MailOptin\Tests\Core\Repositories;

use MailOptin\Core\Repositories\EmailCampaignRepository;
use WP_UnitTestCase;

class EmailCampaignRepositoryTest extends WP_UnitTestCase
{
    public $insert_data;

    public function set_up()
    {
        parent::set_up();

        $this->insert_data = [
            'name' => 'Instant New Post Campaign',
            'campaign_type' => 'new_publish_post',
            'template_class' => 'Lucid'
        ];
    }

    public function tear_down()
    {
        parent::tear_down();
    }

    public function testAddEmailCampaign()
    {
        $response = EmailCampaignRepository::add_email_campaign(
            $this->insert_data['name'],
            $this->insert_data['campaign_type'],
            $this->insert_data['template_class']
        );

        $this->assertIsInt($response);
    }

    public function testGetEmailCampaignById()
    {
        $id = EmailCampaignRepository::add_email_campaign(
            $this->insert_data['name'],
            $this->insert_data['campaign_type'],
            $this->insert_data['template_class']
        );

        $result = EmailCampaignRepository::get_email_campaign_by_id($id);

        $this->assertIsArray($result);
        $this->assertSame($id, absint($result['id']));
    }

    public function test_update_campaign_name()
    {
        $id = EmailCampaignRepository::add_email_campaign(
            $this->insert_data['name'],
            $this->insert_data['campaign_type'],
            $this->insert_data['template_class']
        );

        $result = EmailCampaignRepository::update_campaign_name(
            'ProfilePress new latest post',
            $id
        );

        $this->assertIsInt($result);
        $this->assertSame(1, $result);
    }

    public function testDeleteCampaignById()
    {
        $id = EmailCampaignRepository::add_email_campaign(
            $this->insert_data['name'],
            $this->insert_data['campaign_type'],
            $this->insert_data['template_class']
        );

        $result = EmailCampaignRepository::delete_campaign_by_id($id);

        $this->assertIsInt($result);
        $this->assertSame(1, $result);
    }

    public function testGetByEmailCampaignType()
    {
        EmailCampaignRepository::add_email_campaign(
            $this->insert_data['name'],
            $this->insert_data['campaign_type'],
            $this->insert_data['template_class']
        );

        $result = EmailCampaignRepository::get_by_email_campaign_type('new_publish_post');

        $this->assertIsArray($result);
        $this->assertSame($this->insert_data['name'], $result[0]['name']);
    }
}