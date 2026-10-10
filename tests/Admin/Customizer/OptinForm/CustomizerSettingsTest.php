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
        $this->assertSame([], CustomizerSettings::normalize_campaign_customizer_values((object)['12' => 'invalid'], 12));
        $this->assertSame([], CustomizerSettings::normalize_campaign_customizer_values('invalid', 12));
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