<?php

namespace MailOptin\Tests\Core\OptinForms;

use MailOptin\Core\Admin\Customizer\OptinForm\OptinFormFactory;
use MailOptin\Core\OptinForms\AbstractOptinForm;
use MailOptin\Core\OptinForms\Countdown\Countdown;
use MailOptin\Core\Repositories\OptinCampaignsRepository as Repository;
use WP_UnitTestCase;

/**
 * Countdown: which themes have one and where, what's saved, when it ends, what the page gets,
 * and that a stopped campaign stays off the page.
 */
class CountdownTest extends WP_UnitTestCase
{
    protected $made = [];

    public function set_up()
    {
        parent::set_up();
        if ( ! defined('MAILOPTIN_VERSION_NUMBER')) define('MAILOPTIN_VERSION_NUMBER', 'tests');
        global $wpdb;
        $wpdb->optin_campaignmeta = $wpdb->prefix . \MailOptin\Core\Core::optin_campaign_meta_table_name;
        update_option('timezone_string', 'America/New_York');
        Repository::flush_saved_customizer_data_cache();
    }

    public function tear_down()
    {
        foreach ($this->made as $id) Repository::delete_optin_campaign($id);
        Repository::flush_saved_customizer_data_cache();
        update_option('timezone_string', '');
        parent::tear_down();
    }

    protected function campaign($settings = [], $class = 'BareMetal', $type = 'inpost')
    {
        $id           = Repository::add_optin_campaign('C' . wp_generate_password(9, false), 'Countdown ' . wp_generate_password(6, false), $class, $type);
        $this->made[] = $id;

        $all      = Repository::get_settings();
        $all[$id] = array_merge($all[$id] ?? [], $settings);
        Repository::updateSettings($all);
        Repository::flush_saved_customizer_data_cache();

        return $id;
    }

    protected function on($settings = [], $class = 'BareMetal', $type = 'inpost')
    {
        return $this->campaign(array_merge([
            'countdown_enabled' => true,
            'countdown_mode'    => 'fixed',
            'countdown_end'     => wp_date('m/d/Y h:i A', time() + 2 * DAY_IN_SECONDS + 5 * HOUR_IN_SECONDS),
        ], $settings), $class, $type);
    }

    public function test_themes_declare_their_positions_and_unknown_ones_are_dropped()
    {
        $this->assertSame([AbstractOptinForm::COUNTDOWN_BELOW_DESCRIPTION, AbstractOptinForm::COUNTDOWN_ABOVE_HEADLINE, AbstractOptinForm::COUNTDOWN_ABOVE_BUTTON], Countdown::positions($this->campaign()));
        $this->assertSame([AbstractOptinForm::COUNTDOWN_ABOVE_HEADLINE, AbstractOptinForm::COUNTDOWN_ABOVE_BUTTON], Countdown::positions($this->campaign([], 'Gridgum', 'sidebar')));
        $this->assertSame([], Countdown::positions($this->campaign([], 'WheelSplit', 'lightbox')), 'Wheels have no countdown');
        $this->assertSame([], Countdown::positions(999999));
    }

    public function test_the_saved_position_is_used_only_when_the_theme_has_it()
    {
        $this->assertSame('above_button', Countdown::resolved_position($this->on(['countdown_position' => 'above_button'])));
        $this->assertSame('below_description', Countdown::resolved_position($this->on(['countdown_position' => 'nowhere'])));
        $this->assertSame('above_headline', Countdown::resolved_position($this->on(['countdown_position' => 'below_description'], 'Gridgum', 'sidebar')));
    }

    public function test_saved_values_are_cleaned()
    {
        $this->assertSame('fixed', Countdown::sanitize_mode('bogus'));
        $this->assertSame(defined('MAILOPTIN_DETACH_LIBSODIUM') ? 'daily' : 'fixed', Countdown::sanitize_mode('daily'));

        $this->assertSame('site', Countdown::sanitize_timezone('site'));
        $this->assertSame('visitor', Countdown::sanitize_timezone('visitors_local_time'));
        $this->assertSame('-18000', Countdown::sanitize_timezone('-18000'));
        $this->assertSame('site', Countdown::sanitize_timezone((string)(15 * HOUR_IN_SECONDS)));
        $this->assertSame('site', Countdown::sanitize_timezone('Europe/<script>'));

        $this->assertSame('#1f2a24', Countdown::css_color('#1f2a24'));
        $this->assertSame('rgba(0, 0, 0, .5)', Countdown::css_color('rgba(0, 0, 0, .5)'));
        $this->assertSame('', Countdown::css_color('red;}body{display:none'));
        $this->assertSame('', Countdown::css_color('url(x)'));
    }

