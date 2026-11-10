<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\Admin\EmailEditor\EmailEditor;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * The editor's requests: who may use them, saving (with conflicts), cleaning and the preview.
 *
 * Not in the "ajax" group, which the test suite skips by default: these run with the rest.
 */
class EmailEditorTest extends \WP_Ajax_UnitTestCase
{
    protected $email;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\Base::get_instance()->register_metadata_table();

        // admin_init runs update checks; no network in tests.
        add_filter('pre_http_request', function () { return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []]; });
        // Hooks are reset after each test; the editor is a single instance.
        foreach (['preview', 'save', 'test'] as $action) add_action('wp_ajax_mo_email_editor_' . $action, [EmailEditor::get_instance(), 'ajax_' . $action]);

        $this->email = ER::add_email_campaign('Editor ' . uniqid(), ER::NEWSLETTER, 'Lucid');
        ER::update_settings_by_id($this->email, ['email_campaign_title' => 'Hello', 'email_newsletter_content' => wp_json_encode([['id' => 'a', 'type' => 'text', 'settings' => ['text_content' => 'First']]])]);

        $this->_setRole('administrator');
    }

    protected function call($action, $post)
    {
        $_POST = $post + ['id' => $this->email, 'nonce' => wp_create_nonce('mo_email_editor_' . $this->email)];

        try {
            $this->_handleAjax('mo_email_editor_' . $action);
        } catch (\WPAjaxDieContinueException $e) {
        } catch (\WPAjaxDieStopException $e) {
        }

        $response = json_decode($this->_last_response, true);
        $this->_last_response = '';

        return $response;
    }

    protected function state($changes = [])
    {
        return array_replace_recursive(EmailEditor::state($this->email), $changes);
    }

    public function test_only_built_emails_are_edited_here()
    {
        $this->assertTrue(EmailEditor::edits($this->email));
        $this->assertFalse(EmailEditor::edits(ER::add_email_campaign('Post ' . uniqid(), ER::NEW_PUBLISH_POST, 'Lucid')));
        $this->assertFalse(EmailEditor::edits(0));
    }

    public function test_saving_cleans_the_email_and_returns_a_new_version()
    {
        $version = EmailEditor::version($this->email);
        $state   = $this->state(['settings' => ['email_campaign_title' => 'New <b>subject</b>'], 'design' => ['page_background_color' => 'red;}', 'footer_font_size' => '99']]);
        $state['content'][] = ['id' => 'b', 'type' => 'button', 'settings' => ['button_text' => 'Go', 'button_background_color' => '#123456']];
        $state['content'][] = ['id' => 'c', 'type' => 'made-up', 'settings' => []];

        $res = $this->call('save', ['state' => wp_json_encode($state), 'version' => $version]);

        $this->assertTrue($res['success']);
        $this->assertNotSame($version, $res['data']['version']);
        $this->assertSame('New subject', ER::get_customizer_value($this->email, 'email_campaign_title'));
        $this->assertSame('', ER::get_customizer_value_without_default($this->email, 'page_background_color'));
        $this->assertSame('30', ER::get_customizer_value($this->email, 'footer_font_size'));
        $this->assertSame(['text', 'button'], array_column(json_decode(ER::get_customizer_value($this->email, 'email_newsletter_content'), true), 'type'));
    }

    public function test_a_save_over_a_newer_version_is_refused_unless_forced()
    {
        $old = EmailEditor::version($this->email);
        ER::update_settings_by_id($this->email, ['email_campaign_title' => 'Changed elsewhere']);

        $res = $this->call('save', ['state' => wp_json_encode($this->state(['settings' => ['email_campaign_title' => 'Mine']])), 'version' => $old]);
        $this->assertFalse($res['success']);
        $this->assertTrue($res['data']['conflict']);
        $this->assertSame('Changed elsewhere', $res['data']['state']['settings']['email_campaign_title']);
        $this->assertSame('Changed elsewhere', ER::get_customizer_value($this->email, 'email_campaign_title'));

        $res = $this->call('save', ['state' => wp_json_encode($this->state(['settings' => ['email_campaign_title' => 'Mine']])), 'version' => $old, 'force' => 1]);
        $this->assertTrue($res['success']);
        $this->assertSame('Mine', ER::get_customizer_value($this->email, 'email_campaign_title'));
    }

    public function test_the_preview_draws_what_is_on_screen_without_saving_it()
    {
        $state = $this->state();
        $state['content'][0]['settings']['text_content'] = 'Not saved yet';

        $res = $this->call('preview', ['state' => wp_json_encode($state)]);

        $this->assertTrue($res['success']);
        $this->assertStringContainsString('Not saved yet', $res['data']['html']);
        $this->assertStringContainsString('First', (new \MailOptin\Core\EmailCampaigns\Newsletter\Templatify($this->email))->builderHtml());
    }

    public function test_requests_need_the_emails_nonce_and_the_right_to_edit()
    {
        $other = ER::add_email_campaign('Other ' . uniqid(), ER::NEWSLETTER, 'Lucid');
        $res   = $this->call('save', ['state' => wp_json_encode($this->state(['settings' => ['email_campaign_title' => 'X']])), 'nonce' => wp_create_nonce('mo_email_editor_' . $other)]);
        $this->assertFalse($res['success']);

        $this->_setRole('subscriber');
        $res = $this->call('preview', ['state' => '{}']);
        $this->assertFalse($res['success']);
        $this->assertSame('Hello', ER::get_customizer_value($this->email, 'email_campaign_title'));
    }
}
