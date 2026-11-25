<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Audience\ImportConfirmations;
use MailOptin\Core\Audience\SubscriberExport;
use MailOptin\Core\Audience\SubscriberImport as Import;
use MailOptin\Core\Repositories\SendQueueRepository as Queue;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Regression tests for defects found reviewing the CSV import.
 */
class SubscriberImportReviewTest extends SubscriberImportTest
{
    protected function rows($count, $extra = [])
    {
        $csv = "email\n";
        for ($i = 1; $i <= $count; $i++) $csv .= ($extra[$i] ?? "row$i@example.com") . "\n";

        return $csv;
    }

    protected function start_job($csv, $permission = Import::PERMISSION_SUBSCRIBED)
    {
        $job = $this->job($csv);
        Import::start($job['id'], ['mapping' => $job['mapping'], 'lists' => [$this->news], 'permission' => $permission]);

        return $job;
    }

    protected function finish($id)
    {
        do {
            $job = Import::run_batch($id);
        } while (Import::STATUS_RUNNING === $job['status']);

        return $job;
    }

    public function test_a_batch_that_dies_resumes_without_losing_counts_or_confirmations()
    {
        $job = $this->start_job($this->rows(30, [26 => 'not-an-email']), Import::PERMISSION_CONFIRM);

        $crash = function ($query) {
            if (false !== strpos($query, 'row28@example.com') && 0 === stripos(ltrim($query), 'INSERT')) throw new \RuntimeException('request died');

            return $query;
        };
        add_filter('query', $crash);

        try {
            Import::run_batch($job['id']);
            $this->fail('The batch should have died.');
        } catch (\RuntimeException $e) {
        }

        remove_filter('query', $crash);

        $job = $this->finish($job['id']);

        $this->assertSame(Import::STATUS_DONE, $job['status']);
        $this->assertSame(29, $job['counts']['added']);
        $this->assertSame(0, $job['counts'][Import::PROBLEM_REPEATED]);
        $this->assertSame(1, $job['counts'][Import::PROBLEM_INVALID]);
        $this->assertSame(29, array_sum(Queue::counts($job['log_id'])), 'Everyone gets a confirmation email.');

        $lines = file(Import::problems_path($job), FILE_IGNORE_NEW_LINES);
        $this->assertCount(2, $lines, 'The problem row is in the file once.');
    }

    public function test_stopping_an_import_during_a_batch_sticks()
    {
        $job = $this->start_job($this->rows(3));
        $id  = $job['id'];

        $cancel = function ($query) use ($id) {
            static $done = false;
            if ( ! $done && false !== strpos($query, 'row2@example.com') && 0 === stripos(ltrim($query), 'INSERT')) {
                $done = true;
                Import::cancel($id);
            }

            return $query;
        };
        add_filter('query', $cancel);
        $job = Import::run_batch($id);
        remove_filter('query', $cancel);

        $this->assertSame(Import::STATUS_CANCELLED, $job['status']);
        $this->assertSame(Import::STATUS_CANCELLED, Import::get($id)['status']);
    }

