<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Admin\UI;
use MailOptin\Core\Audience\CampaignRenderer;
use MailOptin\Core\EmailCampaigns\Misc;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\Core\EmailCampaigns\PostsEmailDigest\PostsEmailDigest;
use MailOptin\Core\EmailCampaigns\PostsEmailDigest\Templatify;
use MailOptin\Core\Repositories\ConnectionsRepository;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\SubscribersRepository;
use MailOptin\Core\SendQueue\Mailer;
use MailOptin\Core\SendQueue\WpMailSender;

use function MailOptin\Core\current_user_has_privilege;
use function MailOptin\Core\do_admin_redirect;

/**
 * "Send now" for a Posts Digest: send the next digest straight away instead of waiting for its schedule.
 */
class Digest_Send_Page
{
    const VIEW = 'send-digest';

    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
        // MailOptin pages clear admin_notices on admin_init, then let their own notices back in here.
        add_action('mailoptin_admin_notices', function () {
            add_action('admin_notices', [$this, 'sent_notice']);
        });
    }

    /**
     * On the campaign log, after "Send now".
     */
    public function sent_notice()
    {
        if (empty($_GET['mo_digest_sent']) || MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG !== ($_GET['page'] ?? '')) return;

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('The digest is on its way. Its progress shows here in the campaign log.', 'mailoptin') . '</p></div>';
    }

    public static function is_enabled()
    {
        return (bool)apply_filters('mailoptin_enable_post_email_digest', false);
    }

    public static function url($email_campaign_id, $args = [])
    {
        return add_query_arg(['view' => self::VIEW, 'id' => absint($email_campaign_id)] + $args, MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE);
    }

    protected static function is_this_page()
    {
        return is_admin() && MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG === ($_GET['page'] ?? '') && self::VIEW === ($_GET['view'] ?? '');
    }

    protected static function campaign_id()
    {
        $email_campaign_id = absint($_REQUEST['id'] ?? 0);

        return $email_campaign_id && ER::POSTS_EMAIL_DIGEST === ER::get_email_campaign_type($email_campaign_id) ? $email_campaign_id : 0;
    }

    protected static function digest()
    {
        return PostsEmailDigest::get_instance();
    }

    /**
     * Site time, formatted for people.
     */
    protected static function when($datetime)
    {
        $timestamp = $datetime instanceof \DateTimeInterface ? $datetime->getTimestamp() : strtotime(get_gmt_from_date((string)$datetime));

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    /**
     * How many posts the next digest would carry, for the Emails list.
     */
    public static function waiting_count($email_campaign_id)
    {
        return count((array)self::digest()->post_collection($email_campaign_id));
    }

    /* ---------------------------------------------------------------------------------------------
     * Page
     * ------------------------------------------------------------------------------------------ */

    public static function html()
    {
        $back = '<p><a href="' . esc_url(MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE) . '">&larr; ' . esc_html__('Emails', 'mailoptin') . '</a></p>';

        if ( ! self::is_enabled()) {
            return '<div class="wrap mo-ui-page">' . $back . UI::note(esc_html__('Posts digests need MailOptin Premium.', 'mailoptin'), 'warn') . '</div>';
        }

        $email_campaign_id = self::campaign_id();

        if ( ! $email_campaign_id) {
            return '<div class="wrap mo-ui-page">' . $back . '<p>' . esc_html__('This digest no longer exists.', 'mailoptin') . '</p></div>';
        }

        $digest   = self::digest();
        $latest   = ! empty($_GET['latest']);
        $posts    = $latest ? $digest->latest_posts($email_campaign_id) : $digest->post_collection($email_campaign_id);
        $since    = $digest->posts_since($email_campaign_id);
        $next     = $digest->next_unskipped_at($email_campaign_id);
        $skipped  = $digest->next_scheduled_at($email_campaign_id);
        $skipped  = $skipped && $next && $skipped->notEqualTo($next) ? $skipped : null;
        $can_send = (bool)\MailOptin\Core\Connections\ConnectionFactory::make((string)ER::get_merged_customizer_value($email_campaign_id, 'connection_service'));
        $manual   = $digest->is_manual_only($email_campaign_id);
        $sent_ago = (string)$digest->last_processed_at($email_campaign_id);

        ob_start();
        ?>
        <style>
            .mo-digest-send { max-width: 760px; }
            .mo-digest-send .mo-ui-card { margin-bottom: 16px; }
            .mo-digest-send h2 { font-size: 15px; margin: 0 0 4px; }
            .mo-digest-send .mo-muted { color: #646970; margin: 0 0 12px; }
            .mo-digest-posts { border: 1px solid #dcdcde; border-radius: 6px; margin: 0; }
            .mo-digest-posts li { align-items: center; border-bottom: 1px solid #f0f0f1; display: flex; gap: 10px; margin: 0; padding: 9px 12px; }
            .mo-digest-posts li:last-child { border-bottom: 0; }
            .mo-digest-posts label { flex: 1; min-width: 0; }
            .mo-digest-posts time { color: #646970; white-space: nowrap; }
            .mo-digest-send .mo-choice { display: block; margin: 0 0 10px; }
            .mo-digest-send .mo-choice small { color: #646970; display: block; margin-left: 24px; }
            .mo-digest-send .mo-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        </style>
        <div class="wrap mo-ui-page mo-digest-send">
            <?php echo $back; ?>
            <div class="mo-ui-heading">
                <h1><?php echo esc_html(sprintf(__('Send “%s” now', 'mailoptin'), ER::get_email_campaign_name($email_campaign_id))); ?></h1>
            </div>
            <hr class="wp-header-end">
            <?php echo self::notice_html(); ?>

            <form method="post" action="<?php echo esc_url(self::url($email_campaign_id, $latest ? ['latest' => 1] : [])); ?>">
                <?php wp_nonce_field('mo_digest_send_' . $email_campaign_id); ?>
                <input type="hidden" name="id" value="<?php echo absint($email_campaign_id); ?>">
                <input type="hidden" name="include_latest" value="<?php echo $latest ? 1 : 0; ?>">

                <div class="mo-ui-card mo-ui-card-pad">
                    <h2><?php esc_html_e('Posts in this digest', 'mailoptin'); ?></h2>
                    <?php if ($latest) : ?>
                        <p class="mo-muted"><?php esc_html_e('The most recent posts that match the digest\'s settings, whenever they were published. Untick any you want to leave out.', 'mailoptin'); ?>
                            <a href="<?php echo esc_url(self::url($email_campaign_id)); ?>"><?php esc_html_e('Only new posts', 'mailoptin'); ?></a></p>
                    <?php else : ?>
                        <p class="mo-muted"><?php echo esc_html(sprintf(
                                '' !== $sent_ago
                                    ? __('Published since the last digest (%s). Untick any you want to leave out.', 'mailoptin')
                                    : __('Published since the digest was created (%s). Untick any you want to leave out.', 'mailoptin'),
                                self::when($since)
                            )); ?></p>
                    <?php endif; ?>

                    <?php if (empty($posts)) : ?>
                        <?php echo UI::note(esc_html__('No posts have been published since the last digest.', 'mailoptin'), 'warn'); ?>
                        <p><a class="mo-ui-btn" href="<?php echo esc_url(self::url($email_campaign_id, ['latest' => 1])); ?>"><?php esc_html_e('Include the latest posts anyway', 'mailoptin'); ?></a></p>
                    <?php else : ?>
                        <ul class="mo-digest-posts">
                            <?php foreach ($posts as $post) : ?>
                                <li>
                                    <input type="checkbox" name="post_ids[]" id="mo-post-<?php echo absint($post->ID); ?>" value="<?php echo absint($post->ID); ?>" checked>
                                    <label for="mo-post-<?php echo absint($post->ID); ?>"><?php echo esc_html(get_the_title($post) ?: __('(no title)', 'mailoptin')); ?></label>
                                    <time datetime="<?php echo esc_attr(get_post_time('c', true, $post)); ?>"><?php echo esc_html(get_the_date('', $post)); ?></time>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="mo-ui-card mo-ui-card-pad">
                    <h2><?php esc_html_e('Sends to', 'mailoptin'); ?></h2>
                    <p class="mo-muted" style="margin:0"><?php echo esc_html(self::audience_text($email_campaign_id)); ?></p>
                </div>

                <div class="mo-ui-card mo-ui-card-pad">
                    <?php if ($manual) : ?>
                        <h2><?php esc_html_e('Schedule', 'mailoptin'); ?></h2>
                        <p class="mo-muted" style="margin:0"><?php esc_html_e('This digest is only sent by hand, so there\'s no scheduled send to change.', 'mailoptin'); ?></p>
                    <?php elseif ($next) : ?>
                        <?php $after = $digest->next_scheduled_at($email_campaign_id, $next->addHours(2)); ?>
                        <h2><?php echo esc_html(sprintf(__('And the scheduled digest on %s?', 'mailoptin'), self::when($next))); ?></h2>
                        <?php if ($skipped) : ?>
                            <p class="mo-muted"><?php echo esc_html(sprintf(__('The one on %s is already skipped: an earlier send took its place.', 'mailoptin'), self::when($skipped))); ?></p>
                        <?php endif; ?>
                        <label class="mo-choice"><input type="radio" name="skip_next" value="1" checked> <?php esc_html_e('Skip it', 'mailoptin'); ?>
                            <small><?php echo esc_html($after
                                    ? sprintf(__('This send takes its place. The next scheduled digest goes out %s.', 'mailoptin'), self::when($after))
                                    : __('This send takes its place.', 'mailoptin')); ?></small></label>
                        <label class="mo-choice"><input type="radio" name="skip_next" value="0"> <?php esc_html_e('Still send it', 'mailoptin'); ?>
                            <small><?php esc_html_e('With only the posts published after now. If there are none, nothing is sent.', 'mailoptin'); ?></small></label>
                    <?php endif; ?>
                </div>

                <?php if ( ! empty($posts) && $can_send) : ?>
                    <div class="mo-ui-card mo-ui-card-pad">
                        <h2><?php esc_html_e('Send a test', 'mailoptin'); ?></h2>
                        <p class="mo-actions">
                            <label class="screen-reader-text" for="mo-test-email"><?php esc_html_e('Test email address', 'mailoptin'); ?></label>
                            <input type="email" id="mo-test-email" name="test_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
                            <button type="submit" class="mo-ui-btn" name="mo_digest_action" value="send_test"><?php esc_html_e('Send test', 'mailoptin'); ?></button>
                        </p>
                    </div>
                    <p class="mo-actions">
                        <button type="submit" class="mo-ui-btn mo-ui-primary" name="mo_digest_action" value="send"><?php echo esc_html(self::send_label($email_campaign_id)); ?></button>
                        <a class="mo-ui-btn mo-ui-ghost" href="<?php echo esc_url(MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE); ?>"><?php esc_html_e('Cancel', 'mailoptin'); ?></a>
                    </p>
                <?php endif; ?>
            </form>
        </div>
        <script>
            (function () {
                var form = document.querySelector('.mo-digest-send form');
                if (!form) return;
                form.addEventListener('submit', function (e) {
                    var button = e.submitter;
                    if (!button || 'send' !== button.value) return;
                    // Stop a second click sending it twice.
                    setTimeout(function () { button.disabled = true; }, 0);
                });
            })();
        </script>
        <?php
        return ob_get_clean();
    }

    protected static function audience_text($email_campaign_id)
    {
        $connection  = (string)ER::get_merged_customizer_value($email_campaign_id, 'connection_service');
        $list_id     = (string)ER::get_merged_customizer_value($email_campaign_id, 'connection_email_list');
        $connections = ConnectionsRepository::get_connections('email_campaign');
        $name        = $connections[$connection] ?? $connection;

        if ('' === $connection) return __('No email service is chosen yet. Choose one in the digest\'s settings.', 'mailoptin');

        $count = Broadcasts::recipients_count($email_campaign_id);

        if (is_int($count)) {
            $lists = ConnectionsRepository::connection_email_list($connection);
            $list  = $lists[$list_id] ?? '';

            return '' === $list
                ? sprintf(_n('%s subscriber', '%s subscribers', $count, 'mailoptin'), number_format_i18n($count))
                : sprintf(_n('%1$s · %2$s subscriber', '%1$s · %2$s subscribers', $count, 'mailoptin'), $list, number_format_i18n($count));
        }

        // Another service: it decides who gets it, from the list or audience chosen in the digest's settings.
        return sprintf(__('Your %s list or audience chosen in the digest\'s settings', 'mailoptin'), $name);
    }

    protected static function send_label($email_campaign_id)
    {
        $count = Broadcasts::recipients_count($email_campaign_id);

        return is_int($count)
            ? sprintf(_n('Send to %s subscriber', 'Send to %s subscribers', $count, 'mailoptin'), number_format_i18n($count))
            : __('Send now', 'mailoptin');
    }

    protected static function notice_html()
    {
        $html  = '';
        $error = get_transient('mo_digest_send_error_' . get_current_user_id());

        if ($error) {
            delete_transient('mo_digest_send_error_' . get_current_user_id());
            $html .= sprintf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($error));
        }

        $notice = sanitize_key($_GET['mo_notice'] ?? '');

        if ('test_sent' === $notice) {
            $html .= '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Test email sent. Check your inbox.', 'mailoptin') . '</p></div>';
        }

        if ('test_fail' === $notice) {
            $html .= '<div class="notice notice-error is-dismissible"><p>' . esc_html__('The test email could not be sent. Check the address, and that your site can send email.', 'mailoptin') . '</p></div>';
        }

        return $html;
    }

    /* ---------------------------------------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------------------------------------ */

    protected static function error($message)
    {
        set_transient('mo_digest_send_error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS);
    }

    protected static function redirect($url)
    {
        do_admin_redirect(apply_filters('mailoptin_digest_send_page_redirect', $url));
    }

    /**
     * @return \WP_Post[] the ticked posts, from those the digest would send
     */
    protected static function chosen_posts($email_campaign_id, $latest)
    {
        $available = $latest ? self::digest()->latest_posts($email_campaign_id) : self::digest()->post_collection($email_campaign_id);
        $wanted    = array_map('absint', array_filter((array)($_POST['post_ids'] ?? []), 'is_scalar'));

        return array_values(array_filter($available, function ($post) use ($wanted) {
            return in_array((int)$post->ID, $wanted, true);
        }));
    }

    public function handle_actions()
    {
        if ( ! self::is_this_page() || empty($_POST['mo_digest_action'])) return;

        $email_campaign_id = self::campaign_id();

        check_admin_referer('mo_digest_send_' . $email_campaign_id);

        if ( ! $email_campaign_id || ! current_user_has_privilege() || ! self::is_enabled()) {
            wp_die(esc_html__('You are not allowed to do this.', 'mailoptin'), 403);
        }

        $latest = ! empty($_POST['include_latest']);
        $back   = self::url($email_campaign_id, $latest ? ['latest' => 1] : []);
        $posts  = self::chosen_posts($email_campaign_id, $latest);

        switch (sanitize_key($_POST['mo_digest_action'])) {
            case 'send_test':
                if (empty($posts)) {
                    self::error(__('Choose at least one post to send.', 'mailoptin'));
                    self::redirect($back);
                    break;
                }

                $sent = self::send_test($email_campaign_id, $posts, sanitize_email(wp_unslash($_POST['test_email'] ?? '')));
                self::redirect(add_query_arg('mo_notice', $sent ? 'test_sent' : 'test_fail', $back));
                break;

            case 'send':
                $result = self::digest()->send_now($email_campaign_id, [
                    'post_ids'       => wp_list_pluck($posts, 'ID'),
                    'skip_next'      => '0' !== (string)($_POST['skip_next'] ?? '1'),
                    'include_latest' => $latest,
                ]);

                if (is_wp_error($result)) {
                    self::error($result->get_error_message());
                    self::redirect($back);
                    break;
                }

                self::redirect(add_query_arg('mo_digest_sent', absint($result), MAILOPTIN_CAMPAIGN_LOG_SETTINGS_PAGE));
                break;
        }
    }

    /**
     * Email the digest as it would go out with these posts, to one address.
     */
    public static function send_test($email_campaign_id, $posts, $email)
    {
        if ( ! is_email($email)) return false;

        $subscriber = SubscribersRepository::get_by_email($email) ?: [];
        $subject    = Misc::parse_email_subject(ER::get_merged_customizer_value($email_campaign_id, 'email_campaign_subject'));
        $subject    = apply_filters('mailoptin_digest_email_campaign_subject', $subject, $email_campaign_id, $posts);
        $html       = (string)(new Templatify($email_campaign_id, $posts))->forge();
        $blank      = ['{{unsubscribe}}' => '#', '{{webversion}}' => '#', '{{preferences}}' => '#', '{{list.name}}' => ''];
        $tags       = [
            '{{subscriber.email}}'      => $subscriber['email'] ?? '',
            '{{subscriber.first_name}}' => $subscriber['first_name'] ?? '',
            '{{subscriber.last_name}}'  => $subscriber['last_name'] ?? '',
        ];

        $html    = CampaignRenderer::replace_tags(CampaignRenderer::replace_fallback_tags($html, $subscriber, true), $blank + $tags, true);
        $subject = CampaignRenderer::replace_tags(CampaignRenderer::replace_fallback_tags($subject, $subscriber, false), $tags, false);

        return true === Mailer::send($email, sprintf(__('[Test] %s', 'mailoptin'), $subject), $html, WpMailSender::default_headers());
    }

    /**
     * @return Digest_Send_Page
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
