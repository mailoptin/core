<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Admin\Customizer\EmailCampaign\NewsletterTemplatePreview;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\Core\PluginSettings\Settings;
use MailOptin\Core\Repositories\ConnectionsRepository;
use MailOptin\Core\Repositories\EmailCampaignMeta;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\SegmentsRepository as Segments;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use MailOptin\Core\SendQueue\QueueRunner;
use MailOptin\MailOptinConnect\CampaignAudience;

use function MailOptin\Core\current_user_has_privilege;
use function MailOptin\Core\do_admin_redirect;

/**
 * A broadcast's own page: subject, sender, audience and sending are set here; the email
 * customizer opens over the page to design the email.
 */
class Broadcast_Page
{
    const VIEW = 'broadcast';

    // Added to the customizer's address when it is opened from this page.
    const FROM_PAGE_ARG = 'mo_broadcast_page';

    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public static function url($email_campaign_id, $args = [])
    {
        return add_query_arg(['view' => self::VIEW, 'id' => absint($email_campaign_id)] + $args, MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE);
    }

    public static function design_url($email_campaign_id)
    {
        return add_query_arg([self::FROM_PAGE_ARG => 1, 'return' => rawurlencode(self::url($email_campaign_id, ['designed' => 1]))], Email_Campaign_List::_campaign_customize_url($email_campaign_id));
    }

    protected static function is_this_page()
    {
        return is_admin() && MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG === ($_GET['page'] ?? '') && self::VIEW === ($_GET['view'] ?? '');
    }

    protected static function campaign_id()
    {
        $email_campaign_id = absint($_REQUEST['id'] ?? 0);

        return $email_campaign_id && ER::is_newsletter($email_campaign_id) ? $email_campaign_id : 0;
    }

    public function enqueue()
    {
        if ( ! self::is_this_page()) return;

        // Opens the customizer over the page, as the Themes screen does.
        wp_enqueue_script('customize-loader');
    }

    /* ---------------------------------------------------------------------------------------------
     * Page
     * ------------------------------------------------------------------------------------------ */

