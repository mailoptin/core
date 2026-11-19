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
        $source = file_get_contents(MAILOPTIN_SRC . "EmailCampaigns/$ns/Templates/Lucid.php");

        // A fixed 500px image forced the new-post email to 570px wide on a 320px screen.
        $this->assertDoesNotMatchRegularExpression('/style="max-width:(500|<\?= \$width \?>)px/', $source, 'Inline image styles start with width:100%.');
        $this->assertDoesNotMatchRegularExpression('/^\s*width: 500px;/m', $source, 'Code blocks are not fixed at 500px.');
        $this->assertMatchesRegularExpression('/\.email-body img \{\s*max-width: 100%;/', $source);
    }
}
