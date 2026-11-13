<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\Admin\EmailEditor\Checks;
use MailOptin\Core\Admin\EmailEditor\EmailEditor;
use MailOptin\Core\EmailBlocks\Starters;
use MailOptin\Core\EmailBlocks\Templates;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * "Start from": starter layouts for new broadcasts and automation emails.
 */
class StartersTest extends \WP_UnitTestCase
{
    protected $posts = [];

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\RegisterActivation\CreateDBTables::make();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        add_filter('mailoptin_register_template_class', [Templates::get_instance(), 'template_class'], 10, 3);

        foreach (['Tulips', 'Roses', 'Bulbs', 'Lawns', 'Hedges'] as $i => $title) {
            $this->posts[] = self::factory()->post->create(['post_title' => $title, 'post_date' => '2026-11-0' . ($i + 1) . ' 10:00:00']);
        }
    }

    protected function content($id)
    {
        return json_decode((string)ER::get_customizer_value_without_default($id, 'email_newsletter_content'), true);
    }

    protected function broadcast($template, $starter)
    {
        $id = EmailEditor::start('broadcast', $template, ['name' => 'Starter ' . uniqid()], $starter);
        $this->assertIsInt($id);

        return $id;
    }

    public function test_starters_per_kind_of_email()
    {
        $broadcast  = array_keys(Starters::get_instance()->for_context('broadcast'));
        $automation = array_keys(Starters::get_instance()->for_context('automation'));

        $this->assertSame(['blank', 'newsletter', 'announcement', 'welcome', 'event', 'note'], $broadcast);
        // The shop's layouts only for an automation whose trigger has a cart or an order (none here).
        $this->assertSame(['blank', 'welcome', 'we-miss-you'], $automation);
        $this->assertSame(['blank', 'welcome', 'we-miss-you'], array_keys(Starters::get_instance()->for_context('automation', ['automation_id' => 999])));
    }

    public function test_newsletter_starter()
    {
        $content = $this->content($this->broadcast('Folio', 'newsletter'));

        $this->assertSame(['text', 'posts', 'button', 'text'], array_column($content, 'type'));
        // The 4 latest posts, newest first; the template's defaults in each block.
        $this->assertSame(array_map('strval', array_slice(array_reverse($this->posts), 0, 4)), $content[1]['settings']['post_list']);
        $this->assertSame('2', $content[1]['settings']['post_column_count']);
        $this->assertSame('#2e6b4f', $content[2]['settings']['button_background_color']);
    }

    public function test_welcome_starter_has_columns_with_blocks()
    {
        $content = $this->content($this->broadcast('Ledger', 'welcome'));

        $this->assertSame('columns', $content[1]['type']);
        $this->assertCount(3, $content[1]['columns']);
        $this->assertSame('text', $content[1]['columns'][0][0]['type']);
        $this->assertSame('Georgia', $content[1]['columns'][0][0]['settings']['text_font_family']);
    }

    public function test_blank_and_unknown_starters()
    {
        $this->assertSame([], $this->content($this->broadcast('Folio', 'blank')) ?: []);

        // Unknown, or not offered for broadcasts: the template's own short default.
        foreach (['nope', 'abandoned-cart'] as $starter) {
            $types = array_column((array)$this->content($this->broadcast('Folio', $starter)), 'type');
            $this->assertSame(['text', 'button', 'text'], $types, $starter);
        }
    }

    public function test_blocks_the_email_cant_have_are_left_out()
    {
        // The abandoned-cart layout in an email whose automation isn't a shop's: no Cart contents.
        $id = ER::add_email_campaign('Cart ' . uniqid(), ER::NEWSLETTER, 'Folio');
        $content = Starters::get_instance()->content('abandoned-cart', $id);

        $this->assertSame(['text'], array_column($content, 'type'));

    }

    public function test_a_plugin_can_add_a_starter()
    {
        $starters = new Starters();
        add_action('mailoptin_email_starters', function (Starters $s) {
            $s->register(['id' => 'acme', 'name' => 'Acme', 'content' => function () { return [Starters::text('<p>Acme</p>')]; }]);
        });

        $this->assertArrayHasKey('acme', $starters->for_context('broadcast'));
        $this->assertInstanceOf(\WP_Error::class, $starters->register(['id' => 'bad']));
    }

    public function test_prompts_left_are_flagged()
    {
        $id      = $this->broadcast('Folio', 'announcement');
        $state   = EmailEditor::state($id);
        $checks  = Checks::run($state, '', []);
        $prompts = array_filter($checks, function ($c) { return ! $c['ok'] && false !== strpos($c['text'], '[The news in one line]'); });

        $this->assertNotEmpty($prompts);

        // Shortcodes, ordinary brackets and links aren't prompts.
        $state['content'] = [
            ['id' => 't', 'type' => 'text', 'settings' => ['text_content' => '<p>[gallery ids="1,2"] Our news [sic] [Note] [PDF]</p>']],
            ['id' => 'b', 'type' => 'button', 'settings' => ['button_text' => 'Shop', 'button_link' => 'https://example.com/?f[Size]=L']],
        ];
        $this->assertEmpty(array_filter(Checks::run($state, '', []), function ($c) { return ! $c['ok'] && false !== strpos($c['text'], 'prompt'); }));
    }
}
