<?php

namespace MailOptin\Tests\Core\Automations;

use MailOptin\Core\Admin\EmailEditor\EmailContext;
use MailOptin\Core\Admin\EmailEditor\EmailEditor;
use MailOptin\Core\Admin\SettingsPage\Automations_Page;
use MailOptin\Core\Admin\SettingsPage\Broadcast_Page;
use MailOptin\Core\EmailCampaigns\Newsletter\Templatify;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * One editor, one template picker, one launcher for every kind of email: broadcasts and
 * automation emails differ only in their context.
 */
class EmailLaunchTest extends EngineTest
{
    public function set_up()
    {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        set_current_screen('dashboard');
        // A broadcast's status is read from the campaign log and meta tables.
        \MailOptin\Core\RegisterActivation\CreateDBTables::make();
        \MailOptin\Core\DBUpdates::get_instance()->update_routine_7();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        add_filter('pre_http_request', function () { return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []]; });
    }

    public function test_a_new_broadcast_starts_in_its_template_with_starter_content()
    {
        $id = EmailEditor::start('broadcast', 'Lucid', ['name' => 'November news']);

        $this->assertIsInt($id);
        $this->assertTrue(ER::is_newsletter($id));
        $this->assertSame('Lucid', ER::get_template_class($id));
        $this->assertTrue(EmailEditor::edits($id));

        $content = json_decode(ER::get_customizer_value($id, 'email_newsletter_content'), true);
        $this->assertSame(['text', 'button', 'text'], array_column($content, 'type'));
        $this->assertSame('16', $content[0]['settings']['text_font_size'], "Saved with the block's defaults, as a block added in the editor.");
        $this->assertStringContainsString('Write your message here.', (new Templatify($id))->forge());

        // Its context: back to its page, and on to audience and sending.
        $context = EmailEditor::context_for($id);
        $this->assertSame('broadcast', $context->id());
        $this->assertSame(Broadcast_Page::url($id), $context->back_url($id));
        $this->assertSame(Broadcast_Page::url($id), $context->next_step($id)['url']);
        $this->assertSame(EmailEditor::url($id), Broadcast_Page::design_url($id), 'Design email opens the editor, not the Customizer.');
        $this->assertStringContainsString('Hi there,', $context->preview_html($id, (new Templatify($id))->forge()), 'The preview shows fallbacks.');
    }

    public function test_a_broadcast_needs_a_free_name_and_a_template_it_may_use()
    {
        $this->assertSame('mo_email_name', EmailEditor::start('broadcast', 'Lucid', ['name' => '  '])->get_error_code());

        EmailEditor::start('broadcast', 'Lucid', ['name' => 'Taken']);
        $this->assertSame('mo_email_name', EmailEditor::start('broadcast', 'Lucid', ['name' => 'Taken'])->get_error_code());

        $this->assertSame('mo_email_template', EmailEditor::start('broadcast', 'NoSuchTemplate', ['name' => 'X'])->get_error_code());
        $this->assertSame('mo_email_context', EmailEditor::start('nope', 'Lucid', [])->get_error_code());
    }

    public function test_an_email_written_as_html_opens_in_the_editor_as_code()
    {
        $id = EmailEditor::start('broadcast', ER::CODE_YOUR_OWN_TEMPLATE, ['name' => 'Hand made']);

        $this->assertTrue(EmailEditor::is_code($id));
        $this->assertTrue(EmailEditor::edits($id));
        $start = EmailEditor::state($id)['code'];
        $this->assertStringContainsString('[unsubscribe]', $start, 'It starts with a short email, unsubscribe link included.');
        $this->assertStringContainsString('Write your message here.', $start);
        $this->assertStringNotContainsString('Lorem ipsum', $start, "Not the Customizer's long sample.");

        $html   = '<html><body><h1>Hello</h1><a href="[unsubscribe]">Unsubscribe</a><script>bad()</script></body></html>';
        $values = EmailEditor::clean_state(['code' => $html, 'settings' => ['email_campaign_title' => 'Hi'], 'content' => [['type' => 'text']]], $id);

        $this->assertSame(['code_your_own', 'email_campaign_title'], array_keys($values), 'HTML and settings only: no blocks.');
        $this->assertStringContainsString('<h1>Hello</h1>', $values['code_your_own']);

        // Those who may not post unfiltered HTML keep a whole email (head, style, body, tables),
        // without scripts or event attributes.
        add_filter('map_meta_cap', $no_unfiltered = function ($caps, $cap) { return 'unfiltered_html' === $cap ? ['do_not_allow'] : $caps; }, 10, 2);
        $doc   = '<!doctype html><html><head><meta charset="utf-8"><style>.x{color:red}</style></head><body bgcolor="#fff"><table role="presentation" width="600" cellpadding="0"><tr><td class="x" onclick="bad()">Hi</td></tr></table><!--[if mso]><p>Outlook</p><![endif]--><script>bad()</script></body></html>';
        $clean = EmailEditor::clean_state(['code' => $doc], $id)['code_your_own'];
        foreach (['<!doctype html>', '<head>', '<style>.x{color:red}</style>', '<body bgcolor="#fff">', 'width="600"', 'cellpadding="0"', '<td class="x">', '<!--[if mso]>'] as $kept) {
            $this->assertStringContainsString($kept, $clean);
        }
        $this->assertStringNotContainsString('<script>', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $starter = EmailEditor::clean_state(['code' => EmailEditor::starter_html()], $id)['code_your_own'];
        foreach (['<head>', '<title>', 'role="presentation"', 'href="[unsubscribe]"'] as $kept) $this->assertStringContainsString($kept, $starter, 'The starter email survives a save.');
        remove_filter('map_meta_cap', $no_unfiltered);

        ER::update_settings_by_id($id, ['code_your_own' => $values['code_your_own']]);
        $this->assertStringContainsString('Hello', (new Templatify($id))->forge());
    }

    public function test_adding_an_email_to_an_automation_asks_for_its_template_and_adds_the_step()
    {
        $automation = Repo::create(['name' => 'Welcome', 'trigger_id' => 'test_event', 'status' => Repo::STATUS_DRAFT]);

        $id = EmailEditor::start('automation', 'Lucid', ['automation_id' => $automation]);

        $this->assertIsInt($id);
        $this->assertTrue(ER::is_automation_email($id));
        $steps = Repo::steps($automation);
        $this->assertSame((int)$id, (int)end($steps)['settings']['email_campaign_id'], 'The step that sends it is the last one.');
        $this->assertNotEmpty(json_decode(ER::get_customizer_value($id, 'email_newsletter_content'), true), 'Starter content.');

        $context = EmailEditor::context_for($id);
        $this->assertSame('automation', $context->id());
        $this->assertSame(Automations_Page::edit_url($automation), $context->back_url($id));
        $this->assertNull($context->next_step($id));

        $this->assertSame('mo_email_create', EmailEditor::start('automation', 'Lucid', ['automation_id' => 999999])->get_error_code());
    }

    public function test_the_picker_is_one_form_for_every_kind_of_email()
    {
        $broadcast  = EmailEditor::picker_html('broadcast', [], '<input name="args[name]">');
        $automation = EmailEditor::picker_html('automation', ['automation_id' => 7], '', 'Create and edit', true);

        foreach ([$broadcast, $automation] as $html) {
            $this->assertStringContainsString('value="mo_email_new"', $html);
            $this->assertStringContainsString('value="Lucid"', $html);
            $this->assertStringContainsString('value="' . ER::CODE_YOUR_OWN_TEMPLATE . '"', $html);
            $this->assertStringContainsString('name="_wpnonce"', $html);
        }

        $this->assertStringContainsString('name="args[automation_id]" value="7"', $automation);
        $this->assertTrue(EmailEditor::has_choice('automation'), 'Lucid and Code your own: the dialog asks.');
    }

    public function test_other_kinds_of_email_register_a_context()
    {
        $context = new class extends EmailContext {
            public function id() { return 'receipt'; }
            public function campaign_type() { return ER::NEWSLETTER; }
            public function handles($email_campaign_id) { return false; }
            public function create($template, $args) { return 0; }
            public function back_url($email_campaign_id) { return ''; }
            public function send_test($email_campaign_id, $to) { return false; }
        };

        // (The registry is read once a request; a context added on the filter joins it.)
        add_filter('mailoptin_email_editor_contexts', function ($contexts) use ($context) { return array_merge($contexts, [$context]); });
        $this->assertArrayHasKey('broadcast', EmailEditor::contexts());
        $this->assertInstanceOf(EmailContext::class, $context);
        $this->assertTrue($context->accepts_template('Lucid'));
        $this->assertFalse($context->accepts_template('nope'));
    }

    public function test_the_template_dialog_saves_the_automation_page_first()
    {
        $automation = Repo::create(['name' => 'Before', 'trigger_id' => 'test_event', 'status' => Repo::STATUS_DRAFT]);

        $_GET     = ['page' => MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG, 'view' => Automations_Page::VIEW, 'screen' => 'edit', 'id' => $automation];
        $_POST    = ['mo_automation_action' => 'add_email', 'mo_new_email_template' => ER::CODE_YOUR_OWN_TEMPLATE, 'id' => $automation, 'name' => 'Renamed on the page', '_wpnonce' => wp_create_nonce('mo_automation_' . $automation)];
        $_REQUEST = $_GET + $_POST;

        $location = null;
        add_filter('mailoptin_automations_page_redirect', $stop = function ($url) use (&$location) {
            $location = $url;
            throw new \RuntimeException('redirected');
        });
        try {
            Automations_Page::get_instance()->handle_actions();
        } catch (\RuntimeException $e) {
        }
        remove_filter('mailoptin_automations_page_redirect', $stop);
        $_GET = $_POST = $_REQUEST = [];

        $this->assertSame('Renamed on the page', Repo::get($automation)['name'], 'What was typed on the page is kept.');
        $steps = Repo::steps($automation);
        $email = (int)end($steps)['settings']['email_campaign_id'];
        $this->assertTrue(EmailEditor::is_code($email), 'In the template chosen.');
        $this->assertSame(EmailEditor::url($email), $location, 'And it opens in the editor.');
    }

    public function test_a_sent_broadcasts_preview_is_not_a_link_to_the_editor()
    {
        $id = EmailEditor::start('broadcast', 'Lucid', ['name' => 'Gone out']);
        \MailOptin\Core\Repositories\EmailCampaignMeta::update_meta_data($id, 'newsletter_date_sent', '2026-01-01 10:00:00');

        $_GET = $_REQUEST = ['page' => MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG, 'view' => Broadcast_Page::VIEW, 'id' => $id];
        $html = Broadcast_Page::html();
        $_GET = $_REQUEST = [];

        $this->assertFalse(EmailEditor::edits($id));
        $this->assertStringNotContainsString(esc_url(EmailEditor::url($id)), $html);
    }
}
