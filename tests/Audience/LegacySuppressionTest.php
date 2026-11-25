<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\SuppressionRepository;
use WP_UnitTestCase;

class LegacySuppressionTest extends WP_UnitTestCase
{
    public function test_legacy_unsubscribers_are_copied_into_the_suppression_table()
    {
        update_option('mo_leadbank_unsubscribers', [base64_encode('Lena@Example.com'), base64_encode('lena@example.com'), 'not base64!', base64_encode('not-an-email')]);
        update_option('mo_wp_user_unsubscribers', [base64_encode('sam@example.com')]);

        DBUpdates::get_instance()->update_routine_18();

        $this->assertSame(SuppressionRepository::REASON_UNSUBSCRIBED, SuppressionRepository::get_reason('lena@example.com'));
        $this->assertSame(SuppressionRepository::REASON_UNSUBSCRIBED, SuppressionRepository::get_reason('sam@example.com'));
        $this->assertFalse(SuppressionRepository::is_suppressed('not-an-email'));

        global $wpdb;
        $this->assertSame('2', $wpdb->get_var('SELECT COUNT(*) FROM ' . SuppressionRepository::table()));
        $this->assertSame('mo_wp_user_unsubscribe', $wpdb->get_var($wpdb->prepare('SELECT source FROM ' . SuppressionRepository::table() . ' WHERE email_hash = %s', SuppressionRepository::hash('sam@example.com'))));

        $this->assertCount(4, get_option('mo_leadbank_unsubscribers'), 'The option is kept so a downgrade still honours it.');
    }

    public function test_existing_suppression_keeps_its_reason()
    {
        SuppressionRepository::add('bounce@example.com', SuppressionRepository::REASON_BOUNCED, 'test');
        update_option('mo_wp_user_unsubscribers', [base64_encode('bounce@example.com')]);

        DBUpdates::get_instance()->update_routine_18();

        $this->assertSame(SuppressionRepository::REASON_BOUNCED, SuppressionRepository::get_reason('bounce@example.com'));
    }

    public function test_runs_with_no_legacy_options()
    {
        delete_option('mo_leadbank_unsubscribers');
        delete_option('mo_wp_user_unsubscribers');

        DBUpdates::get_instance()->update_routine_18();

        global $wpdb;
        $this->assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM ' . SuppressionRepository::table()));
    }
}
