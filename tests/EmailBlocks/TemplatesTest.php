<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\Admin\Customizer\EmailCampaign\EmailCampaignFactory;
use MailOptin\Core\Admin\EmailEditor\EmailEditor;
use MailOptin\Core\EmailBlocks\Templates;
use MailOptin\Core\EmailCampaigns\Newsletter\Templates\Lucid;
use MailOptin\Core\EmailCampaigns\Newsletter\Templatify;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/** A wider template, marked so tests can tell it drew the email. */
class WideTemplate extends Lucid
{
    public function content_width()
    {
        return 640;
    }

    public function get_body()
    {
        return '<div class="wide-frame"><table width="640">{{newsletter.content}}</table></div>';
    }
}

class TemplatesTest extends \WP_Ajax_UnitTestCase
{
    protected $email;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\RegisterActivation\CreateDBTables::make();
        \MailOptin\Core\DBUpdates::get_instance()->update_routine_7();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        add_filter('pre_http_request', function () { return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []]; });
        foreach (['preview', 'save'] as $action) add_action('wp_ajax_mo_email_editor_' . $action, [EmailEditor::get_instance(), 'ajax_' . $action]);
        // Hooks are reset after each test: the registry's own filters again.
        add_filter('mailoptin_register_template_class', [Templates::get_instance(), 'template_class'], 10, 3);
        add_filter('mailoptin_registered_email_templates', [Templates::get_instance(), 'list_for_new_broadcasts']);

        $this->assertTrue(mailoptin_register_email_template(['id' => 'wide', 'name' => 'Wide', 'class' => WideTemplate::class]));

        $this->email = ER::add_email_campaign('Templates ' . uniqid(), ER::NEWSLETTER, 'Lucid');
        ER::update_settings_by_id($this->email, ['email_newsletter_content' => wp_json_encode([
            ['id' => 'd', 'type' => 'divider', 'settings' => ['block_padding' => ['top' => 0, 'right' => 20, 'bottom' => 0, 'left' => 20], 'divider_width' => 100]],
        ])]);

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
        $response             = json_decode($this->_last_response, true);
        $this->_last_response = '';

        return $response;
    }

    public function test_a_template_needs_an_id_and_a_newsletter_template_class()
    {
        $templates = Templates::get_instance();

        $this->assertSame('mo_email_template_id', $templates->register(['id' => '', 'class' => WideTemplate::class])->get_error_code());
        $this->assertSame('mo_email_template_id', $templates->register(['id' => ER::CODE_YOUR_OWN_TEMPLATE, 'class' => WideTemplate::class])->get_error_code());
        $this->assertSame('mo_email_template_class', $templates->register(['id' => 'x', 'class' => 'No_Such_Class'])->get_error_code());
        $this->assertSame('mo_email_template_class', $templates->register(['id' => 'x', 'class' => \stdClass::class])->get_error_code());

        $this->assertArrayHasKey('Lucid', $templates->all(), 'The built-in one is there.');
        $this->assertSame(WideTemplate::class, $templates->get('wide')['class']);
    }

    public function test_emails_are_drawn_in_their_registered_template()
    {
        Templates::set_for_email($this->email, 'wide');

        $this->assertInstanceOf(WideTemplate::class, EmailCampaignFactory::make($this->email));
        $this->assertStringContainsString('wide-frame', (new Templatify($this->email))->forge());

        // Automation emails can use it too; post notifications can't (not one of its types).
        $post_email = ER::add_email_campaign('Post ' . uniqid(), ER::NEW_PUBLISH_POST, 'wide');
        $this->assertNotInstanceOf(WideTemplate::class, EmailCampaignFactory::make($post_email) ?: null);

        // Offered when a new broadcast is made.
        $listed = array_filter(apply_filters('mailoptin_registered_email_templates', []), function ($t) { return 'wide' === $t['template_class']; });
        $this->assertCount(1, $listed);
    }

    public function test_blocks_are_drawn_to_the_templates_width()
    {
        $this->assertStringContainsString('width:530px', (new Templatify($this->email))->builderHtml(), "Lucid: 570 less 40px of padding (the divider's Outlook width).");

        Templates::set_for_email($this->email, 'wide');
        $this->assertStringContainsString('width:600px', (new Templatify($this->email))->builderHtml(), 'Wide: 640 less 40px.');
    }

    public function test_the_editor_previews_and_saves_another_template()
    {
        $state = EmailEditor::state($this->email);
        $this->assertSame('Lucid', $state['template']);
        $version = EmailEditor::version($this->email);

        // Previewed, not saved.
        $res = $this->call('preview', ['state' => wp_json_encode(['template' => 'wide'] + $state)]);
        $this->assertStringContainsString('wide-frame', $res['data']['html']);
        $this->assertSame('Lucid', ER::get_template_class($this->email));
        $this->assertStringNotContainsString('wide-frame', (new Templatify($this->email))->forge(), 'The preview is over.');

        // A template this email can't use is ignored.
        $this->call('save', ['state' => wp_json_encode(['template' => 'nope'] + $state), 'version' => $version]);
        $this->assertSame('Lucid', ER::get_template_class($this->email));

        $res = $this->call('save', ['state' => wp_json_encode(['template' => 'wide'] + $state), 'version' => EmailEditor::version($this->email)]);
        $this->assertTrue($res['success']);
        $this->assertSame('wide', ER::get_template_class($this->email));
        $this->assertSame('wide', $res['data']['state']['template']);
        $this->assertNotSame($version, EmailEditor::version($this->email), 'Another template is another version: a stale editor gets a conflict.');
    }
}
