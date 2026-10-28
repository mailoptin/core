<?php

namespace MailOptin\Tests\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\Subscribers_Page;
use MailOptin\Core\Audience\Consent;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Connections\ConnectionFactory;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use WP_UnitTestCase;

class ConsentTest extends WP_UnitTestCase
{
    const AGREEMENT = 'I agree to receive the monthly planting newsletter. I can unsubscribe at any time.';

    protected $list_id;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\Base::get_instance()->register_metadata_table();

        $this->list_id = ListsRepository::create(['name' => 'Newsletter', 'double_optin' => 0]);
    }

    protected function form($settings)
    {
        $id = OCR::add_optin_campaign(wp_generate_password(10, false), 'Signup form ' . wp_generate_password(6, false), 'BareMetal', 'inpost');
        $this->assertNotFalse($id);

        $all      = OCR::get_settings();
        $all[$id] = $settings;
        OCR::updateSettings($all);

        return $id;
    }

    protected function signup($optin_campaign_id, $payload = [], $email = 'rosa@example.com')
    {
        ConnectionFactory::make('MailOptinConnect')->subscribe(
            $email,
            'Rosa Reyes',
            $this->list_id,
            array_merge($payload, ['optin_campaign_id' => $optin_campaign_id, 'conversion_page' => 'https://example.org/?page_id=6'])
        );

        return Subscribers::get_by_email($email);
    }

    public function test_ticked_checkbox_records_the_note_as_agreed_wording()
    {
        $form = $this->form(['note' => '<p><em>' . self::AGREEMENT . '</em></p>', 'note_acceptance_checkbox' => true]);

        $subscriber = $this->signup($form, ['mo-acceptance' => 'yes']);

        $this->assertSame(self::AGREEMENT, $subscriber['consent_text'], 'Saved as plain text.');
        $this->assertSame(Consent::METHOD_CHECKBOX, $subscriber['consent_method']);
    }

    public function test_untick_is_recorded_honestly()
    {
        $form = $this->form(['note' => self::AGREEMENT, 'note_acceptance_checkbox' => true]);

        $subscriber = $this->signup($form);

        $this->assertSame(Consent::METHOD_CHECKBOX_NOT_TICKED, $subscriber['consent_method']);
    }

    public function test_note_without_checkbox()
    {
        $form = $this->form(['note' => self::AGREEMENT]);

        $this->assertSame(Consent::METHOD_NOTE, $this->signup($form)['consent_method']);
    }

    public function test_untouched_note_falls_back_to_the_theme_default()
    {
        $form = $this->form([]);

        $subscriber = $this->signup($form);

        $this->assertSame('We promise not to spam you. You can unsubscribe at any time.', $subscriber['consent_text']);
        $this->assertSame(Consent::METHOD_NOTE, $subscriber['consent_method']);
    }

    public function test_hidden_note_records_no_wording()
    {
        $form = $this->form(['note' => self::AGREEMENT, 'hide_note' => true, 'note_acceptance_checkbox' => true]);

        $subscriber = $this->signup($form, ['mo-acceptance' => 'yes']);

        $this->assertNull($subscriber['consent_text']);
        $this->assertSame(Consent::METHOD_NONE, $subscriber['consent_method']);
    }

    public function test_later_signup_keeps_the_recorded_consent_and_logs_its_own()
    {
        $with_text    = $this->form(['note' => self::AGREEMENT, 'note_acceptance_checkbox' => true]);
        $without_text = $this->form(['hide_note' => true]);

        $this->signup($with_text, ['mo-acceptance' => 'yes']);
        // Anyone can submit a form with someone else's address, so it must not rewrite their record.
        $subscriber = $this->signup($without_text);

        $this->assertSame(self::AGREEMENT, $subscriber['consent_text']);
        $this->assertSame(Consent::METHOD_CHECKBOX, $subscriber['consent_method']);

        $signups = wp_list_filter(Subscribers::get_events($subscriber['id']), ['type' => 'signup']);
        $latest  = json_decode(reset($signups)['meta'], true);
        $this->assertSame(Consent::METHOD_NONE, $latest['consent_method'], 'The later signup is on the timeline.');
    }

    public function test_admin_added_subscriber()
    {
        Subscription::add_by_admin('amara@example.com', [$this->list_id], [], true);

        $this->assertSame(Consent::METHOD_ADMIN, Subscribers::get_by_email('amara@example.com')['consent_method']);
    }

    public function test_profile_shows_wording_method_and_full_page()
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        set_current_screen('dashboard');

        $form       = $this->form(['note' => self::AGREEMENT, 'note_acceptance_checkbox' => true]);
        $subscriber = $this->signup($form, ['mo-acceptance' => 'yes']);

        $_GET = ['page' => Subscribers_Page::SLUG, 'subscriber' => $subscriber['id']];
        ob_start();
        Subscribers_Page::get_instance()->render_subscribers();
        $html = ob_get_clean();
        $_GET = [];

        $this->assertStringContainsString('Agreed to', $html);
        $this->assertStringContainsString(self::AGREEMENT, $html);
        $this->assertStringContainsString('Ticked the checkbox', $html);
        $this->assertStringContainsString('<dd>/?page_id=6</dd>', $html);
        $this->assertStringContainsString('on /?page_id=6', $html, 'The timeline shows the query too.');
    }
}
