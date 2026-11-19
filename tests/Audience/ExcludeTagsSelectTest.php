<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_Tags_Select_Control;
use MailOptin\Core\Admin\SettingsPage\Broadcast_Page;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SegmentsRepository as Segments;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use WP_UnitTestCase;

/**
 * "Leave out people tagged" as a searchable multi-select instead of a checkbox per tag.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ExcludeTagsSelectTest extends WP_UnitTestCase
{
    protected $campaign_id;
    protected $tags = [];

    public function set_up()
    {
        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) define('MAILOPTIN_DETACH_LIBSODIUM', true);

        parent::set_up();

        foreach ([17, 20, 21, 22] as $version) DBUpdates::get_instance()->{'update_routine_' . $version}();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        DBUpdates::get_instance()->update_routine_7();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();

        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, ['from_name' => 'Acme Garden Supply', 'from_email' => 'hello@acmegarden.co']);

        // Enough tags that a checkbox each would be a long list.
        for ($i = 1; $i <= 50; $i++) $this->tags[$i] = Tags::create(sprintf('tag-%02d', $i));

        $list              = ListsRepository::create(['name' => 'Newsletter']);
        $this->campaign_id = ER::add_email_campaign('November notes', ER::NEWSLETTER, 'Lucid');

        Broadcasts::save($this->campaign_id, ['email_campaign_title' => 'Hi', 'connection_service' => 'MailOptinConnect', 'connection_email_list' => (string)$list]);
    }

    public function tear_down()
    {
        \MailOptin\Core\Repositories\CampaignSettingsRepository::delete_all(\MailOptin\Core\Repositories\CampaignSettingsRepository::TYPE_EMAIL);
        $_POST = $_GET = $_REQUEST = [];

        parent::tear_down();
    }

    public function test_broadcast_page_shows_one_select_with_the_saved_tags_chosen()
    {
        Broadcasts::save($this->campaign_id, [Segments::EXCLUDE_TAGS_SETTING => $this->tags[3] . ',' . $this->tags[12]]);

        $_GET = $_REQUEST = ['page' => MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG, 'view' => Broadcast_Page::VIEW, 'id' => $this->campaign_id];
        $html = Broadcast_Page::html();

        $this->assertSame(1, substr_count($html, 'id="mo-exclude-tags"'));
        $this->assertMatchesRegularExpression('/<select id="mo-exclude-tags" name="exclude_tags\[\]"[^>]*\bmultiple\b/', $html);
        $this->assertSame(0, substr_count($html, 'type="checkbox" name="exclude_tags[]"'));
        $this->assertSame(50, preg_match_all('/<option value="\d+"\s*(selected=\'selected\')?\s*>tag-\d\d<\/option>/', $html));
        $this->assertSame(2, preg_match_all('/selected=\'selected\'\s*>tag-(03|12)</', $html));
    }

    public function test_broadcast_page_saves_the_select()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        set_current_screen('dashboard');

        $save = function ($tags) {
            $_GET     = ['page' => MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG, 'view' => Broadcast_Page::VIEW, 'id' => $this->campaign_id];
            $_POST    = ['mo_broadcast_action' => 'save', 'id' => $this->campaign_id, '_wpnonce' => wp_create_nonce('mo_broadcast_' . $this->campaign_id), 'exclude_tags' => $tags];
            $_REQUEST = $_GET + $_POST;

            add_filter('mailoptin_broadcast_page_redirect', $stop = function () { throw new \RuntimeException('redirected'); });
            try {
                Broadcast_Page::get_instance()->handle_actions();
            } catch (\RuntimeException $e) {
            }
            remove_filter('mailoptin_broadcast_page_redirect', $stop);
        };

        // The hidden "" comes first, as the form sends it, so clearing every tag still posts the field.
        $save(['', (string)$this->tags[5], (string)$this->tags[40]]);
        $this->assertSame([$this->tags[5], $this->tags[40]], Segments::excluded_tag_ids($this->campaign_id));

        $save(['']);
        $this->assertSame([], Segments::excluded_tag_ids($this->campaign_id));
    }

    public function test_customizer_control_reads_the_comma_separated_setting()
    {
        require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
        $manager = new \WP_Customize_Manager();
        $id      = MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME . '[' . $this->campaign_id . '][' . Segments::EXCLUDE_TAGS_SETTING . ']';
        // The setting holds the saved comma-separated string, as campaign settings do.
        $manager->add_setting($id, ['type' => 'mo_test_setting', 'default' => $this->tags[7] . ',' . $this->tags[8]]);

        $control = new WP_Customize_Tags_Select_Control($manager, $id, ['settings' => $id, 'section' => 'x', 'choices' => Tags::names()]);

        ob_start();
        $control->render_content();
        $html = ob_get_clean();

        $this->assertSame(2, substr_count($html, "selected='selected'"));
        $this->assertStringContainsString('data-mo-setting="' . esc_attr($id) . '"', $html);
        $this->assertStringNotContainsString('data-customize-setting-link', $html);
        // Not picked up by chosen.js, which would search posts over AJAX as you type.
        $this->assertStringNotContainsString('mailoptin-chosen', $html);
        $this->assertSame(50, substr_count($html, '<option '));
    }

    public function test_the_customizer_uses_the_select()
    {
        require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
        $manager  = new \WP_Customize_Manager();
        $customer = (object)['campaign_settings_section_id' => 'settings', 'email_campaign_id' => $this->campaign_id];

        $controls = (new \MailOptin\MailOptinConnect\CampaignAudience())->controls([], $manager, MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME . '[' . $this->campaign_id . ']', $customer);

        $this->assertInstanceOf(WP_Customize_Tags_Select_Control::class, $controls[Segments::EXCLUDE_TAGS_SETTING]);
    }

    public function test_the_customizer_setting_stores_comma_separated_ids()
    {
        $settings = (new \MailOptin\MailOptinConnect\CampaignAudience())->settings([]);
        $sanitize = $settings[Segments::EXCLUDE_TAGS_SETTING]['sanitize_callback'];

        $this->assertSame($this->tags[2] . ',' . $this->tags[9], $sanitize([(string)$this->tags[2], (string)$this->tags[9]]));
        $this->assertSame($this->tags[2] . ',' . $this->tags[9], $sanitize($this->tags[2] . ',' . $this->tags[9]));
        $this->assertSame('', $sanitize(''));
        $this->assertSame('', $sanitize([]));
    }
}
