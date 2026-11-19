<?php

namespace MailOptin\Tests\Core\Admin\Customizer\OptinForm;

use MailOptin\Core\Admin\Customizer\OptinForm\CustomizerSettings;
use MailOptin\Core\OptinForms\PageTargetingRuleTrait;
use WP_UnitTestCase;

class PageTargetingRuleTraitTestDouble
{
    use PageTargetingRuleTrait;
}

class CustomizerSettingsTest extends WP_UnitTestCase
{
    public function testNonListStructuredValuesAreEncodedAndMultiSelectValuesArePreserved()
    {
        $settings = [
            12 => [
                'headline'             => ['unexpected' => 'array'],
                'integrations'         => (object)['provider' => 'mailchimp'],
                'form_width'           => 500,
                'posts_never_load'     => [12, 15],
                'post_tags_load'       => [],
                'post_categories_hide' => '[]',
                'post_tags_hide'       => '["1432"]',
            ],
            13 => [
                'headline' => ['leave' => 'untouched'],
            ],
        ];

        $normalized = CustomizerSettings::normalize_campaign_customizer_values($settings, 12);

        $this->assertSame(wp_json_encode($settings[12]['headline']), $normalized[12]['headline']);
        $this->assertSame(wp_json_encode($settings[12]['integrations']), $normalized[12]['integrations']);
        $this->assertSame(500, $normalized[12]['form_width']);
        $this->assertSame([12, 15], $normalized[12]['posts_never_load']);
        $this->assertSame([], $normalized[12]['post_tags_load']);
        $this->assertSame([], $normalized[12]['post_categories_hide']);
        $this->assertSame(['1432'], $normalized[12]['post_tags_hide']);
        $this->assertSame($settings[13], $normalized[13]);
    }

    public function testObjectCampaignSettingsAreNormalized()
    {
        $settings = [
            12 => (object)[
                'headline' => (object)['unexpected' => 'object'],
            ],
        ];

        $normalized = CustomizerSettings::normalize_campaign_customizer_values($settings, 12);

        $this->assertSame(wp_json_encode($settings[12]->headline), $normalized[12]['headline']);
    }

    public function testInvalidOptionShapeFallsBackToAnEmptySettingsArray()
    {
        // Only the campaign being customized is reset; the option is shared by every campaign.
        $this->assertSame([12 => []], CustomizerSettings::normalize_campaign_customizer_values((object)['12' => 'invalid'], 12));
        $this->assertSame([12 => [], 13 => ['headline' => 'kept']], CustomizerSettings::normalize_campaign_customizer_values(['12' => 'invalid', '13' => ['headline' => 'kept']], 12));
        $this->assertSame([], CustomizerSettings::normalize_campaign_customizer_values('invalid', 12));
    }

    public function testLegacyJsonTargetingListDoesNotBreakTheFrontEnd()
    {
        $post_id = self::factory()->post->create();

        $id = \MailOptin\Core\Repositories\OptinCampaignsRepository::add_optin_campaign(wp_generate_password(10, false), 'Targeting ' . wp_generate_password(6, false), 'BareMetal', 'inpost');
        $settings      = \MailOptin\Core\Repositories\OptinCampaignsRepository::get_settings();
        // How the old Customizer normalizer saved a multi-select list.
        $settings[$id] = ['posts_never_load' => wp_json_encode([(string)$post_id]), 'post_tags_hide' => '["1432"]'];
        \MailOptin\Core\Repositories\OptinCampaignsRepository::updateSettings($settings);

        $this->go_to(get_permalink($post_id));

        $this->assertFalse((new PageTargetingRuleTraitTestDouble())->page_level_targeting_rule_checker($id), 'The post is excluded, and checking it is not a fatal error.');
    }

    public function testPageTargetingListsDecodeLegacyJsonAndRejectInvalidValues()
    {
        $this->assertSame(['2794', '62'], PageTargetingRuleTraitTestDouble::normalize_page_targeting_list('["2794","62"]'));
        $this->assertSame([], PageTargetingRuleTraitTestDouble::normalize_page_targeting_list('[]'));
        $this->assertSame([8152, 2794], PageTargetingRuleTraitTestDouble::normalize_page_targeting_list([8152, 2794]));
        $this->assertSame([], PageTargetingRuleTraitTestDouble::normalize_page_targeting_list('not-json'));
        $this->assertSame([], PageTargetingRuleTraitTestDouble::normalize_page_targeting_list((object)['unexpected' => new \stdClass()]));
    }
}