    public function test_the_lock_admits_one_batch_and_expired_locks_are_taken_over()
    {
        global $wpdb;

        $job  = $this->start_job($this->rows(2));
        $name = Import::OPTION_PREFIX . $job['id'] . '_lock';

        $wpdb->insert($wpdb->options, ['option_name' => $name, 'option_value' => (time() + 60) . '|someone-else', 'autoload' => 'no']);
        $this->assertSame(0, Import::run_batch($job['id'])['counts']['added']);

        $wpdb->update($wpdb->options, ['option_value' => (time() - 1) . '|someone-else'], ['option_name' => $name]);
        $this->assertSame(Import::STATUS_DONE, Import::run_batch($job['id'])['status']);
        $this->assertNull($wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", $name)));
    }

    public function test_cron_schedules_the_next_batch_first_and_the_watchdog_restarts_stalled_imports()
    {
        $job = $this->start_job($this->rows(2));
        wp_clear_scheduled_hook(Import::CRON_HOOK, [$job['id']]);

        Import::watchdog();
        $this->assertNotFalse(wp_next_scheduled(Import::CRON_HOOK, [$job['id']]));

        $die = function ($query) {
            if (false !== strpos($query, 'row1@example.com') && 0 === stripos(ltrim($query), 'INSERT')) throw new \RuntimeException('died');

            return $query;
        };
        wp_clear_scheduled_hook(Import::CRON_HOOK, [$job['id']]);
        add_filter('query', $die);
        try {
            Import::run_from_cron($job['id']);
        } catch (\RuntimeException $e) {
        }
        remove_filter('query', $die);

        $this->assertNotFalse(wp_next_scheduled(Import::CRON_HOOK, [$job['id']]), 'Scheduled before the batch ran.');
    }

    public function test_windows_1252_utf16_and_mac_line_endings_are_read()
    {
        $job = $this->run_import("email,first name,last name\rjose@example.com,Jos\xE9,Garc\xEDa\r");
        $this->assertSame(['José', 'García'], [Subscribers::get_by_email('jose@example.com')['first_name'], Subscribers::get_by_email('jose@example.com')['last_name']]);
        $this->assertSame(1, $job['counts']['added']);

        $this->run_import("\xFF\xFE" . mb_convert_encoding("email\tname\r\nzoe@example.com\tZoë Park\r\n", 'UTF-16LE', 'UTF-8'), [], 'unicode.txt');
        $this->assertSame('Zoë', Subscribers::get_by_email('zoe@example.com')['first_name']);
    }

    public function test_delimiter_ignores_quoted_commas()
    {
        $this->assertSame(';', Import::detect_delimiter("\"Email\";\"Name, full\";\"Notes, misc\"\na@example.com;\"A, B\";x\n"));
        $this->assertSame(',', Import::detect_delimiter("email,name\na@example.com,A\n"));
        $this->assertSame("\t", Import::detect_delimiter("email\tname\n"));
    }

    public function test_an_unclosed_quote_is_reported()
    {
        $job = $this->run_import("email,name\na@example.com,\"Bob\nb@example.com,Carl\nc@example.com,Dee\n");

        $this->assertSame(1, $job['counts'][Import::PROBLEM_NOTE]);
        $this->assertStringContainsString('quote mark', $job['problems'][0]['message']);
    }

    public function test_files_have_secret_names_and_the_folder_is_guarded_for_every_server()
    {
        $job = $this->run_import("email\nnope\n");

        $this->assertStringNotContainsString($job['id'], basename(Import::problems_path($job)));
        $this->assertGreaterThan(40, strlen(basename(Import::problems_path($job))));
        foreach (['index.php', '.htaccess', 'web.config'] as $guard) $this->assertFileExists(Import::directory() . '/' . $guard);

        $header = file(Import::problems_path($job), FILE_IGNORE_NEW_LINES)[0];
        $this->assertSame('Row,Problem,email', $header);

        $this->assertSame("'=SUM(A1)", SubscriberExport::safe_cell('=SUM(A1)'));
    }

    public function test_people_waiting_for_a_queued_import_confirmation_are_not_cleaned_up()
    {
        global $wpdb;

        $job = $this->run_import($this->rows(1), ['permission' => Import::PERMISSION_CONFIRM]);
        $id  = (int)Subscribers::get_by_email('row1@example.com')['id'];
        $wpdb->update(Subscribers::table(), ['updated_at' => '2020-01-01 00:00:00'], ['id' => $id]);

        $this->assertSame(0, Subscribers::delete_stale_pending(Subscribers::now()));
        $this->assertNotNull(Subscribers::get($id));

        // Once sent, the 30 days to confirm count from the email.
        $wpdb->update(Queue::table(), ['status' => Queue::STATUS_SENT], ['campaign_log_id' => $job['log_id']]);
        ImportConfirmations::get_instance()->log_sent(['recipient_type' => ImportConfirmations::RECIPIENT_TYPE, 'recipient_id' => $id], $job['log_id']);
        $this->assertSame(0, Subscribers::delete_stale_pending(gmdate('Y-m-d H:i:s', time() - 60)));
    }

    public function test_export_pages_through_everyone()
    {
        for ($i = 1; $i <= 7; $i++) Subscribers::create(['email' => "e$i@example.com", 'status' => 'subscribed', 'created_at' => '2026-01-01 00:00:00']);

        $handle = fopen('php://memory', 'w+');
        $this->assertSame(7, SubscriberExport::write($handle, [], []));
    }
}
