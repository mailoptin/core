<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Audience\ImportConfirmations;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Audience\SubscriberExport;
use MailOptin\Core\Audience\SubscriberImport as Import;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SendQueueRepository as Queue;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use WP_UnitTestCase;

class SubscriberImportTest extends WP_UnitTestCase
{
    protected $news;
    protected $workshops;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        DBUpdates::get_instance()->update_routine_17();
        DBUpdates::get_instance()->update_routine_20();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        Fields::flush_cache();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator', 'display_name' => 'Ada Admin']));
        reset_phpmailer_instance();

        // tests/bootstrap.php boots the plugin after plugins_loaded has fired.
        if (is_null(\MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process)) {
            \MailOptin\Core\AsyncHandler\AsyncHandler::get_instance()->bg_process = new \MailOptin\Core\AsyncHandler\BGProcess();
        }
        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        $this->news      = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 1]);
        $this->workshops = ListsRepository::create(['name' => 'Workshops', 'double_optin' => 0]);
    }

    public function tear_down()
    {
        foreach (Import::all() as $job) Import::delete($job['id']);
        Fields::flush_cache();

        parent::tear_down();
    }

    protected function job($csv, $name = 'people.csv')
    {
        $path = Import::directory() . '/test-' . wp_generate_password(6, false) . '.csv';
        file_put_contents($path, $csv);

        $job = Import::create_from_file($path, $name);
        $this->assertIsArray($job, is_wp_error($job) ? $job->get_error_message() : '');

        return $job;
    }

    protected function run_import($csv, $choices = [], $name = 'people.csv')
    {
        $job = $this->job($csv, $name);

        $result = Import::start($job['id'], $choices + ['mapping' => $job['mapping'], 'lists' => [$this->news], 'permission' => Import::PERMISSION_SUBSCRIBED]);
        $this->assertTrue($result, is_wp_error($result) ? $result->get_error_message() : '');

        do {
            $job = Import::run_batch($job['id'], 2);
        } while (Import::STATUS_RUNNING === $job['status']);

        return $job;
    }

    public function test_reads_headers_and_guesses_columns()
    {
        $job = $this->job("\xEF\xBB\xBFEmail Address;First Name;Surname;Full name;TAGS;MEMBER_RATING\r\npriya@example.com;Priya;N;Priya N;vip;4\r\n\r\nb@example.com;;;;;\r\n");

        $this->assertSame(';', $job['delimiter']);
        $this->assertSame('Email Address', $job['headers'][0]);
        $this->assertSame([0 => 'email', 1 => 'first_name', 2 => 'last_name', 3 => 'full_name', 4 => 'tags'], $job['mapping']);
        $this->assertSame('priya@example.com', $job['sample'][0]);
        $this->assertSame(3, $job['total']);
        $this->assertSame(Import::STATUS_MAPPING, $job['status']);
    }

    public function test_rejects_empty_and_oversized_files()
    {
        $path = Import::directory() . '/empty.csv';
        file_put_contents($path, "email\n");
        $this->assertSame('mo_import_empty', Import::create_from_file($path, 'empty.csv')->get_error_code());
        $this->assertFileDoesNotExist($path);

        $this->assertSame('mo_import_upload', Import::create_from_upload(['tmp_name' => '/etc/passwd', 'name' => 'x.csv', 'error' => 0])->get_error_code());
    }

    public function test_start_needs_email_list_and_an_explicit_permission_choice()
    {
        $job = $this->job("email,name\na@example.com,A\n");

        $this->assertSame('mo_import_no_email', Import::start($job['id'], ['mapping' => [1 => 'full_name'], 'lists' => [$this->news], 'permission' => 'subscribed'])->get_error_code());
        $this->assertSame('mo_import_no_list', Import::start($job['id'], ['mapping' => [0 => 'email'], 'lists' => [999], 'permission' => 'subscribed'])->get_error_code());
        $this->assertSame('mo_import_no_permission', Import::start($job['id'], ['mapping' => [0 => 'email'], 'lists' => [$this->news]])->get_error_code());

        // Choices are kept for the form; nothing was imported.
        $this->assertSame([$this->news], Import::get($job['id'])['lists']);
        $this->assertSame(0, Subscribers::query([])['total']);
    }

    public function test_imports_new_subscribers_as_subscribed_with_a_consent_record()
    {
        $job = $this->run_import("Email,First name,Last name,Tags\nPriya@Example.com,Priya,Natarajan,\"customer, roses\"\ntom@example.com,Tom,,\n", ['tag_all' => 'imported-oct', 'lists' => [$this->news, $this->workshops]]);

        $this->assertSame(Import::STATUS_DONE, $job['status']);
        $this->assertSame(2, $job['counts']['added']);
        $this->assertFileDoesNotExist($job['file'], 'The uploaded file is deleted when the import ends.');

        $priya = Subscribers::get_by_email('priya@example.com');
        $this->assertSame([Subscribers::STATUS_SUBSCRIBED, 'Priya', 'Natarajan', 'import', 'import'], [$priya['status'], $priya['first_name'], $priya['last_name'], $priya['source'], $priya['consent_method']]);
        $this->assertSame('Imported from people.csv by Ada Admin', $priya['consent_text']);
        $this->assertSame('subscribed', Subscribers::get_membership($priya['id'], $this->workshops)['status']);
        $this->assertSame(['customer', 'imported-oct', 'roses'], array_values(Tags::for_subscriber($priya['id'])));
        $this->assertSame(Subscribers::EVENT_IMPORTED, Subscribers::get_events($priya['id'])[0]['type']);

        // No confirmation or welcome email for people imported with permission.
        $this->assertEmpty(tests_retrieve_phpmailer_instance()->get_sent());
    }

    public function test_never_adds_back_people_who_unsubscribed_bounced_or_complained_and_reports_bad_rows()
    {
        Subscription::add_by_admin('left@example.com', [$this->news], [], true);
        Subscription::unsubscribe(Subscribers::get_by_email('left@example.com')['id']);
        SuppressionRepository::add('bounced@example.com', SuppressionRepository::REASON_BOUNCED, 'test');

        $job = $this->run_import("email,name\nleft@example.com,\nbounced@example.com,\nnot-an-email,\nnew@example.com,\nNEW@example.com,\n,No Email\n,\n");

        $this->assertSame(1, $job['counts']['added']);
        $this->assertSame(2, $job['counts'][Import::PROBLEM_SUPPRESSED]);
        $this->assertSame(2, $job['counts'][Import::PROBLEM_INVALID]);
        $this->assertSame(1, $job['counts'][Import::PROBLEM_REPEATED]);
        $this->assertSame(5, Import::skipped_count($job));
        $this->assertSame('unsubscribed', Subscribers::get_by_email('left@example.com')['status']);
        $this->assertNull(Subscribers::get_by_email('bounced@example.com'));

        $this->assertSame([2, 'left@example.com', 'suppressed'], [$job['problems'][0]['row'], $job['problems'][0]['email'], $job['problems'][0]['type']]);
        $this->assertSame(6, $job['problems'][3]['row']);

        $problems = array_map('str_getcsv', file(Import::problems_path($job), FILE_IGNORE_NEW_LINES));
        $this->assertSame(['Row', 'Problem', 'email', 'name'], $problems[0]);
        $this->assertCount(6, $problems);
    }

    public function test_existing_subscribers_fill_empty_details_or_replace_them()
    {
        Subscription::add_by_admin('amara@example.com', [$this->news], ['first_name' => 'Amara'], true);
        Subscription::add_by_admin('tom@example.com', [$this->news, $this->workshops], [], true);
        $tom = Subscribers::get_by_email('tom@example.com');
        Subscription::unsubscribe($tom['id'], $this->workshops);

        $csv = "email,first name,last name\namara@example.com,Spoofed,Okafor\ntom@example.com,Tom,Becker\n";
        $job = $this->run_import($csv, ['lists' => [$this->workshops]]);

        $this->assertSame(2, $job['counts']['updated']);
        $amara = Subscribers::get_by_email('amara@example.com');
        $this->assertSame(['Amara', 'Okafor'], [$amara['first_name'], $amara['last_name']]);
        $this->assertSame('subscribed', Subscribers::get_membership($amara['id'], $this->workshops)['status']);
        $this->assertSame('unsubscribed', Subscribers::get_membership($tom['id'], $this->workshops)['status'], 'Someone who left a list only rejoins it themselves.');

        $this->run_import($csv, ['existing' => Import::EXISTING_REPLACE], 'again.csv');
        $this->assertSame('Spoofed', Subscribers::get_by_email('amara@example.com')['first_name']);
    }

    public function test_full_name_is_split_when_there_are_no_name_columns()
    {
        $this->run_import("email,name\namara@example.com,Amara Okafor\n");

        $amara = Subscribers::get_by_email('amara@example.com');
        $this->assertSame(['Amara', 'Okafor'], [$amara['first_name'], $amara['last_name']]);
    }

    public function test_confirmation_emails_go_through_the_send_queue()
    {
        Subscription::add_by_admin('confirmed@example.com', [$this->workshops], [], true);
        global $wpdb;
        $wpdb->update(Subscribers::table(), ['confirmed_at' => Subscribers::now()], ['email' => 'confirmed@example.com']);

        $job = $this->run_import("email\nnew@example.com\nconfirmed@example.com\n", ['permission' => Import::PERMISSION_CONFIRM]);

        $new = Subscribers::get_by_email('new@example.com');
        $this->assertSame('pending', $new['status']);
        $this->assertSame('import_confirm', $new['consent_method']);
        $this->assertSame('subscribed', Subscribers::get_membership(Subscribers::get_by_email('confirmed@example.com')['id'], $this->news)['status'], 'Already confirmed: no need to confirm again.');

        $this->assertGreaterThan(0, $job['log_id']);
        $this->assertSame(1, Queue::counts($job['log_id'])[Queue::STATUS_PENDING] + Queue::counts($job['log_id'])[Queue::STATUS_SENT]);

        // What the queue sends for that row.
        $row     = ['recipient_type' => ImportConfirmations::RECIPIENT_TYPE, 'recipient_id' => $new['id'], 'email' => 'new@example.com'];
        $content = apply_filters('mo_send_queue_email_content', ['subject' => '', 'html' => '', 'text' => ''], $row, $job['log_id']);
        $this->assertSame('Please confirm your subscription', $content['subject']);
        $this->assertStringContainsString('Newsletter', $content['text']);
        $this->assertSame('', apply_filters('mo_send_queue_skip_reason', '', $row, $job['log_id']));

        Subscribers::confirm($new['id']);
        $this->assertNotSame('', apply_filters('mo_send_queue_skip_reason', '', $row, $job['log_id']), 'Confirmed meanwhile: skipped.');
    }

    public function test_batches_resume_where_they_stopped_and_a_lock_prevents_double_runs()
    {
        $csv = "email\n";
        for ($i = 1; $i <= 5; $i++) $csv .= "p$i@example.com\n";

        $job = $this->job($csv);
        Import::start($job['id'], ['mapping' => $job['mapping'], 'lists' => [$this->news], 'permission' => 'subscribed']);

        $job = Import::run_batch($job['id'], 2);
        $this->assertSame([2, Import::STATUS_RUNNING], [$job['counts']['added'], $job['status']]);

        add_option(Import::OPTION_PREFIX . $job['id'] . '_lock', time() + 60);
        $this->assertSame(2, Import::run_batch($job['id'], 2)['counts']['added'], 'Another request holds the lock.');
        delete_option(Import::OPTION_PREFIX . $job['id'] . '_lock');

        $this->assertSame(4, Import::run_batch($job['id'], 2)['counts']['added']);
        $job = Import::run_batch($job['id'], 2);
        $this->assertSame([5, Import::STATUS_DONE], [$job['counts']['added'], $job['status']]);
        $this->assertSame(5, Subscribers::query([])['total']);
    }

    public function test_cron_carries_on_and_cancel_stops()
    {
        $job = $this->job("email\na@example.com\nb@example.com\n");
        Import::start($job['id'], ['mapping' => $job['mapping'], 'lists' => [$this->news], 'permission' => 'subscribed']);
        $this->assertNotFalse(wp_next_scheduled(Import::CRON_HOOK, [$job['id']]));

        $this->assertTrue(Import::cancel($job['id']));
        Import::run_from_cron($job['id']);
        $this->assertSame(0, Subscribers::query([])['total']);
        $this->assertFileDoesNotExist($job['file']);
        $this->assertFalse(Import::cancel($job['id']));
    }

    public function test_old_reports_and_abandoned_imports_are_deleted()
    {
        $done      = $this->run_import("email\na@example.com\n");
        $abandoned = $this->job("email\nb@example.com\n");
        $recent    = $this->job("email\nc@example.com\n");

        foreach ([[$done, 31 * DAY_IN_SECONDS], [$abandoned, 2 * DAY_IN_SECONDS]] as list($job, $age)) {
            $job               = Import::get($job['id']);
            $job['created_at'] = gmdate('Y-m-d H:i:s', time() - $age);
            update_option(Import::OPTION_PREFIX . $job['id'], $job, false);
        }

        Import::delete_old();

        $this->assertNull(Import::get($done['id']));
        $this->assertNull(Import::get($abandoned['id']));
        $this->assertFileDoesNotExist($abandoned['file']);
        $this->assertNotNull(Import::get($recent['id']));
    }

    public function test_custom_field_columns_are_not_imported_without_premium()
    {
        Fields::create(['type' => 'text', 'label' => 'Company']);

        $this->assertArrayNotHasKey('field:1', Import::targets());
        $job = $this->job("email,company\na@example.com,Acme\n");
        $this->assertSame([0 => 'email'], $job['mapping']);

        Import::start($job['id'], ['mapping' => [0 => 'email', 1 => 'field:' . Fields::get_by_key('company')['id']], 'lists' => [$this->news], 'permission' => 'subscribed']);
        Import::run_batch($job['id']);

        $this->assertSame('', Fields::merge_values(Subscribers::get_by_email('a@example.com')['id'])['company']);
    }

    public function test_export_writes_filtered_subscribers_safely_and_imports_again()
    {
        Subscription::add_by_admin('amara@example.com', [$this->news], ['first_name' => '=HYPERLINK("x")', 'last_name' => 'Okafor'], true);
        Subscription::add_by_admin('tom@example.com', [$this->workshops], ['first_name' => 'Tom'], true);
        $amara = Subscribers::get_by_email('amara@example.com');
        Tags::add_to_subscribers(Tags::ensure(['vip']), [$amara['id']]);
        $date = Fields::create(['type' => 'date', 'label' => 'Birthday']);
        Fields::set_values($amara['id'], [$date => '1988-06-02']);

        $handle = fopen('php://memory', 'w+');
        $count  = SubscriberExport::write($handle, ['list_id' => $this->news], [SubscriberExport::COLUMNS_LISTS_TAGS, SubscriberExport::COLUMNS_FIELDS, SubscriberExport::COLUMNS_CONSENT]);
        rewind($handle);
        $rows = [];
        while (false !== ($row = fgetcsv($handle, 0, ',', '"', ''))) $rows[] = $row;

        $this->assertSame(1, $count);
        $this->assertSame(['Email', 'First name', 'Last name', 'Status', 'Lists', 'Tags', 'Birthday'], array_slice($rows[0], 0, 7));
        $this->assertSame(['amara@example.com', "'=HYPERLINK(\"x\")", 'Okafor', 'subscribed', 'Newsletter', 'vip', '1988-06-02'], array_slice($rows[1], 0, 7));
        $this->assertSame('Added by admin', $rows[1][7]);

        // Values the export protected from spreadsheets import as they were.
        $this->assertSame('=HYPERLINK("x")', Import::clean_cell($rows[1][1]));
        $this->assertSame("'quoted", Import::clean_cell("'quoted"));

        // The export's column names are recognised by the import.
        $this->assertSame([0 => 'email', 1 => 'first_name', 2 => 'last_name', 5 => 'tags'], array_intersect_key(Import::guess_mapping($rows[0]), array_flip([0, 1, 2, 3, 4, 5])));
    }
}
