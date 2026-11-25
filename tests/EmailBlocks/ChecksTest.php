<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\Admin\EmailEditor\Checks;

/**
 * The checks before an email goes: warnings, each pointing at the element when there is one.
 */
class ChecksTest extends \WP_UnitTestCase
{
    protected function kinds($checks, $ok = false)
    {
        return array_values(array_map(function ($c) { return $c['kind'] . ($c['block'] ? ':' . $c['block'] : ''); }, array_filter($checks, function ($c) use ($ok) { return $c['ok'] === $ok; })));
    }

    public function test_a_clean_email_passes()
    {
        $state = ['settings' => ['email_campaign_title' => 'Hi {{subscriber.first_name|there}}'], 'content' => [
            ['id' => 'i', 'type' => 'image', 'settings' => ['image_url' => 'https://x.test/a.png', 'image_alt_text' => 'Tulips']],
            ['id' => 'b', 'type' => 'button', 'settings' => ['button_text' => 'Shop', 'button_link' => 'https://x.test/']],
        ]];

        $checks = Checks::run($state, '<p>x</p><a href="{{unsubscribe}}">Unsubscribe</a>', ['subscriber.first_name']);

        $this->assertSame([], $this->kinds($checks));
        $this->assertSame(['subject', 'unsubscribe', 'size'], $this->kinds($checks, true));
    }

    public function test_problems_are_found_and_point_at_their_element()
    {
        $state = ['settings' => ['email_campaign_title' => ''], 'content' => [
            ['id' => 'i', 'type' => 'image', 'settings' => ['image_url' => 'https://x.test/a.png', 'image_alt_text' => '']],
            ['id' => 'c', 'type' => 'columns', 'settings' => [], 'columns' => [[
                ['id' => 'b', 'type' => 'button', 'settings' => ['button_text' => 'Shop', 'button_link' => '#']],
            ], [
                ['id' => 't', 'type' => 'text', 'settings' => ['text_content' => '<p>Your cart: <a href="{{cart.url}}">here</a> <img src="x.png"></p>']],
            ]]],
        ]];

        $checks = Checks::run($state, str_repeat('x', Checks::SIZE_LIMIT + 1), []);

        $this->assertSame(['element:i', 'element:b', 'element:t', 'placeholder:t', 'subject', 'unsubscribe', 'size'], $this->kinds($checks), 'Warnings first, inside columns too.');
        $this->assertStringContainsString('{{cart.url}}', $checks[3]['text']);

        // The same placeholder where the email fills it in: fine.
        $this->assertNotContains('placeholder:t', $this->kinds(Checks::run($state, '', ['cart.url'])));
    }

    public function test_html_written_by_hand_is_checked_too()
    {
        $checks = Checks::run(['code' => '<img src="a.png"><a href="#">x</a> {{order.number}}', 'settings' => ['email_campaign_title' => 'S']], '<a href="[unsubscribe]">u</a>', []);

        $this->assertSame(['element', 'element', 'placeholder'], $this->kinds($checks));
        $this->assertNotContains('unsubscribe', $this->kinds($checks), 'The [unsubscribe] shortcode counts.');
    }

    public function test_alt_text_must_say_something()
    {
        $warned = function ($img) {
            return in_array('element', $this->kinds(Checks::run(['code' => $img, 'settings' => ['email_campaign_title' => 'S']], '{{unsubscribe}}', [])), true);
        };

        $this->assertFalse($warned('<img src="a.png" alt="Tulips">'));
        $this->assertFalse($warned("<img src='a.png' alt='Tulips'>"));
        $this->assertFalse($warned('<img src=a.png alt=Tulips>'));
        $this->assertTrue($warned('<img src="a.png" alt=" ">'), 'A space says nothing.');
        $this->assertTrue($warned('<img src="a.png" data-alt="Tulips">'), 'data-alt is not alt.');
        $this->assertTrue($warned('<img src="a.png">'));
    }
}
