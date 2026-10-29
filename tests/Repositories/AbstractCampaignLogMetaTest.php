<?php

namespace MailOptin\Tests\Core\Repositories;

use MailOptin\Core\Repositories\AbstractCampaignLogMeta;
use WP_UnitTestCase;

class AbstractCampaignLogMetaTest extends WP_UnitTestCase
{
    public $insert_data;

    public function set_up()
    {
        parent::set_up();
    }

    public function tear_down()
    {
        parent::tear_down();
    }

    public function testAddCampaignLogMeta()
    {
        \MailOptin\Core\Base::get_instance()->register_metadata_table();

        $this->assertNotFalse(\MailOptin\Core\Repositories\AbstractCampaignLogMeta::add_campaignlog_meta(3, 'key3', 'value3'));
        $this->assertSame('value3', \MailOptin\Core\Repositories\AbstractCampaignLogMeta::get_campaignlog_meta(3, 'key3', true));
    }
}