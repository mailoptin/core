<?php

namespace MailOptin\Core\Tests\Design;

use MailOptin\Core\Admin\SettingsPage\AdvanceAnalytics;
use MailOptin\Core\Admin\SettingsPage\LeadBank;
use MailOptin\Core\Admin\UI;

class LockedPagesTest extends \WP_UnitTestCase
{
    public function test_locked_feature_escapes_and_links()
    {
        $html = UI::locked_feature([
            'title'     => 'Segments <script>',
            'intro'     => 'Intro & more',
            'bullets'   => ['One', '<b>Two</b>'],
            'cta_url'   => 'https://mailoptin.io/pricing/?a=1&b=2',
            'learn_url' => 'https://mailoptin.io/docs/',
        ]);

        $this->assertStringContainsString('<h2>Segments &lt;script&gt;</h2>', $html);
        $this->assertStringContainsString('<li>&lt;b&gt;Two&lt;/b&gt;</li>', $html);
        $this->assertStringContainsString('href="https://mailoptin.io/pricing/?a=1&#038;b=2"', $html);
        $this->assertStringContainsString('Learn more', $html);
        $this->assertStringContainsString('mo-ui-locked-solo', $html, 'No preview: single column.');
        $this->assertStringContainsString('stays free', $html);

        $this->assertStringNotContainsString('stays free', UI::locked_feature(['title' => 'x', 'footnote' => '']));
        $this->assertStringNotContainsString('mo-ui-btn mo-ui-ghost', UI::locked_feature(['title' => 'x']), 'No docs link without a URL.');
    }

    public function test_preview_rows_escape_cells_and_render_badges()
    {
        $html = UI::preview_rows([['<i>a</i>', ['badge' => 'Sent', 'tone' => 'ok']]]);

        $this->assertStringContainsString('<td>&lt;i&gt;a&lt;/i&gt;</td>', $html);
        $this->assertStringContainsString('<span class="mo-ui-badge mo-ui-ok">Sent</span>', $html);
    }

    public function test_submissions_and_statistics_upsells_use_the_locked_card_not_the_blurred_screenshot()
    {
        foreach ([[LeadBank::get_instance(), 'mo_leads'], [AdvanceAnalytics::get_instance(), 'mo_analytics']] as [$page, $option]) {
            $html = $page->upsell_settings_page('original', $option);

            $this->assertStringContainsString('mo-ui-locked', $html);
            $this->assertStringContainsString('See Premium plans', $html);
            $this->assertStringContainsString('mo-ui-preview', $html);
            $this->assertStringNotContainsString('mo-settings-page-disabled', $html);
            $this->assertStringNotContainsString('<img', $html);

            $this->assertSame('original', $page->upsell_settings_page('original', 'some_other_page'), 'Other pages are left alone.');
        }
    }
}
