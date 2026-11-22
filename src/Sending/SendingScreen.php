<?php

namespace MailOptin\Core\Sending;

use MailOptin\Core\EmailSenders\Fields;
use MailOptin\Core\EmailSenders\SiteMailer;

/**
 * The parts of Settings › Sending and Settings › Email log for any SMTP server, Gmail, Microsoft 365,
 * the site's emails and the log.
 */
class SendingScreen
{
    public static function init()
    {
        add_action('mailoptin_sending_service_settings', [self::class, 'service_cards'], 5);
    }

    /* ---------------------------------------------------------------------------------------------
     * Service cards
     * ------------------------------------------------------------------------------------------ */

    protected static function notice_text($code)
    {
        $notices = [
            'tested'            => ['success', __('Connected. Emails go out through this service now.', 'mailoptin')],
            'test_failed'       => ['error', __('The connection test failed. The reason is below.', 'mailoptin')],
            'test_email_sent'   => ['success', __('Test email sent. Check your inbox.', 'mailoptin')],
            'test_email_failed' => ['error', __('The test email wasn\'t sent. The reason is below.', 'mailoptin')],
            'oauth_connected'   => ['success', __('Signed in and connected. Emails go out through this account now.', 'mailoptin')],
            'oauth_failed'      => ['error', __('Sign-in didn\'t finish. The reason is below.', 'mailoptin')],
            'oauth_needs_app'   => ['error', __('Enter the app\'s client ID and secret and save, then sign in.', 'mailoptin')],
            'disconnected'      => ['success', __('Signed out. Emails go through WordPress until you sign in again or choose another service.', 'mailoptin')],
        ];

        return $notices[$code] ?? null;
    }

