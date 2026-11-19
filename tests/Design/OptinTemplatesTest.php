<?php

namespace MailOptin\Core\Tests\Design;

use MailOptin\Core\OptinForms\Lightbox\BareMetal;
use MailOptin\Core\OptinForms\Inpost\Columbine;
use MailOptin\Core\Repositories\OptinCampaignsRepository as Repository;

/**
 * Front-end optin templates after the Refactoring UI pass: pop-ups fit a 320px screen, every template gets
 * a keyboard focus ring and 44px tap targets, and the worst low-contrast defaults are darker.
 */
class OptinTemplatesTest extends \WP_UnitTestCase
{
    protected $made = [];

    public function set_up()
    {
        parent::set_up();
        if ( ! defined('MAILOPTIN_VERSION_NUMBER')) define('MAILOPTIN_VERSION_NUMBER', 'tests');
        global $wpdb;
        $wpdb->optin_campaignmeta = $wpdb->prefix . \MailOptin\Core\Core::optin_campaign_meta_table_name;
    }

    public function tear_down()
    {
        foreach ($this->made as $id) Repository::delete_optin_campaign($id);
        parent::tear_down();
    }

    protected function campaign($class, $type)
    {
        $id           = Repository::add_optin_campaign('T' . wp_generate_password(9, false), 'Template ' . $class, $class, $type);
        $this->made[] = $id;

        return $id;
    }

    public function test_popup_overlay_padding_stays_inside_the_screen()
    {
        $html = (new BareMetal($this->campaign('BareMetal', 'lightbox')))->get_optin_form_structure();

        $this->assertMatchesRegularExpression("/style='[^']*width: 100%;[^']*padding: 0 10px;[^']*box-sizing: border-box;/", $html,
            'Without border-box, the 10px side padding pushes the pop-up and its close icon off a phone screen.');
    }

    public function test_every_template_gets_focus_ring_and_tap_targets()
    {
        $id   = $this->campaign('BareMetal', 'lightbox');
        $form = new BareMetal($id);
        $uuid = Repository::get_optin_campaign_uuid($id);
        $css  = $form->global_css();

        $this->assertStringContainsString(":focus-visible {outline: 2px solid #2271b1 !important;", $css);
        $this->assertStringNotContainsString(':focus-visible {outline:0', $css);

        // Close links: zero-specificity default position, so a template that positions its link still wins.
        $this->assertStringContainsString(":where(html div#$uuid a.mo-optin-form-close-icon) {position: relative;}", $css);
        $this->assertStringContainsString("html div#$uuid a.mo-optin-form-close-icon::before {content: ''; position: absolute; top: 50%; left: 50%; width: 44px; height: 44px;", $css);

        $this->assertMatchesRegularExpression('/@media screen and \(max-width: 480px\) \{[^}]*\.mo-optin-form-submit-button, [^{]*\{min-height: 44px !important;\}/', $css);
    }

    public function test_low_contrast_defaults_are_darker()
    {
        $html = (new Columbine($this->campaign('Columbine', 'inpost')))->optin_form_css();

        $this->assertStringNotContainsStringIgnoringCase('#54C3A5', $html, 'The old mint fails 4.5:1 on white.');
        $this->assertStringContainsString('#2e836c', $html);

        foreach (['Lightbox/Elegance', 'Inpost/Elegance', 'Sidebar/Gridgum', 'Sidebar/Lupin', 'Inpost/Columbine'] as $file) {
            $source = file_get_contents(MAILOPTIN_SRC . "OptinForms/$file.php");
            foreach (['#777777', 'color: #777;', '#46ca9b', '#54c3a5', '#1998d6'] as $old) {
                $this->assertStringNotContainsStringIgnoringCase($old, $source, "$file still uses $old.");
            }
        }
    }
}
