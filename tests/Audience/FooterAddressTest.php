<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\EmailCampaigns\TemplateTrait;
use WP_UnitTestCase;

class FooterAddressDouble
{
    use TemplateTrait;

    public $email_campaign_id = 0;
}

/**
 * The footer's mailing address when some details are empty: no raw {{tags}} and no stray commas.
 */
class FooterAddressTest extends WP_UnitTestCase
{
    /** The default footer, as the email template renders it (line breaks added). */
    const FOOTER = "<p class=\"mo-footer-description\">Our mailing address is:<br>\n{{company_name}}<br>\n{{company_address}},<br>\n{{company_address_2}}<br>\n{{company_city}}, {{company_state}} {{company_zip}}.<br>\n{{company_country}}.</p>";

    /** What the shared Settings instance held before each test, put back after it. */
    protected $saved;

    protected function settings_property()
    {
        $property = new \ReflectionProperty(\MailOptin\Core\PluginSettings\Settings::class, 'settings_data');
        $property->setAccessible(true);

        return $property;
    }

    public function set_up()
    {
        parent::set_up();

        $this->saved = $this->settings_property()->getValue(\MailOptin\Core\PluginSettings\Settings::instance());
    }

    public function tear_down()
    {
        $this->settings_property()->setValue(\MailOptin\Core\PluginSettings\Settings::instance(), $this->saved);

        parent::tear_down();
    }

    protected function footer($details)
    {
        update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, $details);

        // Settings::instance() keeps what it read first; give it the details for this test (tear_down restores).
        $this->settings_property()->setValue(\MailOptin\Core\PluginSettings\Settings::instance(), $details);

        return (new FooterAddressDouble())->replace_footer_placeholder_tags(self::FOOTER);
    }

    protected function full()
    {
        return ['company_name' => 'Acme Garden Supply', 'company_address' => '412 Orchard Lane', 'company_address_2' => 'Unit 4', 'company_city' => 'Portland', 'company_state' => 'OR', 'company_zip' => '97201', 'company_country' => 'US'];
    }

    public function test_every_detail_filled()
    {
        $this->assertSame(
            "<p class=\"mo-footer-description\">Our mailing address is:<br>\nAcme Garden Supply<br>\n412 Orchard Lane,<br>\nUnit 4<br>\nPortland, OR 97201.<br>\nUnited States (US).</p>",
            $this->footer($this->full())
        );
    }

    public function test_no_second_address_line()
    {
        $this->assertSame(
            "<p class=\"mo-footer-description\">Our mailing address is:<br>\nAcme Garden Supply<br>\n412 Orchard Lane,<br>\nPortland, OR 97201.<br>\nUnited States (US).</p>",
            $this->footer(['company_address_2' => ''] + $this->full())
        );
    }

    public function test_no_city_or_country()
    {
        $html = $this->footer(['company_city' => '', 'company_country' => ''] + $this->full());

        $this->assertStringContainsString("Unit 4<br>\nOR 97201.</p>", $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    public function test_no_state()
    {
        $this->assertStringContainsString("Portland 97201.", $this->footer(['company_state' => ''] + $this->full()));
    }

    public function test_nothing_but_a_name()
    {
        $html = $this->footer(['company_name' => 'Acme Garden Supply']);

        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString(',', $html);
    }

    public function test_the_customizer_preview_still_shows_what_is_missing()
    {
        global $wp_customize;
        require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
        $wp_customize = new \WP_Customize_Manager();
        $wp_customize->start_previewing_theme();

        $this->assertStringContainsString('{{company_address_2}}', $this->footer(['company_address_2' => ''] + $this->full()));

        $wp_customize->stop_previewing_theme();
        $wp_customize = null;
    }

    public function test_custom_footers()
    {
        // Last before the closing tag, after a comma.
        $this->assertSame('<p>Portland</p>', FooterAddressDouble::remove_empty_footer_tag('<p>Portland, {{company_state}}</p>', '{{company_state}}'));
        // One-line HTML with <br> between details.
        $this->assertSame('<p>Acme<br>Portland</p>', FooterAddressDouble::remove_empty_footer_tag('<p>Acme<br>{{company_address_2}}<br>Portland</p>', '{{company_address_2}}'));
        $this->assertSame('<p>Acme<br />Portland</p>', FooterAddressDouble::remove_empty_footer_tag('<p>Acme<br />{{company_address_2}},<br />Portland</p>', '{{company_address_2}}'));
        // Elsewhere in the email only the tag goes.
        $this->assertSame('<td></td>', FooterAddressDouble::remove_empty_footer_tag('<td>{{company_address_2}}</td>', '{{company_address_2}}'));
    }
}
