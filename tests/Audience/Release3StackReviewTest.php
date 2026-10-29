<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\Subscribers_Import_Page;
use MailOptin\Core\Audience\ImportConfirmations;
use MailOptin\Core\Audience\PersonalData;
use MailOptin\Core\Audience\SubscriberImport as Import;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Regression tests for defects found reviewing Release 3 as a whole.
 */
class Release3StackReviewTest extends SubscriberImportTest
{
    public function test_import_confirmations_do_not_use_up_the_form_signup_limit()
    {
        add_filter('mo_confirmation_email_hourly_limit', function () {
            return 2;
        });

        $job = $this->run_import("email\na@example.com\nb@example.com\nc@example.com\n", ['permission' => Import::PERMISSION_CONFIRM]);
        foreach (['a', 'b', 'c'] as $who) {
            ImportConfirmations::get_instance()->log_sent(['recipient_type' => ImportConfirmations::RECIPIENT_TYPE, 'recipient_id' => Subscribers::get_by_email("$who@example.com")['id']], $job['log_id']);
        }

        reset_phpmailer_instance();
        Subscription::subscribe('form@example.com', $this->news, ['ip_address' => '198.51.100.7']);

        $this->assertCount(1, tests_retrieve_phpmailer_instance()->get_sent() ? [1] : [], 'The form signup still gets its confirmation email.');
        $this->assertSame(Subscribers::EVENT_CONFIRMATION_SENT, Subscribers::get_events(Subscribers::get_by_email('form@example.com')['id'])[0]['type']);
    }

    public function test_erasing_personal_data_removes_the_address_from_import_reports()
    {
        $job = $this->run_import("email,last name\nsecret@example.com,Hidden\nSECRET@example.com,Hidden\nother@example.com,Kept\nnot-an-email,Kept\n");
        $this->assertSame(2, count(Import::get($job['id'])['problems']));

        PersonalData::erase('secret@example.com');

        $job = Import::get($job['id']);
        $this->assertSame(['not-an-email'], wp_list_pluck($job['problems'], 'email'));
        $file = file_get_contents(Import::problems_path($job));
        $this->assertStringNotContainsString('secret@example.com', strtolower($file));
        $this->assertStringNotContainsString('Hidden', $file);
        $this->assertStringContainsString('not-an-email', $file);
    }

    public function test_another_users_import_cannot_be_acted_on_by_a_non_admin()
    {
        $job = $this->job("email\na@example.com\n");

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $_REQUEST = ['import' => $job['id'], '_wpnonce' => wp_create_nonce('mo_delete_import_' . $job['id'])];

        $this->expectException(\WPDieException::class);
        try {
            Subscribers_Import_Page::handle_action('delete_import');
        } finally {
            $this->assertNotNull(Import::get($job['id']));
        }
    }

    public function test_the_timeline_says_where_an_import_came_from()
    {
        $this->run_import("email\na@example.com\n", ['tag_all' => 'batch-1']);

        $event = Subscribers::get_events(Subscribers::get_by_email('a@example.com')['id'])[0];
        $label = \MailOptin\Core\Admin\SettingsPage\Subscribers_Tags_Fields::event_label($event, json_decode($event['meta'], true));

        $this->assertSame('Imported from people.csv into Newsletter, tagged batch-1 by you', $label);
    }
}
