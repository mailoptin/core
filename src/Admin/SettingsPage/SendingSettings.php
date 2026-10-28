<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\SendQueue\QueueRunner;
use MailOptin\Core\SendQueue\WpMailSender;

use function MailOptin\Core\current_user_has_privilege;
use function MailOptin\Core\do_admin_redirect;

/**
 * "Sending" tab under MailOptin settings, for email sent through the built-in send queue.
 */
class SendingSettings
{
    const HOURLY_LIMIT_SETTING = 'send_queue_hourly_limit';
    const DEFAULT_HOURLY_LIMIT = 1000;

    public function __construct()
    {
        add_filter('mailoptin_settings_page', [$this, 'settings_tab'], 30);
        add_action('admin_init', [$this, 'regenerate_run_key']);
    }

    public function settings_tab($settings)
    {
        $settings['sending_settings'] = [
            'tab_title' => __('Sending', 'mailoptin'),
            [
                'section_title'            => __('Sending for built-in lists', 'mailoptin'),
                self::HOURLY_LIMIT_SETTING => [
                    'type'        => 'number',
                    'label'       => __('Emails per hour', 'mailoptin'),
                    'value'       => self::DEFAULT_HOURLY_LIMIT,
                    'description' => __('The most emails MailOptin sends in one hour across all campaigns. Check your host\'s or SMTP provider\'s limit; many shared hosts allow 100 to 500 an hour.', 'mailoptin'),
                ],
                'send_queue_transport'     => [
                    'type'  => 'arbitrary',
                    'label' => __('Send through', 'mailoptin'),
                    'data'  => $this->transport_html(),
                ],
                'send_queue_runners'       => [
                    'type'  => 'arbitrary',
                    'label' => __('Background sending', 'mailoptin'),
                    'data'  => $this->runners_html(),
                ],
            ]
        ];

        return $settings;
    }

    protected function transport_html()
    {
        $html = '<h4 style="margin:0 0 4px">' . esc_html__('Send through', 'mailoptin') . '</h4>';
        $html .= '<p>' . esc_html__('WordPress (wp_mail). If an SMTP plugin is installed, email goes through it.', 'mailoptin') . '</p>';

        if (get_option(WpMailSender::ONE_CLICK_UNAVAILABLE_OPTION)) {
            $html .= '<p class="description" style="color:#8a6100">' . esc_html__('This site sends with PHP mail(), which can\'t carry the one-click unsubscribe header Gmail and Yahoo expect from bulk senders. The unsubscribe link in each email still works. Installing an SMTP plugin fixes this and usually improves delivery.', 'mailoptin') . '</p>';
        }

        return $html;
    }

    protected function runners_html()
    {
        $regenerate_url = wp_nonce_url(add_query_arg('mo_regenerate_queue_key', '1'), 'mo_regenerate_queue_key');

        return sprintf(
            '<h4 style="margin:16px 0 4px">' . esc_html__('Background sending', 'mailoptin') . '</h4><p>%1$s</p><p>%2$s</p><p><code>%3$s</code> <a href="%4$s">%5$s</a></p><p>%6$s</p><p><code>wp mailoptin queue run</code></p>',
            esc_html__('By default sending runs on WP-Cron, which only runs when your site gets visits. For faster, steadier sending, use one of these:', 'mailoptin'),
            esc_html__('Call this URL every minute from your host\'s cron settings:', 'mailoptin'),
            esc_html(QueueRunner::run_url()),
            esc_url($regenerate_url),
            esc_html__('Regenerate', 'mailoptin'),
            esc_html__('Or run this WP-CLI command every minute:', 'mailoptin')
        );
    }

    public function regenerate_run_key()
    {
        if (empty($_GET['mo_regenerate_queue_key']) || ! current_user_has_privilege()) return;

        check_admin_referer('mo_regenerate_queue_key');

        QueueRunner::regenerate_run_key();

        do_admin_redirect(remove_query_arg(['mo_regenerate_queue_key', '_wpnonce']));
    }

    /**
     * @return SendingSettings
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}
