<?php

namespace MailOptin\Tests\Core\EmailCampaigns;

use MailOptin\Core\AjaxHandler;
use MailOptin\Core\EmailCampaigns\NewPublishPost\NewPublishPost;
use MailOptin\Core\Repositories\CampaignSettingsRepository;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

/**
 * The list switches need a nonce, post notifications that can never send are flagged, and the
 * customizer doesn't offer WooCommerce product attributes as post filters.
 */
class SmallFixesTest extends WP_Ajax_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\DBUpdates::get_instance()->update_routine_7();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function tear_down()
    {
        CampaignSettingsRepository::delete_all(CampaignSettingsRepository::TYPE_EMAIL);
        $_POST = $_GET = $_REQUEST = [];

        parent::tear_down();
    }

    protected function ajax($action)
    {
        try {
            $this->_handleAjax($action);
        } catch (WPAjaxDieContinueException $e) {
        } catch (WPAjaxDieStopException $e) {
        }
    }

    public function test_the_automation_switch_needs_the_nonce()
    {
        $id = ER::add_email_campaign('New post alert', ER::NEW_PUBLISH_POST, 'Lucid');

        // A forged request: no nonce.
        $_POST = ['id' => $id, 'status' => 'true'];
        $this->ajax('mailoptin_toggle_automation_activated');
        $this->assertFalse(ER::is_campaign_active($id));

        $_POST = ['id' => $id, 'status' => 'true', 'nonce' => wp_create_nonce('mailoptin-admin-nonce')];
        $this->ajax('mailoptin_toggle_automation_activated');
        $this->assertTrue(ER::is_campaign_active($id));
    }

    public function test_the_optin_switch_needs_the_nonce()
    {
        $id = OptinCampaignsRepository::add_optin_campaign('abcdefghij', 'Sidebar form', 'BareMetal', 'sidebar');
        OptinCampaignsRepository::deactivate_campaign($id);

        $_POST = ['id' => $id, 'status' => 'true'];
        $this->ajax('mailoptin_toggle_optin_activated');
        $this->assertFalse(OptinCampaignsRepository::is_activated($id));

        $_POST = ['id' => $id, 'status' => 'true', 'nonce' => wp_create_nonce('mailoptin-admin-nonce')];
        $this->ajax('mailoptin_toggle_optin_activated');
        $this->assertTrue(OptinCampaignsRepository::is_activated($id));
    }

    public function test_a_post_notification_without_a_delay_never_sends()
    {
        $id = ER::add_email_campaign('New post alert', ER::NEW_PUBLISH_POST, 'Lucid');

        // The customizer's defaults: "Send Immediately" off, no delay.
        $this->assertTrue(NewPublishPost::never_sends($id));

        ER::update_settings_by_id($id, ['schedule_digit' => '2']);
        $this->assertFalse(NewPublishPost::never_sends($id));

        ER::update_settings_by_id($id, ['schedule_digit' => '', 'send_immediately' => true]);
        $this->assertFalse(NewPublishPost::never_sends($id));
    }

    public function test_the_list_flags_active_post_notifications_that_never_send()
    {
        $broken = ER::add_email_campaign('Broken alert', ER::NEW_PUBLISH_POST, 'Lucid');
        $paused = ER::add_email_campaign('Paused alert', ER::NEW_PUBLISH_POST, 'Lucid');
        $fine   = ER::add_email_campaign('Working alert', ER::NEW_PUBLISH_POST, 'Lucid');

        ER::activate_email_campaign($broken);
        ER::activate_email_campaign($fine);
        ER::update_settings_by_id($fine, ['send_immediately' => true]);

        ob_start();
        \MailOptin\Core\Admin\SettingsPage\EmailCampaigns::get_instance()->never_sends_notice();
        $html = ob_get_clean();

        $this->assertStringContainsString('Broken alert', $html);
        $this->assertStringNotContainsString('Paused alert', $html);
        $this->assertStringNotContainsString('Working alert', $html);

        ER::update_settings_by_id($broken, ['schedule_digit' => '1']);

        ob_start();
        \MailOptin\Core\Admin\SettingsPage\EmailCampaigns::get_instance()->never_sends_notice();
        $this->assertSame('', ob_get_clean());
    }

    public function test_new_post_notifications_start_with_a_delay()
    {
        $_POST = ['title' => 'Fresh alert', 'template' => 'Lucid', 'type' => ER::NEW_PUBLISH_POST, 'nonce' => wp_create_nonce('mailoptin-admin-nonce')];
        $this->ajax('mailoptin_create_email_campaign');

        global $wpdb;
        $id = (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . \MailOptin\Core\Core::email_campaigns_table_name . ' WHERE name = %s', 'Fresh alert'));

        $this->assertGreaterThan(0, $id);
        $this->assertSame('1', ER::get_merged_customizer_value($id, 'schedule_digit'));
        $this->assertSame('hours', ER::get_merged_customizer_value($id, 'schedule_type'));
        $this->assertFalse(NewPublishPost::never_sends($id));
    }

    public function test_product_attributes_are_not_offered_unless_one_is_already_chosen()
    {
        if ( ! class_exists('WooCommerce')) eval('class WooCommerce {}');

        register_post_type('product', ['label' => 'Products']);
        register_taxonomy('product_cat', 'product', ['label' => 'Product categories']);
        register_taxonomy('pa_material', 'product', ['label' => 'Material']);
        register_taxonomy('pa_finish', 'product', ['label' => 'Finish']);

        $control = new class {
            use \MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_EA_CPT_Control_Trait;

            public $shown = [];

            public function select_markup($saved_value, $name_attr, $choices, $label)
            {
                $this->shown[] = $name_attr;
            }
        };

        $control->render_fields('product', []);
        $this->assertSame(['product_cat'], $control->shown);

        // A filter saved before stays visible, so it can be seen and removed.
        $control->shown = [];
        $control->render_fields('product', ['pa_finish' => ['5']]);
        $this->assertSame(['product_cat', 'pa_finish'], $control->shown);

        unregister_post_type('product');
        foreach (['product_cat', 'pa_material', 'pa_finish'] as $taxonomy) unregister_taxonomy($taxonomy);
    }
}
