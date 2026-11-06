<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Audience\PublicEndpoints;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Audience\Tokens;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use WP_UnitTestCase;

/**
 * Findings from the pre-merge security review.
 */
class PreMergeSecurityTest extends WP_UnitTestCase
{
    public function set_up()
    {
        parent::set_up();
        \MailOptin\Core\DBUpdates::get_instance()->update_routine_7();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();
        add_filter('pre_http_request', function () {
            return ['response' => ['code' => 200], 'body' => ''];
        });
        reset_phpmailer_instance();
    }

    protected function sent_to($email)
    {
        $mailer = tests_retrieve_phpmailer_instance();
        $count  = 0;
        foreach ((array)$mailer->mock_sent as $mail) {
            foreach ((array)$mail['to'] as $to) if (strtolower($to[0]) === $email) $count++;
        }

        return $count;
    }

    public function test_confirming_one_list_does_not_let_a_form_add_them_to_another_double_opt_in_list()
    {
        $newsletter = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 1]);
        $offers     = ListsRepository::create(['name' => 'Offers', 'double_optin' => 1]);

        $first = Subscription::subscribe('victim@example.com', $newsletter, ['source' => 'form']);
        Subscription::confirm($first['subscriber_id']);
        $id = $first['subscriber_id'];
        $this->assertSame('subscribed', Subscribers::get_membership($id, $newsletter)['status']);

        reset_phpmailer_instance();
        // Later than the 10-minute cooldown between confirmation emails.
        add_filter('mo_confirmation_email_cooldown', '__return_zero');

        // Someone else submits a form for another list with the confirmed address.
        $result = Subscription::subscribe('victim@example.com', $offers, ['source' => 'form']);

        $this->assertSame(Subscription::RESULT_PENDING, $result['result']);
        $this->assertSame('pending', Subscribers::get_membership($id, $offers)['status']);
        $this->assertSame(1, $this->sent_to('victim@example.com'), 'They are asked to confirm the new list.');
        $this->assertSame('subscribed', Subscribers::get($id)['status'], 'Their other list is untouched.');
        $this->assertSame('subscribed', Subscribers::get_membership($id, $newsletter)['status']);

        // Clicking the link confirms it.
        Subscription::confirm($id);
        $this->assertSame('subscribed', Subscribers::get_membership($id, $offers)['status']);
    }

    public function test_confirm_page_waits_for_the_person_to_press_the_button()
    {
        $list = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 1]);
        $r    = Subscription::subscribe('maya@example.com', $list, []);
        $sub  = Subscribers::get($r['subscriber_id']);

        parse_str(wp_parse_url(html_entity_decode(Tokens::confirm_url($sub, [$list])), PHP_URL_QUERY), $query);

        $page = $this->endpoint($query, 'GET');
        $this->assertStringContainsString('id="mo-confirm-form"', $page);
        // No script on the page: a link scanner that runs JavaScript can't confirm by opening it.
        $this->assertStringNotContainsString('<script', $page);
        $this->assertStringNotContainsString('.submit()', $page);
        $this->assertSame('pending', Subscribers::get_membership($sub['id'], $list)['status']);

        $this->endpoint($query, 'POST');
        $this->assertSame('subscribed', Subscribers::get_membership($sub['id'], $list)['status']);
    }

    protected function endpoint($query, $method)
    {
        $_GET = $_REQUEST = $query;
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = $method;

        try {
            PublicEndpoints::get_instance()->handle_request();
        } catch (\WPDieException $e) {
            return $e->getMessage();
        } finally {
            $_GET = $_REQUEST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }

        return '';
    }

    public function test_single_opt_in_lists_still_add_straight_away()
    {
        $list   = ListsRepository::create(['name' => 'Updates', 'double_optin' => 0]);
        $result = Subscription::subscribe('someone@example.com', $list, ['source' => 'form']);

        $this->assertSame(Subscription::RESULT_SUBSCRIBED, $result['result']);
    }

    /**
     * @dataProvider names
     */
    public function test_confirmation_greeting_leaves_out_names_that_look_like_links($name, $shown)
    {
        $list = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 1]);
        $r    = Subscription::subscribe(wp_generate_password(8, false) . '@example.com', $list, ['first_name' => $name]);

        $email = Subscription::confirmation_email(Subscribers::get($r['subscriber_id']));

        if ($shown) {
            $this->assertStringContainsString('Hi ' . $name . ',', $email['text']);
        } else {
            $this->assertStringStartsWith('Hi,', $email['text']);
            $this->assertStringNotContainsString($name, $email['text']);
        }
    }

    public function names()
    {
        return [
            'plain'      => ['Maya', true],
            'accented'   => ['Léa', true],
            'link'       => ['WINNER-claim-at-evil.example', false],
            'www'        => ['wwwprizes', false],
            'url'        => ['http://evil', false],
            'address'    => ['me@evil', false],
            'too long'   => [str_repeat('a', 41), false],
        ];
    }
}
