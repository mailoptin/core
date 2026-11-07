<?php

namespace MailOptin\Core\Tests\Design;

use MailOptin\Core\Admin\SetupWizard;
use MailOptin\Core\RegisterActivation\Base as Activation;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\OptinCampaignsRepository;

class SetupWizardTest extends \WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option(SetupWizard::PENDING_OPTION);
        delete_option(SetupWizard::STATE_OPTION);
    }

    public function tear_down()
    {
        $_GET = [];
        unset($GLOBALS['pagenow']);
        parent::tear_down();
    }

    public function test_fresh_install_flags_the_wizard_but_reactivation_and_network_installs_do_not()
    {
        delete_option('mo_plugin_activated');
        delete_option('mo_install_date');
        Activation::mo_install();
        $this->assertSame('1', get_option(SetupWizard::PENDING_OPTION));

        // Reactivation: the install date is still there.
        delete_option(SetupWizard::PENDING_OPTION);
        delete_option('mo_plugin_activated');
        Activation::mo_install();
        $this->assertFalse(get_option(SetupWizard::PENDING_OPTION));

        // Network-wide activation of a fresh site.
        delete_option('mo_plugin_activated');
        delete_option('mo_install_date');
        Activation::mo_install(false);
        $this->assertFalse(get_option(SetupWizard::PENDING_OPTION));
    }

    public function test_redirects_once_then_never_again()
    {
        $GLOBALS['pagenow']          = 'index.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        SetupWizard::flag_new_install();

        $redirects = [];
        add_filter('wp_redirect', function ($location) use (&$redirects) {
            $redirects[] = $location;

            return false; // stop wp_safe_redirect so the test keeps running
        });

        SetupWizard::get_instance()->maybe_redirect_after_install();
        SetupWizard::get_instance()->maybe_redirect_after_install();

        $this->assertCount(1, $redirects);
        $this->assertStringContainsString('page=mailoptin-setup', $redirects[0]);
        $this->assertFalse(get_option(SetupWizard::PENDING_OPTION));
    }

    public function test_redirect_waits_for_a_plain_page_view()
    {
        SetupWizard::flag_new_install();
        $redirects = 0;
        add_filter('wp_redirect', function () use (&$redirects) {
            $redirects++;

            return false;
        });

        // A form post to another screen, and a screen that isn't the dashboard, Plugins or MailOptin.
        foreach ([['POST', 'index.php', []], ['GET', 'post.php', []], ['GET', 'admin.php', ['page' => 'woocommerce']]] as [$method, $pagenow, $get]) {
            $_SERVER['REQUEST_METHOD'] = $method;
            $GLOBALS['pagenow']        = $pagenow;
            $_GET                      = $get;
            SetupWizard::get_instance()->maybe_redirect_after_install();
        }

        $this->assertSame(0, $redirects);
        $this->assertSame('1', get_option(SetupWizard::PENDING_OPTION));

        // An array where a string is expected: ignored, no warning.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $GLOBALS['pagenow']        = 'admin.php';
        $_GET                      = ['page' => ['mailoptin']];
        SetupWizard::get_instance()->maybe_redirect_after_install();
        $this->assertSame(0, $redirects);

        $_GET = ['page' => 'mailoptin-subscribers'];
        SetupWizard::get_instance()->maybe_redirect_after_install();
        $this->assertSame(1, $redirects);
    }

    public function test_redirect_waits_for_someone_who_can_run_it()
    {
        $GLOBALS['pagenow']          = 'index.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        SetupWizard::flag_new_install();
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        SetupWizard::get_instance()->maybe_redirect_after_install();

        $this->assertSame('1', get_option(SetupWizard::PENDING_OPTION));
    }

    public function test_list_step_creates_a_list_or_uses_an_existing_one()
    {
        $wizard = SetupWizard::get_instance();

        $this->assertWPError($wizard->save_step('list', ['list_name' => '  ']));

        set_transient('mo_connection_email_list_MailOptinConnect', ['1' => 'Old list'], MINUTE_IN_SECONDS);
        $this->assertTrue($wizard->save_step('list', ['list_name' => 'Garden news', 'double_optin' => '1']));
        $this->assertFalse(get_transient('mo_connection_email_list_MailOptinConnect'), 'The Customizer list dropdown cache is cleared.');
        $state = get_option(SetupWizard::STATE_OPTION);
        $list  = ListsRepository::get($state['list_id']);
        $this->assertSame('Garden news', $list['name']);
        $this->assertSame('1', (string)$list['double_optin']);

        $other = ListsRepository::create(['name' => 'Existing', 'double_optin' => 0]);
        $this->assertTrue($wizard->save_step('list', ['list_id' => $other, 'list_name' => 'ignored']));
        $this->assertSame((int)$other, get_option(SetupWizard::STATE_OPTION)['list_id']);
        $this->assertCount(2, ListsRepository::get_all());
    }

    public function test_connect_form_fills_an_empty_row_or_adds_one_and_is_idempotent()
    {
        \MailOptin\Core\RegisterActivation\CreateDBTables::make();
        $list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 0]);

        $empty = OptinCampaignsRepository::add_optin_campaign(wp_generate_password(10, false), 'Empty form', 'BareMetal', 'lightbox');
        OptinCampaignsRepository::update_settings_by_id($empty, ['integrations' => wp_json_encode([['connection_service' => '', 'connection_email_list' => '']])]);

        $mailchimp = OptinCampaignsRepository::add_optin_campaign(wp_generate_password(10, false), 'Mailchimp form', 'BareMetal', 'lightbox');
        OptinCampaignsRepository::update_settings_by_id($mailchimp, ['integrations' => wp_json_encode([['connection_service' => 'MailChimpConnect', 'connection_email_list' => 'abc']])]);

        $this->assertGreaterThan(0, $empty);
        $this->assertFalse(SetupWizard::connect_form(0, $list_id));
        $this->assertTrue(SetupWizard::connect_form($empty, $list_id));
        $this->assertTrue(SetupWizard::connect_form($mailchimp, $list_id));
        $this->assertFalse(SetupWizard::connect_form($mailchimp, $list_id), 'Already connected: no duplicate row.');

        $rows = json_decode(OptinCampaignsRepository::get_customizer_value($empty, 'integrations'), true);
        $this->assertCount(1, $rows);
        $this->assertSame('MailOptinConnect', $rows[0]['connection_service']);
        $this->assertSame((string)$list_id, $rows[0]['connection_email_list']);

        $rows = json_decode(OptinCampaignsRepository::get_customizer_value($mailchimp, 'integrations'), true);
        $this->assertSame(['MailChimpConnect', 'MailOptinConnect'], wp_list_pluck($rows, 'connection_service'), 'The existing service keeps working.');
    }

    public function test_sender_step_validates_and_keeps_other_settings()
    {
        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, ['reply_to' => 'keep@example.com', 'from_email' => 'old@example.com']);

        $this->assertWPError(SetupWizard::get_instance()->save_step('sender', ['from_email' => 'nope']));
        $this->assertSame('old@example.com', get_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME)['from_email']);

        $this->assertTrue(SetupWizard::get_instance()->save_step('sender', [
            'from_name' => 'Acme <b>Garden</b>', 'from_email' => 'hello@acme.test', 'company_address' => '412 Orchard Lane', 'company_country' => 'US',
        ]));

        $settings = get_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME);
        $this->assertSame('Acme Garden', $settings['from_name']);
        $this->assertSame('hello@acme.test', $settings['from_email']);
        $this->assertSame('412 Orchard Lane', $settings['company_address']);
        $this->assertSame('keep@example.com', $settings['reply_to']);
        $this->assertTrue(get_option(SetupWizard::STATE_OPTION)['sender']);
    }

    public function test_test_step_sends_from_the_saved_sender()
    {
        reset_phpmailer_instance();
        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, ['from_name' => 'Acme', 'from_email' => 'hello@acme.test']);

        $this->assertTrue(SetupWizard::get_instance()->save_step('test', ['mo_send_test' => '1', 'test_email' => 'me@example.com']));

        $mailer = tests_retrieve_phpmailer_instance();
        $this->assertSame('me@example.com', $mailer->get_recipient('to')->address);
        $this->assertSame('hello@acme.test', $mailer->From);
        $this->assertSame('Acme', $mailer->FromName);
        $this->assertSame('me@example.com', get_option(SetupWizard::STATE_OPTION)['test']);

        // A cleared From email falls back to the site's admin email.
        reset_phpmailer_instance();
        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, ['from_name' => '', 'from_email' => '']);
        $this->assertTrue(SetupWizard::get_instance()->save_step('test', ['mo_send_test' => '1', 'test_email' => 'me@example.com']));
        $this->assertSame(get_bloginfo('admin_email'), tests_retrieve_phpmailer_instance()->From);

        // "Skip for now" posts the same step without the send button.
        $this->assertTrue(SetupWizard::get_instance()->save_step('test', ['test_email' => 'me@example.com']));
    }

    public function test_every_step_renders()
    {
        foreach (SetupWizard::STEPS as $step) {
            $_GET = ['page' => SetupWizard::SLUG, 'step' => $step];
            ob_start();
            SetupWizard::get_instance()->render();
            $html = ob_get_clean();

            $this->assertStringContainsString('mo-setup-card', $html, $step);
            if ('done' !== $step) $this->assertStringContainsString('name="mo_setup_step" value="' . $step . '"', $html, $step);
        }
    }

    public function test_error_box_only_shows_known_messages()
    {
        $_GET = ['page' => SetupWizard::SLUG, 'step' => 'list', 'mo_error' => 'name'];
        ob_start();
        SetupWizard::get_instance()->render();
        $this->assertStringContainsString('Give your list a name.', ob_get_clean());

        $_GET['mo_error'] = 'Your account is suspended, call 555';
        ob_start();
        SetupWizard::get_instance()->render();
        $html = ob_get_clean();
        $this->assertStringNotContainsString('suspended', $html);
        $this->assertStringNotContainsString('mo-ui-note mo-ui-bad', $html);
    }

    public function test_settings_has_a_run_setup_wizard_button()
    {
        set_current_screen('dashboard');
        $settings = \MailOptin\Core\Admin\SettingsPage\Settings::get_instance();
        do_action('mailoptin_admin_settings_page_pre', 'general');

        ob_start();
        $settings->settings_admin_page_callback();
        $html = ob_get_clean();

        $this->assertStringContainsString('page=mailoptin-setup', $html);
        $this->assertStringContainsString('Run setup wizard', $html);
    }
}
