<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Audience\SubscriberImport as Import;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Importing into custom fields, a premium feature. Each test runs in its own process because the
 * premium constant can't be undefined afterwards.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SubscriberImportPremiumTest extends SubscriberImportTest
{
    public function set_up()
    {
        if ( ! defined('MAILOPTIN_DETACH_LIBSODIUM')) define('MAILOPTIN_DETACH_LIBSODIUM', true);

        parent::set_up();
    }

    public function test_custom_field_columns_are_not_imported_without_premium()
    {
        $this->assertTrue(Import::can_import_fields());
    }

    public function test_imports_custom_fields_and_notes_invalid_values()
    {
        Subscribers::create(['email' => 'old@example.com', 'status' => 'subscribed']);
        $company = Fields::create(['type' => 'text', 'label' => 'Company']);
        $orders  = Fields::create(['type' => 'number', 'label' => 'Orders placed', 'merge_key' => 'orders']);
        Fields::set_values(Subscribers::get_by_email('old@example.com')['id'], [$company => 'Kept Ltd']);

        $job = $this->job("Email,Company,orders\nnew@example.com,Acme,seven\nold@example.com,Spoofed,3\n");
        $this->assertSame([0 => 'email', 1 => 'field:' . $company, 2 => 'field:' . $orders], $job['mapping']);

        Import::start($job['id'], ['mapping' => $job['mapping'], 'lists' => [$this->news], 'permission' => 'subscribed']);
        $job = Import::run_batch($job['id']);

        $this->assertSame(['company' => 'Acme', 'orders' => ''], Fields::merge_values(Subscribers::get_by_email('new@example.com')['id']));
        $this->assertSame(['company' => 'Kept Ltd', 'orders' => '3'], Fields::merge_values(Subscribers::get_by_email('old@example.com')['id']));
        $this->assertSame(1, $job['counts'][Import::PROBLEM_NOTE]);
        $this->assertStringContainsString('Orders placed must be a number', $job['problems'][0]['message']);
        $this->assertSame(2, $job['counts']['added'] + $job['counts']['updated']);
    }
}
