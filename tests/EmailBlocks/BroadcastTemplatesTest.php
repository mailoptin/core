<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\Admin\EmailEditor\EmailEditor;
use MailOptin\Core\EmailBlocks\Templates;
use MailOptin\Core\EmailCampaigns\Newsletter\Templatify;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * The MJML looks (Folio and the others) as templates for broadcasts and automation emails.
 */
class BroadcastTemplatesTest extends \WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\RegisterActivation\CreateDBTables::make();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        // Hooks are reset after each test: the registry's own filters again.
        add_filter('mailoptin_register_template_class', [Templates::get_instance(), 'template_class'], 10, 3);
        add_filter('mailoptin_registered_email_templates', [Templates::get_instance(), 'list_for_new_broadcasts']);
    }

    protected function broadcast($template, $settings = [])
    {
        $id = ER::add_email_campaign('Broadcast ' . uniqid(), ER::NEWSLETTER, $template);
        ER::update_settings_by_id($id, $settings + ['email_newsletter_content' => wp_json_encode([
            ['id' => 't', 'type' => 'text', 'settings' => ['text_content' => '<p>Hello from the garden</p>']],
            ['id' => 'b', 'type' => 'button', 'settings' => ['button_text' => 'Shop bulbs', 'button_link' => 'https://example.com/shop']],
            ['id' => 'c', 'type' => 'columns', 'settings' => ['columns_layout' => '50-50'], 'columns' => [
                [['id' => 'c1', 'type' => 'text', 'settings' => ['text_content' => '<p>Left side</p>']]],
                [['id' => 'c2', 'type' => 'text', 'settings' => ['text_content' => '<p>Right side</p>']]],
            ]],
        ])]);

        return $id;
    }

    public function provide_looks()
    {
        return [['Folio'], ['Ledger'], ['Letter'], ['Spotlight'], ['Shelf'], ['Market']];
    }

    /**
     * @dataProvider provide_looks
     */
    public function test_each_look_draws_a_broadcast($look)
    {
        $html = (new Templatify($this->broadcast($look)))->forge();

        $this->assertStringContainsString('Hello from the garden', $html, $look);
        $this->assertStringContainsString('Shop bulbs', $html, $look);
        $this->assertStringContainsString('Left side', $html, $look);
        $this->assertStringContainsString('class="mj-column-per-50 ', $html, $look);
        $this->assertStringContainsString('{{unsubscribe}}', $html, $look);
        foreach (['__MO_', '{{newsletter.content}}', '{{post.', '<!--mo-'] as $left) $this->assertStringNotContainsString($left, $html, "$look: $left");
        $this->assertMatchesRegularExpression('#\.mj-column-per-50\s*\{#', $html, 'The columns head is added.');
    }

    public function test_post_settings_stay_out_of_broadcasts()
    {
        // Letter's opening line and images-off default are for its post emails, not broadcasts.
        $html = (new Templatify($this->broadcast('Letter')))->forge();

        $this->assertStringNotContainsString('just published', $html);
        $this->assertStringNotContainsString('Thanks for reading.', $html);
        $this->assertFalse((bool)ER::get_merged_customizer_value($this->broadcast('Letter'), 'content_remove_feature_image'));
    }

    public function test_new_blocks_start_in_the_look()
    {
        $id = $this->broadcast('Ledger');
        new \MailOptin\Core\EmailCampaigns\Newsletter\Templates\Ledger($id);

        $defaults = apply_filters('mo_ecb_elements_default_values', []);

        $this->assertSame('Georgia', $defaults['text']['text_font_family']);
        $this->assertSame('#9b2226', $defaults['button']['button_background_color']);
        $this->assertSame('0', $defaults['button']['block_padding']['left']);
    }

    public function test_market_offer_strip_is_on_the_design_tab()
    {
        $market = $this->broadcast('Market', ['offer_strip_text' => 'Free delivery over £40']);

        $this->assertArrayHasKey('offer_strip_text', EmailEditor::design_fields($market));
        $this->assertArrayNotHasKey('offer_strip_text', EmailEditor::design_fields($this->broadcast('Folio')));
        $this->assertArrayNotHasKey('offer_strip_text', EmailEditor::design_fields());

        $this->assertStringContainsString('Free delivery over £40', (new Templatify($market))->forge());

        // Saved from the editor like any design setting, as plain text.
        $values = EmailEditor::clean_state(['design' => ['offer_strip_text' => '<b>Sale</b> now']], $market);
        $this->assertSame('Sale now', $values['offer_strip_text']);
    }

    public function test_lucid_broadcasts_are_unchanged()
    {
        $html = (new Templatify($this->broadcast('Lucid')))->forge();

        $this->assertStringContainsString('Hello from the garden', $html);
        $this->assertStringContainsString('email-masthead', $html, 'Lucid\'s own frame.');
    }

    public function test_editor_shows_the_look_s_own_defaults()
    {
        $state = EmailEditor::state($this->broadcast('Market'));

        $this->assertSame('#fff6e8', $state['design']['page_background_color']);
        $this->assertSame('New in the shop', $state['design']['offer_strip_text']);

        // And another look's defaults don't leak into it in the same request.
        $this->assertSame('#eef1f4', EmailEditor::state($this->broadcast('Folio'))['design']['page_background_color']);
    }
}
