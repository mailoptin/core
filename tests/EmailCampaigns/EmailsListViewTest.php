<?php

namespace MailOptin\Tests\Core\EmailCampaigns;

use MailOptin\Core\Admin\SettingsPage\EmailCampaigns;
use WP_UnitTestCase;

/**
 * The Post Notifications list with its own tab name in the address (view=post-notifications) used to fail
 * with "Call to a member function prepare_items() on null".
 */
class EmailsListViewTest extends WP_UnitTestCase
{
    public function tear_down()
    {
        $_GET = [];
        parent::tear_down();
    }

    /**
     * @dataProvider views
     */
    public function test_the_list_table_is_ready($view, $ready)
    {
        set_current_screen('dashboard');
        $_GET = ['page' => MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG] + (null === $view ? [] : ['view' => $view]);

        $page     = EmailCampaigns::get_instance();
        $property = new \ReflectionProperty($page, 'email_campaigns_instance');
        $property->setAccessible(true);
        // The page object is shared: start each case without a list table.
        $property->setValue($page, null);

        $page->screen_option();

        $this->assertSame($ready, null !== $property->getValue($page));
    }

    public function views()
    {
        return [
            'no view'            => [null, true],
            'post-notifications' => ['post-notifications', true],
        ];
    }
}
