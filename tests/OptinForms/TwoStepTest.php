<?php

namespace MailOptin\Tests\Core\OptinForms;

use MailOptin\Core\Admin\Customizer\OptinForm\OptinFormFactory;
use MailOptin\Core\OptinForms\TwoStep;
use MailOptin\Core\Repositories\OptinCampaignsRepository as Repository;
use WP_UnitTestCase;

/**
 * Two steps: what reaches the page, which campaigns can have it, and counting Yes and "subscribed after
 * Yes". Splitting the form is mailoptin.js's, checked on a test site against every template.
 */
class TwoStepTest extends WP_UnitTestCase
{
    protected $made = [];

    public function set_up()
    {
        parent::set_up();
        if ( ! defined('MAILOPTIN_VERSION_NUMBER')) define('MAILOPTIN_VERSION_NUMBER', 'tests');
        add_filter('mailoptin_premium_two_step', '__return_true');
        Repository::flush_saved_customizer_data_cache();

        // Set by the plugin's bootstrap, which the core tests don't run.
        global $wpdb;
        $wpdb->optin_campaignmeta = $wpdb->prefix . \MailOptin\Core\Core::optin_campaign_meta_table_name;
    }

    public function tear_down()
    {
        foreach ($this->made as $id) Repository::delete_optin_campaign($id);
        remove_filter('mailoptin_premium_two_step', '__return_true');
        Repository::flush_saved_customizer_data_cache();
        unset($_REQUEST['stat_data']);
        parent::tear_down();
    }

    protected function campaign($class, $type, $settings = [])
    {
        $id           = Repository::add_optin_campaign(wp_generate_password(10, false), 'TS ' . wp_generate_password(6, false), $class, $type);
        $this->made[] = $id;

        $all      = Repository::get_settings();
        $all[$id] = array_merge($all[$id] ?? [], $settings);
        Repository::updateSettings($all);
        Repository::flush_saved_customizer_data_cache();

        return $id;
    }

    protected function config($id)
    {
        preg_match('/=\s*(\{.*\});\s*$/s', OptinFormFactory::make($id)->optin_js_config(), $m);

        return json_decode($m[1] ?? '{}', true);
    }

    public function test_the_page_gets_the_question_with_clean_text()
    {
        $id = $this->campaign('BareMetal', 'lightbox', [
            'twostep_status'   => true,
            'twostep_question' => '<b>Want</b> our news?',
            'twostep_text'     => '',
            'twostep_yes'      => '  ',
            'twostep_no'       => 'Not now',
            'twostep_progress' => false,
        ]);

        $t = $this->config($id)['twostep'];

        $this->assertSame('Want our news?', $t['question'], 'Text only.');
        $this->assertSame('', $t['text'], 'No text under it is allowed.');
        $this->assertSame('Yes, sign me up', $t['yes'], 'An empty Yes falls back.');
        $this->assertSame('Not now', $t['no']);
        $this->assertFalse($t['progress']);
    }

    public function test_only_when_turned_on_with_premium_on_a_form()
    {
        $this->assertArrayNotHasKey('twostep', $this->config($this->campaign('BareMetal', 'lightbox')), 'Off by default.');

        // A campaign that is only a button has no form to come second.
        $this->assertArrayNotHasKey('twostep', $this->config($this->campaign('BareMetal', 'lightbox', ['twostep_status' => true, 'display_only_button' => true])));

        // Messages don't collect an email.
        $this->assertFalse(TwoStep::supported($this->campaign('NoticeBox', 'inpost')));
        // Bars are too short for two steps.
        $this->assertFalse(TwoStep::supported($this->campaign('BareMetal', 'bar')));
        $this->assertTrue(TwoStep::supported($this->campaign('BareMetal', 'inpost')));

        remove_filter('mailoptin_premium_two_step', '__return_true');
        $this->assertArrayNotHasKey('twostep', $this->config($this->campaign('BareMetal', 'lightbox', ['twostep_status' => true])), 'Not without Premium.');
    }