    public static function service_cards($current)
    {
        $notice = self::notice_text(sanitize_key($_GET['mo_sending_notice'] ?? ''));
        if ($notice) {
            printf('<div class="notice notice-%s inline mo-sending-notice"><p>%s</p></div>', esc_attr($notice[0]), esc_html($notice[1]));
        }

        if (SendingStore::credentials_unreadable()) {
            printf('<div class="notice notice-error inline"><p>%s</p></div>', esc_html__('The saved sending settings can\'t be read: WordPress\'s security keys have changed since they were saved. Enter them again and save. Until then, emails go through WordPress.', 'mailoptin'));
        }

        $credentials = SendingStore::credentials_unreadable() ? [] : SendingStore::credentials();
        $state       = SendingStore::state();
        $active      = SendingStore::active();

        foreach (SendingStore::fields() as $service => $fields) {
            $config    = (array)($credentials[$service] ?? []);
            $test      = $state['tests'][$service] ?? null;
            $mail_test = $state['test_email'][$service] ?? null;
            $connected = ! empty($state['connected'][$service]);
            $oauth     = Fields::oauth($service, ['plugin' => 'MailOptin', 'translate' => function ($t) {
                return __($t, 'mailoptin'); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
            }]);
            $account   = SendingStore::account_email($service);
            ?>
            <div class="mo-service-card mo-free-service" data-service="<?php echo esc_attr($service); ?>" <?php echo $current === $service ? '' : 'hidden'; ?>>
                <div class="mo-card-head">
                    <h3><?php echo esc_html(SendingStore::label($service)); ?></h3>
                    <?php if ($connected) : ?><span class="mo-pill mo-ok"><?php esc_html_e('Connected', 'mailoptin'); ?></span>
                    <?php elseif ($test) : ?><span class="mo-pill mo-bad"><?php esc_html_e('Not connected', 'mailoptin'); ?></span><?php endif; ?>
                </div>

                <?php if ($oauth) : ?>
                    <details class="mo-oauth-steps" <?php echo '' === (string)($config['client_id'] ?? '') ? 'open' : ''; ?>>
                        <summary><?php echo esc_html(sprintf(__('Set up your %s app (once, about 10 minutes)', 'mailoptin'), 'google' === $oauth['provider'] ? 'Google' : 'Microsoft')); ?></summary>
                        <ol>
                            <?php foreach ($oauth['steps'] as $step) :
                                $parts = explode('%redirect_uri%', $step, 2); ?>
                                <li><?php echo esc_html($parts[0]); ?><?php if (isset($parts[1])) : ?><code class="mo-copy"><?php echo esc_html(Sending::redirect_uri()); ?></code><?php echo esc_html($parts[1]); ?><?php endif; ?></li>
                            <?php endforeach; ?>
                        </ol>
                        <p><a href="<?php echo esc_url($oauth['console']); ?>" target="_blank" rel="noopener"><?php echo esc_html('google' === $oauth['provider'] ? __('Open Google Cloud Console', 'mailoptin') : __('Open Microsoft Entra admin center', 'mailoptin')); ?></a></p>
                    </details>
                <?php endif; ?>

                <div class="mo-fields">
                    <?php foreach ($fields as $field => $definition) : self::field($service, $field, $definition, $config); endforeach; ?>
                </div>
                <p class="description"><?php esc_html_e('Passwords and secrets are stored encrypted. Saving tests the connection.', 'mailoptin'); ?></p>

                <?php if ($oauth) : ?>
                    <?php if ('' !== $account) : ?>
                        <div class="mo-account">
                            <span class="mo-account-dot" aria-hidden="true"><?php echo esc_html(strtoupper(substr($account, 0, 1))); ?></span>
                            <div><strong><?php echo esc_html($account); ?></strong><br><span class="description"><?php esc_html_e('Emails go out as this address.', 'mailoptin'); ?></span></div>
                            <a class="button" href="<?php echo esc_url(Sending::action_url('disconnect', $service)); ?>"><?php esc_html_e('Sign out', 'mailoptin'); ?></a>
                        </div>
                    <?php elseif ('' !== (string)($config['client_id'] ?? '') && '' !== (string)($config['client_secret'] ?? '')) : ?>
                        <p><a class="button button-primary" href="<?php echo esc_url(Sending::action_url('sign_in', $service)); ?>"><?php echo esc_html('google' === $oauth['provider'] ? __('Sign in with Google', 'mailoptin') : __('Sign in with Microsoft', 'mailoptin')); ?></a></p>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e('Enter the client ID and secret, save, then sign in.', 'mailoptin'); ?></p>
                    <?php endif; ?>
                    <p class="mo-note"><?php echo esc_html($oauth['limit']); ?></p>
                <?php endif; ?>

                <?php if ($test && empty($test['ok']) && ! empty($test['message'])) : ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html($test['message']); ?></p></div>
                <?php elseif ($test && ! empty($test['ok'])) : ?>
                    <div class="notice notice-success inline"><p><?php echo esc_html($test['message']); ?></p></div>
                <?php endif; ?>
                <?php if ($mail_test) : ?>
                    <div class="notice <?php echo $mail_test['ok'] ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html($mail_test['message']); ?></p></div>
                <?php endif; ?>

                <?php if ($current === $service && $active !== $service) : ?>
                    <p><em><?php echo esc_html(sprintf(__('Emails go through WordPress until %s connects.', 'mailoptin'), SendingStore::label($service))); ?></em></p>
                <?php endif; ?>

                <?php if (SendingStore::is_configured($service)) : ?>
                    <p class="mo-actions">
                        <a class="button" href="<?php echo esc_url(Sending::action_url('test', $service)); ?>"><?php esc_html_e('Test connection', 'mailoptin'); ?></a>
                        <a class="button" href="<?php echo esc_url(Sending::action_url('test_email', $service)); ?>"><?php echo esc_html(sprintf(__('Send a test email to %s', 'mailoptin'), wp_get_current_user()->user_email)); ?></a>
                    </p>
                <?php endif; ?>

                <?php if ('smtp' === $service) : ?>
                    <p class="mo-note"><?php esc_html_e('SMTP servers don\'t report bounces back to MailOptin: addresses that bounce stay on your list. For large lists, a sending service with bounce handling (Premium) keeps delivery healthy.', 'mailoptin'); ?></p>
                <?php endif; ?>
            </div>
            <?php
        }

        self::styles_and_script();
    }

