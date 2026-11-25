<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\Admin\EmailEditor\EmailEditor;
use MailOptin\Core\EmailBlocks\Blocks;
use MailOptin\Core\EmailBlocks\Builtin\ElementBlock;
use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * The builder's edge cases: blocks whose plugin is off, malformed posts to the preview, link
 * fallbacks, password-protected posts and posts in a narrow column.
 */
class BuilderReviewTest extends \WP_Ajax_UnitTestCase
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
        foreach (['preview', 'save', 'test', 'search'] as $action) add_action('wp_ajax_mo_email_editor_' . $action, [EmailEditor::get_instance(), 'ajax_' . $action]);

        $this->email = ER::add_email_campaign('Review ' . uniqid(), ER::NEWSLETTER, 'Lucid');
        ER::update_settings_by_id($this->email, ['email_campaign_title' => 'Hello', 'email_newsletter_content' => wp_json_encode([
            ['id' => 'a', 'type' => 'text', 'settings' => ['text_content' => 'First']],
            // Saved while its plugin was on; not registered now.
            ['id' => 'gone', 'type' => 'acme_social', 'settings' => ['network' => 'mastodon']],
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

    public function test_a_block_whose_plugin_is_off_is_kept_as_saved()
    {
        $state = EmailEditor::state($this->email);
        $this->assertSame(['a', 'gone'], array_column($state['content'], 'id'));

        // Edited around, moved first, its settings tampered with, and a new unknown block posted.
        $state['content'][0]['settings']['text_content'] = 'Changed';
        $gone                         = $state['content'][1];
        $gone['settings']['network']  = '<script>x</script>';
        $state['content']             = [$gone, $state['content'][0], ['id' => 'new', 'type' => 'acme_social', 'settings' => ['network' => 'x']]];

        $res = $this->call('save', ['state' => wp_json_encode($state), 'version' => EmailEditor::version($this->email)]);
        $this->assertTrue($res['success']);

        $saved = json_decode(ER::get_customizer_value($this->email, 'email_newsletter_content'), true);
        $this->assertSame(['gone', 'a'], array_column($saved, 'id'), 'Kept where it was put; a new unknown block is not.');
        $this->assertSame(['network' => 'mastodon'], $saved[0]['settings'], 'The saved copy, not the posted one.');
        $this->assertSame('Changed', $saved[1]['settings']['text_content']);

        // Not drawn, and the checks say so.
        $res = $this->call('preview', ['state' => wp_json_encode(EmailEditor::state($this->email))]);
        $this->assertTrue($res['success']);
        $this->assertContains('gone', array_column($res['data']['checks'], 'block'));
    }

    public function test_the_preview_takes_malformed_blocks_and_settings()
    {
        $state = [
            'content'  => [['id' => 'a', 'type' => 'text'], ['id' => 'b', 'type' => 'discount_code', 'settings' => 'oops'], 'nope', ['type' => 'button', 'settings' => ['button_text' => ['x']]]],
            'settings' => ['email_campaign_title' => ['not', 'a', 'string']],
            'design'   => 'x',
        ];

        $res = $this->call('preview', ['state' => wp_json_encode($state)]);

        $this->assertTrue($res['success']);
        $this->assertContains('subject', array_column($res['data']['checks'], 'kind'));
    }

    public function test_a_link_placeholders_fallback_must_be_a_safe_link()
    {
        $this->assertSame('{{cart.url}}', Fields::link('{{cart.url}}'));
        $this->assertSame('{{cart.url|https://example.com/shop}}', Fields::link('{{cart.url|https://example.com/shop}}'));
        $this->assertSame('{{subscriber.first_name|/shop}}', Fields::link('{{subscriber.first_name|/shop}}'));

        foreach (['javascript:alert(1)', 'JaVaScRiPt&#58;alert(1)', 'data:text/html,x', 'vbscript:x'] as $bad) {
            $this->assertSame('{{subscriber.website}}', Fields::link('{{subscriber.website|' . $bad . '}}'), $bad);
        }

        $html = Blocks::get_instance()->render([['id' => 'b', 'type' => 'button', 'settings' => ['button_text' => 'Go', 'button_link' => '{{subscriber.first_name|javascript:alert(1)}}']]], $this->email);
        $this->assertStringNotContainsString('javascript', $html);
    }

    public function test_password_protected_posts_are_not_found_or_sent()
    {
        $open   = self::factory()->post->create(['post_title' => 'Walrus open', 'post_content' => 'Open body']);
        $locked = self::factory()->post->create(['post_title' => 'Walrus locked', 'post_content' => 'Members only body', 'post_password' => 'secret']);

        $res = $this->call('search', ['kind' => 'posts', 'post_type' => 'post', 'term' => 'Walrus']);
        $this->assertSame([(string)$open], array_column($res['data'], 'id'));
        $res = $this->call('search', ['kind' => 'posts', 'post_type' => 'post', 'ids' => $locked . ',' . $open]);
        $this->assertSame([(string)$open], array_column($res['data'], 'id'));

        $html = Blocks::get_instance()->render([['id' => 'p', 'type' => 'posts', 'settings' => ['post_list' => [(string)$open, (string)$locked]]]], $this->email);
        $this->assertStringContainsString('Open body', $html);
        $this->assertStringNotContainsString('Members only body', $html);
        $this->assertStringNotContainsString('Walrus locked', $html);
    }

    public function test_posts_in_a_narrow_column_go_one_to_a_row()
    {
        $ids = array_map('strval', self::factory()->post->create_many(3));

        $html = Blocks::get_instance()->render([['id' => 'c', 'type' => 'columns', 'settings' => ['columns_layout' => '50-50'], 'columns' => [
            [['id' => 'p', 'type' => 'posts', 'settings' => ['post_list' => $ids, 'post_column_count' => '3']]],
            [],
        ]]], $this->email);

        $this->assertStringNotContainsString('mj-column-per-33-33', $html, 'Three to a row would be about 60px each.');
        $this->assertSame(2, substr_count($html, 'class="mj-column-per-50 '), 'Only the Columns block\'s own two columns.');
    }
}
