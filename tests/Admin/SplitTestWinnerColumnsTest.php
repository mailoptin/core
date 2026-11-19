<?php

namespace MailOptin\Tests\Core\Admin;

use MailOptin\Core\Admin\SettingsPage\OptinCampaign_List;
use MailOptin\Core\Repositories\OptinCampaignsRepository as Repository;

/**
 * Add-ons can add columns to the Optin Campaigns list and to the split test "Select a Winner" window
 * (Premium's product offers add Orders and Revenue).
 */
class SplitTestWinnerColumnsTest extends \WP_Ajax_UnitTestCase
{
    protected $made = [];

    public function tear_down()
    {
        foreach ($this->made as $id) Repository::delete_optin_campaign($id);
        Repository::flush_saved_customizer_data_cache();

        parent::tear_down();
    }

    public function test_the_list_takes_extra_columns()
    {
        add_filter('mailoptin_optin_campaign_list_columns', function ($c) { return $c + ['po_revenue' => 'Revenue']; });

        $columns = (new OptinCampaign_List(null))->get_columns();

        $this->assertSame('Revenue', $columns['po_revenue']);
        $this->assertArrayHasKey('percent', $columns, 'The existing columns stay');
    }

    public function test_the_winner_window_shows_extra_columns_and_a_note_escaped()
    {
        $this->_setRole('administrator');

        // Registered by mailoptin.php, which the core tests don't load.
        global $wpdb;
        $wpdb->optin_campaignmeta = $wpdb->prefix . 'mo_optin_campaignmeta';

        // admin_init would check WordPress.org for updates, which the tests can't reach.
        foreach (['_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes'] as $f) remove_action('admin_init', $f);

        $parent  = Repository::add_optin_campaign(wp_generate_password(10, false), 'Parent <b>x</b>', 'BareMetal', 'lightbox');
        $variant = (new \MailOptin\Core\Admin\SettingsPage\SplitTestOptinCampaign($parent, 'Variant', 'Note'))->forge();
        $this->made = [$parent, $variant];

        add_filter('mailoptin_split_test_winner_columns', function ($c) { return $c + ['po_revenue' => 'Revenue']; });
        add_filter('mailoptin_split_test_winner_column', function ($v, $key, $id) { return 'po_revenue' === $key ? '<strong>£' . $id . '</strong><script>x</script>' : $v; }, 10, 3);
        add_filter('mailoptin_split_test_winner_note', function () { return '<p class="note">More money</p>'; });

        $_POST['nonce']           = wp_create_nonce('mailoptin-admin-nonce');
        $_POST['parent_optin_id'] = $parent;

        try {
            $this->_handleAjax('mailoptin_end_optin_split_modal');
        } catch (\WPAjaxDieContinueException $e) {
        }

        $html = json_decode($this->_last_response, true)['data'];

        $this->assertStringContainsString('<th class="mo-end-test-th-header">Revenue</th>', $html);
        $this->assertStringContainsString('<strong>£' . $variant . '</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html, 'Column values are filtered for safe HTML');
        $this->assertStringContainsString('class="mo-end-test-tbody" data-parent-id="' . $parent . '" data-optin-id="' . $variant . '"', $html, 'Rows still pick the winner');
        $this->assertStringContainsString('Parent &lt;b&gt;x&lt;/b&gt;', $html, 'Names are escaped');
        $this->assertStringContainsString('More money', $html);
    }
}