    protected static function field($service, $field, array $definition, array $config)
    {
        $id     = 'mo-sending-' . $service . '-' . $field;
        $name   = 'mailoptin_settings[' . SendingStore::FIELD_PREFIX . $service . '_' . $field . ']';
        $type   = $definition['type'] ?? 'text';
        $value  = (string)($config[$field] ?? ($definition['default'] ?? ''));
        $saved  = 'secret' === $type && '' !== (string)($config[$field] ?? '');
        $help   = (string)($definition['help'] ?? '');
        ?>
        <div class="mo-field<?php echo 'choice' === $type ? ' mo-field-choice' : ''; ?>">
            <?php if ('choice' === $type) : ?>
                <span class="mo-label" id="<?php echo esc_attr($id); ?>-label"><?php echo esc_html($definition['label']); ?></span>
                <span class="mo-seg" role="radiogroup" aria-labelledby="<?php echo esc_attr($id); ?>-label">
                    <?php foreach ($definition['options'] as $option => $label) : ?>
                        <label><input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($option); ?>" <?php checked($value, (string)$option); ?>><span><?php echo esc_html($label); ?></span></label>
                    <?php endforeach; ?>
                </span>
            <?php else : ?>
                <label class="mo-label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($definition['label']); ?></label>
                <?php if ('select' === $type) : ?>
                    <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>">
                        <?php foreach ($definition['options'] as $option => $label) : ?>
                            <option value="<?php echo esc_attr($option); ?>" <?php selected($value, (string)$option); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ('secret' === $type) : ?>
                    <input class="regular-text" type="password" autocomplete="new-password" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="" placeholder="<?php echo $saved ? esc_attr__('Saved. Leave blank to keep it.', 'mailoptin') : ''; ?>">
                <?php else : ?>
                    <input class="regular-text" type="<?php echo 'number' === $type ? 'number' : 'text'; ?>" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr((string)($definition['placeholder'] ?? '')); ?>" <?php echo 'number' === $type ? 'min="1" max="65535"' : ''; ?>>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ('' !== $help) : ?><span class="description"><?php echo esc_html($help); ?></span><?php endif; ?>
        </div>
        <?php
    }

