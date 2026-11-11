<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\Broadcast_Page;
use MailOptin\Core\Audience\PersonalData;
use MailOptin\Core\Audience\Sources\AbstractSource;
use MailOptin\Core\Audience\Sources\ContactRenderer;
use MailOptin\Core\Audience\Sources\RegisteredUsers;
use MailOptin\Core\Audience\Sources\Sources;
use MailOptin\Core\Audience\Sources\WooCustomers;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\Core\Repositories\AudienceConsentsRepository as Consents;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SegmentsRepository;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\SendQueue\QueueRunner;

class AudienceSourcesTest extends AudienceSourcesTestCase
{
    public function test_only_people_who_agreed_or_are_subscribed_by_default()
    {
        $this->user('ana@example.com', 'editor');
        $this->user('ben@example.com', 'editor');
        $this->user('cleo@example.com', 'editor');
        $this->user('dev@example.com', 'editor');

        // A subscriber counts as agreed; so does ticking the box; withdrawing and suppression don't.
        Subscribers::create(['email' => 'ana@example.com', 'status' => Subscribers::STATUS_SUBSCRIBED]);
        Sources::agree('users', 'Ben@Example.com', 'Email me news', 'Registration');
        Sources::agree('users', 'cleo@example.com', 'Email me news');
        Consents::withdraw('users', 'cleo@example.com');
        Sources::agree('users', 'dev@example.com', 'Email me news');
        SuppressionRepository::add('dev@example.com', SuppressionRepository::REASON_BOUNCED);

        $source = Sources::get('users');

        $this->assertSame(['ana@example.com', 'ben@example.com'], $this->emails($source, 'editor'));

        $this->mode('users', AbstractSource::MODE_EVERYONE);
        $this->assertSame(['ana@example.com', 'ben@example.com'], $this->emails($source, 'editor'), 'Everyone still leaves out who withdrew or is suppressed.');
    }

    public function test_everyone_mode_and_role_option()
    {
        $this->user('ed@example.com', 'editor');
        $this->user('au@example.com', 'author');
        $this->mode('users', AbstractSource::MODE_EVERYONE);

        $source = Sources::get('users');

        $this->assertContains('ed@example.com', $this->emails($source, 'editor'));
        $this->assertNotContains('au@example.com', $this->emails($source, 'editor'), 'The role narrows it.');
        $this->assertContains('au@example.com', $this->emails($source, ''));

        // An unknown role in a saved selection means any role, not none: it's dropped on read.
        $this->assertSame([['source' => 'users', 'option' => '']], Sources::campaign_selection([['source' => 'users', 'option' => 'no-such-role'], ['source' => 'nope', 'option' => '']]));
    }

    public function test_someone_who_unsubscribed_from_everything_is_left_out_until_they_tick_the_box_again()
    {
        $this->user('una@example.com', 'editor');
        $this->mode('users', AbstractSource::MODE_EVERYONE);

        $id = Subscribers::create(['email' => 'una@example.com', 'status' => Subscribers::STATUS_UNSUBSCRIBED]);
        $this->assertNotContains('una@example.com', $this->emails(Sources::get('users'), 'editor'));

        global $wpdb;
        $wpdb->update(Subscribers::table(), ['updated_at' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)], ['id' => $id]);
        Sources::agree('users', 'una@example.com', 'Email me news');

