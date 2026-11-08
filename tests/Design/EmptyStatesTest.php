<?php

namespace MailOptin\Tests\Core\Design;

use MailOptin\Core\Admin\EmptyState;
use MailOptin\Core\Admin\SettingsPage\AddEmailCampaign;
use MailOptin\Core\Admin\SettingsPage\AddOptinCampaign;
use MailOptin\Core\Admin\SettingsPage\Campaign_Log_List;
use MailOptin\Core\Admin\SettingsPage\Email_Campaign_List;
use MailOptin\Core\Admin\SettingsPage\Newsletter_List;
use MailOptin\Core\Admin\SettingsPage\OptinCampaign_List;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\ListsRepository;
use WP_UnitTestCase;

/**
 * Empty Optin Campaigns, Post Notifications, Broadcasts and Logs screens.
 */
class EmptyStatesTest extends WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();

        foreach ([17, 20, 21, 22] as $version) \MailOptin\Core\DBUpdates::get_instance()->{'update_routine_' . $version}();
        set_current_screen('dashboard');
    }

    public function tear_down()
    {
        $_GET = [];
        remove_all_filters('mailoptin_enable_post_email_digest');
        parent::tear_down();
    }

    public function test_the_helper_escapes_what_it_prints()
    {
        $html = EmptyState::render([
            'title'   => 'Start <here>',
            'text'    => 'A & B',
            'cards'   => [['label' => '<b>x</b>', 'url' => 'javascript:alert(1)', 'pro' => true]],
            'buttons' => [['label' => 'Go', 'url' => 'https://example.org/?a=1&b=2', 'primary' => true]],
        ]);

        $this->assertStringContainsString('<h2>Start &lt;here&gt;</h2>', $html);
        $this->assertStringContainsString('<p>A &amp; B</p>', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('class="mo-ui-btn mo-ui-primary"', $html);
        $this->assertStringContainsString('mo-ui-pro', $html);
    }

    public function test_optins_screen_marks_premium_types_on_the_free_plan()
    {
        $html = OptinCampaign_List::empty_state(['lightbox', 'inpost', 'sidebar']);

        $this->assertStringContainsString('Start collecting email addresses', $html);
        $this->assertSame(5, substr_count($html, 'class="mo-ui-empty-card"'));
        $this->assertStringContainsString('optin-type=slidein', $html);

        // Two Premium pills: slide-in and bar.
        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) {
            $this->assertSame(2, substr_count($html, 'mo-ui-pro'));
        }
    }

    public function test_which_lists_show_their_first_screen()
    {
        global $wpdb;

        $optins = new OptinCampaign_List($wpdb);
        $emails = Email_Campaign_List::get_instance();
        $news   = new Newsletter_List($wpdb);
        $logs   = new Campaign_Log_List($wpdb);

        foreach ([$optins, $emails, $news, $logs] as $list) $this->assertTrue($list->shows_empty_state(), get_class($list));

        // The first screen's own markup.
        ob_start();
        $logs->display();
        $this->assertStringContainsString('Nothing sent yet', ob_get_clean());

        // A type filter with nothing in it keeps the table and its short line.
        $_GET = ['page' => MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_SLUG, 'optin-type' => 'lightbox'];
        $this->assertFalse($optins->shows_empty_state());
        $_GET = [];

        // Once there's something, each table is back; a broadcast doesn't count as a post notification.
        ER::add_email_campaign('November notes', ER::NEWSLETTER, 'Lucid');
        $this->assertFalse($news->shows_empty_state());
        $this->assertTrue($emails->shows_empty_state());

        ER::add_email_campaign('Weekly roundup', ER::NEW_PUBLISH_POST, 'Lucid');
        $this->assertFalse($emails->shows_empty_state());

        $wpdb->insert($wpdb->prefix . \MailOptin\Core\Core::campaign_log_table_name, ['email_campaign_id' => 1, 'title' => 'x', 'content_html' => '', 'content_text' => '', 'status' => 'processed']);
        $this->assertFalse($logs->shows_empty_state());
    }

    public function test_post_notifications_digest_card_and_broadcast_list_note()
    {
        $this->assertStringContainsString('mo-ui-pro', Email_Campaign_List::empty_state());
        add_filter('mailoptin_enable_post_email_digest', '__return_true');
        $this->assertStringNotContainsString('mo-ui-pro', Email_Campaign_List::empty_state());
        $this->assertStringContainsString('type=' . ER::POSTS_EMAIL_DIGEST, Email_Campaign_List::empty_state());

        $this->assertStringContainsString('create a list', Newsletter_List::empty_state());
        ListsRepository::create(['name' => 'Newsletter']);
        $this->assertStringNotContainsString('create a list', Newsletter_List::empty_state());
    }

    public function test_the_add_pages_open_on_the_chosen_type()
    {
        $this->assertSame('lightbox', AddOptinCampaign::chosen_type());
        $_GET['optin-type'] = 'slidein';
        $this->assertSame('slidein', AddOptinCampaign::chosen_type());
        $_GET['optin-type'] = 'nope"><script>';
        $this->assertSame('lightbox', AddOptinCampaign::chosen_type());

        $this->assertSame('', AddEmailCampaign::chosen_type());
        $_GET['type'] = ER::POSTS_EMAIL_DIGEST;
        $this->assertSame(ER::POSTS_EMAIL_DIGEST, AddEmailCampaign::chosen_type());
        $_GET['type'] = ER::NEWSLETTER;
        $this->assertSame('', AddEmailCampaign::chosen_type());
    }
}