    protected static function styles_and_script()
    {
        ?>
        <style>
            .mo-service-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 20px;margin:12px 0;max-width:960px}
            .mo-card-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
            .mo-card-head h3{margin:0}
            .mo-pill{display:inline-block;padding:1px 8px;border-radius:99px;font-size:12px;font-weight:600}
            .mo-pill.mo-ok{background:#edfaef;color:#00701a}.mo-pill.mo-bad{background:#fcf0f1;color:#b32d2e}
            .mo-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:16px 0 8px}
            .mo-field{display:flex;flex-direction:column;gap:4px;min-width:0}
            .mo-field .regular-text,.mo-field select{width:100%;max-width:100%}
            .mo-field .description{color:#646970}
            .mo-label{font-weight:600}
            .mo-seg{display:inline-flex;border:1px solid #8c8f94;border-radius:4px;overflow:hidden;width:max-content;max-width:100%}
            .mo-seg label{margin:0;cursor:pointer}
            .mo-seg input{position:absolute;opacity:0;width:1px;height:1px}
            .mo-seg span{display:block;padding:6px 16px;min-height:20px;border-left:1px solid #8c8f94}
            .mo-seg label:first-child span{border-left:0}
            .mo-seg input:checked + span{background:#2271b1;color:#fff;font-weight:600}
            .mo-seg input:focus-visible + span{outline:2px solid #2271b1;outline-offset:-4px}
            .mo-oauth-steps{border:1px solid #dcdcde;border-radius:6px;padding:8px 12px;margin:16px 0 0}
            .mo-oauth-steps summary{cursor:pointer;font-weight:600;padding:4px 0}
            .mo-oauth-steps ol{margin:8px 0 0 20px}
            .mo-oauth-steps li{margin:4px 0;color:#50575e}
            .mo-copy{word-break:break-all}
            .mo-account{display:flex;gap:12px;align-items:center;flex-wrap:wrap;border:1px solid #dcdcde;border-radius:6px;padding:12px;margin:12px 0}
            .mo-account div{flex:1;min-width:0;overflow-wrap:anywhere}
            .mo-account-dot{width:32px;height:32px;border-radius:50%;background:#2271b1;color:#fff;display:grid;place-items:center;font-weight:600}
            .mo-note{color:#50575e;background:#f6f7f7;border-radius:4px;padding:8px 12px;margin:12px 0}
            .mo-actions{display:flex;gap:8px;flex-wrap:wrap}
            @media (max-width:782px){.mo-fields{grid-template-columns:minmax(0,1fr)}.mo-actions .button{min-height:44px;line-height:42px}}
        </style>
        <script>
            (function () {
                var radios = document.querySelectorAll('input[name="mailoptin_settings[<?php echo esc_js(\MailOptin\Core\Admin\SettingsPage\SendingSettings::SERVICE_SETTING); ?>]"]');
                radios.forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        document.querySelectorAll('.mo-service').forEach(function (card) {
                            card.classList.toggle('is-on', card.contains(radio));
                        });
                        document.querySelectorAll('.mo-service-card').forEach(function (card) {
                            card.hidden = card.getAttribute('data-service') !== radio.value;
                        });
                    });
                });
            })();
        </script>
        <?php
    }

    /* ---------------------------------------------------------------------------------------------
     * All of this site's emails
     * ------------------------------------------------------------------------------------------ */

    public static function site_mail_html()
    {
        $settings = Sending::settings();
        $current  = SiteMailer::current();
        $ours     = $current && Sending::OWNER === $current['owner'];
        $other    = $current && ! $ours ? (string)$current['label'] : '';
        $sender   = Sending::site_sender();
        $taken    = get_transient('mo_site_mail_taken_' . get_current_user_id());
        if ($taken) delete_transient('mo_site_mail_taken_' . get_current_user_id());

        $from_email = (string)($settings[Sending::SITE_FROM_EMAIL] ?? '');
        $from_name  = (string)($settings[Sending::SITE_FROM_NAME] ?? '');
        $force      = '1' === ($settings[Sending::SITE_FORCE_FROM] ?? '');
        $service    = SendingStore::chosen();
        $label      = SendingStore::is_ours($service) ? SendingStore::label($service) : (\MailOptin\Core\Admin\SettingsPage\SendingSettings::SERVICES[$service] ?? '');

        ob_start();
        ?>
        <div class="mo-site-mail">
            <?php if ($taken) : ?>
                <div class="notice notice-success inline"><p><?php echo esc_html(sprintf(__('MailOptin now sends this site\'s emails. %s no longer does.', 'mailoptin'), $taken)); ?></p></div>
            <?php endif; ?>

            <label class="mo-toggle-row" for="mo-site-mail-enabled">
                <input type="hidden" name="mailoptin_settings[<?php echo esc_attr(Sending::SITE_ENABLED); ?>]" value="">
                <input type="checkbox" id="mo-site-mail-enabled" name="mailoptin_settings[<?php echo esc_attr(Sending::SITE_ENABLED); ?>]" value="1" <?php checked($ours); ?>>
                <span>
                    <strong><?php echo esc_html($label && 'wp_mail' !== $service ? sprintf(__('Send all of this site\'s emails through %s', 'mailoptin'), $label) : __('Send all of this site\'s emails through MailOptin\'s sending service', 'mailoptin')); ?></strong>
                    <span class="description"><?php esc_html_e('Password resets, new-user emails, WooCommerce orders, contact forms: anything WordPress or another plugin sends.', 'mailoptin'); ?></span>
                </span>
            </label>

            <?php if ($other) : ?>
                <div class="notice notice-warning inline"><p><strong><?php echo esc_html(sprintf(__('%s sends this site\'s emails.', 'mailoptin'), $other)); ?></strong> <?php echo esc_html(sprintf(__('Only one plugin can. Turning this on and saving switches it off in %s.', 'mailoptin'), $other)); ?></p></div>
            <?php endif; ?>

            <?php if ($ours && ! $sender) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('No sending service is connected yet, so WordPress still sends the site\'s emails the usual way. Choose and connect one above.', 'mailoptin'); ?></p></div>
            <?php elseif ( ! $ours && 'wp_mail' === $service) : ?>
                <p class="description"><?php esc_html_e('Choose and connect a sending service above first.', 'mailoptin'); ?></p>
            <?php endif; ?>

            <div class="mo-fields">
                <div class="mo-field">
                    <label class="mo-label" for="mo-site-mail-from-email"><?php esc_html_e('From email', 'mailoptin'); ?></label>
                    <input class="regular-text" type="email" id="mo-site-mail-from-email" name="mailoptin_settings[<?php echo esc_attr(Sending::SITE_FROM_EMAIL); ?>]" value="<?php echo esc_attr($from_email); ?>" placeholder="<?php echo esc_attr($sender && method_exists($sender, 'default_from') ? (string)$sender->default_from() : ''); ?>">
                    <span class="description"><?php esc_html_e('Used when an email doesn\'t set one. Many servers only accept their own address.', 'mailoptin'); ?></span>
                </div>
                <div class="mo-field">
                    <label class="mo-label" for="mo-site-mail-from-name"><?php esc_html_e('From name', 'mailoptin'); ?></label>
                    <input class="regular-text" type="text" id="mo-site-mail-from-name" name="mailoptin_settings[<?php echo esc_attr(Sending::SITE_FROM_NAME); ?>]" value="<?php echo esc_attr($from_name); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                </div>
            </div>
            <label class="mo-check" for="mo-site-mail-force">
                <input type="hidden" name="mailoptin_settings[<?php echo esc_attr(Sending::SITE_FORCE_FROM); ?>]" value="">
                <input type="checkbox" id="mo-site-mail-force" name="mailoptin_settings[<?php echo esc_attr(Sending::SITE_FORCE_FROM); ?>]" value="1" <?php checked($force); ?>>
                <?php esc_html_e('Use this From address for every email, even when a plugin sets another', 'mailoptin'); ?>
            </label>
            <p class="description"><?php esc_html_e('Gmail, Microsoft 365 and most SMTP servers refuse or rewrite other From addresses.', 'mailoptin'); ?></p>

            <p class="mo-note"><?php printf(
                esc_html__('Every email is listed in %1$s for 30 days: who it went to, the subject and what happened, not the content. If the service refuses MailOptin\'s login, WordPress sends the email instead, so password resets still arrive. Plugins that change emails through WordPress\'s own mailer (DKIM signing, embedded images) don\'t apply while this is on.', 'mailoptin'),
                '<a href="' . esc_url(admin_url('admin.php?page=' . MAILOPTIN_SETTINGS_SETTINGS_SLUG . '&view=general#email_log')) . '">' . esc_html__('Email log', 'mailoptin') . '</a>'
            ); ?></p>
        </div>
        <style>
            .mo-site-mail{max-width:960px}
            .mo-toggle-row,.mo-check{display:flex;gap:10px;align-items:flex-start;margin:8px 0}
            .mo-toggle-row input,.mo-check input{margin-top:3px}
            .mo-toggle-row span{display:flex;flex-direction:column;gap:2px}
            .mo-site-mail .mo-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:16px 0 8px}
            .mo-site-mail .mo-field{display:flex;flex-direction:column;gap:4px;min-width:0}
            .mo-site-mail .regular-text{width:100%;max-width:100%}
            .mo-site-mail .description{color:#646970}
            .mo-site-mail .mo-label{font-weight:600}
            .mo-site-mail .mo-note{color:#50575e;background:#f6f7f7;border-radius:4px;padding:8px 12px;margin:12px 0}
            @media (max-width:782px){.mo-site-mail .mo-fields{grid-template-columns:minmax(0,1fr)}}
        </style>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------------------------------
     * Email log
     * ------------------------------------------------------------------------------------------ */

    public static function email_log_html()
    {
        $view     = 'failed' === ($_GET['log_view'] ?? '') ? 'failed' : 'all';
        $page     = max(1, absint($_GET['log_page'] ?? 1));
        $per_page = 30;
        $all      = EmailLog::count('all');
        $failed   = EmailLog::count('failed');
        $total    = 'failed' === $view ? $failed : $all;
        $rows     = $total ? EmailLog::rows($view, '', $page, $per_page) : [];
        $pages    = max(1, (int)ceil($total / $per_page));
        $base     = admin_url('admin.php?page=' . MAILOPTIN_SETTINGS_SETTINGS_SLUG . '&view=general');
        $link     = function ($args) use ($base) {
            return esc_url(add_query_arg($args, $base) . '#email_log');
        };
        $statuses = [
            'sent'     => ['mo-ok', __('Sent', 'mailoptin')],
            'failed'   => ['mo-bad', __('Failed', 'mailoptin')],
            'fallback' => ['mo-warn', __('Sent by WordPress', 'mailoptin')],
        ];

        ob_start();
        ?>
        <div class="mo-email-log">
            <p class="description"><?php echo esc_html(sprintf(__('Every email this site sent through MailOptin\'s sending service in the last %d days. The content isn\'t kept.', 'mailoptin'), EmailLog::KEEP_DAYS)); ?></p>
            <ul class="subsubsub">
                <li><a href="<?php echo $link(['log_view' => 'all']); ?>" <?php echo 'all' === $view ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e('All', 'mailoptin'); ?> <span class="count">(<?php echo esc_html(number_format_i18n($all)); ?>)</span></a> |</li>
                <li><a href="<?php echo $link(['log_view' => 'failed']); ?>" <?php echo 'failed' === $view ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e('Failed', 'mailoptin'); ?> <span class="count">(<?php echo esc_html(number_format_i18n($failed)); ?>)</span></a></li>
            </ul>
            <?php if ( ! $rows) : ?>
                <div class="mo-log-empty">
                    <strong><?php echo 'failed' === $view && $all ? esc_html__('No failed emails.', 'mailoptin') : esc_html__('No emails logged yet.', 'mailoptin'); ?></strong>
                    <p><?php esc_html_e('Emails appear here once "Send all of this site\'s emails" is on (Sending tab) and the site sends something.', 'mailoptin'); ?></p>
                </div>
            <?php else : ?>
                <div class="mo-log-wrap">
                    <table class="widefat striped mo-log-table">
                        <colgroup><col style="width:130px"><col style="width:26%"><col><col style="width:120px"><col style="width:26%"></colgroup>
                        <thead><tr><th scope="col"><?php esc_html_e('Sent', 'mailoptin'); ?></th><th scope="col"><?php esc_html_e('To', 'mailoptin'); ?></th><th scope="col"><?php esc_html_e('Subject', 'mailoptin'); ?></th><th scope="col"><?php esc_html_e('From', 'mailoptin'); ?></th><th scope="col"><?php esc_html_e('Result', 'mailoptin'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $row) : $s = $statuses[$row['status']] ?? $statuses['failed']; ?>
                            <tr>
                                <td class="mo-log-when"><?php echo esc_html(wp_date(get_option('date_format') . ', ' . get_option('time_format'), strtotime($row['sent_at'] . ' UTC'))); ?></td>
                                <td class="mo-log-to"><?php echo esc_html($row['recipients']); ?></td>
                                <td><?php echo esc_html($row['subject']); ?></td>
                                <td><?php echo esc_html(EmailLog::source_label($row['source'])); ?></td>
                                <td><span class="mo-pill <?php echo esc_attr($s[0]); ?>"><?php echo esc_html($s[1]); ?></span>
                                    <?php if ('sent' !== $row['status'] && '' !== (string)$row['error']) : ?><span class="mo-log-error"><?php echo esc_html($row['error']); ?></span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pages > 1) : ?>
                    <p class="mo-log-pages">
                        <?php if ($page > 1) : ?><a class="button" href="<?php echo $link(['log_view' => $view, 'log_page' => $page - 1]); ?>"><?php esc_html_e('Newer', 'mailoptin'); ?></a><?php endif; ?>
                        <span><?php echo esc_html(sprintf(__('Page %1$s of %2$s', 'mailoptin'), number_format_i18n($page), number_format_i18n($pages))); ?></span>
                        <?php if ($page < $pages) : ?><a class="button" href="<?php echo $link(['log_view' => $view, 'log_page' => $page + 1]); ?>"><?php esc_html_e('Older', 'mailoptin'); ?></a><?php endif; ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <style>
            .mo-email-log .subsubsub{float:none;margin:8px 0 12px;text-align:left}
            .mo-log-wrap{overflow-x:auto;max-width:100%}
            .mo-log-table{min-width:640px;table-layout:fixed}
            .form-table .mo-log-table th,.form-table .mo-log-table td{width:auto;padding:8px 12px;vertical-align:top;line-height:1.5}
            .mo-log-when{color:#646970;font-variant-numeric:tabular-nums}
            .mo-log-to{overflow-wrap:break-word}
            .mo-log-error{display:block;color:#b32d2e;font-size:12px;margin-top:4px;overflow-wrap:anywhere}
            .mo-email-log .mo-pill{display:inline-block;padding:1px 8px;border-radius:99px;font-size:12px;font-weight:600;white-space:nowrap}
            .mo-email-log .mo-ok{background:#edfaef;color:#00701a}.mo-email-log .mo-bad{background:#fcf0f1;color:#b32d2e}.mo-email-log .mo-warn{background:#fcf9e8;color:#7a5a00}
            .mo-log-empty{border:1px dashed #c3c4c7;border-radius:6px;padding:24px;text-align:center;color:#50575e}
            .mo-log-pages{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
            .mo-log-to{word-break:normal}
            /* Nothing to save on this tab. */
            #email_log p:has(> input[name="save_mailoptin_settings"]){display:none}
            @media (max-width:782px){
                .mo-log-table{min-width:0;border:0;background:none}
                .mo-log-table thead{display:none}
                .mo-log-table,.mo-log-table tbody{display:block}
                .mo-log-table tr{display:grid;grid-template-columns:minmax(0,1fr);gap:4px;padding:12px;margin:0 0 8px;border:1px solid #c3c4c7;border-radius:6px;background:#fff}
                .mo-log-table.striped>tbody>:nth-child(odd){background:#fff}
                .mo-log-table td{display:block;padding:0;border:0}
                .mo-log-table td:nth-child(3){font-weight:600}
                .mo-log-table td:nth-child(4){color:#646970}
                .mo-log-when{white-space:normal}
                .mo-log-pages .button{min-height:44px;line-height:42px}
            }
        </style>
        <?php
        return ob_get_clean();
    }
}
