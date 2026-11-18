<?php

namespace MailOptin\Tests\Core\OptinForms;

use MailOptin\Core\Admin\Customizer\OptinForm\OptinFormFactory;
use MailOptin\Core\OptinForms\TwoStep;
use MailOptin\Core\OptinForms\Wheel\Spins;
use MailOptin\Core\OptinForms\Wheel\Wheel;
use MailOptin\Core\Repositories\OptinCampaignsRepository as Repository;
use MailOptin\Core\Repositories\OptinThemesRepository;
use WP_UnitTestCase;

/**
 * Spin to win: the slices as saved, the odds line, the draw, the spin and its record, one spin per address,
 * the prize email, and what reaches the page.
 */
class WheelTest extends WP_UnitTestCase
{
    protected $made = [];

    public function set_up()
    {
        parent::set_up();
        if ( ! defined('MAILOPTIN_VERSION_NUMBER')) define('MAILOPTIN_VERSION_NUMBER', 'tests');
        global $wpdb;
        $wpdb->optin_campaignmeta = $wpdb->prefix . \MailOptin\Core\Core::optin_campaign_meta_table_name;
        Spins::maybe_install();
        Repository::flush_saved_customizer_data_cache();
        reset_phpmailer_instance();
    }

    public function tear_down()
    {
        foreach ($this->made as $id) Repository::delete_optin_campaign($id);
        Repository::flush_saved_customizer_data_cache();
        parent::tear_down();
    }

    protected function campaign($settings = [], $class = 'WheelSplit', $type = 'lightbox')
    {
        $id           = Repository::add_optin_campaign('W' . wp_generate_password(9, false), 'Wheel ' . wp_generate_password(6, false), $class, $type);
        $this->made[] = $id;

        $all      = Repository::get_settings();
        $all[$id] = array_merge($all[$id] ?? [], $settings);
        Repository::updateSettings($all);
        Repository::flush_saved_customizer_data_cache();

        return $id;
    }

    protected function slices($list)
    {
        $out = [];
        foreach ($list as $i => $s) $out[] = array_merge(['id' => 's' . ($i + 1), 'label' => 'Prize ' . ($i + 1), 'type' => 'code', 'code' => 'CODE' . ($i + 1), 'chance' => 0, 'color' => '#2f6b4f', 'ink' => '#ffffff'], $s);

        return wp_json_encode($out);
    }

    public function test_slices_are_cleaned_when_saved()
    {
        $clean = json_decode(Wheel::sanitize_slices(wp_json_encode([
            ['id' => 'a', 'label' => '<b>Ten</b> off', 'type' => 'code', 'code' => ' TEN<script> ', 'chance' => 140, 'color' => 'red', 'ink' => '#fff', 'evil' => 'x'],
            ['id' => 'a', 'label' => 'Guide', 'type' => 'link', 'url' => 'javascript:alert(1)', 'chance' => -5],
            ['label' => 'Odd', 'type' => 'jackpot', 'chance' => 10],
        ])), true);

        $this->assertCount(3, $clean);
        $this->assertSame('Ten off', $clean[0]['label'], 'Text only.');
        $this->assertSame('TEN', $clean[0]['code']);
        $this->assertSame(100, $clean[0]['chance'], 'Chances are 0 to 100.');
        $this->assertSame(0, $clean[1]['chance']);
        $this->assertSame('#2f6b4f', $clean[0]['color'], 'Only hex colours.');
        $this->assertArrayNotHasKey('evil', $clean[0]);
        $this->assertNotSame($clean[0]['id'], $clean[1]['id'], 'Slice ids stay unique.');
        $this->assertSame('', $clean[1]['url'], 'Only web addresses.');
        $this->assertSame('none', $clean[2]['type'], 'An unknown prize is no prize.');

        $this->assertCount(6, json_decode(Wheel::sanitize_slices('[{"label":"Only one"}]'), true), 'Fewer than two slices: the default wheel.');
        $this->assertCount(Wheel::MAX_SLICES, json_decode(Wheel::sanitize_slices(wp_json_encode(array_fill(0, 20, ['label' => 'x', 'type' => 'none']))), true));
    }

    public function test_the_odds_line_is_worked_out_from_the_chances()
    {
        $s = function ($win, $lose) { return [['type' => 'code', 'chance' => $win], ['type' => 'none', 'chance' => $lose]]; };

        $this->assertSame('9 in 10 spins win a prize.', Wheel::odds_line($s(90, 10)));
        $this->assertSame('1 in 10 spins wins a prize.', Wheel::odds_line($s(10, 90)));
        $this->assertSame('Every spin wins a prize.', Wheel::odds_line($s(50, 0)));
        $this->assertSame('5% of spins win a prize.', Wheel::odds_line($s(5, 95)));
        $this->assertSame('', Wheel::odds_line($s(0, 100)), 'Nothing to win: no line.');
    }

    public function test_the_draw_follows_the_chances()
    {
        $slices = json_decode(Wheel::sanitize_slices($this->slices([['chance' => 25], ['chance' => 75], ['chance' => 0, 'type' => 'none'], ['type' => 'coupon', 'coupon' => 999999, 'chance' => 100]])), true);

        $counts = [0, 0, 0, 0];
        for ($i = 0; $i < 4000; $i++) $counts[Wheel::draw($slices)]++;

        $this->assertSame(0, $counts[2], 'A chance of 0 never comes up.');
        $this->assertSame(0, $counts[3], 'A prize the site can\'t give (no such coupon) never comes up.');
        $this->assertGreaterThan(850, $counts[0]);
        $this->assertLessThan(1150, $counts[0], 'About a quarter.');

        $this->assertNull(Wheel::draw(json_decode(Wheel::sanitize_slices($this->slices([['chance' => 0], ['chance' => 0]])), true)), 'Nothing can come up.');
    }

