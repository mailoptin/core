<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\Admin\EmailEditor\EmailEditor;
use MailOptin\Core\EmailBlocks\Blocks;
use MailOptin\Core\EmailBlocks\Builtin\ElementBlock;
use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Style;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * More of the builder's edge cases: lists posted where one value belongs, posts of a type that
 * isn't public, the design settings read once per email drawn, and the test email's address.
 */
class BuilderReview2Test extends \WP_Ajax_UnitTestCase
{
    protected $email;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\RegisterActivation\CreateDBTables::make();
        \MailOptin\Core\DBUpdates::get_instance()->update_routine_7();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        ElementBlock::flush_defaults();

        add_filter('pre_http_request', function () { return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []]; });
        add_action('wp_ajax_mo_email_editor_test', [EmailEditor::get_instance(), 'ajax_test']);
        add_action('wp_ajax_mo_email_editor_preview', [EmailEditor::get_instance(), 'ajax_preview']);

        $this->email = ER::add_email_campaign('Review2 ' . uniqid(), ER::NEWSLETTER, 'Lucid');
        ER::update_settings_by_id($this->email, ['email_campaign_title' => 'Hello', 'email_newsletter_content' => wp_json_encode([
            ['id' => 'a', 'type' => 'text', 'settings' => ['text_content' => 'First']],
        ])]);

        $this->_setRole('administrator');
    }

    public function test_a_list_where_one_value_belongs_is_dropped_without_a_warning()
    {
        $warnings = [];
        set_error_handler(function ($no, $text) use (&$warnings) { $warnings[] = $text; return true; }, E_WARNING | E_NOTICE);

        try {
            $content = Blocks::get_instance()->sanitize_content([
                ['id' => ['x'], 'type' => 'text', 'settings' => ['text_content' => ['a'], 'block_background_color' => ['#fff']]],
                ['id' => 'b', 'type' => 'button', 'settings' => ['button_text' => ['Go'], 'button_link' => ['https://x.test']]],
                ['id' => 'i', 'type' => 'image', 'settings' => ['image_url' => ['https://x.test/a.png']]],
            ]);
            $html = Blocks::get_instance()->render($content, $this->email);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame('', $content[0]['settings']['text_content']);
        $this->assertSame('', $content[0]['settings']['block_background_color']);
        $this->assertSame('', $content[1]['settings']['button_text'], 'Not a list saved for a text field.');
        $this->assertSame('', $content[2]['settings']['image_url'], 'Not "http://Array".');
        $this->assertStringNotContainsString('Array', $html);
        $this->assertSame('', Fields::color(['#fff']));
        // Lists stay lists where they belong.
        $this->assertSame(['author'], Fields::sanitize(['type' => 'select', 'multiple' => true, 'choices' => ['author' => 'Author', 'date' => 'Date']], ['author', 'evil']));
    }

    public function test_posts_of_a_type_that_isnt_public_are_not_sent()
    {
        register_post_type('mo_private_thing', ['public' => false]);
        $hidden = self::factory()->post->create(['post_type' => 'mo_private_thing', 'post_title' => 'Walrus internal', 'post_content' => 'Internal body', 'post_status' => 'publish']);
        $open   = self::factory()->post->create(['post_title' => 'Walrus open', 'post_content' => 'Open body']);

        $html = Blocks::get_instance()->render([['id' => 'p', 'type' => 'posts', 'settings' => ['post_list' => [(string)$hidden, (string)$open]]]], $this->email);

        $this->assertStringContainsString('Open body', $html);
        $this->assertStringNotContainsString('Internal body', $html);
        $this->assertStringNotContainsString('Walrus internal', $html);
    }

    public function test_design_settings_are_read_once_per_email_drawn()
    {
        global $wpdb;

        $style = new Style($this->email);
        $first = $style->get('content_text_color', 'x');
        $narrow = $style->with_width(200);

        $queries = $wpdb->num_queries;
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($first, $style->get('content_text_color', 'x'));
            $this->assertSame($first, $narrow->get('content_text_color', 'x'), 'A column\'s copy shares what was read.');
        }
        $this->assertSame($queries, $wpdb->num_queries);

        // A new drawing reads again (the preview's settings may differ).
        ER::update_settings_by_id($this->email, ['content_text_color' => '#123456']);
        $this->assertSame('#123456', (new Style($this->email))->get('content_text_color'));
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

    public function test_the_preview_gives_another_templates_block_defaults_when_asked()
    {
        $lucid = Blocks::get_instance()->get('button')->defaults($this->email);
        $state = EmailEditor::state($this->email);
        $state['template'] = 'Folio';

        $res = $this->call('preview', ['state' => wp_json_encode($state)]);
        $this->assertTrue($res['success']);
        $this->assertArrayNotHasKey('blocks', $res['data'], 'Only when asked.');

        $res = $this->call('preview', ['state' => wp_json_encode($state), 'defaults' => 1]);
        $folio = $res['data']['blocks']['button'];
        $this->assertNotSame($lucid['button_background_color'], $folio['button_background_color'], 'Folio\'s button, not Lucid\'s.');
        $this->assertSame('Helvetica', $folio['button_font_family']);
    }

    public function test_a_mistyped_test_address_is_an_error_not_a_test_to_someone_else()
    {
        $sent = [];
        add_filter('pre_wp_mail', function ($null, $atts) use (&$sent) { $sent[] = $atts['to']; return true; }, 10, 2);

        $_POST = ['id' => $this->email, 'nonce' => wp_create_nonce('mo_email_editor_' . $this->email), 'email' => 'not-an-address'];
        try {
            $this->_handleAjax('mo_email_editor_test');
        } catch (\WPAjaxDieContinueException $e) {
        } catch (\WPAjaxDieStopException $e) {
        }
        $res = json_decode($this->_last_response, true);

        $this->assertFalse($res['success']);
        $this->assertSame([], $sent);
    }
}
