<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Audience\EmailBranding;
use MailOptin\Core\PluginSettings\Settings;
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
        add_action('admin_init', [$this, 'send_branding_test']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_branding_assets']);
    }

    public function enqueue_branding_assets()
    {
        if ( ! isset($_GET['page']) || MAILOPTIN_SETTINGS_SETTINGS_SLUG !== $_GET['page']) return;

        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }

    public function send_branding_test()
    {
        if (empty($_GET['mo_send_branding_test']) || ! current_user_has_privilege()) return;

        check_admin_referer('mo_send_branding_test');

        $sent = wp_mail(
            wp_get_current_user()->user_email,
            __('[Test] Email branding', 'mailoptin'),
            EmailBranding::sample_html(),
            WpMailSender::default_headers()
        );

        do_admin_redirect(add_query_arg('mo_branding_test', $sent ? 'sent' : 'failed', remove_query_arg(['mo_send_branding_test', '_wpnonce'])) . '#sending_settings');
    }

    protected function branding_html()
    {
        $b        = EmailBranding::settings();
        $logo_id  = absint((new Settings())->{EmailBranding::LOGO_ID}());
        $name     = 'mailoptin_settings';
        $test_url = wp_nonce_url(add_query_arg('mo_send_branding_test', '1'), 'mo_send_branding_test');
        $notice   = sanitize_key($_GET['mo_branding_test'] ?? '');

        ob_start();
        ?>
        <div class="mo-branding">
            <div class="mo-branding-controls">
                <p class="description"><?php esc_html_e('Used by the welcome email and the confirmation email, so everything a new subscriber receives looks the same.', 'mailoptin'); ?></p>

                <div class="mo-branding-row">
                    <strong><?php esc_html_e('Logo', 'mailoptin'); ?></strong>
                    <div>
                        <input type="hidden" id="mo-brand-logo-id" name="<?php echo $name; ?>[<?php echo EmailBranding::LOGO_ID; ?>]" value="<?php echo $logo_id ?: ''; ?>">
                        <span class="mo-brand-logo-thumb"><?php if ($b['logo_url']) : ?><img src="<?php echo esc_url($b['logo_url']); ?>" alt=""><?php endif; ?></span>
                        <button type="button" class="button" id="mo-brand-logo-choose"><?php echo $b['logo_url'] ? esc_html__('Change logo', 'mailoptin') : esc_html__('Choose logo', 'mailoptin'); ?></button>
                        <button type="button" class="button-link mo-brand-remove" id="mo-brand-logo-remove" <?php echo $b['logo_url'] ? '' : 'hidden'; ?>><?php esc_html_e('Remove', 'mailoptin'); ?></button>
                        <p class="description"><?php esc_html_e('Shown at the top of the email. PNG or JPG, at least 300px wide. Your company name is used as its alt text, for email apps that hide images.', 'mailoptin'); ?></p>
                    </div>
                </div>

                <div class="mo-branding-row">
                    <label for="mo-brand-logo-width"><strong><?php esc_html_e('Logo width', 'mailoptin'); ?></strong></label>
                    <div><input type="number" min="40" max="560" id="mo-brand-logo-width" name="<?php echo $name; ?>[<?php echo EmailBranding::LOGO_WIDTH; ?>]" value="<?php echo absint($b['logo_width']); ?>" class="small-text"> px</div>
                </div>

                <div class="mo-branding-row">
                    <strong><?php esc_html_e('Logo position', 'mailoptin'); ?></strong>
                    <div>
                        <label><input type="radio" name="<?php echo $name; ?>[<?php echo EmailBranding::LOGO_ALIGN; ?>]" value="left" <?php checked($b['logo_align'], 'left'); ?>> <?php esc_html_e('Left', 'mailoptin'); ?></label>&nbsp;&nbsp;
                        <label><input type="radio" name="<?php echo $name; ?>[<?php echo EmailBranding::LOGO_ALIGN; ?>]" value="center" <?php checked($b['logo_align'], 'center'); ?>> <?php esc_html_e('Center', 'mailoptin'); ?></label>
                    </div>
                </div>

                <div class="mo-branding-row">
                    <label for="mo-brand-accent"><strong><?php esc_html_e('Accent colour', 'mailoptin'); ?></strong></label>
                    <div>
                        <input type="text" class="mo-brand-color" id="mo-brand-accent" data-default-color="<?php echo EmailBranding::DEFAULT_ACCENT; ?>" name="<?php echo $name; ?>[<?php echo EmailBranding::ACCENT; ?>]" value="<?php echo esc_attr($b['accent']); ?>">
                        <p class="description"><?php esc_html_e('Links and the confirmation button.', 'mailoptin'); ?></p>
                    </div>
                </div>

                <div class="mo-branding-row">
                    <label for="mo-brand-background"><strong><?php esc_html_e('Background colour', 'mailoptin'); ?></strong></label>
                    <div>
                        <input type="text" class="mo-brand-color" id="mo-brand-background" data-default-color="<?php echo EmailBranding::DEFAULT_BACKGROUND; ?>" name="<?php echo $name; ?>[<?php echo EmailBranding::BACKGROUND; ?>]" value="<?php echo esc_attr($b['background']); ?>">
                        <p class="description"><?php esc_html_e('Around the white message card.', 'mailoptin'); ?></p>
                    </div>
                </div>
            </div>

            <div class="mo-branding-preview">
                <strong><?php esc_html_e('Preview', 'mailoptin'); ?></strong>
                <iframe id="mo-brand-preview" title="<?php esc_attr_e('Email preview', 'mailoptin'); ?>" srcdoc="<?php echo esc_attr(EmailBranding::sample_html()); ?>"></iframe>
                <p class="description">
                    <?php esc_html_e('Updates as you change the settings. Save before sending a test.', 'mailoptin'); ?>
                    <a href="<?php echo esc_url($test_url); ?>"><?php printf(esc_html__('Send test to %s', 'mailoptin'), esc_html(wp_get_current_user()->user_email)); ?></a>
                </p>
                <?php if ('sent' === $notice) : ?><p class="mo-brand-notice"><?php esc_html_e('Test email sent. Check your inbox.', 'mailoptin'); ?></p><?php endif; ?>
                <?php if ('failed' === $notice) : ?><p class="mo-brand-notice mo-brand-error"><?php esc_html_e('The test email could not be sent. Check your site can send email, for example with an SMTP plugin.', 'mailoptin'); ?></p><?php endif; ?>
            </div>
        </div>
        <style>
            .mo-branding{display:flex;gap:28px;align-items:flex-start;flex-wrap:wrap}
            .mo-branding-controls{flex:1 1 380px;min-width:0}
            .mo-branding-row{display:grid;grid-template-columns:150px 1fr;gap:12px;padding:12px 0;align-items:start}
            .mo-brand-logo-thumb{display:block}
            .mo-brand-logo-thumb img{max-height:44px;max-width:200px;border:1px solid #dcdcde;padding:6px;background:#fff;margin:0 0 8px}
            .mo-branding input#mo-brand-logo-width{width:90px !important;display:inline-block}
            .mo-brand-remove{color:#b32d2e;margin-left:8px}
            .mo-branding-preview{flex:1 1 360px;min-width:0;max-width:460px}
            #mo-brand-preview{width:100%;height:470px;border:1px solid #dcdcde;margin-top:8px;background:#fff}
            .mo-brand-notice{color:#00701a;font-weight:600}.mo-brand-error{color:#b32d2e}
        </style>
        <script>
            jQuery(function ($) {
                var frame = document.getElementById('mo-brand-preview');

                function preview() {
                    var doc = frame.contentDocument;
                    if (!doc || !doc.body) return;
                    var accent = $('#mo-brand-accent').val() || '<?php echo EmailBranding::DEFAULT_ACCENT; ?>',
                        background = $('#mo-brand-background').val() || '<?php echo EmailBranding::DEFAULT_BACKGROUND; ?>',
                        logo = $('.mo-brand-logo-thumb img').attr('src') || '',
                        width = parseInt($('#mo-brand-logo-width').val(), 10) || <?php echo EmailBranding::DEFAULT_LOGO_WIDTH; ?>,
                        align = $('input[name="<?php echo $name; ?>[<?php echo EmailBranding::LOGO_ALIGN; ?>]"]:checked').val() || 'left',
                        card = doc.body.firstElementChild, img = doc.querySelector('img');

                    doc.body.style.background = background;
                    doc.querySelectorAll('a').forEach(function (a) {
                        if (a.style.backgroundColor) a.style.background = accent; else a.style.color = accent;
                    });

                    if (!logo && img) img.parentNode.remove();
                    if (logo) {
                        if (!img) {
                            var wrap = doc.createElement('div');
                            wrap.style.margin = '0 0 20px';
                            img = doc.createElement('img');
                            img.style.maxWidth = '100%'; img.style.height = 'auto';
                            wrap.appendChild(img);
                            card.insertBefore(wrap, card.firstChild);
                        }
                        img.src = logo; img.style.width = width + 'px';
                        img.parentNode.style.textAlign = align;
                    }
                }

                frame.addEventListener('load', preview);
                $('.mo-brand-color').wpColorPicker({change: function () { setTimeout(preview, 0); }, clear: function () { setTimeout(preview, 0); }});
                $('#mo-brand-logo-width, input[name="<?php echo $name; ?>[<?php echo EmailBranding::LOGO_ALIGN; ?>]"]').on('input change', preview);

                var media;
                $('#mo-brand-logo-choose').on('click', function () {
                    media = media || wp.media({title: '<?php echo esc_js(__('Choose logo', 'mailoptin')); ?>', library: {type: 'image'}, multiple: false});
                    media.off('select').on('select', function () {
                        var file = media.state().get('selection').first().toJSON();
                        $('#mo-brand-logo-id').val(file.id);
                        $('.mo-brand-logo-thumb').html('<img alt="" src="' + file.url + '">');
                        $('#mo-brand-logo-remove').prop('hidden', false);
                        preview();
                    });
                    media.open();
                });
                $('#mo-brand-logo-remove').on('click', function () {
                    $('#mo-brand-logo-id').val('');
                    $('.mo-brand-logo-thumb').empty();
                    $(this).prop('hidden', true);
                    preview();
                });
            });
        </script>
        <?php
        return ob_get_clean();
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
                \MailOptin\Core\Audience\PreferenceCentre::LINK_SETTING => [
                    'type'        => 'select',
                    'label'       => __('Preference centre link', 'mailoptin'),
                    'options'     => ['on' => __('Add "Manage preferences" next to Unsubscribe in every email', 'mailoptin'), 'off' => __('Don\'t add it', 'mailoptin')],
                    'value'       => 'on',
                    'description' => __('Subscribers choose their lists and update their name and the custom fields you allow (Subscribers › Custom fields). Use {{preferences}} to place the link yourself.', 'mailoptin'),
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
            ],
            [
                'section_title'  => __('Email branding for built-in lists', 'mailoptin'),
                'email_branding' => [
                    'type'  => 'arbitrary',
                    'label' => __('Email branding', 'mailoptin'),
                    'data'  => $this->branding_html(),
                ],
            ],
            [
                'section_title'                                       => __('Tracking for built-in lists', 'mailoptin'),
                \MailOptin\Core\Audience\Tracking::OPENS_SETTING     => [
                    'type'        => 'select',
                    'label'       => __('Track opens', 'mailoptin'),
                    'options'     => ['on' => __('Count who opens each email', 'mailoptin'), 'off' => __('Don\'t track opens', 'mailoptin')],
                    'value'       => 'on',
                    'description' => __('Adds a tiny invisible image to each email. Opens are an estimate: some email apps load images for everyone, others block them.', 'mailoptin'),
                ],
                \MailOptin\Core\Audience\Tracking::CLICKS_SETTING    => [
                    'type'        => 'select',
                    'label'       => __('Track clicks', 'mailoptin'),
                    'options'     => ['on' => __('Count clicks on links', 'mailoptin'), 'off' => __('Don\'t track clicks', 'mailoptin')],
                    'value'       => 'on',
                    'description' => __('Links go through your site first, then on to their address. Unsubscribe and preference centre links are never changed.', 'mailoptin'),
                ],
                \MailOptin\Core\Audience\Tracking::RETENTION_SETTING => [
                    'type'        => 'select',
                    'label'       => __('Keep activity for', 'mailoptin'),
                    'options'     => ['180' => __('6 months', 'mailoptin'), '365' => __('1 year', 'mailoptin'), '730' => __('2 years', 'mailoptin'), '0' => __('Forever', 'mailoptin')],
                    'value'       => (string)\MailOptin\Core\Audience\Tracking::DEFAULT_RETENTION_DAYS,
                    'description' => __('Who opened and clicked is deleted after this; campaign totals are kept. Tracking collects personal data, so mention it in your privacy policy. Personal data exports and erasure requests include it.', 'mailoptin'),
                ],
            ],
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
