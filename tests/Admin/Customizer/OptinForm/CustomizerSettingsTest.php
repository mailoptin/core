<?php

namespace MailOptin\Tests\Core\Admin\Customizer\OptinForm;

use MailOptin\Core\Admin\Customizer\OptinForm\CustomizerSettings;
use WP_UnitTestCase;

class CustomizerSettingsTest extends WP_UnitTestCase
{
    public function testNonScalarValuesAreEncodedForCustomizerControls()
    {
        $settings = [
            12 => [
                'headline'     => ['unexpected' => 'array'],
                'integrations' => (object)['provider' => 'mailchimp'],
                'form_width'   => 500,
            ],
            13 => [
                'headline' => ['leave' => 'untouched'],
            ],
        ];

        $normalized = CustomizerSettings::normalize_campaign_customizer_values($settings, 12);

        $this->assertSame(wp_json_encode($settings[12]['headline']), $normalized[12]['headline']);
        $this->assertSame(wp_json_encode($settings[12]['integrations']), $normalized[12]['integrations']);
        $this->assertSame(500, $normalized[12]['form_width']);
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
}