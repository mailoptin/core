<?php

namespace MailOptin\Tests\Core\Repositories;

use MailOptin\Core\Admin\Customizer\CampaignCustomizeSetting;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\CampaignSettingsRepository as Settings;
use MailOptin\Core\Repositories\EmailCampaignRepository;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use WP_UnitTestCase;

class CampaignSettingsStorageTest extends WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();

        DBUpdates::get_instance()->update_routine_23();

        // Tests that rename the table commit the transaction, so clear what they may leave behind.
        foreach ([MO_OPTIN_CAMPAIGN_WP_OPTION_NAME, MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME] as $name) {
            delete_option($name . Settings::LEGACY_SUFFIX);
        }
        delete_option(Settings::ERROR_OPTION);
        delete_option(Settings::LOCK_OPTION);
        update_option(Settings::READY_OPTION, time());

        Settings::flush_cache();
        Settings::delete_all(Settings::TYPE_OPTIN);
        Settings::delete_all(Settings::TYPE_EMAIL);
    }

    public function tear_down()
    {
        update_option(Settings::READY_OPTION, time());
        Settings::flush_cache();
        parent::tear_down();
    }

    /**
     * Back to before the move: settings in the two option rows, the table empty.
     */
    protected function legacy_site(array $optins, array $emails)
    {
        delete_option(Settings::READY_OPTION);
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Settings::table());
        Settings::flush_cache();

        update_option(MO_OPTIN_CAMPAIGN_WP_OPTION_NAME, $optins, false);
        update_option(MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME, $emails, false);
    }

    /**
     * Settings like real campaigns hold: HTML, JSON strings, nested arrays, booleans, numbers, unicode.
     */
    protected function sample($i)
    {
        return [
            'activate_optin'   => 0 === $i % 2,
            'headline'         => "Get 10% off, José's garden № $i 🌱",
            'description'      => '<p class="x">Join <strong>' . $i . '</strong> gardeners &amp; friends</p>',
            'fields'           => wp_json_encode([['cid' => 'c' . $i, 'field_type' => 'text', 'placeholder' => 'Name']]),
            'integrations'     => wp_json_encode([['connection_service' => 'MailOptinConnect', 'MailOptinConnect_lists' => '2']]),
            'load_optin_index' => [$i, $i + 1],
            'cookie'           => 30,
            'opacity'          => 0.85,
            'empty'            => '',
            'nested'           => ['a' => ['b' => [true, false, null]]],
        ];
    }

    public function test_migration_moves_every_campaign_unchanged_and_keeps_a_backup()
    {
        $optins = [];
        for ($i = 1; $i <= 1000; $i++) $optins[$i] = $this->sample($i);
        $emails = [3 => ['email_campaign_subject' => 'Bulbs, {{subscriber.first_name|friend}}', 'connection_email_list' => '2', 'activate_email_campaign' => true]];

        $this->legacy_site($optins, $emails);

        $this->assertFalse(Settings::is_ready());
        // Before the move, everything still reads the option rows.
        $this->assertSame($optins[7], OptinCampaignsRepository::get_settings_by_id(7));

        $this->assertTrue(Settings::migrate());
        Settings::flush_cache();

        $this->assertTrue(Settings::is_ready());
        foreach ($optins as $id => $settings) {
            $this->assertSame($settings, OptinCampaignsRepository::get_settings_by_id($id), "optin $id");
        }
        $this->assertSame($emails[3], EmailCampaignRepository::get_settings_by_id(3));

        global $wpdb;
        $this->assertSame('1000', $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Settings::table() . ' WHERE campaign_type = %s', 'optin')));

        // The rows are kept as backups, and are no longer the live ones. The original row stays as it
        // was, so an older MailOptin still finds every campaign after a downgrade.
        $this->assertSame($optins, get_option(MO_OPTIN_CAMPAIGN_WP_OPTION_NAME . Settings::LEGACY_SUFFIX));
        $this->assertSame($optins, maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", MO_OPTIN_CAMPAIGN_WP_OPTION_NAME))));

        // Running it again changes nothing.
        $this->assertTrue(Settings::migrate());
        $this->assertSame($optins[1000], OptinCampaignsRepository::get_settings_by_id(1000));
    }

    public function test_a_failed_move_leaves_the_site_on_the_option_rows()
    {
        $this->legacy_site([1 => ['headline' => 'Hi']], []);

        global $wpdb;
        $wpdb->query('RENAME TABLE ' . Settings::table() . ' TO ' . Settings::table() . '_away');

        $this->assertFalse(Settings::migrate());

        $wpdb->query('RENAME TABLE ' . Settings::table() . '_away TO ' . Settings::table());

        $this->assertFalse(Settings::is_ready());
        $this->assertStringContainsString('missing', get_option(Settings::ERROR_OPTION));
        $this->assertSame(['headline' => 'Hi'], OptinCampaignsRepository::get_settings_by_id(1));
        $this->assertSame([1 => ['headline' => 'Hi']], get_option(MO_OPTIN_CAMPAIGN_WP_OPTION_NAME));
    }

    public function test_campaigns_stored_as_objects_move_as_arrays()
    {
        $object           = new \stdClass();
        $object->headline = 'From an object';
        $object->nested   = (object)['a' => 1];

        $this->legacy_site([1 => $object, 2 => ['headline' => 'Array'], 'junk' => 'x', 3 => 'not settings'], []);

        $this->assertTrue(Settings::migrate());
        Settings::flush_cache();

        $this->assertSame(['headline' => 'From an object', 'nested' => ['a' => 1]], OptinCampaignsRepository::get_settings_by_id(1));
        $this->assertSame(['headline' => 'Array'], OptinCampaignsRepository::get_settings_by_id(2));
    }

    public function test_only_one_request_moves_the_settings_and_the_original_rows_are_kept()
    {
        $this->legacy_site([1 => ['headline' => 'A']], []);

        // Another request holds the lock.
        add_option(Settings::LOCK_OPTION, time(), '', 'no');
        $this->assertFalse(Settings::migrate());
        $this->assertFalse(Settings::is_ready());

        // A lock left by a request that died is taken over.
        update_option(Settings::LOCK_OPTION, time() - HOUR_IN_SECONDS);

        // The original row is the backup now; *_legacy records what was moved, to notice later changes.
        add_option(MO_OPTIN_CAMPAIGN_WP_OPTION_NAME . Settings::LEGACY_SUFFIX, [1 => ['headline' => 'Earlier backup'], 2 => ['headline' => 'B']], '', 'no');

        $this->assertTrue(Settings::migrate());
        $this->assertFalse(get_option(Settings::LOCK_OPTION));
        $this->assertSame([1 => ['headline' => 'A']], get_option(MO_OPTIN_CAMPAIGN_WP_OPTION_NAME . Settings::LEGACY_SUFFIX));
        $this->assertSame(['headline' => 'A'], OptinCampaignsRepository::get_settings_by_id(1));

        global $wpdb;
        $this->assertSame([1 => ['headline' => 'A']], maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", MO_OPTIN_CAMPAIGN_WP_OPTION_NAME))));
    }

    public function test_a_failed_move_is_retried_and_leftover_rows_are_cleared()
    {
        $this->legacy_site([1 => ['headline' => 'A']], []);

        global $wpdb;
        // Rows from an earlier, failed attempt, including a campaign since deleted.
        $wpdb->insert(Settings::table(), ['campaign_type' => 'optin', 'campaign_id' => 99, 'settings' => '{"headline":"orphan"}', 'updated_at' => gmdate('Y-m-d H:i:s')]);
        update_option(Settings::ERROR_OPTION, 'Earlier failure');
        delete_transient('mo_campaign_settings_retry');

        Settings::maybe_retry();
        Settings::flush_cache();

        $this->assertTrue(Settings::is_ready());
        $this->assertFalse(get_option(Settings::ERROR_OPTION));
        $this->assertSame([1], array_keys(Settings::all(Settings::TYPE_OPTIN)));

        // A retry happens at most once an hour.
        $this->assertNotFalse(get_transient('mo_campaign_settings_retry'));
    }

    public function test_save_many_compares_strictly()
    {
        OptinCampaignsRepository::save_settings_by_id(1, ['activate_optin' => '1', 'count' => 0]);

        OptinCampaignsRepository::updateSettings([1 => ['activate_optin' => true, 'count' => 'abc']]);
        Settings::flush_cache();

        $this->assertSame(['activate_optin' => true, 'count' => 'abc'], OptinCampaignsRepository::get_settings_by_id(1));
        $this->assertTrue(OptinCampaignsRepository::is_activated(1));
    }

    public function test_a_failed_write_doesnt_leave_the_new_value_cached()
    {
        OptinCampaignsRepository::save_settings_by_id(1, ['headline' => 'Stored']);

        global $wpdb;
        $wpdb->suppress_errors(true);
        $wpdb->query('RENAME TABLE ' . Settings::table() . ' TO ' . Settings::table() . '_away');

        $this->assertFalse(Settings::update(Settings::TYPE_OPTIN, 1, ['headline' => 'Not stored']));

        $wpdb->query('RENAME TABLE ' . Settings::table() . '_away TO ' . Settings::table());
        $wpdb->suppress_errors(false);

        $this->assertSame('Stored', OptinCampaignsRepository::get_customizer_value(1, 'headline'));
    }

    public function test_option_filters_still_run_on_the_old_names()
    {
        $this->setExpectedIncorrectUsage("get_option('mo_optin_campaign')");

        OptinCampaignsRepository::save_settings_by_id(1, ['headline' => 'One']);

        add_filter('option_' . MO_OPTIN_CAMPAIGN_WP_OPTION_NAME, function ($all) {
            $all[1]['filtered'] = true;

            return $all;
        });

        $this->assertTrue(get_option(MO_OPTIN_CAMPAIGN_WP_OPTION_NAME)[1]['filtered']);
    }

    public function test_cache_keys_change_when_the_table_is_emptied()
    {
        OptinCampaignsRepository::save_settings_by_id(1, ['headline' => 'Before uninstall']);
        $this->assertSame('Before uninstall', OptinCampaignsRepository::get_customizer_value(1, 'headline'));

        // A persistent cache keeps keys after the rows go; a new campaign 1 mustn't read them.
        Settings::delete_all(Settings::TYPE_OPTIN);
        OptinCampaignsRepository::save_settings_by_id(1, ['headline' => 'New campaign']);

        $this->assertSame('New campaign', OptinCampaignsRepository::get_customizer_value(1, 'headline'));
    }

    public function test_old_get_option_and_update_option_keep_working()
    {
        $this->setExpectedIncorrectUsage("get_option('mo_optin_campaign')");
        $this->setExpectedIncorrectUsage("update_option('mo_optin_campaign')");

        global $wpdb;
        $row_before = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", MO_OPTIN_CAMPAIGN_WP_OPTION_NAME));

        OptinCampaignsRepository::save_settings_by_id(1, ['headline' => 'One']);
        OptinCampaignsRepository::save_settings_by_id(2, ['headline' => 'Two']);

        $all = get_option(MO_OPTIN_CAMPAIGN_WP_OPTION_NAME);
        $this->assertSame(['headline' => 'One'], $all[1]);

        // A stale copy without campaign 2 changes campaign 1 and leaves 2 alone.
        update_option(MO_OPTIN_CAMPAIGN_WP_OPTION_NAME, [1 => ['headline' => 'Changed']]);
        Settings::flush_cache();

        $this->assertSame(['headline' => 'Changed'], OptinCampaignsRepository::get_settings_by_id(1));
        $this->assertSame(['headline' => 'Two'], OptinCampaignsRepository::get_settings_by_id(2));

        // Writes go to the table; the original row is left as the move found it.
        $this->assertSame($row_before, $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", MO_OPTIN_CAMPAIGN_WP_OPTION_NAME)));
    }

    public function test_saving_one_campaign_never_touches_another()
    {
        OptinCampaignsRepository::save_settings_by_id(1, ['headline' => 'One', 'activate_optin' => false]);
        OptinCampaignsRepository::save_settings_by_id(2, ['headline' => 'Two']);

        OptinCampaignsRepository::activate_campaign(1);
        OptinCampaignsRepository::enable_test_mode(2);

        $this->assertSame(['headline' => 'One', 'activate_optin' => true], OptinCampaignsRepository::get_settings_by_id(1));
        $this->assertSame(['headline' => 'Two', 'test_mode' => true], OptinCampaignsRepository::get_settings_by_id(2));

        OptinCampaignsRepository::delete_settings_by_id(1);
        $this->assertSame([], OptinCampaignsRepository::get_settings_by_id(1));
        $this->assertSame('Two', OptinCampaignsRepository::get_customizer_value(2, 'headline'));

        EmailCampaignRepository::update_settings_by_id(5, ['email_campaign_subject' => 'Hi']);
        EmailCampaignRepository::activate_email_campaign(5);
        $this->assertSame(['email_campaign_subject' => 'Hi', 'activate_email_campaign' => true], EmailCampaignRepository::get_settings_by_id(5));
    }

    public function test_prime_loads_several_campaigns_in_one_query()
    {
        foreach ([1, 2, 3] as $id) OptinCampaignsRepository::save_settings_by_id($id, ['headline' => "H$id"]);
        Settings::flush_cache();

        global $wpdb;
        $before = $wpdb->num_queries;
        Settings::prime(Settings::TYPE_OPTIN, [1, 2, 3]);
        $this->assertSame(1, $wpdb->num_queries - $before);

        $before = $wpdb->num_queries;
        foreach ([1, 2, 3] as $id) OptinCampaignsRepository::get_customizer_value($id, 'headline');
        $this->assertSame(0, $wpdb->num_queries - $before);
    }

    protected function customizer()
    {
        require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        return new \WP_Customize_Manager();
    }

    public function test_customizer_settings_read_preview_and_save_the_table()
    {
        global $wpdb;
        $row_before = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", MO_OPTIN_CAMPAIGN_WP_OPTION_NAME));

        OptinCampaignsRepository::save_settings_by_id(12, ['headline' => 'Saved', 'note' => 'keep', 'colors' => ['bg' => '#fff', 'fg' => '#000']]);
        OptinCampaignsRepository::save_settings_by_id(13, ['headline' => 'Other']);

        $manager   = $this->customizer();
        $sanitized = 0;

        // As MailOptin's CustomizerSettings register them, with the arguments they pass.
        CampaignCustomizeSetting::add($manager, 'mo_optin_campaign[12][headline]', ['type' => 'option', 'default' => 'Default', 'transport' => 'postMessage', 'sanitize_callback' => function ($value) use (&$sanitized) {
            $sanitized++;

            return strtoupper($value);
        }]);
        CampaignCustomizeSetting::add($manager, 'mo_optin_campaign[12][subhead]', ['type' => 'option', 'default' => 'Default sub']);
        CampaignCustomizeSetting::add($manager, 'mo_optin_campaign[12][colors][bg]', ['type' => 'option', 'default' => '#eee']);
        CampaignCustomizeSetting::add($manager, 'blogname', ['type' => 'option']);

        // Registering them never wrote every campaign's settings to the original row.
        $this->assertSame($row_before, $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", MO_OPTIN_CAMPAIGN_WP_OPTION_NAME)));

        $setting = $manager->get_setting('mo_optin_campaign[12][headline]');
        $this->assertInstanceOf(CampaignCustomizeSetting::class, $setting);
        $this->assertSame('postMessage', $setting->transport);
        $this->assertNotInstanceOf(CampaignCustomizeSetting::class, $manager->get_setting('blogname'));

        // Values come from the table, defaults where unset.
        $this->assertSame('Saved', $setting->value());
        $this->assertSame('Default sub', $manager->get_setting('mo_optin_campaign[12][subhead]')->value());
        $this->assertSame('#fff', $manager->get_setting('mo_optin_campaign[12][colors][bg]')->value());

        // Previewing an unsaved change shows it wherever the campaign is read, without saving it.
        $manager->set_post_value('mo_optin_campaign[12][headline]', 'Preview');
        $manager->set_post_value('mo_optin_campaign[12][colors][bg]', '#123');
        $setting->preview();
        $manager->get_setting('mo_optin_campaign[12][colors][bg]')->preview();

        $this->assertSame('PREVIEW', OptinCampaignsRepository::get_customizer_value(12, 'headline'));
        $this->assertSame(['bg' => '#123', 'fg' => '#000'], OptinCampaignsRepository::get_customizer_value(12, 'colors'));
        $this->assertSame('Saved', Settings::get_saved(Settings::TYPE_OPTIN, 12)['headline']);

        // Saving writes this campaign's keys, sanitized once, and nothing else.
        $sanitized = 0;
        $setting->save();
        $manager->get_setting('mo_optin_campaign[12][colors][bg]')->save();
        Settings::clear_preview();
        Settings::flush_cache();

        $this->assertSame(1, $sanitized);
        $this->assertSame(['headline' => 'PREVIEW', 'note' => 'keep', 'colors' => ['bg' => '#123', 'fg' => '#000']], OptinCampaignsRepository::get_settings_by_id(12));
        $this->assertSame(['headline' => 'Other'], OptinCampaignsRepository::get_settings_by_id(13));
    }

    public function test_a_post_value_set_after_preview_is_still_previewed()
    {
        OptinCampaignsRepository::save_settings_by_id(12, ['headline' => 'Saved']);

        $manager = $this->customizer();
        CampaignCustomizeSetting::add($manager, 'mo_optin_campaign[12][headline]', ['type' => 'option']);
        $setting = $manager->get_setting('mo_optin_campaign[12][headline]');

        $this->assertFalse($setting->preview());
        $manager->set_post_value('mo_optin_campaign[12][headline]', 'Later');

        $this->assertSame('Later', OptinCampaignsRepository::get_customizer_value(12, 'headline'));
    }

    public function test_email_customizer_settings_and_ids_parse()
    {
        $this->assertSame(['email', 7, ['email_campaign_subject']], CampaignCustomizeSetting::parse_id('mo_email_campaigns[7][email_campaign_subject]'));
        $this->assertSame(['optin', 12, ['a', 'b']], CampaignCustomizeSetting::parse_id('mo_optin_campaign[12][a][b]'));
        $this->assertSame([null, 0, []], CampaignCustomizeSetting::parse_id('mo_optin_campaign[12]'));
        $this->assertSame([null, 0, []], CampaignCustomizeSetting::parse_id('other[12][a]'));

        EmailCampaignRepository::save_settings_by_id(7, ['email_campaign_subject' => 'Old']);

        $manager = $this->customizer();
        CampaignCustomizeSetting::add($manager, 'mo_email_campaigns[7][email_campaign_subject]', ['type' => 'option', 'autoload' => false]);
        CampaignCustomizeSetting::add($manager, 'mo_email_campaigns[7][schedule_digit]', ['type' => 'option']);

        // EC_Customizer_Setting's checks still apply.
        $this->assertTrue(is_wp_error($manager->get_setting('mo_email_campaigns[7][schedule_digit]')->validate('soon')));
        $this->assertTrue($manager->get_setting('mo_email_campaigns[7][schedule_digit]')->validate('5'));

        $setting = $manager->get_setting('mo_email_campaigns[7][email_campaign_subject]');
        $manager->set_post_value($setting->id, 'New subject');
        $setting->save();
        Settings::flush_cache();

        $this->assertSame('New subject', EmailCampaignRepository::get_customizer_value(7, 'email_campaign_subject'));
    }

    public function test_settings_another_plugin_registers_as_options_are_swapped_in()
    {
        // Registering an option reads the whole row once, through the compatibility filter.
        $this->setExpectedIncorrectUsage("get_option('mo_optin_campaign')");

        OptinCampaignsRepository::save_settings_by_id(4, ['custom' => 'Saved']);

        $manager = $this->customizer();
        $manager->add_setting('mo_optin_campaign[4][custom]', ['type' => 'option', 'transport' => 'postMessage']);
        $manager->add_setting('blogname', ['type' => 'option']);

        CampaignCustomizeSetting::swap($manager);
        CampaignCustomizeSetting::swap($manager);

        $this->assertInstanceOf(CampaignCustomizeSetting::class, $manager->get_setting('mo_optin_campaign[4][custom]'));
        $this->assertSame('postMessage', $manager->get_setting('mo_optin_campaign[4][custom]')->transport);
        $this->assertSame('Saved', $manager->get_setting('mo_optin_campaign[4][custom]')->value());
        $this->assertNotInstanceOf(CampaignCustomizeSetting::class, $manager->get_setting('blogname'));
    }

    public function test_not_swapped_before_the_move()
    {
        $this->legacy_site([], []);

        $manager = $this->customizer();
        CampaignCustomizeSetting::add($manager, 'mo_optin_campaign[1][headline]', ['type' => 'option']);
        CampaignCustomizeSetting::add($manager, 'mo_email_campaigns[1][schedule_digit]', ['type' => 'option']);
        CampaignCustomizeSetting::swap($manager);

        $this->assertNotInstanceOf(CampaignCustomizeSetting::class, $manager->get_setting('mo_optin_campaign[1][headline]'));
        $this->assertSame('option', $manager->get_setting('mo_optin_campaign[1][headline]')->type);
        $this->assertInstanceOf(\MailOptin\Core\Admin\Customizer\EmailCampaign\EC_Customizer_Setting::class, $manager->get_setting('mo_email_campaigns[1][schedule_digit]'));
    }
}
