<?php

namespace MailOptin\Tests\Core\Api;

use MailOptin\Core\Api\ApiKeys;
use MailOptin\Core\Api\SubscribersApi;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use WP_UnitTestCase;

class SubscriberApiTest extends WP_UnitTestCase
{
    protected $admin;
    protected $newsletter;
    protected $workshops;
    protected $write_key;
    protected $read_key;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        DBUpdates::get_instance()->update_routine_7();
        DBUpdates::get_instance()->update_routine_17();
        DBUpdates::get_instance()->update_routine_20();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        Fields::flush_cache();
        delete_option(ApiKeys::OPTION);
        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });
        reset_phpmailer_instance();

        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $this->admin      = self::factory()->user->create(['role' => 'administrator']);
        $this->newsletter = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 1]);
        $this->workshops  = ListsRepository::create(['name' => 'Workshops', 'double_optin' => 0]);
        Fields::create(['label' => 'Company', 'merge_key' => 'company', 'type' => 'text']);
        Fields::create(['label' => 'Orders', 'merge_key' => 'orders', 'type' => 'number']);
        Tags::create('customer');

        $this->write_key = ApiKeys::create('Zapier', ApiKeys::ACCESS_WRITE, $this->admin)['key'];
        $this->read_key  = ApiKeys::create('Report', ApiKeys::ACCESS_READ, $this->admin)['key'];

        wp_set_current_user(0);

        // Routes are registered on rest_api_init.
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);
    }

    public function tear_down()
    {
        unset($_SERVER['HTTPS']);
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    protected function call($method, $path, $body = null, $key = 'write', $query = [])
    {
        wp_set_current_user(0);
        $request = new \WP_REST_Request($method, '/mailoptin/v1' . $path);
        if ('write' === $key) $key = $this->write_key;
        if ('read' === $key) $key = $this->read_key;
        if ($key) $request->set_header('Authorization', 'Bearer ' . $key);
        if (null !== $body) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }
        foreach ($query as $k => $v) $request->set_param($k, $v);

        // As serve_request() does: this builds the Allow header, which runs every method's permission check.
        return apply_filters('rest_post_dispatch', rest_ensure_response(rest_do_request($request)), rest_get_server(), $request);
    }

    protected function mails_to($email)
    {
        $n = 0;
        foreach ((array)tests_retrieve_phpmailer_instance()->mock_sent as $mail) {
            foreach ((array)$mail['to'] as $to) if (strtolower($to[0]) === $email) $n++;
        }

        return $n;
    }

    /* Keys -------------------------------------------------------------------------------------- */

    public function test_keys_are_stored_hashed_shown_once_and_revocable()
    {
        $created = ApiKeys::create('Shop', ApiKeys::ACCESS_WRITE, $this->admin);
        $this->assertMatchesRegularExpression('/^mo_live_[A-Za-z0-9]{8}_[A-Za-z0-9]{32}$/', $created['key']);

        $stored = get_option(ApiKeys::OPTION);
        $secret = substr($created['key'], -32);
        $this->assertStringNotContainsString($secret, serialize($stored), 'The secret is never stored.');

        $this->assertSame($created['record']['id'], ApiKeys::verify($created['key'])['id']);
        // A wrong last character: one that differs from the real one (a fixed "x" is the real one 1 time in 62).
        $wrong = 'x' === substr($created['key'], -1) ? 'y' : 'x';
        $this->assertNull(ApiKeys::verify(substr($created['key'], 0, -1) . $wrong));
        $this->assertNull(ApiKeys::verify('mo_live_nope'));
        $this->assertSame('mo_live_' . $created['record']['id'] . '_••••', ApiKeys::masked($created['record']));
        $this->assertWPError(ApiKeys::create('  ', 'write', $this->admin));

        ApiKeys::revoke($created['record']['id']);
        $this->assertNull(ApiKeys::verify($created['key']));
    }

    public function test_a_key_stops_working_when_its_creator_loses_access()
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        $key    = ApiKeys::create('Old', ApiKeys::ACCESS_WRITE, $this->admin)['key'];
        $this->assertNotNull(ApiKeys::verify($key));

        (new \WP_User($this->admin))->set_role('subscriber');
        $this->assertNull(ApiKeys::verify($key));
        $this->assertSame(401, $this->call('GET', '/lists', null, $key)->get_status());
        unset($editor);
    }

    /* Authentication ---------------------------------------------------------------------------- */

    public function test_requests_need_a_valid_key_or_a_logged_in_admin()
    {
        $this->assertSame(401, $this->call('GET', '/subscribers', null, '')->get_status());
        $this->assertSame(401, $this->call('GET', '/subscribers', null, 'mo_live_abcdefgh_' . str_repeat('x', 32))->get_status());
        $this->assertSame(200, $this->call('GET', '/subscribers')->get_status());

        // The X-MailOptin-Key header works too.
        $request = new \WP_REST_Request('GET', '/mailoptin/v1/lists');
        $request->set_header('X-MailOptin-Key', $this->read_key);
        $this->assertSame(200, rest_do_request($request)->get_status());

        // A logged-in admin (cookie and nonce, or an Application Password) needs no key.
        wp_set_current_user($this->admin);
        $this->assertSame(200, rest_do_request(new \WP_REST_Request('GET', '/mailoptin/v1/lists'))->get_status());

        // Others don't get in.
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertSame(401, rest_do_request(new \WP_REST_Request('GET', '/mailoptin/v1/lists'))->get_status());
    }

    public function test_key_in_the_url_and_plain_http_are_refused()
    {
        $response = $this->call('GET', '/subscribers', null, '', ['api_key' => $this->write_key]);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('mo_api_key_in_url', $response->get_data()['code']);

        unset($_SERVER['HTTPS']);
        add_filter('mo_api_allow_http', '__return_false');
        $this->assertSame(403, $this->call('GET', '/subscribers')->get_status());
        $this->assertSame('mo_api_https_required', $this->call('GET', '/subscribers')->get_data()['code']);
    }

    public function test_read_only_keys_cannot_change_anything()
    {
        $response = $this->call('POST', '/subscribers', ['email' => 'maya@example.org', 'lists' => ['Workshops']], 'read');
        $this->assertSame(403, $response->get_status());
        $this->assertNull(Subscribers::get_by_email('maya@example.org'));
        $this->assertSame(200, $this->call('GET', '/subscribers', null, 'read')->get_status());
    }

    public function test_rate_limit_answers_429_with_retry_after_and_records_last_use()
    {
        add_filter('mo_api_rate_limit', function () {
            return 2;
        });

        $this->assertSame(200, $this->call('GET', '/lists')->get_status());
        $this->assertSame(200, $this->call('GET', '/lists')->get_status());
        $third = $this->call('GET', '/lists');
        $this->assertSame(429, $third->get_status());

        $response = rest_get_server()->dispatch(new \WP_REST_Request('GET', '/mailoptin/v1/lists'));
        $headers  = apply_filters('rest_post_dispatch', $third, rest_get_server(), new \WP_REST_Request())->get_headers();
        $this->assertGreaterThan(0, (int)$headers['Retry-After']);
        unset($response);

        $record = ApiKeys::get(explode('_', $this->write_key)[2]);
        $this->assertGreaterThan(0, $record['last_used_at']);
        $this->assertSame('203.0.113.7', $record['last_used_ip']);
    }

    /* Adding ----------------------------------------------------------------------------------- */

    public function test_add_with_consent_subscribes_and_records_it()
    {
        $response = $this->call('POST', '/subscribers', [
            'email'      => 'Maya@Example.org',
            'first_name' => 'Maya',
            'lists'      => ['Newsletter', $this->workshops],
            'tags'       => ['customer'],
            'fields'     => ['company' => 'Okafor Gardens', 'orders' => 3],
            'consent'    => ['given' => true, 'text' => 'Ticked "Email me offers" at checkout'],
        ]);

        $this->assertSame(201, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['created']);
        $this->assertFalse($data['confirmation_sent']);
        $this->assertSame('maya@example.org', $data['email']);
        $this->assertSame('subscribed', $data['status']);
        $this->assertSame(['Newsletter' => 'subscribed', 'Workshops' => 'subscribed'], wp_list_pluck($data['lists'], 'status', 'name'));
        $this->assertSame(['customer'], $data['tags']);
        $this->assertSame(['company' => 'Okafor Gardens', 'orders' => '3'], (array)$data['fields']);
        $this->assertSame('api', $data['consent']['method']);
        $this->assertSame('Ticked "Email me offers" at checkout', $data['consent']['text']);
        $this->assertSame(0, $this->mails_to('maya@example.org'));

        // The timeline records the key that added them.
        $events = Subscribers::get_events($data['id']);
        $signup = json_decode($events[count($events) - 1]['meta'] ?? '{}', true);
        $this->assertSame(explode('_', $this->write_key)[2], $signup['api_key'] ?? null);
    }

    public function test_add_without_consent_asks_them_to_confirm()
    {
        $data = $this->call('POST', '/subscribers', ['email' => 'tom@example.org', 'lists' => ['Newsletter']])->get_data();

        $this->assertSame('pending', $data['status']);
        $this->assertTrue($data['confirmation_sent']);
        $this->assertSame(1, $this->mails_to('tom@example.org'));
    }

    public function test_existing_email_is_updated_not_duplicated()
    {
        $this->call('POST', '/subscribers', ['email' => 'amara@example.org', 'first_name' => 'Amara', 'lists' => ['Workshops'], 'consent' => ['given' => true]]);
        $response = $this->call('POST', '/subscribers', ['email' => 'AMARA@example.org', 'last_name' => 'Obi', 'tags' => ['customer']]);

        $this->assertSame(200, $response->get_status());
        $this->assertFalse($response->get_data()['created']);
        $this->assertSame(['Amara', 'Obi'], [$response->get_data()['first_name'], $response->get_data()['last_name']]);
        $this->assertSame(['customer'], $response->get_data()['tags']);
        $this->assertSame(1, Subscribers::query(['search' => 'amara@'])['total']);
    }

    public function test_unknown_lists_tags_and_fields_are_refused_unless_created()
    {
        $r = $this->call('POST', '/subscribers', ['email' => 'lee@example.org', 'lists' => ['VIP'], 'consent' => ['given' => true]]);
        $this->assertSame(400, $r->get_status());
        $this->assertSame(['VIP'], $r->get_data()['data']['unknown']);
        $this->assertNull(Subscribers::get_by_email('lee@example.org'), 'A refused request changes nothing.');

        $r = $this->call('POST', '/subscribers', ['email' => 'lee@example.org', 'lists' => ['Workshops'], 'tags' => ['vip']]);
        $this->assertSame('mo_unknown_tags', $r->get_data()['code']);
        $this->assertNull(Subscribers::get_by_email('lee@example.org'));

        $r = $this->call('POST', '/subscribers', ['email' => 'lee@example.org', 'lists' => ['Workshops'], 'fields' => ['shoe_size' => 9]]);
        $this->assertSame('mo_unknown_fields', $r->get_data()['code']);

        $r = $this->call('POST', '/subscribers', ['email' => 'lee@example.org', 'lists' => ['Workshops'], 'fields' => ['orders' => 'lots']]);
        $this->assertSame('mo_invalid_field_values', $r->get_data()['code']);
        $this->assertArrayHasKey('orders', $r->get_data()['data']['fields']);
        $this->assertNull(Subscribers::get_by_email('lee@example.org'));

        $r = $this->call('POST', '/subscribers', ['email' => 'lee@example.org', 'lists' => ['VIP'], 'tags' => ['vip'], 'create_missing' => true, 'consent' => ['given' => true]]);
        $this->assertSame(201, $r->get_status());
        $this->assertSame(['VIP'], wp_list_pluck($r->get_data()['lists'], 'name'));
        $this->assertSame(['vip'], $r->get_data()['tags']);

        $this->assertSame('mo_no_lists', $this->call('POST', '/subscribers', ['email' => 'new@example.org'])->get_data()['code']);
        $this->assertSame('mo_invalid_email', $this->call('POST', '/subscribers', ['email' => 'nope'])->get_data()['code']);
    }

    public function test_unsubscribed_bounced_and_complained_addresses_are_refused()
    {
        SuppressionRepository::add('gone@example.org', SuppressionRepository::REASON_UNSUBSCRIBED, 'test');
        $r = $this->call('POST', '/subscribers', ['email' => 'gone@example.org', 'lists' => ['Workshops'], 'consent' => ['given' => true]]);
        $this->assertSame(409, $r->get_status());
        $this->assertNull(Subscribers::get_by_email('gone@example.org'));

        SuppressionRepository::add('bounced@example.org', SuppressionRepository::REASON_BOUNCED, 'test');
        $r = $this->call('POST', '/subscribers', ['email' => 'bounced@example.org', 'lists' => ['Workshops'], 'consent' => ['given' => true]]);
        $this->assertSame(409, $r->get_status());
        $this->assertSame('bounced', $r->get_data()['data']['reason']);

        // Their details can still be updated without adding them to lists.
        Subscription::add_by_admin('left@example.org', [$this->workshops], [], true);
        Subscription::unsubscribe(Subscribers::get_by_email('left@example.org')['id']);
        $this->assertSame(200, $this->call('POST', '/subscribers', ['email' => 'left@example.org', 'first_name' => 'Lee'])->get_status());
        $this->assertSame(409, $this->call('POST', '/subscribers/left@example.org/lists', ['lists' => ['Workshops'], 'consent' => ['given' => true]])->get_status());
    }

    /* Reading ---------------------------------------------------------------------------------- */

    public function test_list_filters_paging_and_polling()
    {
        Subscription::add_by_admin('a@example.org', [$this->workshops], [], true);
        Subscription::add_by_admin('b@example.org', [$this->workshops, $this->newsletter], [], true);
        Subscription::add_by_admin('c@example.org', [$this->newsletter], [], false);
        Tags::add_to_subscribers([Tags::get_by_name('customer')['id']], [Subscribers::get_by_email('b@example.org')['id']]);

        $emails = function ($response) {
            $list = wp_list_pluck($response->get_data(), 'email');
            sort($list);

            return $list;
        };

        $this->assertSame(['a@example.org', 'b@example.org'], $emails($this->call('GET', '/subscribers', null, 'read', ['list' => 'Workshops'])));
        $this->assertSame(['b@example.org'], $emails($this->call('GET', '/subscribers', null, 'read', ['tag' => 'customer'])));
        $this->assertSame(['c@example.org'], $emails($this->call('GET', '/subscribers', null, 'read', ['status' => 'pending'])));
        $this->assertSame(400, $this->call('GET', '/subscribers', null, 'read', ['status' => 'everyone'])->get_status());
        $this->assertSame(400, $this->call('GET', '/subscribers', null, 'read', ['updated_since' => 'yesterday-ish'])->get_status());

        $page = $this->call('GET', '/subscribers', null, 'read', ['per_page' => 2, 'page' => 1]);
        $this->assertCount(2, $page->get_data());
        $this->assertSame('3', $page->get_headers()['X-WP-Total']);
        $this->assertSame('2', $page->get_headers()['X-WP-TotalPages']);

        global $wpdb;
        $wpdb->update(Subscribers::table(), ['updated_at' => '2020-01-01 00:00:00', 'created_at' => '2020-01-01 00:00:00'], ['email' => 'a@example.org']);
        $since = $this->call('GET', '/subscribers', null, 'read', ['updated_since' => gmdate('Y-m-d\TH:i:s\Z', time() - HOUR_IN_SECONDS)]);
        $this->assertSame(['b@example.org', 'c@example.org'], $emails($since));
        $this->assertSame(['b@example.org', 'c@example.org'], $emails($this->call('GET', '/subscribers', null, 'read', ['created_since' => '2025-01-01'])));
    }

    public function test_get_one_by_id_or_email()
    {
        Subscription::add_by_admin('maya@example.org', [$this->workshops], ['first_name' => 'Maya'], true);
        $id = Subscribers::get_by_email('maya@example.org')['id'];

        $this->assertSame('Maya', $this->call('GET', '/subscribers/' . $id, null, 'read')->get_data()['first_name']);
        $this->assertSame((int)$id, $this->call('GET', '/subscribers/maya@example.org', null, 'read')->get_data()['id']);
        $this->assertSame(404, $this->call('GET', '/subscribers/nobody@example.org', null, 'read')->get_status());

        $lists = $this->call('GET', '/lists', null, 'read')->get_data();
        $this->assertSame(['Newsletter', 'Workshops'], wp_list_pluck($lists, 'name'));
        $this->assertSame(['customer'], wp_list_pluck($this->call('GET', '/tags', null, 'read')->get_data(), 'name'));
        $this->assertSame(['company', 'orders'], wp_list_pluck($this->call('GET', '/fields', null, 'read')->get_data(), 'key'));
    }

    /* Changing --------------------------------------------------------------------------------- */

    public function test_patch_tags_lists_unsubscribe_and_delete()
    {
        Subscription::add_by_admin('maya@example.org', [$this->workshops], [], true);
        $id = Subscribers::get_by_email('maya@example.org')['id'];

        $patched = $this->call('PATCH', '/subscribers/' . $id, ['first_name' => 'Maya', 'fields' => ['company' => 'Okafor']])->get_data();
        $this->assertSame('Maya', $patched['first_name']);
        $this->assertSame('Okafor', $patched['fields']->company);

        $this->assertSame(['customer', 'gold'], $this->call('POST', "/subscribers/$id/tags", ['tags' => ['customer', 'gold'], 'create_missing' => true])->get_data()['tags']);
        $this->assertSame(['gold'], $this->call('DELETE', "/subscribers/$id/tags/customer")->get_data()['tags']);

        // Adding to a double opt-in list without consent asks them to confirm.
        reset_phpmailer_instance();
        $added = $this->call('POST', "/subscribers/$id/lists", ['lists' => ['Newsletter']])->get_data();
        $this->assertTrue($added['confirmation_sent']);
        $this->assertSame('pending', Subscribers::get_membership($id, $this->newsletter)['status']);

        // Taking them off a list is not an unsubscribe.
        $this->call('DELETE', "/subscribers/$id/lists/Newsletter");
        $this->assertNull(Subscribers::get_membership($id, $this->newsletter));
        $this->assertFalse(SuppressionRepository::is_suppressed('maya@example.org'));

        // A real unsubscribe from everything suppresses the address.
        $this->assertSame('unsubscribed', $this->call('POST', "/subscribers/$id/unsubscribe", [])->get_data()['status']);
        $this->assertTrue(SuppressionRepository::is_suppressed('maya@example.org'));

        $this->assertSame(200, $this->call('DELETE', "/subscribers/$id")->get_status());
        $this->assertNull(Subscribers::get($id));
        $this->assertSame(404, $this->call('DELETE', "/subscribers/$id")->get_status());
    }

    /* Review fixes ----------------------------------------------------------------------------- */

    public function test_rate_limit_counts_each_request_once_even_on_routes_with_many_methods()
    {
        add_filter('mo_api_rate_limit', function () {
            return 3;
        });
        Subscription::add_by_admin('maya@example.org', [$this->workshops], [], true);
        $id = Subscribers::get_by_email('maya@example.org')['id'];

        // /subscribers/{id} has GET, PATCH, PUT and DELETE: one request is still one.
        foreach ([1, 2, 3] as $n) $this->assertSame(200, $this->call('GET', '/subscribers/' . $id)->get_status(), "request $n");
        $this->assertSame(429, $this->call('GET', '/subscribers/' . $id)->get_status());
    }

    public function test_unsubscribe_with_a_bad_list_is_an_error_not_everything()
    {
        Subscription::add_by_admin('maya@example.org', [$this->workshops, $this->newsletter], [], true);
        $id = Subscribers::get_by_email('maya@example.org')['id'];

        foreach ([' ', ['Newsletter'], 1.5, 'Nope', 999] as $bad) {
            $r = $this->call('POST', "/subscribers/$id/unsubscribe", ['list' => $bad]);
            $this->assertSame(404, $r->get_status(), wp_json_encode($bad));
        }

        $this->assertSame('subscribed', Subscribers::get($id)['status']);
        $this->assertFalse(SuppressionRepository::is_suppressed('maya@example.org'));
        $this->assertWPError(mailoptin_unsubscribe_subscriber('maya@example.org', ['Workshops']));
        $this->assertSame(404, $this->call('DELETE', "/subscribers/$id/lists/%20")->get_status());

        // A real list works, and only that list.
        $this->assertSame(200, $this->call('POST', "/subscribers/$id/unsubscribe", ['list' => 'Workshops'])->get_status());
        $this->assertSame('unsubscribed', Subscribers::get_membership($id, $this->workshops)['status']);
        $this->assertSame('subscribed', Subscribers::get_membership($id, $this->newsletter)['status']);
    }

    public function test_create_missing_creates_nothing_when_the_request_fails()
    {
        SuppressionRepository::add('bounced@example.org', SuppressionRepository::REASON_BOUNCED, 'test');
        $lists = count(ListsRepository::get_all());

        $this->assertSame(409, $this->call('POST', '/subscribers', ['email' => 'bounced@example.org', 'lists' => ['Brand new'], 'tags' => ['brand-new'], 'create_missing' => true])->get_status());
        $this->assertSame(400, $this->call('POST', '/subscribers', ['email' => 'x@example.org', 'lists' => ['Brand new'], 'tags' => ['brand-new'], 'fields' => ['nope' => 1], 'create_missing' => true])->get_status());
        $this->assertSame(400, $this->call('POST', '/subscribers', ['email' => 'x@example.org', 'lists' => ['Brand new'], 'tags' => [9999], 'create_missing' => true])->get_status());

        $this->assertCount($lists, ListsRepository::get_all());
        $this->assertNull(Tags::get_by_name('brand-new'));
    }

    public function test_a_list_they_unsubscribed_from_is_refused_even_with_consent()
    {
        Subscription::add_by_admin('maya@example.org', [$this->workshops, $this->newsletter], [], true);
        $id = Subscribers::get_by_email('maya@example.org')['id'];
        Subscription::unsubscribe($id, $this->workshops);

        $r = $this->call('POST', '/subscribers', ['email' => 'maya@example.org', 'lists' => ['Workshops'], 'consent' => ['given' => true]]);
        $this->assertSame(409, $r->get_status());
        $this->assertSame('unsubscribed', Subscribers::get_membership($id, $this->workshops)['status']);
    }

    public function test_repeating_a_request_does_not_resend_the_confirmation()
    {
        $this->call('POST', '/subscribers', ['email' => 'tom@example.org', 'lists' => ['Newsletter']]);
        $again = $this->call('POST', '/subscribers', ['email' => 'tom@example.org', 'lists' => ['Newsletter']])->get_data();
        $this->call('POST', '/subscribers', ['email' => 'tom@example.org', 'lists' => ['Newsletter']]);

        $this->assertFalse($again['confirmation_sent']);
        $this->assertSame(1, $this->mails_to('tom@example.org'));

        // Consent later still subscribes them.
        $this->assertSame('subscribed', $this->call('POST', '/subscribers', ['email' => 'tom@example.org', 'lists' => ['Newsletter'], 'consent' => ['given' => true]])->get_data()['status']);
    }

    public function test_list_changes_show_up_in_updated_since()
    {
        Subscription::add_by_admin('maya@example.org', [$this->workshops, $this->newsletter], [], true);
        $id = Subscribers::get_by_email('maya@example.org')['id'];

        global $wpdb;
        $old = function () use ($wpdb, $id) {
            $wpdb->update(Subscribers::table(), ['updated_at' => '2020-01-01 00:00:00'], ['id' => $id]);
        };
        $since = ['updated_since' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)];
        $found = function () use ($since) {
            return wp_list_pluck($this->call('GET', '/subscribers', null, 'read', $since)->get_data(), 'email');
        };

        $other = ListsRepository::create(['name' => 'Offers', 'double_optin' => 0]);
        $old();
        $this->call('POST', "/subscribers/$id/lists", ['lists' => [$other], 'consent' => ['given' => true]]);
        $this->assertContains('maya@example.org', $found());

        $old();
        $this->call('POST', "/subscribers/$id/unsubscribe", ['list' => 'Offers']);
        $this->assertContains('maya@example.org', $found());
    }

    public function test_numbers_are_ids_only_when_such_an_id_exists()
    {
        $tag = Tags::create('2024');
        Subscription::add_by_admin('maya@example.org', [$this->workshops], [], true);
        Tags::add_to_subscribers([$tag], [Subscribers::get_by_email('maya@example.org')['id']]);

        // No tag has ID 2024, so "2024" is the tag's name.
        $this->assertSame([], $this->call('DELETE', '/subscribers/maya@example.org/tags/2024')->get_data()['tags']);
        $this->assertSame(['1999'], $this->call('POST', '/subscribers/maya@example.org/tags', ['tags' => ['1999'], 'create_missing' => true])->get_data()['tags']);
    }

    public function test_bad_filters_and_odd_input_are_refused_cleanly()
    {
        $this->assertSame(400, $this->call('GET', '/subscribers', null, 'read', ['list' => ''])->get_status());
        $this->assertSame(400, $this->call('GET', '/subscribers', null, 'read', ['list' => ['a', 'b']])->get_status());
        $this->assertSame(400, $this->call('GET', '/subscribers', null, 'read', ['tag' => ''])->get_status());

        $this->assertSame('mo_invalid_email', $this->call('POST', '/subscribers', ['email' => ['x@example.org'], 'lists' => ['Workshops']])->get_data()['code']);
        $r = $this->call('POST', '/subscribers', ['email' => 'arr@example.org', 'first_name' => ['x'], 'lists' => ['Workshops'], 'consent' => ['given' => true, 'text' => ['y']]]);
        $this->assertSame(201, $r->get_status());
        $this->assertSame('', $r->get_data()['first_name']);
    }

    public function test_last_used_is_kept_apart_from_the_keys()
    {
        $id = explode('_', $this->write_key)[2];
        $this->call('GET', '/lists');

        $this->assertArrayNotHasKey('last_used_at', get_option(ApiKeys::OPTION)[$id], 'Recording use never rewrites the keys.');
        $this->assertGreaterThan(0, ApiKeys::get($id)['last_used_at']);

        ApiKeys::revoke($id);
        $this->assertArrayNotHasKey($id, (array)get_option(ApiKeys::USED_OPTION));
    }

    /* PHP functions ---------------------------------------------------------------------------- */

    public function test_php_functions_follow_the_same_rules()
    {
        $added = mailoptin_add_subscriber(['email' => 'shop@example.org', 'lists' => ['Workshops'], 'tags' => ['customer'], 'consent' => ['given' => true]]);
        $this->assertSame('subscribed', $added['status']);
        $this->assertTrue($added['created']);

        $this->assertSame(['customer', 'repeat-buyer'], mailoptin_tag_subscriber('shop@example.org', 'repeat-buyer')['tags']);
        $this->assertSame(['repeat-buyer'], mailoptin_untag_subscriber('shop@example.org', 'customer')['tags']);
        $this->assertSame('shop@example.org', mailoptin_get_subscriber('shop@example.org')['email']);
        $this->assertNull(mailoptin_get_subscriber('nobody@example.org'));

        $this->assertSame('unsubscribed', mailoptin_unsubscribe_subscriber('shop@example.org')['status']);
        $refused = mailoptin_add_subscriber(['email' => 'shop@example.org', 'lists' => ['Workshops'], 'consent' => ['given' => true]]);
        $this->assertWPError($refused);
        $this->assertSame(409, $refused->get_error_data()['status']);
        $this->assertWPError(mailoptin_tag_subscriber('nobody@example.org', 'x'));
    }
}
