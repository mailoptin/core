<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\SendingSettings;
use MailOptin\Core\AsyncHandler\AsyncHandler;
use MailOptin\Core\AsyncHandler\BGProcess;
use MailOptin\Core\Audience\EmailBranding;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Audience\WelcomeEmail;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\SendQueue\QueueRunner;
use WP_UnitTestCase;

class EmailBrandingTest extends WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        if (is_null(AsyncHandler::get_instance()->bg_process)) {
            AsyncHandler::get_instance()->bg_process = new BGProcess();
        }

        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        reset_phpmailer_instance();
    }

    protected function brand($values)
    {
        $settings = get_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, []);
        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, array_merge($settings, $values));
    }

    protected function logo_id()
    {
        return self::factory()->attachment->create_object(['file' => 'acme-logo.png', 'post_mime_type' => 'image/png', 'post_title' => 'Acme logo']);
    }

    public function test_defaults_and_bad_values_fall_back()
    {
        $b = EmailBranding::settings();
        $this->assertSame(EmailBranding::DEFAULT_ACCENT, $b['accent']);
        $this->assertSame(EmailBranding::DEFAULT_BACKGROUND, $b['background']);
        $this->assertSame('', $b['logo_url']);
        $this->assertSame('left', $b['logo_align']);

        $this->brand([EmailBranding::ACCENT => 'red; background:url(x)', EmailBranding::LOGO_WIDTH => '9000', EmailBranding::LOGO_ALIGN => 'diagonal']);

        $b = EmailBranding::settings();
        $this->assertSame(EmailBranding::DEFAULT_ACCENT, $b['accent'], 'Only hex colours are accepted.');
        $this->assertSame(560, $b['logo_width']);
        $this->assertSame('left', $b['logo_align']);
    }

    public function test_frame_applies_logo_colours_and_alt_text()
    {
        $this->brand([
            'company_name'                 => 'Acme Garden Supply',
            EmailBranding::LOGO_ID         => $this->logo_id(),
            EmailBranding::LOGO_WIDTH      => '160',
            EmailBranding::LOGO_ALIGN      => 'center',
            EmailBranding::ACCENT          => '#2f7d4f',
            EmailBranding::BACKGROUND      => '#f3efe6',
        ]);

        $html = EmailBranding::frame('<p>Hi <a href="https://example.org">link</a> <a href="#" style="color:red">own style</a></p>', 'Footer');

        $this->assertStringContainsString('background:#f3efe6', $html);
        $this->assertMatchesRegularExpression('#<div style="text-align:center;[^"]*"><img src="[^"]*acme-logo\.png" alt="Acme Garden Supply" width="160"#', $html);
        $this->assertStringContainsString('<a style="color:#2f7d4f" href="https://example.org">', $html);
        $this->assertStringContainsString('<a href="#" style="color:red">', $html, 'A link with its own style keeps it.');
    }

    public function test_confirmation_email_uses_the_accent_button()
    {
        $this->brand([EmailBranding::ACCENT => '#2f7d4f', EmailBranding::BACKGROUND => '#f3efe6']);
        $list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 1]);

        Subscription::subscribe('ines@example.com', $list_id, ['first_name' => 'Ines']);

        $body = tests_retrieve_phpmailer_instance()->mock_sent[0]['body'];
        $this->assertStringContainsString('background:#2f7d4f;color:#ffffff', $body);
        $this->assertStringContainsString('background:#f3efe6', $body);
        $this->assertStringContainsString('Yes, subscribe me', $body);
    }

    public function test_changing_branding_updates_welcome_emails_not_sent_yet()
    {
        $list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 0]);
        WelcomeEmail::save($list_id, true, 'Welcome', 'Hello <a href="https://example.org">there</a>');

        Subscription::subscribe('ines@example.com', $list_id, []);

        // Saved after the welcome email was queued.
        $this->brand([EmailBranding::ACCENT => '#aa3377']);

        reset_phpmailer_instance();
        QueueRunner::get_instance()->run_all();

        $this->assertStringContainsString('<a style="color:#aa3377" href="https://example.org">', tests_retrieve_phpmailer_instance()->mock_sent[0]['body']);
    }

    public function test_postal_address_saved_later_reaches_welcome_emails()
    {
        $list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 0]);
        WelcomeEmail::save($list_id, true, 'Welcome', 'Hello');
        Subscription::subscribe('ines@example.com', $list_id, []);

        $this->brand(['company_name' => 'Acme Garden Supply', 'company_address' => '412 Orchard Lane']);

        reset_phpmailer_instance();
        QueueRunner::get_instance()->run_all();

        $this->assertStringContainsString('Acme Garden Supply, 412 Orchard Lane', tests_retrieve_phpmailer_instance()->mock_sent[0]['body']);
    }

    public function test_settings_section_renders()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->brand([EmailBranding::ACCENT => '#2f7d4f']);

        $settings = SendingSettings::get_instance()->settings_tab([]);
        $html     = $settings['sending_settings'][1]['email_branding']['data'];

        $this->assertStringContainsString('name="mailoptin_settings[email_brand_accent]" value="#2f7d4f"', $html);
        $this->assertStringContainsString('id="mo-brand-preview"', $html);
        $this->assertStringContainsString('Send test to', $html);
    }
}