    public static function html()
    {
        $email_campaign_id = self::campaign_id();

        if ( ! $email_campaign_id) {
            return '<div class="wrap"><p>' . esc_html__('This broadcast no longer exists.', 'mailoptin') . '</p><p><a href="' . esc_url(MAILOPTIN_EMAIL_NEWSLETTERS_SETTINGS_PAGE) . '">&larr; ' . esc_html__('Broadcasts', 'mailoptin') . '</a></p></div>';
        }

        $status     = Broadcasts::status($email_campaign_id);
        $editable   = in_array($status['status'], [Broadcasts::STATUS_DRAFT, Broadcasts::STATUS_SCHEDULED, Broadcasts::STATUS_FAILED], true);
        $connection = (string)Broadcasts::get($email_campaign_id, 'connection_service');
        $settings   = Settings::instance();
        $subject    = Broadcasts::subject($email_campaign_id);
        $preheader  = (string)Broadcasts::get($email_campaign_id, 'email_campaign_preheader');
        $checks     = Broadcasts::checks($email_campaign_id);
        $test_email = (string)(Broadcasts::get($email_campaign_id, 'send_test_email_input') ?: wp_get_current_user()->user_email);

        ob_start();
        ?>
        <?php echo self::styles(); ?>
        <div class="wrap mo-broadcast">
            <p class="mo-back"><a href="<?php echo esc_url(MAILOPTIN_EMAIL_NEWSLETTERS_SETTINGS_PAGE); ?>">&larr; <?php esc_html_e('Broadcasts', 'mailoptin'); ?></a></p>
            <div class="mo-broadcast-head">
                <h1><?php echo esc_html(ER::get_email_campaign_name($email_campaign_id)); ?></h1>
                <?php echo self::status_pill($status); ?>
            </div>
            <?php echo self::notice_html($email_campaign_id, $status); ?>

            <form method="post" action="<?php echo esc_url(self::url($email_campaign_id)); ?>" id="mo-broadcast-form">
                <?php wp_nonce_field('mo_broadcast_' . $email_campaign_id); ?>
                <input type="hidden" name="id" value="<?php echo absint($email_campaign_id); ?>">
                <fieldset class="mo-broadcast-cols" <?php disabled( ! $editable); ?>>
                    <div class="mo-broadcast-main">
                        <div class="mo-card">
                            <h3><?php esc_html_e('Email', 'mailoptin'); ?></h3>
                            <div class="mo-row">
                                <label for="mo-subject"><?php esc_html_e('Subject', 'mailoptin'); ?></label>
                                <div>
                                    <input type="text" id="mo-subject" name="subject" class="widefat" value="<?php echo esc_attr($subject); ?>" maxlength="200" data-count="60">
                                    <p class="mo-hint"><span><?php esc_html_e('Merge tags work here, like {{subscriber.first_name|friend}}.', 'mailoptin'); ?></span><span class="mo-counter"></span></p>
                                </div>
                            </div>
                            <div class="mo-row">
                                <label for="mo-preheader"><?php esc_html_e('Preview text', 'mailoptin'); ?></label>
                                <div>
                                    <input type="text" id="mo-preheader" name="preheader" class="widefat" value="<?php echo esc_attr($preheader); ?>" maxlength="200" data-count="100">
                                    <p class="mo-hint"><span><?php esc_html_e('Shown after the subject in most inboxes.', 'mailoptin'); ?></span><span class="mo-counter"></span></p>
                                </div>
                            </div>
                            <div class="mo-row">
                                <span class="mo-label"><?php esc_html_e('From', 'mailoptin'); ?></span>
                                <div>
                                    <p class="mo-static"><?php echo esc_html(sprintf('%s <%s>', $settings->from_name(), $settings->from_email())); ?>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . MAILOPTIN_SETTINGS_SETTINGS_SLUG)); ?>"><?php esc_html_e('Change in Settings', 'mailoptin'); ?></a></p>
                                </div>
                            </div>
                            <div class="mo-row">
                                <label for="mo-name"><?php esc_html_e('Internal name', 'mailoptin'); ?></label>
                                <div>
                                    <input type="text" id="mo-name" name="name" class="widefat" value="<?php echo esc_attr(ER::get_email_campaign_name($email_campaign_id)); ?>" maxlength="200" required>
                                    <p class="mo-hint"><span><?php esc_html_e('Only you see this.', 'mailoptin'); ?></span></p>
                                </div>
                            </div>
                        </div>

                        <?php echo self::audience_html($email_campaign_id, $connection); ?>

                        <div class="mo-card">
                            <h3><?php esc_html_e('Content', 'mailoptin'); ?></h3>
                            <div class="mo-design">
                                <a class="mo-thumb" href="<?php echo esc_url(self::design_url($email_campaign_id)); ?>" data-design aria-label="<?php esc_attr_e('Design email', 'mailoptin'); ?>">
                                    <iframe title="<?php esc_attr_e('Email preview', 'mailoptin'); ?>" sandbox="" tabindex="-1" srcdoc="<?php echo htmlspecialchars((string)(new NewsletterTemplatePreview($email_campaign_id))->forge(), ENT_QUOTES, 'UTF-8', true); ?>"></iframe>
                                    <span><?php esc_html_e('Design email', 'mailoptin'); ?></span>
                                </a>
                                <div>
                                    <?php if ($editable) : ?>
                                        <p><a class="button button-primary button-hero" href="<?php echo esc_url(self::design_url($email_campaign_id)); ?>" data-design><?php esc_html_e('Design email', 'mailoptin'); ?></a></p>
                                        <p class="description"><?php esc_html_e('Opens the email designer over this page. Your changes here are saved first. Close the designer to come back.', 'mailoptin'); ?></p>
                                    <?php endif; ?>
                                    <h4><?php esc_html_e('Send a test', 'mailoptin'); ?></h4>
                                    <p class="mo-inline">
                                        <label class="screen-reader-text" for="mo-test-email"><?php esc_html_e('Test email address', 'mailoptin'); ?></label>
                                        <input type="email" id="mo-test-email" name="test_email" value="<?php echo esc_attr($test_email); ?>">
                                        <button type="submit" class="button" name="mo_broadcast_action" value="send_test" formnovalidate><?php esc_html_e('Send test', 'mailoptin'); ?></button>
                                    </p>
                                    <p class="description"><?php esc_html_e('Merge tags use the subscriber with that address, or their fallbacks.', 'mailoptin'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mo-broadcast-side">
                        <?php echo self::send_html($email_campaign_id, $status, $checks); ?>
                    </div>
                </fieldset>
            </form>
            <?php if (isset($_GET['review']) && $editable) echo self::review_html($email_campaign_id, $checks); ?>
        </div>
        <?php echo self::script($email_campaign_id); ?>
        <?php
        return ob_get_clean();
    }

    protected static function status_pill($status)
    {
        $labels = [
            Broadcasts::STATUS_DRAFT     => [__('Draft', 'mailoptin'), ''],
            Broadcasts::STATUS_SCHEDULED => [__('Scheduled', 'mailoptin'), 'info'],
            Broadcasts::STATUS_SENDING   => [__('Sending', 'mailoptin'), 'info'],
            Broadcasts::STATUS_SENT      => [__('Sent', 'mailoptin'), 'ok'],
            Broadcasts::STATUS_FAILED    => [__('Failed', 'mailoptin'), 'bad'],
        ];

        list($label, $class) = $labels[$status['status']];

        return sprintf('<span class="mo-pill %s">%s</span>', esc_attr($class), esc_html($label));
    }

    protected static function local_time($utc)
    {
        return get_date_from_gmt($utc, get_option('date_format') . ' ' . get_option('time_format'));
    }

    protected static function notice_html($email_campaign_id, $status)
    {
        $html    = '';
        $notices = [
            'saved'     => ['success', __('Saved.', 'mailoptin')],
            'test_sent' => ['success', __('Test email sent. Check your inbox.', 'mailoptin')],
            'test_fail' => ['error', __('The test email could not be sent. Check the address, and that your site can send email.', 'mailoptin')],
            'scheduled' => ['success', __('Scheduled.', 'mailoptin')],
            'cancelled' => ['success', __('The scheduled send was cancelled. The broadcast is a draft again.', 'mailoptin')],
            'sending'   => ['success', __('Sending. Follow its progress in the campaign log.', 'mailoptin')],
            'blocked'   => ['error', __('It can\'t be sent yet. Fix the items marked in "Before it goes".', 'mailoptin')],
        ];

        $notice = sanitize_key($_GET['mo_notice'] ?? '');

        if (isset($notices[$notice])) {
            $html .= sprintf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notices[$notice][0]), esc_html($notices[$notice][1]));
        }

        $error = get_transient('mo_broadcast_error_' . get_current_user_id());

        if ($error) {
            delete_transient('mo_broadcast_error_' . get_current_user_id());
            $html .= sprintf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($error));
        }

        $scheduled_error = EmailCampaignMeta::get_meta_data($email_campaign_id, Broadcasts::ERROR_META);

        if ($scheduled_error && Broadcasts::STATUS_SCHEDULED !== $status['status']) {
            $html .= sprintf('<div class="notice notice-error"><p>%s</p></div>', esc_html(sprintf(__('The scheduled send didn\'t go out: %s', 'mailoptin'), $scheduled_error)));
        }

        if (in_array($status['status'], [Broadcasts::STATUS_SENDING, Broadcasts::STATUS_SENT], true)) {
            $report = $status['log'] && \MailOptin\Core\Repositories\CampaignActivityRepository::is_ready() && \MailOptin\Core\Repositories\CampaignActivityRepository::sent_count($status['log']['id']) ? sprintf(' <a href="%s">%s</a>', esc_url(Campaign_Report::url($status['log']['id'])), esc_html__('See the report', 'mailoptin')) : '';
            $html  .= sprintf(
                '<div class="notice notice-info"><p>%s%s</p></div>',
                esc_html(Broadcasts::STATUS_SENT === $status['status'] ? __('This broadcast was sent, so it can\'t be changed. Duplicate it to send another.', 'mailoptin') : __('This broadcast is sending, so it can\'t be changed.', 'mailoptin')),
                $report
            );
        }

        return $html;
    }

    protected static function audience_html($email_campaign_id, $connection)
    {
        $connections = ConnectionsRepository::get_connections('email_campaign');
        $lists       = $connection ? ConnectionsRepository::connection_email_list($connection) : [];
        $list_id     = (string)Broadcasts::get($email_campaign_id, 'connection_email_list');
        $builtin     = 'MailOptinConnect' === $connection;

        ob_start();
        ?>
        <div class="mo-card">
            <h3><?php esc_html_e('Audience', 'mailoptin'); ?></h3>
            <div class="mo-row">
                <label for="mo-connection"><?php esc_html_e('Send with', 'mailoptin'); ?></label>
                <div class="mo-inline">
                    <select id="mo-connection" name="connection_service" data-autosave>
                        <?php foreach ($connections as $key => $label) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($connection, $key); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button mo-no-js" name="mo_broadcast_action" value="save"><?php esc_html_e('Update', 'mailoptin'); ?></button>
                </div>
            </div>
            <?php if ( ! empty($lists)) : ?>
                <div class="mo-row">
                    <label for="mo-list"><?php esc_html_e('List', 'mailoptin'); ?></label>
                    <div>
                        <select id="mo-list" name="connection_email_list" data-autosave>
                            <option value=""><?php esc_html_e('Choose a list…', 'mailoptin'); ?></option>
                            <?php foreach ($lists as $key => $label) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($list_id, (string)$key); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($builtin && Segments::is_ready() && Tags::is_ready()) : ?>
                <?php echo self::builtin_audience_html($email_campaign_id); ?>
            <?php elseif ($connection && ! $builtin) : ?>
                <p class="mo-count"><?php printf(
                        esc_html__('%s sends this broadcast and keeps its report. Options of its own, like its segments or tags, are under Recipient in the email designer.', 'mailoptin'),
                        esc_html($connections[$connection] ?? $connection)
                    ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function builtin_audience_html($email_campaign_id)
    {
        $segment_id  = absint(Broadcasts::get($email_campaign_id, Segments::SEGMENT_SETTING));
        $excluded    = (array)Segments::excluded_tag_ids($email_campaign_id);
        $available   = Segments::is_available();
        $count       = Broadcasts::recipients_count($email_campaign_id);

        ob_start();
        if ($available || $segment_id || ! empty($excluded)) : ?>
            <div class="mo-row">
                <label for="mo-segment"><?php esc_html_e('Send to', 'mailoptin'); ?></label>
                <div>
                    <select id="mo-segment" name="segment" data-autosave>
                        <option value=""><?php esc_html_e('Everyone in the list', 'mailoptin'); ?></option>
                        <?php foreach (Segments::names() as $id => $name) : ?>
                            <option value="<?php echo absint($id); ?>" <?php selected($segment_id, (int)$id); ?>><?php echo esc_html(sprintf(__('%s (segment)', 'mailoptin'), $name)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mo-hint"><span><a href="<?php echo esc_url(Subscribers_Page::page_url('segments')); ?>"><?php esc_html_e('Manage segments', 'mailoptin'); ?></a></span></p>
                </div>
            </div>
            <?php $tags = Tags::names(); if ( ! empty($tags)) : ?>
                <div class="mo-row">
                    <span class="mo-label"><?php esc_html_e('Leave out people tagged', 'mailoptin'); ?></span>
                    <div class="mo-tag-checks">
                        <input type="hidden" name="exclude_tags[]" value="">
                        <?php foreach ($tags as $id => $name) : ?>
                            <label><input type="checkbox" name="exclude_tags[]" value="<?php echo absint($id); ?>" data-autosave <?php checked(in_array((int)$id, $excluded, true)); ?>> <?php echo esc_html($name); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ( ! $available) : ?>
                <p class="mo-count"><?php printf(esc_html__('Segments and leaving out tags need %sMailOptin Premium%s. Choose "Everyone in the list" and untick the tags to send this broadcast.', 'mailoptin'), '<a target="_blank" href="' . esc_url(CampaignAudience::upsell_url('builtin_segments_broadcast')) . '">', '</a>'); ?></p>
            <?php endif; ?>
        <?php else : ?>
            <p class="mo-count mo-upsell"><?php printf(
                    esc_html__('This broadcast goes to everyone in the list. %sMailOptin Premium%s lets you send to a saved segment instead and leave out people with certain tags.', 'mailoptin'),
                    '<a target="_blank" href="' . esc_url(CampaignAudience::upsell_url('builtin_segments_broadcast')) . '">',
                    '</a>'
                ); ?></p>
        <?php endif; ?>
        <?php if (null !== $count) : ?>
            <p class="mo-count"><?php echo is_wp_error($count)
                    ? esc_html($count->get_error_message())
                    : wp_kses(sprintf(_n('<b>Goes to %s subscriber</b> if it sends now. Worked out again when it sends.', '<b>Goes to %s subscribers</b> if it sends now. Worked out again when it sends.', $count, 'mailoptin'), number_format_i18n($count)), ['b' => []]); ?></p>
        <?php endif;

        return ob_get_clean();
    }

    protected static function send_html($email_campaign_id, $status, $checks)
    {
        $scheduled = $status['scheduled_at'];
        $local     = $scheduled ? get_date_from_gmt($scheduled, 'Y-m-d H:i') : current_time('Y-m-d') . ' 09:00';
        list($date, $time) = explode(' ', $local);

        ob_start();
        ?>
        <div class="mo-card">
            <h3><?php esc_html_e('Send', 'mailoptin'); ?></h3>
            <?php if ($scheduled) : ?>
                <p><?php echo esc_html(sprintf(__('Scheduled for %s.', 'mailoptin'), self::local_time($scheduled))); ?></p>
                <p><button type="submit" class="button" name="mo_broadcast_action" value="cancel_schedule" formnovalidate><?php esc_html_e('Cancel the scheduled send', 'mailoptin'); ?></button></p>
            <?php endif; ?>
            <div class="mo-when">
                <label><input type="radio" name="when" value="now" <?php checked( ! $scheduled); ?>> <?php esc_html_e('Send now', 'mailoptin'); ?></label>
                <label><input type="radio" name="when" value="later" <?php checked((bool)$scheduled); ?>> <?php echo $scheduled ? esc_html__('Change the time', 'mailoptin') : esc_html__('Schedule', 'mailoptin'); ?></label>
                <div class="mo-when-at">
                    <label class="screen-reader-text" for="mo-send-date"><?php esc_html_e('Date', 'mailoptin'); ?></label>
                    <input type="date" id="mo-send-date" name="send_date" value="<?php echo esc_attr($date); ?>">
                    <label class="screen-reader-text" for="mo-send-time"><?php esc_html_e('Time', 'mailoptin'); ?></label>
                    <input type="time" id="mo-send-time" name="send_time" value="<?php echo esc_attr($time); ?>">
                    <p class="mo-hint"><span><?php echo esc_html(sprintf(__('Site time (%s)', 'mailoptin'), wp_timezone_string())); ?></span></p>
                </div>
            </div>
            <h4><?php esc_html_e('Before it goes', 'mailoptin'); ?></h4>
            <ul class="mo-checks">
                <?php foreach ($checks as $check) : ?>
                    <li class="<?php echo $check['ok'] ? 'ok' : ($check['blocking'] ? 'bad' : 'warn'); ?>"><?php echo esc_html($check['label']); ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="mo-actions">
                <button type="submit" class="button" name="mo_broadcast_action" value="save"><?php esc_html_e('Save draft', 'mailoptin'); ?></button>
                <button type="submit" class="button button-primary" name="mo_broadcast_action" value="review"><?php esc_html_e('Review and send…', 'mailoptin'); ?></button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function review_html($email_campaign_id, $checks)
    {
        $settings = Settings::instance();
        $count    = Broadcasts::recipients_count($email_campaign_id);
        $later    = 'later' === ($_GET['when'] ?? '');
        $at       = $later ? self::local_time(gmdate('Y-m-d H:i:s', absint($_GET['at'] ?? 0))) : '';
        $hourly   = QueueRunner::hourly_limit();
        $blocked  = ! Broadcasts::can_send($email_campaign_id);
        $tested   = (bool)EmailCampaignMeta::get_meta_data($email_campaign_id, Broadcasts::TEST_META);

        ob_start();
        ?>
        <div class="mo-review-dim"></div>
        <div class="mo-review" role="dialog" aria-modal="true" aria-labelledby="mo-review-title">
            <h2 id="mo-review-title"><?php echo $later ? esc_html__('Schedule this broadcast?', 'mailoptin') : esc_html__('Send this broadcast now?', 'mailoptin'); ?></h2>
            <p class="mo-muted"><?php esc_html_e('It can\'t be unsent. You can pause it from the campaign log while it\'s sending.', 'mailoptin'); ?></p>
            <dl>
                <dt><?php esc_html_e('Subject', 'mailoptin'); ?></dt><dd><?php echo esc_html(Broadcasts::subject($email_campaign_id) ?: '—'); ?></dd>
                <dt><?php esc_html_e('From', 'mailoptin'); ?></dt><dd><?php echo esc_html(sprintf('%s <%s>', $settings->from_name(), $settings->from_email())); ?></dd>
                <?php if (is_int($count)) : ?>
                    <dt><?php esc_html_e('To', 'mailoptin'); ?></dt><dd><?php echo esc_html(sprintf(_n('%s subscriber', '%s subscribers', $count, 'mailoptin'), number_format_i18n($count))); ?></dd>
                    <?php if ($hourly && $count > $hourly) : ?>
                        <dt><?php esc_html_e('Sending', 'mailoptin'); ?></dt><dd><?php echo esc_html(sprintf(__('About %1$s hours at %2$s emails an hour', 'mailoptin'), number_format_i18n(ceil($count / $hourly)), number_format_i18n($hourly))); ?></dd>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($later) : ?>
                    <dt><?php esc_html_e('When', 'mailoptin'); ?></dt><dd><?php echo esc_html($at); ?></dd>
                <?php endif; ?>
            </dl>
            <?php if ($blocked) : ?>
                <div class="notice notice-error inline"><p><?php esc_html_e('It can\'t be sent yet. Fix the items marked in "Before it goes".', 'mailoptin'); ?></p></div>
            <?php elseif ( ! $tested) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('You haven\'t sent a test. You can still send it.', 'mailoptin'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(self::url($email_campaign_id)); ?>" class="mo-actions">
                <?php wp_nonce_field('mo_broadcast_' . $email_campaign_id); ?>
                <input type="hidden" name="id" value="<?php echo absint($email_campaign_id); ?>">
                <input type="hidden" name="at" value="<?php echo absint($_GET['at'] ?? 0); ?>">
                <a class="button" href="<?php echo esc_url(self::url($email_campaign_id)); ?>"><?php esc_html_e('Cancel', 'mailoptin'); ?></a>
                <?php if ( ! $blocked) : ?>
                    <button type="submit" class="button button-primary" name="mo_broadcast_action" value="<?php echo $later ? 'schedule' : 'send_now'; ?>" autofocus><?php
                        echo esc_html($later
                            ? __('Schedule it', 'mailoptin')
                            : (is_int($count) ? sprintf(_n('Send to %s subscriber', 'Send to %s subscribers', $count, 'mailoptin'), number_format_i18n($count)) : __('Send now', 'mailoptin')));
                        ?></button>
                <?php endif; ?>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------------------------------------ */

    protected static function redirect($url)
    {
        do_admin_redirect(apply_filters('mailoptin_broadcast_page_redirect', $url));
    }

    protected static function error($message)
    {
        set_transient('mo_broadcast_error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS);
    }

    /**
     * Save what the main form posted.
     */
    protected static function save_form($email_campaign_id)
    {
        // Only what was posted, so a request without a field never blanks it.
        $values = [];

        if (isset($_POST['subject'])) $values['email_campaign_title'] = sanitize_text_field(wp_unslash($_POST['subject']));
        if (isset($_POST['preheader'])) $values['email_campaign_preheader'] = sanitize_text_field(wp_unslash($_POST['preheader']));

        $connection = isset($_POST['connection_service']) ? sanitize_text_field(wp_unslash($_POST['connection_service'])) : null;

        if (null !== $connection && array_key_exists($connection, ConnectionsRepository::get_connections('email_campaign'))) {
            // A different connection's lists don't apply: choose again.
            $changed = $connection !== (string)Broadcasts::get($email_campaign_id, 'connection_service');

            $values['connection_service'] = $connection;

            // Only change the list when it was posted: the field is missing when the connection's
            // lists couldn't be fetched, and that mustn't clear the chosen list.
            if ($changed) {
                $values['connection_email_list'] = '';
            } elseif (isset($_POST['connection_email_list'])) {
                $values['connection_email_list'] = sanitize_text_field(wp_unslash($_POST['connection_email_list']));
            }
        }

        if (isset($_POST['segment'])) {
            $values[Segments::SEGMENT_SETTING] = absint($_POST['segment']) ? (string)absint($_POST['segment']) : '';
        }

        if (isset($_POST['exclude_tags'])) {
            $values[Segments::EXCLUDE_TAGS_SETTING] = implode(',', (array)Segments::parse_tag_ids(array_filter((array)$_POST['exclude_tags'], 'is_scalar')));
        }

        if ( ! empty($_POST['test_email']) && is_email(wp_unslash($_POST['test_email']))) {
            $values['send_test_email_input'] = sanitize_email(wp_unslash($_POST['test_email']));
        }

        Broadcasts::save($email_campaign_id, $values);

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));

        if ('' !== $name && $name !== ER::get_email_campaign_name($email_campaign_id)) {
            if (ER::campaign_name_exist($name)) {
                self::error(__('Another email already has that internal name, so the name wasn\'t changed.', 'mailoptin'));
            } else {
                ER::update_campaign_name($name, $email_campaign_id);
            }
        }
    }

    /**
     * @return int|null Unix timestamp of the date and time chosen, in site time
     */
    protected static function chosen_time()
    {
        $date = sanitize_text_field(wp_unslash($_POST['send_date'] ?? ''));
        $time = sanitize_text_field(wp_unslash($_POST['send_time'] ?? ''));

        if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^\d{2}:\d{2}$/', $time)) return null;

        $datetime = date_create_immutable_from_format('Y-m-d H:i', "$date $time", wp_timezone());

        return $datetime ? $datetime->getTimestamp() : null;
    }

    public function handle_actions()
    {
        if ( ! self::is_this_page() || empty($_POST['mo_broadcast_action'])) return;

        $email_campaign_id = self::campaign_id();

        check_admin_referer('mo_broadcast_' . $email_campaign_id);

        if ( ! $email_campaign_id || ! current_user_has_privilege()) wp_die(esc_html__('You are not allowed to do this.', 'mailoptin'), 403);

        $action = sanitize_key($_POST['mo_broadcast_action']);
        $status = Broadcasts::status($email_campaign_id)['status'];

        // A broadcast that went out can't be edited or sent again from here; duplicate it instead.
        if ( ! in_array($status, [Broadcasts::STATUS_DRAFT, Broadcasts::STATUS_SCHEDULED, Broadcasts::STATUS_FAILED], true)) {
            self::redirect(self::url($email_campaign_id));

            return;
        }

        switch ($action) {
            case 'save':
            case 'design':
            case 'send_test':
            case 'review':
                self::save_form($email_campaign_id);
                break;
        }

        switch ($action) {
            case 'save':
                self::redirect(self::url($email_campaign_id, ['mo_notice' => 'saved']));
                break;

            case 'design':
                self::redirect(self::url($email_campaign_id, ['design' => 1]));
                break;

            case 'send_test':
                $sent = Broadcasts::send_test($email_campaign_id, sanitize_email(wp_unslash($_POST['test_email'] ?? '')));
                self::redirect(self::url($email_campaign_id, ['mo_notice' => $sent ? 'test_sent' : 'test_fail']));
                break;

            case 'review':
                $later = 'later' === ($_POST['when'] ?? '');
                $at    = $later ? self::chosen_time() : null;

                if ($later && ( ! $at || $at < time() + MINUTE_IN_SECONDS)) {
                    self::error(__('Choose a date and time in the future to schedule it.', 'mailoptin'));
                    self::redirect(self::url($email_campaign_id));
                    break;
                }

                self::redirect(self::url($email_campaign_id, ['review' => 1, 'when' => $later ? 'later' : 'now', 'at' => $at ?: 0]) . '#mo-review-title');
                break;

            case 'send_now':
                if ( ! Broadcasts::can_send($email_campaign_id)) {
                    self::redirect(self::url($email_campaign_id, ['mo_notice' => 'blocked']));
                    break;
                }

                $result = Broadcasts::send($email_campaign_id);

                if ( ! $result['success']) self::error($result['error']);

                self::redirect($result['success'] ? self::url($email_campaign_id, ['mo_notice' => 'sending']) : self::url($email_campaign_id));
                break;

            case 'schedule':
                if ( ! Broadcasts::can_send($email_campaign_id)) {
                    self::redirect(self::url($email_campaign_id, ['mo_notice' => 'blocked']));
                    break;
                }

                $result = Broadcasts::schedule($email_campaign_id, absint($_POST['at'] ?? 0));

                if (is_wp_error($result)) self::error($result->get_error_message());

                self::redirect(self::url($email_campaign_id, is_wp_error($result) ? [] : ['mo_notice' => 'scheduled']));
                break;

            case 'cancel_schedule':
                Broadcasts::cancel($email_campaign_id);
                self::redirect(self::url($email_campaign_id, ['mo_notice' => 'cancelled']));
                break;
        }
    }

    /* ---------------------------------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------------------------------ */

    /**
     * Character counts, saving when the connection or audience changes, and opening the designer
     * over the page. Without JavaScript the buttons and links still work.
     */
    protected static function script($email_campaign_id)
    {
        ob_start();
        ?>
        <script type="text/javascript">
            // After every ready handler, so the customizer loader (printed in the footer) has set itself up.
            jQuery(function () { setTimeout(function () {
                var form = document.getElementById('mo-broadcast-form');
                if ( ! form) return;

                document.documentElement.classList.add('mo-js');

                form.querySelectorAll('[data-count]').forEach(function (input) {
                    var counter = input.parentNode.querySelector('.mo-counter'), max = parseInt(input.getAttribute('data-count'), 10);
                    var sync = function () {
                        counter.textContent = input.value.length + ' / ' + max;
                        counter.classList.toggle('mo-over', input.value.length > max);
                    };
                    input.addEventListener('input', sync);
                    sync();
                });

                var submit = function (action) {
                    var field = document.createElement('input');
                    field.type = 'hidden';
                    field.name = 'mo_broadcast_action';
                    field.value = action;
                    form.appendChild(field);
                    form.submit();
                };

                form.querySelectorAll('[data-autosave]').forEach(function (input) {
                    input.addEventListener('change', function () { submit('save'); });
                });

                // One click sends once.
                document.querySelectorAll('.mo-review form').forEach(function (review) {
                    review.addEventListener('submit', function () {
                        setTimeout(function () { review.querySelectorAll('button').forEach(function (button) { button.disabled = true; }); }, 0);
                    });
                });

                var loader = window.wp && wp.customize && wp.customize.Loader;

                // Save the page first, then open the designer over it once it reloads.
                form.querySelectorAll('[data-design]').forEach(function (link) {
                    link.addEventListener('click', function (e) {
                        if ( ! loader || form.querySelector('fieldset').disabled) return;
                        e.preventDefault();
                        submit('design');
                    });
                });

                if (loader && /[?&]design=1/.test(location.search)) {
                    loader.open(<?php echo wp_json_encode(self::design_url($email_campaign_id)); ?>);
                    loader.bind('close', function () {
                        location.replace(<?php echo wp_json_encode(self::url($email_campaign_id, ['designed' => 1])); ?>);
                    });
                }
            }, 0); });
        </script>
        <?php
        return ob_get_clean();
    }

    protected static function styles()
    {
        return '<style>
.mo-broadcast{max-width:1240px}
.mo-broadcast .mo-back{margin:12px 0 0}
.mo-broadcast-head{display:flex;gap:10px;align-items:center;margin:8px 0 12px}
.mo-broadcast-head h1{font-size:22px;margin:0;padding:0}
.mo-broadcast .mo-pill{display:inline-block;padding:1px 8px;border-radius:99px;font-size:12px;font-weight:600;background:#f0f0f1;color:#50575e}
.mo-broadcast .mo-pill.ok{background:#edfaef;color:#00701a}.mo-broadcast .mo-pill.info{background:#f0f6fc;color:#135e96}.mo-broadcast .mo-pill.bad{background:#fcf0f1;color:#b32d2e}
.mo-broadcast-cols{display:flex;gap:16px;align-items:flex-start;border:0;margin:0;padding:0;min-width:0}
.mo-broadcast-main{flex:2;min-width:0}.mo-broadcast-side{flex:1;position:sticky;top:46px}
.mo-broadcast .mo-card{background:#fff;border:1px solid #c3c4c7;padding:4px 20px 16px;margin-bottom:16px}
.mo-broadcast .mo-card h3{font-size:15px}
.mo-broadcast .mo-row{display:grid;grid-template-columns:160px 1fr;gap:12px;align-items:start;padding:8px 0}
.mo-broadcast .mo-row>label,.mo-broadcast .mo-label{font-weight:600;padding-top:6px}
.mo-broadcast .mo-row input.widefat{max-width:560px}
.mo-broadcast .mo-static{margin:6px 0 0}
.mo-hint{display:flex;justify-content:space-between;max-width:560px;font-size:12px;color:#646970;margin:3px 0 0}
.mo-counter.mo-over{color:#b32d2e;font-weight:600}
.mo-broadcast .mo-inline{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0}
.mo-js .mo-no-js{display:none}
.mo-tag-checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:2px 12px;padding-top:6px}
.mo-broadcast .mo-count{background:#f0f6fc;border:1px solid #c5d9ed;border-radius:4px;padding:8px 12px;margin:6px 0 0;max-width:560px}
.mo-broadcast .mo-upsell{border-left:4px solid #7e3bd0}
.mo-design{display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap}
.mo-design>div{flex:1;min-width:260px}
.mo-thumb{position:relative;display:block;width:220px;height:280px;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden;background:#f6f7f7;flex:none}
.mo-thumb iframe{width:640px;height:800px;border:0;transform:scale(.344);transform-origin:0 0;pointer-events:none}
.mo-thumb span{position:absolute;left:0;right:0;bottom:0;background:rgba(29,35,39,.78);color:#fff;text-align:center;padding:8px;font-weight:600}
.mo-thumb:focus-visible{outline:2px solid #2271b1;outline-offset:2px}
.mo-broadcast h4{margin:18px 0 6px}
.mo-when label{display:block;margin:6px 0}
.mo-when-at{margin-left:24px}
.mo-checks{list-style:none;margin:6px 0 12px;padding:0}
.mo-checks li{padding:4px 0 4px 24px;position:relative}
.mo-checks li:before{position:absolute;left:0;top:3px;font-weight:700}
.mo-checks li.ok:before{content:"\2713";color:#00a32a}.mo-checks li.warn:before{content:"!";color:#dba617;left:5px}.mo-checks li.bad:before{content:"\2715";color:#d63638}
.mo-checks li.bad{color:#b32d2e}
.mo-broadcast .mo-actions{display:flex;gap:8px;justify-content:flex-end;border-top:1px solid #f0f0f1;padding-top:12px;margin:0}
.mo-review-dim{position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:100000}
.mo-review{position:fixed;top:120px;left:50%;transform:translateX(-50%);width:540px;max-width:calc(100vw - 32px);background:#fff;border-radius:6px;padding:18px 24px;box-shadow:0 8px 32px rgba(0,0,0,.25);z-index:100001}
.mo-review h2{margin:0 0 4px}.mo-review .mo-muted{color:#646970;margin:0}
.mo-review dl{display:grid;grid-template-columns:110px 1fr;gap:6px 12px;margin:14px 0}.mo-review dt{color:#646970}.mo-review dd{margin:0;font-weight:600}
@media (max-width:960px){.mo-broadcast-cols{flex-direction:column}.mo-broadcast-side{position:static;width:100%}.mo-broadcast .mo-row{grid-template-columns:1fr}}
</style>';
    }

    /**
     * @return Broadcast_Page
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
