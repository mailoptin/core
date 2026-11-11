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


    protected function premium()
    {
        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) define('MAILOPTIN_DETACH_LIBSODIUM', true);
    }

    protected function matching($conditions)
    {
        $result = Subscribers::query(['store' => $conditions, 'per_page' => 100]);

        $emails = array_map('strtolower', wp_list_pluck($result['items'], 'email'));
        sort($emails);

        return $emails;
    }

    public function test_purchase_conditions()
    {
        $this->premium();
        $this->woo_store();

        foreach (['maya@example.com', 'guest@example.com', 'old@example.com', 'pending@example.com', 'none@example.com'] as $email) {
            Subscribers::create(['email' => $email, 'status' => Subscribers::STATUS_SUBSCRIBED]);
        }

        $bought = ['what' => 'bought', 'source' => 'woo', 'target' => 'product', 'ids' => [47]];
        $this->assertSame(['maya@example.com', 'old@example.com'], $this->matching([$bought]), 'Paid orders only: pending@ ordered 47 unpaid.');
        $this->assertSame(['maya@example.com'], $this->matching([['ids' => [51]] + $bought]), 'A variation counts as its product.');
        $this->assertSame(['guest@example.com', 'none@example.com', 'pending@example.com'], $this->matching([['what' => 'not_bought'] + $bought]));

        $this->assertSame(['maya@example.com'], $this->matching([['what' => 'spent', 'source' => 'woo', 'op' => 'gt', 'amount' => 30]]), 'Refund child orders aren\'t counted twice.');
        $this->assertSame(['maya@example.com'], $this->matching([['what' => 'orders', 'source' => 'woo', 'op' => 'eq', 'count' => 2]]));
        $this->assertSame(['guest@example.com', 'maya@example.com'], $this->matching([['what' => 'last_order', 'source' => 'woo', 'op' => 'within', 'days' => 30]]));
        $this->assertSame(['old@example.com'], $this->matching([['what' => 'last_order', 'source' => 'woo', 'op' => 'not_within', 'days' => 30]]), 'Ordered before but not lately; never-ordered isn\'t lapsed.');

        // Conditions combine.
        $this->assertSame(['maya@example.com'], $this->matching([$bought, ['what' => 'last_order', 'source' => 'woo', 'op' => 'within', 'days' => 30]]));
    }

    public function test_a_purchase_segment_narrows_customers_who_arent_subscribers()
    {
        $this->premium();
        $this->woo_store();

        // Only maya is a subscriber; the segment is about purchases, so it applies to every customer.
        Subscribers::create(['email' => 'maya@example.com', 'status' => Subscribers::STATUS_SUBSCRIBED]);

        $segment = SegmentsRepository::create('Bought 47', ['store' => [['what' => 'bought', 'source' => 'woo', 'target' => 'product', 'ids' => [47]]]]);
        $this->assertIsInt($segment);

        Broadcasts::save($this->campaign_id, [SegmentsRepository::SEGMENT_SETTING => (string)$segment]);
        $this->choose([['source' => 'woo', 'option' => '']]);

        $this->assertSame(2, Broadcasts::recipients_count($this->campaign_id), 'maya and old (not a subscriber) bought 47; guest didn\'t.');

        $this->send();

        $emails = wp_list_pluck($this->queue_rows(), 'email');
        $this->assertSame(['maya@example.com', 'old@example.com'], $emails);
    }

    public function test_spend_is_net_of_refunds_and_categories_include_children()
    {
        $this->premium();
        $this->woo_store();

        global $wpdb;

        // maya spent 50; a 25 refund on order 10 (its own row, negative) brings her to 25.
        $wpdb->insert("{$wpdb->prefix}wc_order_stats", ['order_id' => 16, 'parent_id' => 10, 'date_created' => gmdate('Y-m-d H:i:s'), 'date_created_gmt' => gmdate('Y-m-d H:i:s'), 'status' => 'wc-completed', 'customer_id' => 1, 'total_sales' => -25]);

        Subscribers::create(['email' => 'maya@example.com', 'status' => Subscribers::STATUS_SUBSCRIBED]);
        Subscribers::create(['email' => 'guest@example.com', 'status' => Subscribers::STATUS_SUBSCRIBED]);

        $this->assertSame([], $this->matching([['what' => 'spent', 'source' => 'woo', 'op' => 'gt', 'amount' => 30]]));
        $this->assertSame(['maya@example.com'], $this->matching([['what' => 'spent', 'source' => 'woo', 'op' => 'gt', 'amount' => 20]]));

        // Product 47 is only in Bulbs › Tulips; "bought Bulbs" includes it.
        register_taxonomy('product_cat', 'product', ['hierarchical' => true]);
        $bulbs  = self::factory()->term->create(['taxonomy' => 'product_cat', 'name' => 'Bulbs']);
        $tulips = self::factory()->term->create(['taxonomy' => 'product_cat', 'name' => 'Tulips', 'parent' => $bulbs]);
        wp_set_object_terms(47, [$tulips], 'product_cat');
        clean_taxonomy_cache('product_cat');

        $this->assertSame(['maya@example.com'], $this->matching([['what' => 'bought', 'source' => 'woo', 'target' => 'category', 'ids' => [$bulbs]]]));
        $this->assertSame(['guest@example.com'], $this->matching([['what' => 'not_bought', 'source' => 'woo', 'target' => 'category', 'ids' => [$bulbs]]]));
    }
}