    public function test_a_spin_is_drawn_recorded_emailed_and_once_per_address()
    {
        $id = $this->campaign(['wheel_slices' => $this->slices([['label' => '10% off', 'code' => 'SPIN10', 'chance' => 100], ['type' => 'none', 'chance' => 0]])]);

        $first = Wheel::spin($id, 'Ann@Example.com');

        $this->assertFalse($first['already']);
        $this->assertSame(0, $first['slice']);
        $this->assertSame('SPIN10', $first['code']);
        $this->assertTrue($first['emailed']);

        $mail = tests_retrieve_phpmailer_instance()->get_sent();
        $this->assertSame('ann@example.com', strtolower($mail->to[0][0]));
        $this->assertStringContainsString('SPIN10', $mail->body);

        $spin = Spins::find($id, 'ann@example.com');
        $this->assertSame('s1', $spin->slice_id);
        $this->assertNotSame('ann@example.com', $spin->email_hash, 'Only a hash of the address is kept.');

        // The same address again: no second draw, and the prize isn't shown to whoever typed it.
        $again = Wheel::spin($id, 'ann@example.com');
        $this->assertTrue($again['already']);
        $this->assertArrayNotHasKey('code', $again);
        $this->assertSame(1, Spins::counts($id)['spins']);

        // One spin per address off: they can spin again.
        $all                         = Repository::get_settings();
        $all[$id]['wheel_once']      = false;
        Repository::updateSettings($all);
        Repository::flush_saved_customizer_data_cache();
        $this->assertFalse(Wheel::spin($id, 'ann@example.com')['already']);
        $this->assertSame(2, Spins::counts($id)['spins']);
    }

    public function test_no_prize_and_a_link_prize()
    {
        $lose = $this->campaign(['wheel_slices' => $this->slices([['type' => 'none', 'chance' => 100], ['chance' => 0]])]);
        $r    = Wheel::spin($lose, 'bo@example.com');
        $this->assertSame('none', $r['type']);
        $this->assertArrayNotHasKey('code', $r);
        $this->assertSame(0, Spins::counts($lose)['won']);
        $this->assertEmpty(tests_retrieve_phpmailer_instance()->get_sent(), 'No prize, no prize email.');

        $link = $this->campaign(['wheel_slices' => $this->slices([['type' => 'link', 'title' => 'The workbook', 'url' => 'https://example.org/book.pdf', 'button' => 'Download', 'chance' => 100], ['chance' => 0]])]);
        $r    = Wheel::spin($link, 'cy@example.com');
        $this->assertSame('link', $r['type']);
        $this->assertSame('https://example.org/book.pdf', $r['url']);
        $this->assertSame('The workbook', $r['title']);
    }

    public function test_the_prize_rides_on_a_successful_subscription_only()
    {
        $wheel = $this->campaign(['wheel_slices' => $this->slices([['chance' => 100], ['chance' => 0]])]);
        $plain = Repository::add_optin_campaign('W' . wp_generate_password(9, false), 'Plain', 'BareMetal', 'lightbox');
        $this->made[] = $plain;
        $w = Wheel::get_instance();

        $this->assertArrayHasKey('wheel', $w->respond(['success' => true], $wheel, ['email' => 'd@example.com']));
        $this->assertArrayNotHasKey('wheel', $w->respond(['success' => false, 'message' => 'x'], $wheel, ['email' => 'e@example.com']), 'No spin when the subscription failed.');
        $this->assertArrayNotHasKey('wheel', $w->respond(['success' => true], $plain, ['email' => 'f@example.com']), 'Only wheels.');
        $this->assertSame(1, Spins::counts($wheel)['spins']);
    }

    public function test_what_reaches_the_page()
    {
        $id = $this->campaign(['wheel_slices' => $this->slices([['label' => '<script>x</script> [mo-optin-form id=1]', 'chance' => 50], ['type' => 'none', 'label' => 'No luck', 'chance' => 50]])]);

        $form = OptinFormFactory::make($id);
        $html = do_shortcode($form->optin_form());

        $this->assertStringContainsString('data-slices="2"', $html);
        $this->assertStringNotContainsString('<script>x', $html, 'Slice labels are escaped.');
        $this->assertMatchesRegularExpression('/&#0?91;mo-optin-form id=1&#0?93;/', $html, 'And never run as shortcodes.');
        $this->assertStringContainsString('5 in 10 spins win a prize.', $html, 'The odds, from the chances.');

        preg_match('/=\s*(\{.*\});\s*$/s', $form->optin_js_config(), $m);
        $config = json_decode($m[1] ?? '{}', true);
        $this->assertTrue($config['is_wheel']);

        $this->assertFalse(TwoStep::supported($id), 'No two steps on a wheel.');

        $names = array_column(OptinThemesRepository::wheel_themes(), 'optin_class');
        $this->assertContains('WheelSplit', $names);
        $this->assertContains('WheelInline', $names);
    }

    public function test_deleting_the_campaign_deletes_its_spins()
    {
        $id = $this->campaign(['wheel_slices' => $this->slices([['chance' => 100], ['chance' => 0]])]);
        Wheel::spin($id, 'g@example.com');
        $this->assertSame(1, Spins::counts($id)['spins']);

        Repository::delete_optin_campaign($id);
        $this->made = array_diff($this->made, [$id]);

        $this->assertSame(0, Spins::counts($id)['spins']);
    }
}
