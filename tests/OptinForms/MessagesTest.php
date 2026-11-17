<?php

namespace MailOptin\Tests\Core\OptinForms;

use MailOptin\Core\Admin\Customizer\OptinForm\OptinFormFactory;
use MailOptin\Core\OptinForms\Messages;
use MailOptin\Core\Repositories\OptinCampaignsRepository as Repository;
use MailOptin\Core\Repositories\OptinThemesRepository;
use WP_UnitTestCase;

/**
 * Message campaigns (Announcements): the templates, which campaigns count as messages, the Customizer
 * sections they get, and clicks shown on the list.
 */
class MessagesTest extends WP_UnitTestCase
{
    protected $made = [];

    public function set_up()
    {
        parent::set_up();
        if ( ! defined('MAILOPTIN_VERSION_NUMBER')) define('MAILOPTIN_VERSION_NUMBER', 'tests');
        Repository::flush_saved_customizer_data_cache();
    }

    public function tear_down()
    {
        foreach ($this->made as $id) Repository::delete_optin_campaign($id);
        Repository::flush_saved_customizer_data_cache();
        unset($_REQUEST['mailoptin_optin_campaign_id']);
        parent::tear_down();
    }

    protected function campaign($class, $type)
    {
        $id           = Repository::add_optin_campaign(wp_generate_password(10, false), 'Msg ' . wp_generate_password(6, false), $class, $type);
        $this->made[] = $id;

        return $id;
    }

    public function test_fifteen_templates_four_of_them_free()
    {
        // Without Premium: the four free ones, and the locked Premium pop-ups, in-post and sidebar ones (free
        // sites have no bars or slide-ins, whose "upgrade" notice shows instead).
        $themes = OptinThemesRepository::message_themes();

        $this->assertCount(9, $themes);
        $this->assertCount(5, OptinThemesRepository::premium_message_themes());
        $this->assertEmpty(array_filter($themes, function ($t) { return in_array($t['optin_type'], ['bar', 'slidein'], true); }));

        $free = array_map(function ($t) { return $t['optin_class']; }, array_filter($themes, function ($t) { return empty($t['flag']); }));
        $this->assertEqualsCanonicalizing(['AnnouncementPopup', 'NoticeBox', 'FeatureCard', 'SimpleNotice'], array_values($free));

        foreach ($themes as $t) {
            $this->assertSame(OptinThemesRepository::OBJECTIVE_MESSAGES, $t['objective']);
            if (empty($t['flag'])) $this->assertTrue(class_exists('MailOptin\\Core\\OptinForms\\' . ucfirst($t['optin_type']) . '\\' . $t['optin_class']), $t['optin_class'] . ' is in core');
        }

        $this->assertArrayHasKey('messages', OptinThemesRepository::objectives());
    }

    public function test_a_message_campaign_is_known_and_renders_its_parts()
    {
        $box  = $this->campaign('NoticeBox', 'inpost');
        $form = $this->campaign('BareMetal', 'inpost');

        $this->assertTrue(Messages::is_campaign($box));
        $this->assertFalse(Messages::is_campaign($form));
        $this->assertSame(['icon', 'title', 'text', 'button'], Messages::parts($box));

        $html = OptinFormFactory::build($box);
        $this->assertStringContainsString('class="mo-optin-form-cta-button mo-msg-button"', $html);
        $this->assertStringContainsString('Read the guide', $html);
        $this->assertStringNotContainsString('type="email"', $html, 'No email field');
    }

    public function test_the_button_link_and_new_tab_are_escaped_settings()
    {
        $id                = $this->campaign('FeatureCard', 'inpost');
        $settings          = Repository::get_settings();
        $settings[$id]     = ['cta_button_navigation_url' => 'javascript:alert(1)', 'msg_new_tab' => true, 'msg_label' => '<b>x</b>'];
        Repository::updateSettings($settings);
        Repository::flush_saved_customizer_data_cache();

        $html = OptinFormFactory::build($id);

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('target="_blank" rel="noopener"', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }

    public function test_typed_brackets_never_run_as_shortcodes_and_the_image_is_an_img()
    {
        add_shortcode('mo_test_boom', function () { return 'BOOM'; });

        $id            = $this->campaign('AnnouncementPopup', 'lightbox');
        $settings      = Repository::get_settings();
        $settings[$id] = ['msg_no_thanks' => 'No [mo_test_boom] thanks', 'msg_image' => "https://example.org/x.jpg');position:fixed;inset:0;background:url('//evil"];
        Repository::updateSettings($settings);
        Repository::flush_saved_customizer_data_cache();

        $html = OptinFormFactory::build($id);

        $this->assertStringNotContainsString('BOOM', $html);
        $this->assertMatchesRegularExpression('/No &#0?91;mo_test_boom&#0?93; thanks/', $html);
        $this->assertDoesNotMatchRegularExpression('#url\([^)]*x\.jpg#', $html);
        $this->assertMatchesRegularExpression('#<img src="[^"]*" alt="">#', $html);
    }

    public function test_the_customizer_shows_message_and_hides_what_a_message_has_no_use_for()
    {
        $id = $this->campaign('AnnouncementPopup', 'lightbox');
        $_REQUEST['mailoptin_optin_campaign_id'] = $id;

        $sections = (new Messages())->sections(['mo_design_section', 'mo_headline_section', 'mo_fields_section', 'mo_integration_section', 'mo_success_section', 'mo_configuration_section', 'mo_note_section', 'mo_description_section']);

        $this->assertEqualsCanonicalizing(['mo_design_section', 'mo_configuration_section', Messages::SECTION], $sections);

        $_REQUEST['mailoptin_optin_campaign_id'] = $this->campaign('BareMetal', 'lightbox');
        $this->assertContains('mo_fields_section', (new Messages())->sections(['mo_fields_section']), 'Ordinary optins keep their sections');
    }

    public function test_the_list_shows_clicks_for_messages()
    {
        $box  = $this->campaign('SimpleNotice', 'sidebar');
        $form = $this->campaign('Lupin', 'sidebar');

        $this->assertStringContainsString('3 clicks', (new Messages())->list_value(3, ['id' => $box], 'conversion'));
        $this->assertSame(3, (new Messages())->list_value(3, ['id' => $form], 'conversion'));
        $this->assertSame('x', (new Messages())->list_value('x', ['id' => $box], 'impression'));
    }
}
