<?php

namespace MailOptin\Core\Tests\Design;

use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * The Lucid email template (new post, broadcast and digest) after the Refactoring UI pass: images shrink to
 * fit a phone, no text is under 12px, and the default colours meet 4.5:1 (3:1 for the large site name).
 */
class EmailTemplatesTest extends \WP_UnitTestCase
{
    public function lucid_types()
    {
        return [
            'new post'  => [ER::NEW_PUBLISH_POST, 'NewPublishPost'],
            'broadcast' => [ER::NEWSLETTER, 'Newsletter'],
            'digest'    => [ER::POSTS_EMAIL_DIGEST, 'PostsEmailDigest'],
        ];
    }

    protected function forge($type, $ns)
    {
        $post = self::factory()->post->create_and_get(['post_title' => 'Spring planting', 'post_content' => 'Bulbs go in now.']);
        $id   = ER::add_email_campaign('Lucid ' . $type . wp_generate_password(6, false), $type, 'Lucid');
        $cls  = "\\MailOptin\\Core\\EmailCampaigns\\$ns\\Templatify";

        if ($type === ER::NEW_PUBLISH_POST) return (new $cls($id, $post))->forge();
        if ($type === ER::POSTS_EMAIL_DIGEST) return (new $cls($id, [$post]))->forge();

        return (new $cls($id))->forge();
    }

    /**
     * @dataProvider lucid_types
     */
    public function test_lucid_defaults_are_readable($type, $ns)
    {
        $html = $this->forge($type, $ns);

        foreach (['#bbbfc3', '#aeaeae', '#74787e', '#dc4d2f'] as $old) {
            $this->assertStringNotContainsStringIgnoringCase($old, $html, "$old fails contrast on Lucid's grey or white.");
        }
        $this->assertStringContainsStringIgnoringCase('#6d7076', $html);
        $this->assertDoesNotMatchRegularExpression('/font-size:\s*(10|11)px/', $html, 'No text under 12px.');
    }

    /**
     * @dataProvider lucid_types
     */
    public function test_lucid_images_shrink_on_phones($type, $ns)
    {
        $html = $this->forge($type, $ns);

        // Media queries are ignored by some clients, so what fits a phone has to be in the inlined styles.
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $images = (new \DOMXPath($dom))->query('//img[contains(@class,"mo-imgix")]');
        if ($type !== ER::NEWSLETTER) {
            $this->assertGreaterThan(0, $images->length, 'The post image is in the email.');
        }

        foreach ($images as $img) {
            $style = $img->getAttribute('style');
            $this->assertMatchesRegularExpression('/(^|;)\s*max-width:\s*100%/', $style, "A post image can shrink below its column: $style");
            $this->assertDoesNotMatchRegularExpression('/(^|;)\s*width:\s*\d+px/', $style, "No fixed pixel width in the inlined style: $style");
            if ($type === ER::NEW_PUBLISH_POST) {
                $this->assertDoesNotMatchRegularExpression('/(^|;)\s*width:\s*100%/', $style, 'Small images keep their own size.');
            }
            $this->assertNotSame('', $img->getAttribute('width'), 'Outlook sizes images by the width attribute.');
        }

        // The content cell carries width:570px inline; on phones the media query lets it shrink.
        if ($type !== ER::NEWSLETTER) {
            $this->assertMatchesRegularExpression('/@media only screen and \(max-width: ?600px\)\s*\{[^{]*\.content-cell\s*\{\s*width:\s*100% !important;/', $html);
        }

        $this->assertDoesNotMatchRegularExpression('/(^|[;"\s])width:\s*500px/', $html, 'Nothing is fixed at 500px.');
    }
}