    public function test_add_ons_can_rule_campaigns_out()
    {
        $id = $this->campaign('BareMetal', 'lightbox');
        add_filter('mailoptin_twostep_supported', '__return_false');

        $this->assertFalse(TwoStep::supported($id));

        remove_filter('mailoptin_twostep_supported', '__return_false');
    }

    protected function send($uuid, $stage = 'yes')
    {
        $_REQUEST['stat_data'] = ['optin_uuid' => $uuid, 'stage' => $stage];

        try {
            TwoStep::get_instance()->track_yes();
        } catch (\WPDieException $e) {
        }
    }

    public function test_yes_and_subscribed_are_counted_once_per_visitor_per_day()
    {
        $id   = $this->campaign('BareMetal', 'lightbox', ['twostep_status' => true]);
        $uuid = Repository::get_optin_campaign_uuid($id);
        Repository::activate_campaign($id);
        Repository::flush_saved_customizer_data_cache();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $this->send($uuid);
        $this->send($uuid);
        $this->assertSame(1, TwoStep::yes_count($id), 'The same visitor again the same day: not counted twice.');

        $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
        $this->send($uuid);
        $this->assertSame(2, TwoStep::yes_count($id));

        $this->send($uuid, 'done');
        $this->assertSame(1, TwoStep::done_count($id));
        $this->assertSame(2, TwoStep::yes_count($id), 'Subscribing is its own count.');

        // An unknown or inactive campaign counts nothing.
        $this->send('nosuchuuid0');
        Repository::deactivate_campaign($id);
        Repository::flush_saved_customizer_data_cache();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->send($uuid);
        $this->assertSame(2, TwoStep::yes_count($id));
    }

    public function test_campaigns_without_two_steps_count_nothing()
    {
        $id = $this->campaign('BareMetal', 'lightbox');
        Repository::activate_campaign($id);
        Repository::flush_saved_customizer_data_cache();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.20';

        $this->send(Repository::get_optin_campaign_uuid($id));

        $this->assertSame(0, TwoStep::yes_count($id), 'Two steps is off: a beacon to it is ignored.');
    }

    public function test_a_forwarded_for_header_does_not_make_a_new_visitor()
    {
        $id   = $this->campaign('BareMetal', 'lightbox', ['twostep_status' => true]);
        $uuid = Repository::get_optin_campaign_uuid($id);
        Repository::activate_campaign($id);
        Repository::flush_saved_customizer_data_cache();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.30';

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
        $this->send($uuid);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.2';
        $this->send($uuid);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $this->assertSame(1, TwoStep::yes_count($id));
    }

    public function test_the_first_count_makes_one_row_and_resets_clear_it()
    {
        global $wpdb;
        $id = $this->campaign('BareMetal', 'lightbox', ['twostep_status' => true]);

        TwoStep::add_count($id, TwoStep::YES_META);
        TwoStep::add_count($id, TwoStep::YES_META);

        $rows = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->optin_campaignmeta} WHERE optin_campaign_id = %d AND meta_key = %s", $id, TwoStep::YES_META));
        $this->assertSame(1, $rows);
        $this->assertSame(2, TwoStep::yes_count($id));

        TwoStep::add_count($id, TwoStep::DONE_META);
        (new \MailOptin\Core\Repositories\OptinCampaignStat($id))->reset_stat();
        $this->assertSame(0, TwoStep::yes_count($id), 'Resetting the stats resets both counts.');
        $this->assertSame(0, TwoStep::done_count($id));

        TwoStep::add_count($id, TwoStep::YES_META);
        Repository::delete_optin_campaign($id);
        $rows = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->optin_campaignmeta} WHERE optin_campaign_id = %d AND meta_key LIKE 'twostep_%%'", $id));
        $this->assertSame(0, $rows, 'Deleting the campaign removes its counts.');
    }
}
