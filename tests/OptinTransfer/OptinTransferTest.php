<?php

namespace MailOptin\Core\Tests\OptinTransfer;

use MailOptin\Core\OptinTransfer\Exporter;
use MailOptin\Core\OptinTransfer\Importer;
use MailOptin\Core\Repositories\CampaignSettingsRepository as Settings;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\OptinCampaignMeta;
use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;
use MailOptin\Core\Repositories\TagsRepository as Tags;

/**
 * Exporting optin campaigns to a file and importing them on another site, simulated here by changing the
 * site in between: pages move to new IDs, tags and custom fields are deleted.
 */
class OptinTransferTest extends \WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();
        if ( ! defined('MAILOPTIN_VERSION_NUMBER')) define('MAILOPTIN_VERSION_NUMBER', 'tests');
        global $wpdb;
        $wpdb->optin_campaignmeta = $wpdb->prefix . \MailOptin\Core\Core::optin_campaign_meta_table_name;
        // Tags and custom fields are on (their tables are in this database version).
        update_option('mo_db_ver', \MailOptin\Core\DBUpdates::DB_VER);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function campaign($name, $settings, $class = 'BareMetal', $type = 'lightbox')
    {
        $id = OCR::add_optin_campaign(\MailOptin\Core\AjaxHandler::generateUniqueId(), $name, $class, $type);
        OCR::save_settings_by_id($id, $settings);

        return $id;
    }

    protected function roundtrip(array $ids)
    {
        $export = Importer::parse(wp_json_encode(Exporter::build($ids)));
        $this->assertIsArray($export, 'The file the exporter writes is one the importer accepts.');

        return $export;
    }

    public function test_references_are_found_again_or_reported()
    {
        $guide = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Planting guide', 'post_name' => 'planting-guide']);
        $gone  = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Old pricing', 'post_name' => 'old-pricing']);
        $cat   = self::factory()->category->create(['name' => 'Bulbs', 'slug' => 'bulbs']);
        $tag   = Tags::create('Spring buyers ' . wp_generate_password(4, false));
        $field = Fields::create(['label' => 'Company', 'type' => 'text', 'merge_key' => 'company_' . strtolower(wp_generate_password(4, false))]);
        $key   = Fields::get($field)['merge_key'];

        $id   = $this->campaign('Spring guide', ['headline' => 'Get the guide', 'activate_optin' => true]);
        $uuid = OCR::get_optin_campaign_uuid($id);
        OCR::save_settings_by_id($id, [
            'headline'             => 'Get the guide',
            'activate_optin'       => true,
            'pages_never_load'     => [$guide, $gone],
            'post_categories_load' => [$cat],
            'form_custom_css'      => "#$uuid .x{color:red}",
            'integrations'         => wp_json_encode([
                ['connection_service' => 'MailChimpConnect', 'connection_email_list' => 'a1b2c3'],
                ['connection_service' => 'MailOptinConnect', 'MailOptinConnect_lead_tags' => [$tag]],
            ]),
            // As the Customizer stores it: per integration position. Mailchimp's keys are its own merge tags.
            'custom_field_mappings' => wp_json_encode([0 => ['FNAME' => 'field_x'], 1 => [$key => 'field_abc']]),
        ]);

        // The old site has the Mailchimp connection registered; this one doesn't.
        $label = function ($c) { return $c + ['MailChimpConnect' => 'Mailchimp']; };
        add_filter('mailoptin_registered_connections', $label);
        $file = Exporter::build([$id]);
        $this->assertArrayNotHasKey('activate_optin', $file['campaigns'][0]['settings'], 'Whether it was live stays on the old site.');

        $export = $this->roundtrip([$id]);
        remove_filter('mailoptin_registered_connections', $label);

        // Now make this "another site": the guide is a new page at the same address; the rest is gone.
        wp_delete_post($guide, true);
        wp_delete_post($gone, true);
        $new_guide = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Planting guide', 'post_name' => 'planting-guide']);
        wp_delete_term($cat, 'category');
        $new_cat = self::factory()->category->create(['name' => 'Bulbs', 'slug' => 'bulbs']);
        $tag_name  = Tags::get($tag)['name'];
        Tags::delete($tag);
        Fields::delete($field);

        $review = Importer::review($export);
        $this->assertSame('check', $review[0]['status']);
        $issues = implode("\n", $review[0]['issues']);
        $this->assertStringContainsString('“Old pricing”', $issues);
        $this->assertStringContainsString('Creates the tag “' . $tag_name . '”', $issues);
        $this->assertStringContainsString('Creates the custom field “Company”', $issues);
        $this->assertStringContainsString('already exists', $issues);
        $this->assertStringContainsString("Sends subscribers to Mailchimp, which isn't connected here", $issues, "The service's name comes from the file.");

        $result = Importer::run($export, [$id], false);
        $this->assertCount(1, $result['imported']);
        $new = array_key_first($result['imported']);

        $this->assertSame('Spring guide (2)', $result['imported'][$new]);
        $saved = Settings::get_saved(Settings::TYPE_OPTIN, $new);
        $this->assertFalse(OCR::is_activated($new), 'Imported campaigns are drafts.');
        $this->assertSame([$new_guide], $saved['pages_never_load'], 'Found by its address; the missing page is left out.');
        $this->assertSame([$new_cat], $saved['post_categories_load'], 'Found by its slug.');
        $this->assertSame('#' . OCR::get_optin_campaign_uuid($new) . ' .x{color:red}', $saved['form_custom_css'], 'Custom CSS points at the new campaign.');

        $integration = json_decode($saved['integrations'], true)[1];
        $recreated   = Tags::get_by_name($tag_name);
        $this->assertNotEmpty($recreated);
        $this->assertSame([(int)$recreated['id']], $integration['MailOptinConnect_lead_tags']);
        $this->assertSame('Company', Fields::get_by_key($key)['label']);
        $this->assertSame([0 => ['FNAME' => 'field_x'], 1 => [$key => 'field_abc']], json_decode($saved['custom_field_mappings'], true), "Mailchimp's merge tags are left alone.");
        $this->assertSame((string)wp_parse_url(home_url(), PHP_URL_HOST), OptinCampaignMeta::get_campaign_meta($new, 'imported_from'));
    }

    public function test_an_ab_test_travels_whole_and_stays_linked()
    {
        $parent  = $this->campaign('Exit offer', ['headline' => 'A']);
        $variant = $this->campaign('Exit offer B', ['headline' => 'B']);
        OptinCampaignMeta::add_campaign_meta($variant, 'split_test_parent', $parent);

        // Exporting the variant alone still brings the whole test.
        $export = $this->roundtrip([$variant]);
        $this->assertCount(2, $export['campaigns']);

        $result = Importer::run($export, [$parent], false);
        $this->assertCount(2, $result['imported']);
        [$new_parent, $new_variant] = array_keys($result['imported']);

        $this->assertEquals($new_parent, OCR::get_split_parent_id($new_variant));
        $this->assertSame('B', Settings::get_saved(Settings::TYPE_OPTIN, $new_variant)['headline']);
    }

    public function test_files_that_are_not_exports_are_refused()
    {
        $this->assertWPError(Importer::parse(''));
        $this->assertWPError(Importer::parse('{"hello":"world"}'));
        $this->assertSame('mo_import_version', Importer::parse(wp_json_encode(['format' => Exporter::FORMAT, 'version' => 99, 'mailoptin' => '9.9', 'campaigns' => []]))->get_error_code());
        $this->assertSame('mo_import_none', Importer::parse(wp_json_encode(['format' => Exporter::FORMAT, 'version' => 1, 'campaigns' => [['id' => 1]]]))->get_error_code());
    }

    public function test_unknown_template_is_skipped_and_odd_settings_are_dropped()
    {
        $json = wp_json_encode([
            'format'    => Exporter::FORMAT,
            'version'   => 1,
            'campaigns' => [
                ['id' => 7, 'name' => 'Ghost', 'type' => 'lightbox', 'template' => 'NoSuchTemplate', 'settings' => ['headline' => 'x']],
                ['id' => 8, 'name' => 'Fine', 'type' => 'lightbox', 'template' => 'BareMetal', 'settings' => ['headline' => 'ok', 'bad key!' => 'x', 'nested' => ['a' => ['b' => 1]]]],
            ],
        ]);

        $export = Importer::parse($json);
        $this->assertArrayNotHasKey('bad key!', $export['campaigns'][8]['settings'], 'Only setting names the Customizer could write.');

        $review = Importer::review($export);
        $this->assertSame('skip', $review[0]['status']);

        $result = Importer::run($export, [7, 8], false);
        $this->assertSame(['Ghost'], $result['skipped']);
        $this->assertCount(1, $result['imported']);
        $this->assertSame(['a' => ['b' => 1]], Settings::get_saved(Settings::TYPE_OPTIN, array_key_first($result['imported']))['nested']);
    }

    public function test_entries_that_are_not_ids_are_kept_and_shapes_stay()
    {
        $this->assertSame([12, 'all'], \MailOptin\Core\OptinTransfer\References::reshape(['5', 'all'], [12]));
        $this->assertSame('12,all', \MailOptin\Core\OptinTransfer\References::reshape('5,all', [12]));
        $this->assertSame(12, \MailOptin\Core\OptinTransfer\References::reshape(5, [12]));
        $this->assertSame('', \MailOptin\Core\OptinTransfer\References::reshape('5', []));
    }

    public function test_form_image_never_points_at_an_unrelated_attachment()
    {
        $id = $this->campaign('Picture', ['headline' => 'x', 'form_image' => 4242]);
        $export = $this->roundtrip([$id]);
        // The file names the attachment's URL; on this "site" it can't be fetched.
        $export['attachments'][4242] = 'http://127.0.0.1:1/wp-content/uploads/flower.jpg';

        $review = Importer::review($export);
        $this->assertSame(1, $review[0]['images']);

        $off = Importer::run($export, [$id], false);
        $this->assertSame('', Settings::get_saved(Settings::TYPE_OPTIN, array_key_first($off['imported']))['form_image'], 'Not copied: the template default shows.');

        $failed = Importer::run($export, [$id], true);
        $this->assertSame('', Settings::get_saved(Settings::TYPE_OPTIN, array_key_first($failed['imported']))['form_image'], 'Copy failed: the template default shows.');
    }

    public function test_variant_css_naming_its_parent_follows_the_parent()
    {
        $parent = $this->campaign('Exit', ['headline' => 'A']);
        $p_uuid = OCR::get_optin_campaign_uuid($parent);
        $variant = $this->campaign('Exit B', ['form_custom_css' => "#$p_uuid .b{color:red}"]);
        OptinCampaignMeta::add_campaign_meta($variant, 'split_test_parent', $parent);

        $result = Importer::run($this->roundtrip([$parent]), [$parent], false);
        [$new_parent, $new_variant] = array_keys($result['imported']);

        $this->assertSame('#' . OCR::get_optin_campaign_uuid($new_parent) . ' .b{color:red}', Settings::get_saved(Settings::TYPE_OPTIN, $new_variant)['form_custom_css']);
    }

    public function test_risky_content_is_named_and_links_must_be_web_addresses()
    {
        $id = $this->campaign('Risky', ['success_js_script' => 'alert(1)', 'redirect_url_value' => 'javascript:alert(1)']);
        $export = $this->roundtrip([$id]);

        $issues = implode("\n", Importer::review($export)[0]['issues']);
        $this->assertStringContainsString('Runs its own JavaScript', $issues);
        $this->assertStringContainsString("isn't a web address", $issues);

        $new = array_key_first(Importer::run($export, [$id], false)['imported']);
        $this->assertSame('', Settings::get_saved(Settings::TYPE_OPTIN, $new)['redirect_url_value']);
    }

    public function test_versions_of_a_skipped_test_are_reported_not_dropped()
    {
        $json = wp_json_encode(['format' => Exporter::FORMAT, 'version' => 1, 'campaigns' => [
            ['id' => 1, 'name' => 'Ghost', 'type' => 'lightbox', 'template' => 'NoSuchTemplate', 'settings' => []],
            ['id' => 2, 'name' => 'Ghost B', 'type' => 'lightbox', 'template' => 'BareMetal', 'variant_of' => 1, 'settings' => []],
        ]]);
        $export = Importer::parse($json);

        $this->assertSame(['skip', 'skip'], array_column(Importer::review($export), 'status'));
        $this->assertSame(['Ghost', 'Ghost B'], Importer::run($export, [1], false)['skipped']);
    }

    public function test_a_numbered_name_gets_the_next_number()
    {
        $this->campaign('Promo', ['headline' => 'x']);
        $second = $this->campaign('Promo (2)', ['headline' => 'y']);

        $result = Importer::run($this->roundtrip([$second]), [$second], false);
        $this->assertSame(['Promo (3)'], array_values($result['imported']));
    }
}
