<?php

namespace MailOptin\Core\Tests\Design;

use MailOptin\Core\Admin\SettingsPage\OptinCampaign_List;
use MailOptin\Core\Repositories\OptinCampaignsRepository as Repository;

/**
 * The Optin Campaigns list after the Refactoring UI pass: plain columns, the campaign's details under its name,
 * every action as a row link (Delete last), and counts on the type tabs that match what each tab lists.
 */
class OptinCampaignListTest extends \WP_UnitTestCase
{
    protected $made = [];

    public function set_up()
    {
        parent::set_up();
        if ( ! defined('MAILOPTIN_VERSION_NUMBER')) define('MAILOPTIN_VERSION_NUMBER', 'tests');
        global $wpdb;
        $wpdb->optin_campaignmeta = $wpdb->prefix . \MailOptin\Core\Core::optin_campaign_meta_table_name;
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function tear_down()
    {
        foreach ($this->made as $id) Repository::delete_optin_campaign($id);
        parent::tear_down();
    }

    protected function campaign($name, $class = 'BareMetal', $type = 'lightbox')
    {
        $id           = Repository::add_optin_campaign('L' . wp_generate_password(9, false), $name, $class, $type);
        $this->made[] = $id;

        return $id;
    }

    protected function table()
    {
        global $wpdb;
        set_current_screen('toplevel_page_mailoptin-optin-campaigns');

        return new OptinCampaign_List($wpdb);
    }

    public function test_plain_columns_with_the_old_keys()
    {
        $columns = $this->table()->get_columns();

        $this->assertSame(['cb', 'name', 'activated', 'impression', 'conversion', 'percent'], array_keys($columns));
        $this->assertSame(['Campaign', 'Status', 'Views', 'Subscribers', 'Rate'], array_values(array_slice($columns, 1)));
    }

    public function test_name_cell_has_details_and_every_action_as_a_link()
    {
        $id   = $this->campaign('<b>Spring</b> & co', 'BareMetal', 'lightbox');
        $item = Repository::get_optin_campaign_by_id($id);

        add_filter('mo_optin_popover_actions', $extra = function ($actions) {
            $actions['addon'] = ['href' => 'https://example.com/report', 'label' => 'Report'];

            return $actions;
        });
        $html = $this->table()->column_name($item);
        remove_filter('mo_optin_popover_actions', $extra);

        $this->assertStringContainsString('&lt;b&gt;Spring&lt;/b&gt; &amp; co', $html, 'The name is escaped.');
        $this->assertStringNotContainsString('<b>Spring', $html);
        $this->assertStringContainsString('Pop-up · BareMetal · ID ' . $item['uuid'], $html);

        preg_match_all('/<span class=\'([a-z_]+)\'>/', $html, $m);
        $this->assertSame('edit', $m[1][0], 'Edit comes first.');
        $this->assertSame('delete', end($m[1]), 'Delete comes last.');
        $this->assertContains('addon', $m[1], 'Actions added by add-ons still show.');
        $this->assertContains('clone', $m[1]);
        $this->assertStringContainsString('class="mo-delete-prompt"', $html, 'Delete still asks first.');
    }

    public function test_type_counts_match_what_each_tab_lists()
    {
        $table  = $this->table();
        $before = $table->type_counts();

        $this->campaign('A', 'BareMetal', 'lightbox');
        $this->campaign('B', 'BareMetal', 'lightbox');
        $this->campaign('C', 'Columbine', 'inpost');
        $this->campaign('Premium only', 'NoSuchPremiumTemplate', 'lightbox');

        $after = $table->type_counts();

        if (defined('MAILOPTIN_DETACH_LIBSODIUM')) {
            $this->assertSame(($before['lightbox'] ?? 0) + 3, $after['lightbox']);
        } else {
            // On Lite, a campaign on a Premium template isn't listed, so it isn't counted either.
            $this->assertSame(($before['lightbox'] ?? 0) + 2, $after['lightbox']);
            $this->assertSame($before[''] + 3, $after['']);
        }
        $this->assertSame(($before['inpost'] ?? 0) + 1, $after['inpost']);

        // The list's total and pages use the same count.
        $_GET['page']       = MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_SLUG;
        $_GET['optin-type'] = 'lightbox';
        $table->prepare_items();
        unset($_GET['optin-type'], $_GET['page']);
        $this->assertSame($after['lightbox'], (int)$table->get_pagination_arg('total_items'));
    }

    public function test_split_test_row_spans_every_column()
    {
        $table = $this->table();
        $id    = $this->campaign('Parent');
        \MailOptin\Core\Repositories\OptinCampaignMeta::add_campaign_meta($this->campaign('Variant'), 'split_test_parent', $id);

        ob_start();
        $table->split_test_actions_row($id);
        $html = ob_get_clean();

        $this->assertStringContainsString('class="colspanchange" colspan="' . ($table->get_column_count() - 1) . '"', $html, 'WordPress widens it when columns are shown or hidden.');
        $this->assertSame(2, substr_count($html, '<td'), 'The checkbox cell, then one cell for the buttons.');
    }
}
