<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Audience\CampaignRenderer;
use MailOptin\Core\Audience\PreferenceCentre;
use MailOptin\Core\Audience\PublicEndpoints;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use WP_UnitTestCase;

class PreferenceCentreTest extends WP_UnitTestCase
{
    protected $news;
    protected $workshops;
    protected $id;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        DBUpdates::get_instance()->update_routine_7();
        DBUpdates::get_instance()->update_routine_17();
        DBUpdates::get_instance()->update_routine_20();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        Fields::flush_cache();

        if (is_null(\MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process)) {
            \MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process = new \MailOptin\Core\AsyncHandler\BGProcess();
        }
        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        $this->news      = ListsRepository::create(['name' => 'Newsletter', 'description' => 'Monthly planting notes', 'double_optin' => 1]);
        $this->workshops = ListsRepository::create(['name' => 'Workshops', 'double_optin' => 0]);

        Subscription::add_by_admin('amara@example.com', [$this->news, $this->workshops], ['first_name' => 'Amara'], true);
        $this->id = (int)Subscribers::get_by_email('amara@example.com')['id'];
    }

    public function tear_down()
    {
        $_GET = $_POST = $_REQUEST = [];
        unset($_SERVER['REQUEST_METHOD']);
        Fields::flush_cache();

        parent::tear_down();
    }

    /**
     * @return array [status code, html]
     */
    protected function visit($post = null, $token = null)
    {
        $subscriber = Subscribers::get($this->id);
        $url        = PreferenceCentre::url($subscriber);
        parse_str(wp_parse_url($url, PHP_URL_QUERY), $query);
        if (null !== $token) $query['mo_token'] = $token;

        $_GET     = $query;
        $_POST    = $post ? wp_slash($post) : [];
        $_REQUEST = array_merge($_GET, $_POST);
        $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';

        $status = 0;
        $capture = function ($handler) use (&$status) {
            return function ($message, $title, $args) use (&$status) {
                $status = $args['response'] ?? 200;
                throw new \WPDieException(is_string($message) ? $message : '');
            };
        };
        add_filter('wp_die_handler', $capture, 99);

        try {
            PreferenceCentre::get_instance()->handle_request();
            $this->fail('The page should have been shown.');
        } catch (\WPDieException $e) {
            return [$status, $e->getMessage()];
        } finally {
            remove_filter('wp_die_handler', $capture, 99);
        }
    }

    public function test_a_bad_link_shows_nothing_personal()
    {
        list($status, $html) = $this->visit(null, 'forged');

        $this->assertSame(404, $status);
        $this->assertStringNotContainsString('amara', $html);
    }

    public function test_viewing_shows_lists_and_only_editable_fields_and_changes_nothing()
    {
        $zone   = Fields::create(['type' => 'dropdown', 'label' => 'Climate zone', 'options' => ['6a', '7b'], 'subscriber_editable' => true]);
        $secret = Fields::create(['type' => 'text', 'label' => 'Lead score']);

        list($status, $html) = $this->visit();

        $this->assertSame(200, $status);
        $this->assertStringContainsString('a••••@example.com', $html);
        $this->assertStringNotContainsString('amara@example.com', $html);
        $this->assertStringContainsString('Monthly planting notes', $html);
        $this->assertSame(2, substr_count($html, 'name="lists[]"'));
        $this->assertStringContainsString('name="fields[' . $zone . ']"', $html);
        $this->assertStringNotContainsString('name="fields[' . $secret . ']"', $html);
        $this->assertStringContainsString('value="Amara"', $html);
        $this->assertSame('subscribed', Subscribers::get_membership($this->id, $this->news)['status']);
    }

    public function test_new_fields_are_not_editable_by_subscribers_until_allowed()
    {
        $this->assertFalse(Fields::get(Fields::create(['type' => 'text', 'label' => 'Company']))['subscriber_editable']);
    }

    public function test_saving_leaves_lists_and_updates_details()
    {
        $zone   = Fields::create(['type' => 'dropdown', 'label' => 'Climate zone', 'options' => ['6a', '7b'], 'subscriber_editable' => true]);
        $orders = Fields::create(['type' => 'number', 'label' => 'Orders', 'subscriber_editable' => true]);
        $secret = Fields::create(['type' => 'text', 'label' => 'Lead score']);

        list(, $html) = $this->visit(['lists_shown' => '1', 'lists' => [$this->news], 'first_name' => 'Ama', 'last_name' => 'Okafor', 'fields' => [$zone => '7b', $orders => 'many', $secret => '100']]);

        $this->assertStringContainsString('Orders must be a number', $html);
        $this->assertSame('unsubscribed', Subscribers::get_membership($this->id, $this->workshops)['status']);
        $this->assertSame('subscribed', Subscribers::get($this->id)['status']);
        $this->assertSame(['Ama', 'Okafor'], [Subscribers::get($this->id)['first_name'], Subscribers::get($this->id)['last_name']]);
        $values = Fields::merge_values($this->id);
        $this->assertSame(['7b', '', ''], [$values['climate_zone'], $values['orders'], $values['lead_score']], 'Fields the admin keeps to themselves can\'t be set.');

        $event = Subscribers::get_events($this->id)[0];
        $this->assertSame(Subscribers::EVENT_PREFERENCES_CHANGED, $event['type']);
        $meta = json_decode($event['meta'], true);
        $this->assertSame(['Workshops'], $meta['left']);
        $this->assertCount(3, $meta['fields']);
    }

    public function test_unticking_everything_unsubscribes_and_ticking_again_rejoins()
    {
        list(, $html) = $this->visit(['lists_shown' => '1']);

        $this->assertStringContainsString('get any more emails from us', $html);
        $this->assertSame('unsubscribed', Subscribers::get($this->id)['status']);
        $this->assertTrue(SuppressionRepository::is_suppressed('amara@example.com'));

        // The signed link proves the inbox is theirs, like a confirmation click.
        $this->visit(['lists_shown' => '1', 'lists' => [$this->news]]);

        $this->assertSame('subscribed', Subscribers::get($this->id)['status']);
        $this->assertSame('subscribed', Subscribers::get_membership($this->id, $this->news)['status']);
        $this->assertSame('unsubscribed', Subscribers::get_membership($this->id, $this->workshops)['status']);
        $this->assertFalse(SuppressionRepository::is_suppressed('amara@example.com'));
    }

    public function test_a_pending_list_is_unticked_and_ticking_it_confirms_only_that_list()
    {
        $third = ListsRepository::create(['name' => 'Offers', 'double_optin' => 1]);
        Subscription::add_by_admin('tom@example.com', [$this->news, $third], [], false);
        $this->id = (int)Subscribers::get_by_email('tom@example.com')['id'];

        list(, $html) = $this->visit();
        $this->assertStringContainsString('Not confirmed yet', $html);
        $this->assertDoesNotMatchRegularExpression('/value="' . $this->news . '"\s+checked/', $html, 'A box ticked for them is not consent.');

        $this->visit(['lists_shown' => '1', 'was' => [$this->news => 0, $third => 0], 'lists' => [$this->news]]);
        $this->assertSame('subscribed', Subscribers::get_membership($this->id, $this->news)['status']);
        $this->assertSame('pending', Subscribers::get_membership($this->id, $third)['status'], 'Only the list they ticked.');
    }

    public function test_saving_details_never_confirms_a_pending_list()
    {
        Subscription::add_by_admin('tom@example.com', [$this->workshops], [], true);
        $this->id = (int)Subscribers::get_by_email('tom@example.com')['id'];
        Subscribers::set_membership($this->id, $this->news, Subscribers::STATUS_PENDING);

        $this->visit(['lists_shown' => '1', 'was' => [$this->news => 0, $this->workshops => 1], 'lists' => [$this->workshops], 'first_name' => 'Tom']);

        $this->assertSame('pending', Subscribers::get_membership($this->id, $this->news)['status']);
    }

    public function test_a_stale_cached_page_does_not_resubscribe()
    {
        // The page showed both lists ticked; they unsubscribed from everything afterwards.
        Subscription::unsubscribe($this->id);

        $this->visit(['lists_shown' => '1', 'was' => [$this->news => 1, $this->workshops => 1], 'lists' => [$this->news, $this->workshops], 'first_name' => 'Amara']);

        $this->assertSame('unsubscribed', Subscribers::get($this->id)['status']);
        $this->assertTrue(SuppressionRepository::is_suppressed('amara@example.com'));
    }

    public function test_moving_between_lists_never_unsubscribes_from_everything_in_between()
    {
        Subscription::unsubscribe($this->id, $this->news);
        $hooks = [];
        $spy   = function () use (&$hooks) {
            $hooks[] = current_filter();
        };
        add_action('mo_subscriber_unsubscribed', $spy);
        add_action('mo_subscriber_confirmed', $spy);

        $this->visit(['lists_shown' => '1', 'was' => [$this->news => 0, $this->workshops => 1], 'lists' => [$this->news]]);

        $this->assertSame(['mo_subscriber_confirmed', 'mo_subscriber_unsubscribed'], $hooks);
        $this->assertSame('subscribed', Subscribers::get($this->id)['status']);
        $this->assertFalse(SuppressionRepository::is_suppressed('amara@example.com'));
    }

    public function test_a_value_whose_option_was_removed_survives_a_save()
    {
        $zone = Fields::create(['type' => 'dropdown', 'label' => 'Zone', 'options' => ['6a', '7b'], 'subscriber_editable' => true]);
        $plants = Fields::create(['type' => 'multiselect', 'label' => 'Plants', 'options' => ['Roses', 'Veg'], 'subscriber_editable' => true]);
        Fields::set_values($this->id, [$zone => '6a', $plants => ['Roses', 'Veg']]);
        Fields::update($zone, ['options' => "7b\n8a"]);
        Fields::update($plants, ['options' => 'Veg']);

        list(, $html) = $this->visit();
        $this->assertStringContainsString('<option value="6a" selected>6a</option>', $html);
        $this->assertMatchesRegularExpression('/value="Roses"\s+checked/', $html);

        $this->visit(['fields' => [$zone => '6a', $plants => ['', 'Roses', 'Veg']]]);
        $this->assertSame(['6a', 'Roses, Veg'], [Fields::merge_values($this->id)['zone'], Fields::merge_values($this->id)['plants']]);
    }

    public function test_untouched_names_are_not_rewritten()
    {
        global $wpdb;
        $wpdb->update(Subscribers::table(), ['first_name' => 'Amara  Jo'], ['id' => $this->id]);

        $this->visit(['first_name' => sanitize_text_field('Amara  Jo'), 'last_name' => '']);

        $this->assertSame('Amara  Jo', Subscribers::get($this->id)['first_name']);
        $this->assertNotSame(Subscribers::EVENT_PREFERENCES_CHANGED, Subscribers::get_events($this->id)[0]['type']);
    }

    public function test_the_unsubscribe_page_masks_the_address()
    {
        $subscriber = Subscribers::get($this->id);
        parse_str(wp_parse_url(\MailOptin\Core\Audience\Tokens::unsubscribe_url($subscriber), PHP_URL_QUERY), $_GET);
        $_REQUEST = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            (new PublicEndpoints())->unsubscribe();
        } catch (\WPDieException $e) {
            $html = $e->getMessage();
        }

        $this->assertStringContainsString('a••••@example.com', $html);
        $this->assertStringNotContainsString('amara@example.com', $html);
    }

    public function test_link_injection_handles_real_world_footers()
    {
        // Outlook-only copy first: the link goes next to the one everyone sees.
        $mso = '<!--[if mso]><a href="{{unsubscribe}}">U</a><![endif]--><a class="mo-footer-unsubscribe-link-color" href = \'{{unsubscribe}}\'>Unsubscribe</a>';
        $this->assertSame(
            '<!--[if mso]><a href="{{unsubscribe}}">U</a><![endif]--><a class="mo-footer-unsubscribe-link-color" href = \'{{unsubscribe}}\'>Unsubscribe</a> &middot; <a href="{{preferences}}" class="mo-footer-unsubscribe-link-color">Manage preferences</a>',
            PreferenceCentre::add_link($mso, true)
        );

        $data = '<a data-href="{{unsubscribe}}" href="#">x</a> <a href="{{unsubscribe}}">Unsubscribe</a>';
        $this->assertSame('<a data-href="{{unsubscribe}}" href="#">x</a> <a href="{{unsubscribe}}">Unsubscribe</a> &middot; <a href="{{preferences}}">Manage preferences</a>', PreferenceCentre::add_link($data, true));

        $translated = function ($text, $original) {
            return 'Manage preferences' === $original ? 'Pay $1 \\1' : $text;
        };
        add_filter('gettext', $translated, 10, 2);
        $this->assertStringContainsString('>Pay $1 \\1</a>', PreferenceCentre::add_link('<a href="{{unsubscribe}}">U</a>', true));
        remove_filter('gettext', $translated, 10);

        $this->assertSame("Hi\r\nUnsubscribe: {{unsubscribe}}\r\nManage preferences: {{preferences}}\r\nBye", PreferenceCentre::add_link("Hi\r\nUnsubscribe: {{unsubscribe}}\r\nBye", false));
    }

    public function test_bounced_addresses_cannot_be_resubscribed_here()
    {
        SuppressionRepository::add('amara@example.com', SuppressionRepository::REASON_BOUNCED, 'test');
        Subscription::unsubscribe($this->id);

        list(, $html) = $this->visit();
        $this->assertStringContainsString('bounced or were marked as spam', $html);
        $this->assertStringContainsString('disabled', $html);

        $this->visit(['lists_shown' => '1', 'lists' => [$this->news]]);
        $this->assertSame('unsubscribed', Subscribers::get($this->id)['status']);
        $this->assertSame(SuppressionRepository::REASON_BOUNCED, SuppressionRepository::get_reason('amara@example.com'));
    }

    public function test_link_is_added_next_to_unsubscribe_unless_placed_or_turned_off()
    {
        $html = '<p>Bye <a class="x" href="{{unsubscribe}}">Unsubscribe</a> <a href="{{unsubscribe}}">again</a></p>';

        $this->assertSame('<p>Bye <a class="x" href="{{unsubscribe}}">Unsubscribe</a> &middot; <a href="{{preferences}}" class="x">Manage preferences</a> <a href="{{unsubscribe}}">again</a></p>', PreferenceCentre::add_link($html, true));
        $this->assertSame("Hi\nUnsubscribe: {{unsubscribe}}\nManage preferences: {{preferences}}\nAddress", PreferenceCentre::add_link("Hi\nUnsubscribe: {{unsubscribe}}\nAddress", false));
        $this->assertSame('<a href="{{preferences}}">x</a> {{unsubscribe}}', PreferenceCentre::add_link('<a href="{{preferences}}">x</a> {{unsubscribe}}', true));

        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, [PreferenceCentre::LINK_SETTING => 'off']);
        $this->assertSame($html, PreferenceCentre::add_link($html, true));
    }

    public function test_campaign_emails_get_their_personal_link_and_the_web_version_does_not()
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'mo_campaign_log', ['email_campaign_id' => 0, 'title' => 'News', 'content_html' => '', 'content_text' => '', 'status' => 'draft']);
        $log = (int)$wpdb->insert_id;
        CampaignRenderer::save_campaign_content($log, $this->news, '<p><a href="{{unsubscribe}}">Unsubscribe</a></p>', "Unsubscribe: {{unsubscribe}}");

        $content = CampaignRenderer::get_instance()->personalize('', ['recipient_type' => 'subscriber', 'recipient_id' => $this->id], $log);
        $url     = esc_html(PreferenceCentre::url(Subscribers::get($this->id)));

        $this->assertStringContainsString('<a href="' . $url . '">Manage preferences</a>', $content['html']);
        $this->assertStringContainsString('Manage preferences: ' . PreferenceCentre::url(Subscribers::get($this->id)), $content['text']);
        $this->assertStringNotContainsString('mo_prefs', PublicEndpoints::web_version_html($log));
    }

    public function test_a_confirmation_link_confirms_only_the_lists_its_email_named()
    {
        Subscription::add_by_admin('tom@example.com', [$this->news], [], false);
        $tom   = Subscribers::get_by_email('tom@example.com');
        $email = Subscription::confirmation_email($tom);
        preg_match('#href="([^"]*mo_confirm[^"]*)"#', $email['html'], $m);
        $url = html_entity_decode($m[1]);

        // Added to another list before clicking.
        $offers = ListsRepository::create(['name' => 'Partner offers', 'double_optin' => 1]);
        Subscribers::set_membership($tom['id'], $offers, Subscribers::STATUS_PENDING);

        parse_str(wp_parse_url($url, PHP_URL_QUERY), $_GET);
        $_REQUEST = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            (new PublicEndpoints())->confirm();
        } catch (\WPDieException $e) {
        }

        $this->assertSame('subscribed', Subscribers::get_membership($tom['id'], $this->news)['status']);
        $this->assertSame('pending', Subscribers::get_membership($tom['id'], $offers)['status']);

        // Tampering with the lists breaks the signature.
        $_GET['mo_lists'] = \MailOptin\Core\Audience\Tokens::list_param([$this->news, $offers]);
        $_REQUEST = $_GET;
        try {
            (new PublicEndpoints())->confirm();
        } catch (\WPDieException $e) {
        }
        $this->assertSame('pending', Subscribers::get_membership($tom['id'], $offers)['status']);

        // Links sent before lists were signed still confirm everything pending.
        $_GET = ['mo_confirm' => $tom['id'], 'mo_token' => \MailOptin\Core\Audience\Tokens::make('confirm', [$tom['id'], $tom['email']])];
        $_REQUEST = $_GET;
        try {
            (new PublicEndpoints())->confirm();
        } catch (\WPDieException $e) {
        }
        $this->assertSame('subscribed', Subscribers::get_membership($tom['id'], $offers)['status']);
    }
}
