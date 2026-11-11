<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Audience\Sources\AbstractSource;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\Core\Repositories\SegmentsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Premium (segments) with customers, users and members. Its own processes: the Premium constant
 * can't be undefined for the tests after it.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceSourcesPremiumTest extends AudienceSourcesTestCase
{
    public function test_a_segment_narrows_source_people_to_matching_subscribers()
    {
        if ( ! SegmentsRepository::is_ready()) $this->markTestSkipped('Segments table missing.');

        $this->user('ana@example.com', 'editor');
        $this->user('ben@example.com', 'editor');
        $this->mode('users', AbstractSource::MODE_EVERYONE);

        $tag = \MailOptin\Core\Repositories\TagsRepository::create('vip');
        $ana = Subscribers::create(['email' => 'ana@example.com', 'status' => Subscribers::STATUS_SUBSCRIBED]);
        \MailOptin\Core\Repositories\TagsRepository::add_to_subscribers([$tag], [$ana]);

        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) define('MAILOPTIN_DETACH_LIBSODIUM', true);
        $segment = SegmentsRepository::create('VIPs', ['tag_ids' => [$tag], 'tag_match' => 'any']);

        Broadcasts::save($this->campaign_id, [SegmentsRepository::SEGMENT_SETTING => (string)$segment]);
        $this->choose([['source' => 'users', 'option' => 'editor']]);

        $this->assertSame(1, Broadcasts::recipients_count($this->campaign_id), 'Ben is a user but not a subscriber, so can\'t be in the segment.');
    }

}