        $this->assertContains('una@example.com', $this->emails(Sources::get('users'), 'editor'));
    }

    public function test_a_broadcast_to_users_only_sends_with_names_and_their_own_unsubscribe()
    {
        $this->user('ana@example.com', 'editor', 'Ana');
        $this->user('ben@example.com', 'editor', 'Ben');
        $this->mode('users', AbstractSource::MODE_EVERYONE);

        $sub = Subscribers::create(['email' => 'ben@example.com', 'first_name' => 'Benny', 'status' => Subscribers::STATUS_SUBSCRIBED]);

        $this->choose([['source' => 'users', 'option' => 'editor']]);

        $this->assertSame(2, Broadcasts::recipients_count($this->campaign_id));
        $this->assertTrue(Broadcasts::can_send($this->campaign_id), 'No list is needed when people are chosen.');

        // One-click unsubscribe headers are only sent over SMTP (see BuiltInListsTest).
        add_action('phpmailer_init', function ($phpmailer) {
            $phpmailer->isSMTP();
        }, 1);

        $log_id = $this->send();

        $rows = $this->queue_rows();
        $this->assertSame([ContactRenderer::type('users'), ContactRenderer::type('users')], wp_list_pluck($rows, 'recipient_type'));
        $this->assertSame([0, $sub], array_map('intval', wp_list_pluck($rows, 'recipient_id')));

        $mail = [];
        foreach (tests_retrieve_phpmailer_instance()->mock_sent as $message) $mail[$message['to'][0][0]] = $message;

        $this->assertCount(2, $mail);
        $this->assertSame('Hi Ana', $mail['ana@example.com']['subject'], 'A non-subscriber gets their name from the source.');
        $this->assertStringContainsString('mo_src_unsub=', $mail['ana@example.com']['body']);
        $this->assertStringContainsString('List-Unsubscribe: <' . home_url('/') . '?mo_src_unsub=', $mail['ana@example.com']['header']);

        $this->assertSame('Hi Benny', $mail['ben@example.com']['subject'], 'A subscriber is personalized as one.');
        $this->assertStringContainsString('mo_unsub=' . $sub, $mail['ben@example.com']['body']);

        $this->assertNotEmpty(Subscribers::get_events($sub, 10), 'Sent is logged for subscribers.');
        unset($log_id);
    }

    public function test_list_and_source_together_reach_each_person_once()
    {
        $this->user('ana@example.com', 'editor');
        $this->user('ben@example.com', 'editor');
        $this->mode('users', AbstractSource::MODE_EVERYONE);

        $ana = Subscribers::create(['email' => 'ana@example.com', 'status' => Subscribers::STATUS_SUBSCRIBED]);
        Subscribers::set_membership($ana, $this->list_id, Subscribers::STATUS_SUBSCRIBED);
        $zoe = Subscribers::create(['email' => 'zoe@example.com', 'status' => Subscribers::STATUS_SUBSCRIBED]);
        Subscribers::set_membership($zoe, $this->list_id, Subscribers::STATUS_SUBSCRIBED);

        Broadcasts::save($this->campaign_id, ['connection_email_list' => (string)$this->list_id]);
        $this->choose([['source' => 'users', 'option' => 'editor']]);

        $this->assertSame(3, Broadcasts::recipients_count($this->campaign_id));

        $this->send();

        $rows = $this->queue_rows();
        $this->assertSame(['ana@example.com', 'ben@example.com', 'zoe@example.com'], wp_list_pluck($rows, 'email'));
        $this->assertSame('subscriber', $rows[0]['recipient_type'], 'On the list and a user: queued once, from the list.');
        $this->assertCount(3, tests_retrieve_phpmailer_instance()->mock_sent);
    }

    public function test_unsubscribe_link_suppresses_and_ticking_again_lifts_it()
    {
        $this->user('ana@example.com', 'editor');
        $this->mode('users', AbstractSource::MODE_EVERYONE);

        $url = ContactRenderer::unsubscribe_url(['email' => 'Ana@example.com'], null, 1);
        parse_str(wp_parse_url($url, PHP_URL_QUERY), $query);

        $_GET                      = $query;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        add_filter('wp_die_handler', function () {
            return function () {
                throw new \RuntimeException('died');
            };
        });

        try {
            ContactRenderer::get_instance()->unsubscribe_request();
        } catch (\RuntimeException $e) {
        }
        unset($_SERVER['REQUEST_METHOD']);

        $this->assertTrue(SuppressionRepository::is_suppressed('ana@example.com'));
        $this->assertNotContains('ana@example.com', $this->emails(Sources::get('users'), 'editor'));

        Sources::agree('users', 'ana@example.com', 'Email me news', 'Registration');
        $this->assertFalse(SuppressionRepository::is_suppressed('ana@example.com'), 'They asked again.');

        // A bad token does nothing.
        $_GET = ['mo_src_unsub' => 'ana@example.com', 'mo_token' => 'nope'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            ContactRenderer::get_instance()->unsubscribe_request();
        } catch (\RuntimeException $e) {
        }
        unset($_SERVER['REQUEST_METHOD']);
        $this->assertFalse(SuppressionRepository::is_suppressed('ana@example.com'));
    }

    public function test_withdrawing_after_queueing_skips_them()
    {
        $this->user('ana@example.com', 'editor');
        Sources::agree('users', 'ana@example.com', 'Email me news');
        $this->choose([['source' => 'users', 'option' => 'editor']]);

        Broadcasts::send($this->campaign_id);
        Consents::withdraw('users', 'ana@example.com');

        global $wpdb;
        QueueRunner::get_instance()->process((int)$wpdb->get_var('SELECT MAX(campaign_log_id) FROM ' . SendQueueRepository::table()));

        $this->assertCount(0, tests_retrieve_phpmailer_instance()->mock_sent);
        $this->assertSame('skipped', $wpdb->get_var('SELECT status FROM ' . SendQueueRepository::table()));
    }

    public function test_registration_checkbox_records_consent_only_when_ticked_on_the_form()
    {
        (new RegisteredUsers())->register_checkbox('Email me news');

        $_REQUEST = ['action' => 'register'];
        $_POST    = [RegisteredUsers::FIELD => '1'];
        $this->user('yes@example.com');

        $_POST = [];
        $this->user('no@example.com');

        // An admin adding a user isn't the form, even with the field posted.
        $_REQUEST = ['action' => 'createuser'];
        $_POST    = [RegisteredUsers::FIELD => '1'];
        $this->user('admin-made@example.com');

        $this->assertSame('agreed', Consents::get('users', 'yes@example.com')['status']);
        $this->assertSame('Email me news', Consents::get('users', 'yes@example.com')['consent_text']);
        $this->assertNull(Consents::get('users', 'no@example.com'));
        $this->assertNull(Consents::get('users', 'admin-made@example.com'));
    }

    public function test_woocommerce_checkbox_records_consent_from_classic_and_block_checkout()
    {
        $order = function ($email, $consent) {
            return new class($email, $consent) {
                public $email;
                public $consent;
                public function __construct($email, $consent) { $this->email = $email; $this->consent = $consent; }
                public function get_billing_email() { return $this->email; }
                public function get_order_number() { return '1042'; }
                public function get_meta($key) { return '_wc_other/' . WooCustomers::FIELD_ID === $key ? $this->consent : ''; }
            };
        };

        (new WooCustomers())->register_checkbox('News and offers');

        do_action('woocommerce_store_api_checkout_order_processed', $order('block@example.com', '1'));
        do_action('woocommerce_store_api_checkout_order_processed', $order('noblock@example.com', ''));

        $_POST = [WooCustomers::CLASSIC_FIELD => '1'];
        do_action('woocommerce_checkout_order_processed', 7, [], $order('classic@example.com', ''));
        $_POST = [];
        do_action('woocommerce_checkout_order_processed', 8, [], $order('noclassic@example.com', ''));

        $this->assertSame('Order #1042', Consents::get('woo', 'block@example.com')['reference']);
        $this->assertSame('agreed', Consents::get('woo', 'classic@example.com')['status']);
        $this->assertNull(Consents::get('woo', 'noblock@example.com'));
        $this->assertNull(Consents::get('woo', 'noclassic@example.com'));
    }

    public function test_woocommerce_customers_come_from_paid_orders_within_the_window()
    {
        global $wpdb;

        $wpdb->query("CREATE TABLE {$wpdb->prefix}wc_customer_lookup (customer_id bigint(20) NOT NULL, user_id bigint(20) NULL, first_name varchar(255) NOT NULL DEFAULT '', last_name varchar(255) NOT NULL DEFAULT '', email varchar(100) NULL)");
        $wpdb->query("CREATE TABLE {$wpdb->prefix}wc_order_stats (order_id bigint(20) NOT NULL, parent_id bigint(20) NOT NULL DEFAULT 0, date_created datetime NOT NULL, date_created_gmt datetime NOT NULL, total_sales double NOT NULL DEFAULT 0, status varchar(200) NOT NULL, customer_id bigint(20) NOT NULL)");

        $customers = [[1, 5, 'Maya', 'O', 'Maya@Example.com'], [2, null, 'Gus', 'T', 'guest@example.com'], [3, 6, 'Old', 'C', 'old@example.com'], [4, 7, 'Pen', 'D', 'pending@example.com']];
        foreach ($customers as $c) {
            $wpdb->insert("{$wpdb->prefix}wc_customer_lookup", ['customer_id' => $c[0], 'user_id' => $c[1], 'first_name' => $c[2], 'last_name' => $c[3], 'email' => $c[4]]);
        }

        $recent = gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS);
        $old    = gmdate('Y-m-d H:i:s', strtotime('-30 months'));
        $orders = [[10, 0, $recent, 'wc-completed', 1, 40], [11, 0, $recent, 'wc-processing', 1, 10], [12, 10, $recent, 'wc-completed', 1, 5], [13, 0, $recent, 'wc-completed', 2, 20], [14, 0, $old, 'wc-completed', 3, 9], [15, 0, $recent, 'wc-pending', 4, 9]];
        foreach ($orders as $o) {
            $wpdb->insert("{$wpdb->prefix}wc_order_stats", ['order_id' => $o[0], 'parent_id' => $o[1], 'date_created' => $o[2], 'date_created_gmt' => $o[2], 'status' => $o[3], 'customer_id' => $o[4], 'total_sales' => $o[5]]);
        }

        // WooCommerce itself isn't loaded in tests; its tables are enough.
        $woo = new class extends WooCustomers {
            public function is_active() { return true; }
        };
        Sources::add($woo);
        Sources::save_settings(['woo' => ['mode' => 'everyone', 'months' => '24', 'guests' => '1']]);

        $got = $this->emails($woo);
        $this->assertSame(['guest@example.com', 'maya@example.com'], $got, 'Paid orders in the last 24 months, guests too, one row each.');

        Sources::save_settings(['woo' => ['mode' => 'everyone', 'months' => '0', 'guests' => '']]);
        $this->assertSame(['maya@example.com', 'old@example.com'], $this->emails($woo), 'Any time, no guests.');

        $summary = $woo->profile_summary('maya@example.com');
        $this->assertSame('2', $summary['Orders'], 'The refund child order isn\'t an order of its own.');
        $this->assertSame([], $woo->profile_summary('pending@example.com'), 'An unpaid order isn\'t a customer.');
    }

    public function test_settings_are_sanitized_and_defaults_apply()
    {
        $this->assertSame(AbstractSource::MODE_AGREED, Sources::settings('users')['mode']);
        $this->assertSame(AbstractSource::MODE_EVERYONE, Sources::settings('pmpro')['mode'], 'Members default to everyone.');

        Sources::save_settings(['users' => ['mode' => 'bogus', 'checkbox_text' => '<b>Yes</b> please']]);
        $this->assertSame(AbstractSource::MODE_AGREED, Sources::settings('users')['mode']);
        $this->assertSame('Yes please', Sources::settings('users')['checkbox_text']);

        Sources::save_settings(['users' => ['mode' => 'agreed', 'checkbox_text' => '   ']]);
        $this->assertNotSame('', Sources::settings('users')['checkbox_text'], 'An empty text falls back to the default.');
    }

    public function test_broadcast_page_saves_the_chosen_sources_and_draws_them()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $_GET     = ['page' => MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG, 'view' => Broadcast_Page::VIEW, 'id' => $this->campaign_id];
        $_POST    = [
            'mo_broadcast_action'     => 'save',
            'id'                      => $this->campaign_id,
            '_wpnonce'                => wp_create_nonce('mo_broadcast_' . $this->campaign_id),
            'audience_sources_posted' => '1',
            'audience_sources'        => ['users' => ['on' => '1', 'option' => 'editor'], 'nope' => ['on' => '1']],
        ];
        $_REQUEST = $_GET + $_POST;
        set_current_screen('dashboard');

        add_filter('mailoptin_broadcast_page_redirect', function () {
            throw new \RuntimeException('redirected');
        });

        try {
            Broadcast_Page::get_instance()->handle_actions();
        } catch (\RuntimeException $e) {
        }

        $this->assertSame([['source' => 'users', 'option' => 'editor']], Sources::campaign_selection(Broadcasts::get($this->campaign_id, Sources::CAMPAIGN_SETTING)));

        // Unticking everything clears it.
        $_POST['audience_sources'] = [];
        $_REQUEST                  = $_GET + $_POST;
        try {
            Broadcast_Page::get_instance()->handle_actions();
        } catch (\RuntimeException $e) {
        }
        $this->assertSame([], Sources::campaign_selection(Broadcasts::get($this->campaign_id, Sources::CAMPAIGN_SETTING)));
    }

    public function test_personal_data_export_and_erase_include_consent()
    {
        Sources::agree('users', 'ana@example.com', 'Email me news', 'Registration');

        $export = PersonalData::export('ana@example.com');
        $this->assertSame('mailoptin-marketing-consent', $export['data'][0]['group_id']);

        PersonalData::erase('ana@example.com');
        $this->assertNull(Consents::get('users', 'ana@example.com'));
    }
}
