<?php

namespace MailOptin\Tests\Core\Design;

use MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_Tagit_Control;
use WP_UnitTestCase;

/**
 * The tags box (Referrers under Referrer Detection) on Tagify instead of tag-it.
 */
class TagifyControlTest extends WP_UnitTestCase
{
    protected function control($value, $options = [])
    {
        require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
        $manager = new \WP_Customize_Manager();
        $manager->add_setting('mo_test_referrers', ['type' => 'mo_test_setting', 'default' => $value]);

        return new WP_Customize_Tagit_Control($manager, 'mo_test_referrers', [
            'settings' => 'mo_test_referrers',
            'section'  => 'x',
            'label'    => 'Referrers',
            'field_id' => 'mo-referrer-detection',
            'options'  => $options,
        ]);
    }

    protected function html($control)
    {
        ob_start();
        $control->render_content();

        return ob_get_clean();
    }

    public function test_saved_tags_and_options_reach_tagify()
    {
        $html = $this->html($this->control(['facebook.com', 'google.com'], [
            'placeholderText'         => 'Type referrer address',
            'availableTags'           => ['facebook.com', 'twitter.com'],
            'singleField'             => true,
            'showAutocompleteOnFocus' => true,
        ]));

        $this->assertStringContainsString('class="mo-tagify" id="mo-referrer-detection"', $html);
        $this->assertStringContainsString('value="facebook.com,google.com"', $html);

        preg_match('/data-tagify-options="([^"]*)"/', $html, $m);
        $this->assertSame(
            ['placeholder' => 'Type referrer address', 'whitelist' => ['facebook.com', 'twitter.com'], 'onFocus' => true],
            json_decode(html_entity_decode($m[1], ENT_QUOTES), true)
        );
    }

    public function test_saved_tags_are_escaped()
    {
        // tag-it printed saved tags into <li> as they were.
        $html = $this->html($this->control(['<script>alert(1)</script>', 'bing.com']));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;,bing.com"', $html);
    }

    public function test_tags_from_any_saved_shape()
    {
        $this->assertSame(['a.com', 'b.com'], $this->control(' a.com , ,b.com ')->tags());
        $this->assertSame(['a.com'], $this->control(['a.com', '', ['x']])->tags());
        $this->assertSame([], $this->control('')->tags());
    }

    public function test_the_tagify_files_are_bundled()
    {
        foreach (['tagify.min.js', 'tagify.css', 'tagify-init.js'] as $file) {
            $this->assertFileExists(MAILOPTIN_ASSETS_DIR . 'js/customizer-controls/tagify/' . $file);
        }
    }
}
