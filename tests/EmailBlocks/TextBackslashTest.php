<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\Admin\EmailEditor\EmailEditor;
use MailOptin\Core\EmailBlocks\Blocks;
use MailOptin\Core\EmailBlocks\Builtin\Text;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * Backslashes in a Text block: stripped from text the Customizer saved slashed, kept in text the
 * email editor saved.
 */
class TextBackslashTest extends \WP_UnitTestCase
{
    public function test_customizer_text_loses_its_added_slashes()
    {
        $this->assertSame("It's", Text::content(['text_content' => "It\\'s"]));
    }

    public function test_editor_text_keeps_real_backslashes()
    {
        $this->assertSame('C:\\path \\o/', Text::content(['text_content' => 'C:\\path \\o/', Text::AS_TYPED => true]));
    }

    public function test_saving_through_the_editor_marks_the_text()
    {
        $clean = Blocks::get_instance()->sanitize_content([['id' => 'a', 'type' => 'text', 'settings' => ['text_content' => 'C:\\path']]]);

        $this->assertTrue($clean[0]['settings'][Text::AS_TYPED]);
        $this->assertSame('C:\\path', Text::content($clean[0]['settings']));
    }

    public function test_the_editor_opens_customizer_text_as_written_and_keeps_it_after_saving()
    {
        $id = ER::add_email_campaign('Slashes ' . uniqid(), ER::NEWSLETTER, 'Lucid');
        $legacy = [['id' => 'a', 'type' => 'text', 'settings' => ['text_content' => "<p>It\\'s here</p>"]],
                   ['id' => 'c', 'type' => 'columns', 'settings' => ['columns_layout' => '50-50'], 'columns' => [[['id' => 'b', 'type' => 'text', 'settings' => ['text_content' => "Don\\'t"]]], []]]];
        ER::update_settings_by_id($id, ['email_newsletter_content' => wp_json_encode($legacy)]);

        $state = EmailEditor::state($id);
        $this->assertSame("<p>It's here</p>", $state['content'][0]['settings']['text_content']);
        $this->assertSame("Don't", $state['content'][1]['columns'][0][0]['settings']['text_content']);

        // Saved as shown, then drawn as saved.
        $values = EmailEditor::clean_state($state, $id);
        $saved  = json_decode($values['email_newsletter_content'], true);
        $this->assertTrue($saved[0]['settings'][Text::AS_TYPED]);
        $this->assertSame("<p>It's here</p>", Text::content($saved[0]['settings']));
    }
}
