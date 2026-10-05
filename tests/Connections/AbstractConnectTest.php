<?php

namespace MailOptin\Tests\Core\Repositories;

use MailOptin\Core\Connections\AbstractConnect;
use WP_UnitTestCase;

class AbstractConnectTest extends WP_UnitTestCase
{
    public $instance;
    public $mail_filter;
    public $disable_email_filter;
    public $notification_key;

    public function setUp()
    {
        parent::setUp();
    }

    public function tearDown()
    {
        if ( ! empty($this->mail_filter)) {
            remove_filter('pre_wp_mail', $this->mail_filter);
        }

        if ( ! empty($this->disable_email_filter)) {
            remove_filter('mailoptin_disable_send_optin_error_email', $this->disable_email_filter);
        }

        if ( ! empty($this->notification_key)) {
            delete_transient($this->notification_key);
        }

        $this->unlink(MAILOPTIN_OPTIN_ERROR_LOG.'mailchimp.log');
        parent::tearDown();
    }


    public function testSaveOptinErrorLog()
    {
        AbstractConnect::save_optin_error_log('hello', 'mailchimp');
        AbstractConnect::save_optin_error_log('hi', 'mailchimp');

        $content = file_get_contents(MAILOPTIN_OPTIN_ERROR_LOG.'mailchimp.log');

        $this->assertSame('hello'. "\r\n" . 'hi'. "\r\n", $content);
    }

    public function testSendOptinErrorEmailThrottlesRepeatedNotifications()
    {
        $error_message          = 'Unit test optin error ' . microtime(true);
        $this->notification_key = 'mailoptin_optin_error_email_' . md5('0||' . $error_message);
        $sent_emails            = [];

        $this->disable_email_filter = function () {
            return false;
        };

        $this->mail_filter = function ($pre_wp_mail, $atts) use (&$sent_emails) {
            $sent_emails[] = $atts;

            return true;
        };

        add_filter('mailoptin_disable_send_optin_error_email', $this->disable_email_filter);
        add_filter('pre_wp_mail', $this->mail_filter, 10, 2);

        AbstractConnect::send_optin_error_email(0, $error_message);
        AbstractConnect::send_optin_error_email(0, $error_message);

        $this->assertCount(1, $sent_emails);
        $this->assertNotFalse(strpos($sent_emails[0]['subject'], 'Unknown optin campaign'));
    }
}