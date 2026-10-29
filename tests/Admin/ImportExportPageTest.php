<?php

namespace MailOptin\Tests\Core\Admin;

use MailOptin\Core\Admin\SettingsPage\Subscribers_Import_Page;
use MailOptin\Core\Admin\SettingsPage\Subscribers_Page;
use MailOptin\Core\Audience\SubscriberImport as Import;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\DBUpdates;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use W3Guy\Custom_Settings_Page_Api;
use WP_UnitTestCase;

class ImportExportPageTest extends WP_UnitTestCase
{
    protected $list_id;

    /** @var Subscribers_Page */
    protected $page;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        DBUpdates::get_instance()->update_routine_17();
        DBUpdates::get_instance()->update_routine_20();
        update_option('mo_db_ver', DBUpdates::DB_VER);
        Fields::flush_cache();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        set_current_screen('dashboard');

        $this->list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 1]);
        $this->page    = Subscribers_Page::get_instance();
    }

    public function tear_down()
    {
        $_GET = $_POST = $_REQUEST = [];
        foreach (Import::all() as $job) Import::delete($job['id']);
        Fields::flush_cache();

        parent::tear_down();
    }

    /**
     * @return string redirect output
     */
    protected function request($get, $post = [], $nonce_action = '')
    {
        $get['page'] = Subscribers_Page::SLUG;

        if ($nonce_action) {
            $nonce = wp_create_nonce($nonce_action);
            $post ? $post['_wpnonce'] = $nonce : $get['_wpnonce'] = $nonce;
        }

        $_GET     = $get;
        $_POST    = $post;
        $_REQUEST = array_merge($get, $post);

        ob_start();
        try {
            $this->page->handle_actions();
        } finally {
            $output = ob_get_clean();
        }

        return $output;
    }

    protected function render($method, $get = [])
    {
        $_GET = array_merge(['page' => Subscribers_Page::SLUG], $get);

        $instance = Custom_Settings_Page_Api::instance();
        $instance->option_name(Subscribers_Page::OPTION_NAME);
        $this->page->set_settings_page_instance($instance);

        ob_start();
        $this->page->$method();

        return ob_get_clean();
    }

    protected function job($csv)
    {
        $path = Import::directory() . '/t-' . wp_generate_password(6, false) . '.csv';
        file_put_contents($path, $csv);

        return Import::create_from_file($path, 'people.csv');
    }

    public function test_upload_screen_and_recent_imports()
    {
        $html = $this->render('render_import', ['view' => 'import']);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringNotContainsString('Recent imports', $html);

        $this->job("email\na@example.com\n");
        $this->assertStringContainsString('Recent imports', $this->render('render_import', ['view' => 'import']));
    }

    public function test_a_failed_upload_shows_why()
    {
        $output = $this->request(['view' => 'import'], ['mo_subscriber_action' => 'upload_import'], 'mo_upload_import');

        $this->assertStringContainsString('mo_notice=error', $output);
        $this->assertStringContainsString('could not be uploaded', $this->render('render_import', ['view' => 'import', 'mo_notice' => 'error']));
    }

    public function test_matching_step_requires_an_explicit_permission_choice()
    {
        $job  = $this->job("Email,First name\na@example.com,Amara\n");
        $html = $this->render('render_import', ['view' => 'import', 'import' => $job['id']]);

        $this->assertStringContainsString('name="mapping[0]"', $html);
        $this->assertMatchesRegularExpression('/value="email"\s+selected/', $html);
        $this->assertStringNotContainsString('name="permission" value="subscribed"  checked', $html);
        $this->assertDoesNotMatchRegularExpression('/name="permission"[^>]*checked/', $html, 'No permission option is chosen for the admin.');

        $output = $this->request(['view' => 'import'], ['mo_subscriber_action' => 'start_import', 'import' => $job['id'], 'mapping' => [0 => 'email'], 'lists' => [$this->list_id]], 'mo_start_import_' . $job['id']);
        $this->assertStringContainsString('mo_notice=error', $output);
        $this->assertStringContainsString('already agreed to hear from you', $this->render('render_import', ['view' => 'import', 'import' => $job['id'], 'mo_notice' => 'error']));

        $this->request(['view' => 'import'], ['mo_subscriber_action' => 'start_import', 'import' => $job['id'], 'mapping' => [0 => 'email', 1 => 'first_name'], 'lists' => [$this->list_id], 'permission' => 'subscribed'], 'mo_start_import_' . $job['id']);
        $this->assertSame(Import::STATUS_RUNNING, Import::get($job['id'])['status']);

        // The progress page imports a batch on each load, then shows the report.
        $progress = $this->render('render_import', ['view' => 'import', 'import' => $job['id']]);
        $this->assertStringContainsString('Import finished', $progress);
        $this->assertSame('Amara', Subscribers::get_by_email('a@example.com')['first_name']);
    }

    public function test_report_lists_problems_and_offers_the_download()
    {
        Subscription::add_by_admin('left@example.com', [$this->list_id], [], true);
        Subscription::unsubscribe(Subscribers::get_by_email('left@example.com')['id']);

        $job = $this->job("email\nleft@example.com\nnope\nok@example.com\n");
        Import::start($job['id'], ['mapping' => [0 => 'email'], 'lists' => [$this->list_id], 'permission' => 'subscribed', 'tag_all' => 'batch-1']);
        Import::run_batch($job['id']);

        $html = $this->render('render_import', ['view' => 'import', 'import' => $job['id']]);
        $this->assertStringContainsString('They unsubscribed', $html);
        $this->assertStringContainsString('Download the 2 rows with a problem', $html);
        $this->assertStringContainsString('View imported subscribers', $html);
        $this->assertStringContainsString('tags%5B0%5D=' . Tags::get_by_name('batch-1')['id'], $html);

        $output = $this->request(['view' => 'import', 'mo_subscriber_action' => 'delete_import', 'import' => $job['id']], [], 'mo_delete_import_' . $job['id']);
        $this->assertStringContainsString('mo_notice=import_deleted', $output);
        $this->assertNull(Import::get($job['id']));
        $this->assertNotNull(Subscribers::get_by_email('ok@example.com'));
    }

    public function test_actions_need_their_own_nonce()
    {
        $job = $this->job("email\na@example.com\n");

        $this->expectException(\WPDieException::class);
        try {
            $this->request(['view' => 'import', 'mo_subscriber_action' => 'delete_import', 'import' => $job['id']], [], 'mo_delete_import_other');
        } finally {
            $this->assertNotNull(Import::get($job['id']));
        }
    }

    public function test_export_screen_keeps_the_list_filters()
    {
        Subscription::add_by_admin('amara@example.com', [$this->list_id], [], true);
        $other = ListsRepository::create(['name' => 'Workshops', 'double_optin' => 0]);
        Subscription::add_by_admin('tom@example.com', [$other], [], true);
        $vip = Tags::create('vip');
        Tags::add_to_subscribers([$vip], [Subscribers::get_by_email('amara@example.com')['id']]);

        $this->assertStringContainsString('view=export', Subscribers_Import_Page::export_url(['list' => $this->list_id, 'tags' => [$vip]]));

        $html = $this->render('render_export', ['view' => 'export', 'tags' => [$vip]]);
        $this->assertStringContainsString('Export 1 subscriber', $html);
        $this->assertStringContainsString('name="tags[0]" value="' . $vip . '"', $html);
        $this->assertStringContainsString('Tag is any of', $html);

        $this->assertStringContainsString('Export 2 subscribers', $this->render('render_export', ['view' => 'export']));

        $list = $this->render('render_subscribers', ['list' => $this->list_id]);
        $this->assertStringContainsString('Import CSV', $list);
        $this->assertStringContainsString('view=export&#038;list=' . $this->list_id, $list);
    }
}
