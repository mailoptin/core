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

        // An unknown role or source in a saved selection is dropped, never read as "any role".
        $this->assertSame([], Sources::campaign_selection([['source' => 'users', 'option' => 'no-such-role'], ['source' => 'nope', 'option' => '']]));
    }

    public function test_someone_who_unsubscribed_from_everything_stays_out_even_after_ticking_a_box()
    {
        $this->user('una@example.com', 'editor');
        $this->mode('users', AbstractSource::MODE_EVERYONE);

        $id = Subscribers::create(['email' => 'una@example.com', 'status' => Subscribers::STATUS_UNSUBSCRIBED]);
        $this->assertNotContains('una@example.com', $this->emails(Sources::get('users'), 'editor'));

        // A box ticked somewhere (perhaps by someone else, at a guest checkout) doesn't undo it.
        Sources::agree('users', 'una@example.com', 'Email me news');
        $this->assertNotContains('una@example.com', $this->emails(Sources::get('users'), 'editor'));
        unset($id);
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

    public function test_unsubscribe_link_suppresses_and_a_bad_token_does_nothing()
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
        $this->assertTrue(SuppressionRepository::is_suppressed('ana@example.com'), 'Ticking a box elsewhere doesn\'t undo an unsubscribe.');
        SuppressionRepository::remove('ana@example.com');

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

    public function test_woocommerce_consent_is_recorded_once_the_order_is_paid()
    {
        $order = function ($email, $block_box = '') {
            return new class($email, $block_box) {
                public $email;
                public $meta = [];
                public function __construct($email, $block_box) { $this->email = $email; if ($block_box) $this->meta['_wc_other/' . WooCustomers::FIELD_ID] = $block_box; }
                public function get_billing_email() { return $this->email; }
                public function get_order_number() { return '1042'; }
                public function get_meta($key) { return $this->meta[$key] ?? ''; }
                public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
                public function save_meta_data() { }
            };
        };

        (new WooCustomers())->register_checkbox('News and offers');

        // Block checkout: the box is on the order; nothing is recorded until it's paid.
        $block = $order('block@example.com', '1');
        do_action('woocommerce_order_status_changed', 1, 'pending', 'failed', $block);
        $this->assertNull(Consents::get('woo', 'block@example.com'), 'An unpaid order records nothing: anyone can type any address.');
        do_action('woocommerce_order_status_changed', 1, 'pending', 'processing', $block);
        $this->assertSame('Order #1042', Consents::get('woo', 'block@example.com')['reference']);

        // Classic checkout: the ticked box is kept on the order, then recorded on payment, once.
        $classic = $order('classic@example.com');
        $_POST   = [WooCustomers::CLASSIC_FIELD => '1'];
        do_action('woocommerce_checkout_order_processed', 7, [], $classic);
        $_POST = [];
        $this->assertNull(Consents::get('woo', 'classic@example.com'));
        do_action('woocommerce_order_status_changed', 7, 'pending', 'on-hold', $classic);
        $this->assertSame('News and offers', Consents::get('woo', 'classic@example.com')['consent_text']);

        Consents::withdraw('woo', 'classic@example.com');
        do_action('woocommerce_order_status_changed', 7, 'on-hold', 'completed', $classic);
        $this->assertSame('withdrawn', Consents::get('woo', 'classic@example.com')['status'], 'Recorded once per order, so a later status change doesn\'t undo a withdrawal.');

        $unticked = $order('noclassic@example.com');
        do_action('woocommerce_checkout_order_processed', 8, [], $unticked);
        do_action('woocommerce_order_status_changed', 8, 'pending', 'completed', $unticked);
        $this->assertNull(Consents::get('woo', 'noclassic@example.com'));
    }

    public function test_woocommerce_customers_come_from_paid_orders_within_the_window()
    {
        $woo = $this->woo_store();
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
        $this->assertSame(AbstractSource::MODE_AGREED, Sources::settings('pmpro')['mode'], 'Members too: nobody gets marketing without agreeing until the owner says otherwise.');

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
        $this->assertFalse(SuppressionRepository::is_suppressed('ana@example.com'));

        // A withdrawal outlives erasure as a suppression hash, or "everyone" would email them again.
        Sources::agree('users', 'ben@example.com', 'Email me news');
        Consents::withdraw('users', 'ben@example.com');
        PersonalData::erase('ben@example.com');
        $this->assertNull(Consents::get('users', 'ben@example.com'));
        $this->assertTrue(SuppressionRepository::is_suppressed('ben@example.com'));
    }

    public function test_a_choice_that_no_longer_exists_never_widens_to_everyone()
    {
        $this->user('ed@example.com', 'editor');
        $this->user('au@example.com', 'author');
        $this->mode('users', AbstractSource::MODE_EVERYONE);

        add_role('gardeners', 'Gardeners');
        $this->choose([['source' => 'users', 'option' => 'gardeners']]);
        $this->assertTrue(Broadcasts::has_sources($this->campaign_id));

        remove_role('gardeners');

        $this->assertSame([], Sources::campaign_selection(Broadcasts::get($this->campaign_id, Sources::CAMPAIGN_SETTING)));
        $this->assertSame(0, Broadcasts::recipients_count($this->campaign_id));
        $this->assertFalse(Broadcasts::can_send($this->campaign_id));
        $this->assertContains('The role chosen for Registered users no longer exists.', wp_list_pluck(Broadcasts::checks($this->campaign_id, false), 'label'));
    }

    public function test_source_ids_fit_the_send_queue()
    {
        $long = new class extends RegisteredUsers {
            public function id() { return str_repeat('a', 24); }
        };
        Sources::add($long);
        $this->assertNull(Sources::get(str_repeat('a', 24)));

        $this->assertLessThanOrEqual(30, strlen(ContactRenderer::type(str_repeat('a', 23))));
    }

    public function test_a_persons_name_is_looked_up_by_address()
    {
        $this->user('Name@Example.com', 'editor', 'Nell');

        $this->assertSame('Nell', Sources::person('users', 'name@example.com')['first_name']);
        $this->assertSame('', Sources::person('users', 'nobody@example.com')['first_name']);
    }

    protected function subscriber_matches($filters)
    {
        $result = Subscribers::query($filters + ['per_page' => 100]);

        return array_values(array_map('strtolower', wp_list_pluck($result['items'] ?? $result, 'email')));
    }

    public function test_member_condition_filters_subscribers_by_role_for_free()
    {
        $this->user('ed@example.com', 'editor');
        $this->user('au@example.com', 'author');
        Subscribers::create(['email' => 'ed@example.com', 'status' => Subscribers::STATUS_SUBSCRIBED]);
        Subscribers::create(['email' => 'au@example.com', 'status' => Subscribers::STATUS_SUBSCRIBED]);
        Subscribers::create(['email' => 'nobody@example.com', 'status' => Subscribers::STATUS_SUBSCRIBED]);

        $filters = \MailOptin\Core\Admin\SettingsPage\Subscriber_Filters::from_request(['sc' => [['what' => 'member', 'source' => 'users', 'option' => 'editor']]]);
        $this->assertCount(1, $filters['store'], 'Free: no Premium needed for "is one of".');

        $this->assertSame(['ed@example.com'], $this->subscriber_matches($filters));

        // Purchase conditions need Premium. On a free site one in the URL (a bookmark from when it
        // was Premium) is kept and matches nobody, rather than being dropped and widening the view.
        $premium = \MailOptin\Core\Admin\SettingsPage\Subscriber_Filters::from_request(['sc' => [['what' => 'spent', 'source' => 'users', 'op' => 'gt', 'amount' => '5']]]);
        $this->assertCount(1, $premium['store']);
        $this->assertSame([], $this->subscriber_matches($premium));

        // So is one that can't be read.
        $broken = \MailOptin\Core\Admin\SettingsPage\Subscriber_Filters::from_request(['sc' => [['what' => 'bought', 'source' => 'woo', 'target' => 'product']]]);
        $this->assertSame('invalid', $broken['store'][0]['what']);
        $this->assertSame([], $this->subscriber_matches($broken));
        $this->assertNotEmpty(SegmentsRepository::problems(['filters' => SegmentsRepository::clean_filters($broken)]), 'Saved as a segment, it blocks sending.');
    }

    public function test_a_condition_that_cant_be_applied_matches_nobody_and_blocks_a_segment()
    {
        Subscribers::create(['email' => 'ed@example.com', 'status' => Subscribers::STATUS_SUBSCRIBED]);

        $filters = ['store' => [['what' => 'member', 'source' => 'pmpro', 'option' => '']]];

        $this->assertSame([], $this->subscriber_matches($filters), 'Paid Memberships Pro isn\'t active: nobody, not everybody.');
        $this->assertNotEmpty(SegmentsRepository::problems(['filters' => SegmentsRepository::clean_filters($filters)]));

        $this->assertNull(\MailOptin\Core\Audience\Sources\StoreConditions::clean(['what' => 'bought', 'source' => 'woo', 'target' => 'product', 'ids' => []]), 'Bought nothing in particular isn\'t a condition.');
        $this->assertNull(\MailOptin\Core\Audience\Sources\StoreConditions::clean(['what' => 'nope', 'source' => 'woo']));
    }

    public function test_filter_chips_and_query_round_trip()
    {
        $condition = ['what' => 'member', 'source' => 'users', 'option' => 'editor'];
        $filters   = \MailOptin\Core\Admin\SettingsPage\Subscriber_Filters::from_request(['sc' => [$condition]]);
        $query     = \MailOptin\Core\Admin\SettingsPage\Subscriber_Filters::to_query($filters);

        $this->assertSame($filters['store'], \MailOptin\Core\Admin\SettingsPage\Subscriber_Filters::from_request($query)['store']);
        $this->assertStringContainsString('Registered users: Editor', \MailOptin\Core\Admin\SettingsPage\Subscriber_Filters::chips_html($filters, admin_url()));
    }

    public function test_customizer_picker_is_for_campaigns_not_automation_emails()
    {
        require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';

        $controls = function ($campaign_id) {
            $wp_customize = new \WP_Customize_Manager();
            $customizer   = (object)['campaign_settings_section_id' => 'campaign_settings', 'email_campaign_id' => $campaign_id];

            return array_keys((new \MailOptin\MailOptinConnect\CampaignAudience())->controls([], $wp_customize, 'mo_email_campaigns[' . $campaign_id . ']', $customizer));
        };

        $post_notification = ER::add_email_campaign('New post ' . uniqid(), ER::NEW_PUBLISH_POST, 'Lucid');
        $automation_email  = ER::add_email_campaign('Auto ' . uniqid(), \MailOptin\Core\Repositories\AutomationsRepository::EMAIL_CAMPAIGN_TYPE, 'Lucid');

        $this->assertContains(Sources::CAMPAIGN_SETTING, $controls($post_notification));
        $this->assertNotContains(Sources::CAMPAIGN_SETTING, $controls($automation_email));
    }

    public function test_customizer_setting_keeps_values_with_quotes()
    {
        add_role('o"neill\\crew', 'Odd role');

        $settings = (new \MailOptin\MailOptinConnect\CampaignAudience())->settings([]);
        $sanitize = $settings[Sources::CAMPAIGN_SETTING]['sanitize_callback'];

        // What the Customizer passes: already unslashed JSON.
        $value = wp_json_encode([['source' => 'users', 'option' => 'o"neill\\crew']]);

        $this->assertSame([['source' => 'users', 'option' => 'o"neill\\crew']], Sources::campaign_selection($sanitize($value)));

        remove_role('o"neill\\crew');
    }
}