    public function test_active_needs_the_toggle_a_theme_with_positions_and_an_end_date()
    {
        $this->assertFalse(Countdown::active($this->campaign()));
        $this->assertTrue(Countdown::active($this->on()));
        $this->assertFalse(Countdown::active($this->on(['countdown_end' => ''])));
        $this->assertFalse(Countdown::active($this->on([], 'WheelSplit', 'lightbox')));

        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) {
            $this->assertFalse(Countdown::active($this->on(['countdown_mode' => 'evergreen', 'countdown_duration' => 30])), 'Premium modes stay off on free sites');
        }
    }

    public function test_the_end_follows_the_chosen_time_zone()
    {
        $end = '11/30/2026 11:59 PM';

        // Site time zone: New York, UTC-5 in November.
        $this->assertSame(gmmktime(4, 59, 0, 12, 1, 2026), Countdown::end_timestamp($this->on(['countdown_end' => $end, 'countdown_timezone' => 'site'])));

        // A fixed offset, as the schedule saves it: UTC+2.
        $this->assertSame(gmmktime(21, 59, 0, 11, 30, 2026), Countdown::end_timestamp($this->on(['countdown_end' => $end, 'countdown_timezone' => (string)(2 * HOUR_IN_SECONDS)])));

        // The visitor's own clock: only the browser knows.
        $this->assertNull(Countdown::end_timestamp($this->on(['countdown_end' => $end, 'countdown_timezone' => 'visitor'])));

        $this->assertNull(Countdown::end_timestamp($this->on(['countdown_end' => 'not a date'])));
    }

    public function test_units_and_the_sentence()
    {
        $this->assertSame(['days' => 2, 'hours' => 5, 'minutes' => 14, 'seconds' => 9], Countdown::units(2 * DAY_IN_SECONDS + 5 * HOUR_IN_SECONDS + 14 * MINUTE_IN_SECONDS + 9));
        $this->assertNull(Countdown::units(3600)['days'], 'No days left: hidden');
        $this->assertSame(0, Countdown::units(3600, false)['days']);
        $this->assertSame(['days' => null, 'hours' => 0, 'minutes' => 0, 'seconds' => 0], Countdown::units(-5));

        $this->assertSame('Ends in 2 days, 5 hours, 14 minutes', Countdown::sentence(2 * DAY_IN_SECONDS + 5 * HOUR_IN_SECONDS + 14 * MINUTE_IN_SECONDS + 9));
        $this->assertSame('Ends in 1 hour', Countdown::sentence(HOUR_IN_SECONDS + 30));
        $this->assertSame('Ends in under a minute', Countdown::sentence(42));
    }

    public function test_seconds_left_for_a_date_and_to_midnight()
    {
        $id  = $this->on(['countdown_end' => '11/30/2026 11:59 PM', 'countdown_timezone' => 'site']);
        $now = gmmktime(4, 0, 0, 12, 1, 2026);
        $this->assertSame(59 * MINUTE_IN_SECONDS, Countdown::seconds_left($id, $now));
        $this->assertSame(0, Countdown::seconds_left($id, $now + DAY_IN_SECONDS));

        if (defined('MAILOPTIN_DETACH_LIBSODIUM')) {
            $daily = $this->on(['countdown_mode' => 'daily']);
            // 10pm in New York: two hours to midnight there.
            $this->assertSame(2 * HOUR_IN_SECONDS, Countdown::seconds_left($daily, gmmktime(3, 0, 0, 12, 1, 2026)));
        }
    }

    public function test_a_campaign_set_to_stop_is_not_shown_after_the_end()
    {
        $past = wp_date('m/d/Y h:i A', time() - HOUR_IN_SECONDS);

        $stop    = $this->on(['countdown_end' => $past, 'countdown_on_end' => 'stop']);
        $message = $this->on(['countdown_end' => $past, 'countdown_on_end' => 'message', 'countdown_end_message' => 'The sale has ended.']);
        $running = $this->on(['countdown_on_end' => 'stop']);

        $this->assertFalse(apply_filters('mailoptin_show_optin_form', true, $stop));
        $this->assertTrue(apply_filters('mailoptin_show_optin_form', true, $message));
        $this->assertTrue(apply_filters('mailoptin_show_optin_form', true, $running));
        $this->assertFalse(apply_filters('mailoptin_show_optin_form', false, $running), 'Never turns a hidden campaign on');

        $html = OptinFormFactory::build($message);
        $this->assertStringContainsString('The sale has ended.', $html);
        $this->assertStringNotContainsString('role="timer"', $html);
    }

    public function test_the_page_gets_one_countdown_in_the_chosen_place()
    {
        $html = OptinFormFactory::build($this->on(['countdown_position' => 'above_headline', 'countdown_label_days' => 'Tage']));

        $this->assertSame(1, substr_count($html, 'role="timer"'));
        $this->assertStringContainsString('Tage', $html);
        $this->assertStringContainsString('Ends in 2 days', $html);
        preg_match('/<[a-z0-9]+ class="[^"]*mo-optin-form-headline/', $html, $m, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($m);
        $this->assertLessThan($m[0][1], strpos($html, 'role="timer"'));
    }

    public function test_above_the_button_follows_the_button_the_campaign_shows()
    {
        $form = OptinFormFactory::build($this->on(['countdown_position' => 'above_button']));
        $this->assertLessThan(strpos($form, '_submit_button'), strpos($form, 'role="timer"'));

        $cta = OptinFormFactory::build($this->on(['countdown_position' => 'above_button', 'display_only_button' => true]));
        $this->assertSame(1, substr_count($cta, 'role="timer"'));
        $this->assertLessThan(strpos($cta, '_cta_button'), strpos($cta, 'role="timer"'));
    }

    public function test_numbers_take_the_headline_colour_unless_one_is_set()
    {
        $html = OptinFormFactory::build($this->on(['headline_font_color' => '#123456']));
        $this->assertMatchesRegularExpression('/\.mo-countdown\{color:#123456\}/', $html);

        $html = OptinFormFactory::build($this->on(['headline_font_color' => '#123456', 'countdown_number_color' => '#abcdef']));
        $this->assertStringContainsString('.mo-countdown-number', $html);
        $this->assertStringContainsString('color:#abcdef', $html);
    }

    public function test_the_page_script_gets_what_it_needs()
    {
        $id     = $this->on(['countdown_on_end' => 'message', 'countdown_end_message' => 'Over']);
        $form   = OptinFormFactory::make($id);
        $config = apply_filters('mo_optin_js_config', [], $form);

        $this->assertSame('fixed', $config['countdown']['mode']);
        $this->assertSame('message', $config['countdown']['on_end']);
        $this->assertSame('Over', $config['countdown']['message']);
        $this->assertSame(Countdown::end_timestamp($id), $config['countdown']['end']);

        $this->assertArrayNotHasKey('countdown', apply_filters('mo_optin_js_config', [], OptinFormFactory::make($this->campaign())));
    }

    public function test_a_visitor_time_zone_date_goes_to_the_browser_as_numbers()
    {
        $id     = $this->on(['countdown_end' => '11/30/2026 11:59 PM', 'countdown_timezone' => 'visitor']);
        $config = apply_filters('mo_optin_js_config', [], OptinFormFactory::make($id));

        $this->assertSame([2026, 11, 30, 23, 59], $config['countdown']['end_local']);
        $this->assertArrayNotHasKey('end', $config['countdown']);
    }

    public function test_midnights_are_worked_out_on_the_server()
    {
        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) $this->markTestSkipped('Premium mode');

        $config = apply_filters('mo_optin_js_config', [], OptinFormFactory::make($this->on(['countdown_mode' => 'daily'])));
        $ends   = $config['countdown']['ends'];

        $this->assertCount(3, $ends);
        foreach ($ends as $end) $this->assertSame('00:00', wp_date('H:i', $end));
        $this->assertGreaterThan(time(), $ends[0]);
    }

    public function test_a_bad_saved_colour_never_reaches_the_page()
    {
        $html = OptinFormFactory::build($this->on(['countdown_number_color' => 'red}body{display:none', 'countdown_box_color' => '#abc']));

        $this->assertStringNotContainsString('red}body', $html);
        $this->assertStringContainsString('background:#abc', $html);
    }
}
