<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Admin\Customizer\CustomControls\ControlsHelpers;
use MailOptin\Core\EmailCampaigns\Automations;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\Core\PluginSettings\Settings;
use MailOptin\Core\Repositories\CampaignActivityRepository;
use MailOptin\Core\Repositories\ConnectionsRepository;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\SegmentsRepository as Segments;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use MailOptin\MailOptinConnect\CampaignAudience;

use function MailOptin\Core\current_user_has_privilege;
use function MailOptin\Core\do_admin_redirect;

/**
 * A post notification's or email digest's own page: subject, which posts, when, audience and
 * whether it is on are set here; the email customizer opens over the page to design the email.
 *
 * It edits the same settings as the customizer, so automations made before it open here as they are.
 */
class Automation_Page
{
    const VIEW = 'automation';

    // Added to the customizer's address when it is opened from this page.
    const FROM_PAGE_ARG = 'mo_automation_page';

    // The customizer controls this page owns. They stay in the customizer, only hidden when it is
    // opened from here, so the controls that depend on them keep working.
    const OWNED_CONTROLS = [
        'email_campaign_title', 'email_campaign_subject', 'email_campaign_preheader', 'item_number',
        'custom_post_type', 'custom_post_type_settings', 'post_categories', 'post_tags', 'post_authors',
        'connection_service', 'connection_email_list', 'MailOptinConnect_segment', 'MailOptinConnect_exclude_tags',
        'MailOptinConnect_audience_count', 'schedule_header', 'send_immediately', 'email_campaign_schedule', 'email_digest_schedule',
    ];

    const DELAY_UNITS = ['minutes', 'hours', 'days'];

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

        return $email_campaign_id && Automations::is_automation($email_campaign_id) ? $email_campaign_id : 0;
    }

    public function enqueue()
    {
        if ( ! self::is_this_page()) return;

        // Opens the customizer over the page, as the Themes screen does.
        wp_enqueue_script('customize-loader');
        wp_enqueue_style('mailoptin-customizer-chosen', MAILOPTIN_ASSETS_URL . 'chosen/chosen.min.css', [], MAILOPTIN_VERSION_NUMBER);
        wp_enqueue_script('mailoptin-customizer-chosen', MAILOPTIN_ASSETS_URL . 'chosen/chosen.jquery.min.js', ['jquery'], MAILOPTIN_VERSION_NUMBER);
    }

    /* ---------------------------------------------------------------------------------------------
     * Page
     * ------------------------------------------------------------------------------------------ */

    public static function html()
    {
        $email_campaign_id = self::campaign_id();

        if ( ! $email_campaign_id) {
            return '<div class="wrap"><p>' . esc_html__('This automation no longer exists.', 'mailoptin') . '</p><p><a href="' . esc_url(MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE) . '">&larr; ' . esc_html__('Post Notifications', 'mailoptin') . '</a></p></div>';
        }

        $digest     = Automations::is_digest($email_campaign_id);
        $active     = Automations::is_active($email_campaign_id);
        $settings   = Settings::instance();
        $html       = Automations::preview_html($email_campaign_id);
        $checks     = Automations::checks($email_campaign_id, $html);
        $test_email = (string)(Automations::get($email_campaign_id, 'send_test_email_input') ?: wp_get_current_user()->user_email);
        $name       = ER::get_email_campaign_name($email_campaign_id);

        ob_start();
        ?>
        <?php echo Broadcast_Page::styles() . self::styles(); ?>
        <div class="wrap mo-broadcast mo-automation">
            <p class="mo-back"><a href="<?php echo esc_url(MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE); ?>">&larr; <?php esc_html_e('Post Notifications', 'mailoptin'); ?></a></p>
            <div class="mo-broadcast-head">
                <h1><?php echo esc_html($name); ?></h1>
                <span class="mo-pill info"><?php echo $digest ? esc_html__('Digest', 'mailoptin') : esc_html__('New post', 'mailoptin'); ?></span>
                <span class="mo-pill <?php echo $active ? 'ok' : ''; ?>"><?php echo $active ? esc_html__('Active', 'mailoptin') : esc_html__('Paused', 'mailoptin'); ?></span>
            </div>
            <hr class="wp-header-end">
            <?php echo self::notice_html($email_campaign_id, $active, $checks); ?>

            <form method="post" action="<?php echo esc_url(self::url($email_campaign_id)); ?>" id="mo-broadcast-form">
                <?php wp_nonce_field('mo_automation_' . $email_campaign_id); ?>
                <input type="hidden" name="id" value="<?php echo absint($email_campaign_id); ?>">
                <div class="mo-broadcast-cols">
                    <div class="mo-broadcast-main">
                        <div class="mo-card">
                            <h3><?php esc_html_e('Email', 'mailoptin'); ?></h3>
                            <div class="mo-row">
                                <label for="mo-subject"><?php esc_html_e('Subject', 'mailoptin'); ?></label>
                                <div>
                                    <input type="text" id="mo-subject" name="subject" class="widefat" value="<?php echo esc_attr(Automations::subject($email_campaign_id)); ?>" maxlength="200" data-count="60">
                                    <p class="mo-hint"><span><?php echo $digest
                                                ? esc_html__('Placeholders: {{date}}, or {{date format="F j"}}.', 'mailoptin')
                                                : esc_html__('Placeholders: {{title}} (the post\'s title), {{date}}.', 'mailoptin'); ?></span><span class="mo-counter"></span></p>
                                </div>
                            </div>
                            <div class="mo-row">
                                <label for="mo-preheader"><?php esc_html_e('Preview text', 'mailoptin'); ?></label>
                                <div>
                                    <input type="text" id="mo-preheader" name="preheader" class="widefat" value="<?php echo esc_attr((string)Automations::get($email_campaign_id, 'email_campaign_preheader')); ?>" maxlength="200" data-count="100">
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
                                    <input type="text" id="mo-name" name="name" class="widefat" value="<?php echo esc_attr($name); ?>" maxlength="200" required>
                                    <p class="mo-hint"><span><?php esc_html_e('Only you see this.', 'mailoptin'); ?></span></p>
                                </div>
                            </div>
                        </div>

                        <?php echo self::when_html($email_campaign_id, $digest); ?>

                        <?php echo self::audience_html($email_campaign_id); ?>

                        <div class="mo-card">
                            <h3><?php esc_html_e('Content', 'mailoptin'); ?></h3>
                            <div class="mo-design">
                                <a class="mo-thumb" href="<?php echo esc_url(self::design_url($email_campaign_id)); ?>" data-design aria-label="<?php esc_attr_e('Design email', 'mailoptin'); ?>">
                                    <iframe title="<?php esc_attr_e('Email preview', 'mailoptin'); ?>" sandbox="" tabindex="-1" srcdoc="<?php echo htmlspecialchars($html, ENT_QUOTES, 'UTF-8', true); ?>"></iframe>
                                    <span><?php esc_html_e('Design email', 'mailoptin'); ?></span>
                                </a>
                                <div>
                                    <p><a class="button button-primary button-hero" href="<?php echo esc_url(self::design_url($email_campaign_id)); ?>" data-design><?php esc_html_e('Design email', 'mailoptin'); ?></a></p>
                                    <p class="description"><?php echo $digest
                                                ? esc_html__('Opens the email designer over this page, previewed with your latest posts. Your changes here are saved first. Close the designer to come back.', 'mailoptin')
                                                : esc_html__('Opens the email designer over this page. Your changes here are saved first. Close the designer to come back.', 'mailoptin'); ?></p>
                                    <h4><?php esc_html_e('Send a test', 'mailoptin'); ?></h4>
                                    <p class="mo-inline">
                                        <label class="screen-reader-text" for="mo-test-email"><?php esc_html_e('Test email address', 'mailoptin'); ?></label>
                                        <input type="email" id="mo-test-email" name="test_email" value="<?php echo esc_attr($test_email); ?>">
                                        <button type="submit" class="button" name="mo_automation_action" value="send_test" formnovalidate><?php esc_html_e('Send test', 'mailoptin'); ?></button>
                                    </p>
                                    <p class="description"><?php echo $digest
                                                ? esc_html__('Uses the posts the next digest would include, or your latest posts if there are none yet.', 'mailoptin')
                                                : esc_html__('Uses your latest matching post.', 'mailoptin'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mo-broadcast-side">
                        <?php echo self::status_html($email_campaign_id, $active, $checks); ?>
                    </div>
                </div>
            </form>
        </div>
        <?php echo self::script($email_campaign_id); ?>
        <?php
        return ob_get_clean();
    }

    protected static function notice_html($email_campaign_id, $active, $checks)
    {
        $html    = '';
        $notices = [
            'saved'       => ['success', __('Saved.', 'mailoptin')],
            'test_sent'   => ['success', __('Test email sent. Check your inbox.', 'mailoptin')],
            'test_fail'   => ['error', __('The test email could not be sent. Check the address, and that your site can send email.', 'mailoptin')],
            'activated'   => ['success', __('Saved and switched on.', 'mailoptin')],
            'deactivated' => ['success', __('Saved and paused. Nothing sends until you switch it on again.', 'mailoptin')],
            'blocked'     => ['error', __('It can\'t be switched on yet. Fix the items marked in "Ready to send".', 'mailoptin')],
        ];

        $notice = sanitize_key($_GET['mo_notice'] ?? '');

        if (isset($notices[$notice])) {
            $html .= sprintf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notices[$notice][0]), esc_html($notices[$notice][1]));
        }

        $error = get_transient('mo_automation_error_' . get_current_user_id());

        if ($error) {
            delete_transient('mo_automation_error_' . get_current_user_id());
            $html .= sprintf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($error));
        }

        if ($active && ! Automations::can_send($email_campaign_id, $checks)) {
            $html .= '<div class="notice notice-warning"><p>' . esc_html__('It\'s switched on, but it won\'t send until the items marked in "Ready to send" are fixed.', 'mailoptin') . '</p></div>';
        }

        return $html;
    }

    /**
     * Which posts, and when: as soon as one is published or after a delay, or the digest's schedule.
     */
    protected static function when_html($email_campaign_id, $digest)
    {
        ob_start();
        ?>
        <div class="mo-card">
            <h3><?php esc_html_e('When it sends', 'mailoptin'); ?></h3>
            <?php if ($digest) : ?>
                <?php echo self::digest_schedule_html($email_campaign_id); ?>
            <?php endif; ?>

            <?php echo self::posts_html($email_campaign_id); ?>

            <?php if ( ! $digest) : ?>
                <?php echo self::delay_html($email_campaign_id); ?>
            <?php elseif (Automations::digest_available()) : ?>
                <?php echo self::next_digest_html($email_campaign_id); ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function digest_schedule_html($email_campaign_id)
    {
        $interval = (string)Automations::get($email_campaign_id, 'schedule_interval');
        $hour     = absint(Automations::get($email_campaign_id, 'schedule_time'));
        $weekday  = absint(Automations::get($email_campaign_id, 'schedule_day'));
        $monthday = absint(Automations::get($email_campaign_id, 'schedule_month_date'));
        $items    = absint(Automations::get($email_campaign_id, 'item_number'));

        ob_start();
        if ( ! Automations::digest_available()) : ?>
            <p class="mo-count mo-upsell"><?php printf(
                    esc_html__('Digests need %sMailOptin Pro%s. Until then this one doesn\'t send.', 'mailoptin'),
                    '<a target="_blank" href="https://mailoptin.io/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=posts_email_digest">',
                    '</a>'
                ); ?></p>
        <?php endif; ?>
        <div class="mo-row">
            <label for="mo-interval"><?php esc_html_e('How often', 'mailoptin'); ?></label>
            <div class="mo-inline mo-schedule" data-interval="<?php echo esc_attr($interval); ?>">
                <select id="mo-interval" name="schedule_interval">
                    <option value="every_day" <?php selected($interval, 'every_day'); ?>><?php esc_html_e('Every day', 'mailoptin'); ?></option>
                    <option value="every_week" <?php selected($interval, 'every_week'); ?>><?php esc_html_e('Every week', 'mailoptin'); ?></option>
                    <option value="every_month" <?php selected($interval, 'every_month'); ?>><?php esc_html_e('Every month', 'mailoptin'); ?></option>
                </select>
                <span class="mo-for-week"><?php esc_html_e('on', 'mailoptin'); ?></span>
                <label class="screen-reader-text" for="mo-day"><?php esc_html_e('Day of the week', 'mailoptin'); ?></label>
                <select id="mo-day" name="schedule_day" class="mo-for-week">
                    <?php foreach (Automations::day_names() as $day => $label) : ?>
                        <option value="<?php echo absint($day); ?>" <?php selected($weekday, $day); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="mo-for-month"><?php esc_html_e('on the', 'mailoptin'); ?></span>
                <label class="screen-reader-text" for="mo-month-date"><?php esc_html_e('Day of the month', 'mailoptin'); ?></label>
                <select id="mo-month-date" name="schedule_month_date" class="mo-for-month">
                    <?php for ($i = 1; $i <= 28; $i++) : ?>
                        <option value="<?php echo $i; ?>" <?php selected($monthday, $i); ?>><?php echo esc_html(Automations::ordinal($i)); ?></option>
                    <?php endfor; ?>
                </select>
                <span><?php esc_html_e('at', 'mailoptin'); ?></span>
                <label class="screen-reader-text" for="mo-hour"><?php esc_html_e('Time', 'mailoptin'); ?></label>
                <select id="mo-hour" name="schedule_time">
                    <?php for ($i = 0; $i < 24; $i++) : ?>
                        <option value="<?php echo $i; ?>" <?php selected($hour, $i); ?>><?php echo esc_html(Automations::hour_label($i)); ?></option>
                    <?php endfor; ?>
                </select>
                <span class="description"><?php echo esc_html(sprintf(__('site time (%s)', 'mailoptin'), wp_timezone_string())); ?></span>
            </div>
        </div>
        <div class="mo-row">
            <label for="mo-items"><?php esc_html_e('Posts', 'mailoptin'); ?></label>
            <div>
                <p class="mo-inline"><?php esc_html_e('Up to', 'mailoptin'); ?>
                    <input type="number" id="mo-items" name="item_number" min="1" max="1000" class="small-text" value="<?php echo esc_attr($items ?: 5); ?>">
                    <?php esc_html_e('posts published since the last digest', 'mailoptin'); ?></p>
                <p class="mo-hint"><span><?php esc_html_e('No new posts, no email: an empty digest is never sent.', 'mailoptin'); ?></span></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * The post type, and the categories, tags, other terms and authors a post needs one of.
     */
    protected static function posts_html($email_campaign_id)
    {
        $available  = Automations::post_filters_available();
        $post_type  = Automations::post_type($email_campaign_id);
        $post_types = ['post' => __('Posts', 'mailoptin')] + ($available ? ControlsHelpers::custom_post_types() : []);

        if ( ! isset($post_types[$post_type])) $post_types[$post_type] = Automations::post_type_label($post_type);

        ob_start();
        ?>
        <div class="mo-row">
            <label for="mo-post-type"><?php esc_html_e('Post type', 'mailoptin'); ?></label>
            <div>
                <?php if ($available) : ?>
                    <p class="mo-inline">
                        <select id="mo-post-type" name="post_type" data-autosave>
                            <?php foreach ($post_types as $key => $label) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($post_type, $key); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button mo-no-js" name="mo_automation_action" value="save"><?php esc_html_e('Update', 'mailoptin'); ?></button>
                    </p>
                    <p class="mo-hint"><span><?php esc_html_e('Changing it clears the filters below: each post type has its own.', 'mailoptin'); ?></span></p>
                <?php else : ?>
                    <p class="mo-static"><?php echo esc_html($post_types[$post_type]); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($available && ! post_type_exists($post_type)) : ?>
            <p class="mo-count"><?php echo esc_html(sprintf(__('The "%s" post type isn\'t registered, so its filters can\'t be shown. They are kept as they are: activate its plugin to see them.', 'mailoptin'), $post_type)); ?></p>
        <?php elseif ($available) : ?>
            <?php echo self::filters_html($email_campaign_id, $post_type); ?>
        <?php else : ?>
            <p class="mo-count mo-upsell"><?php printf(
                    esc_html__('Every published post is sent. %sMailOptin Pro%s lets you choose a custom post type, like WooCommerce products, and only send posts in certain categories, tags or other terms, or by certain authors.', 'mailoptin'),
                    '<a target="_blank" href="https://mailoptin.io/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=automation_page_filters">',
                    '</a>'
                ); ?></p>
        <?php endif;

        return ob_get_clean();
    }

    protected static function filters_html($email_campaign_id, $post_type)
    {
        $filters = Automations::term_filters($email_campaign_id);
        $rows    = [];

        foreach (Automations::page_taxonomies($email_campaign_id, $post_type) as $taxonomy => $label) {
            // Categories on posts need Pro's category support; Pro has it.
            if ('post' === $post_type && 'category' === $taxonomy && ! apply_filters('mailoptin_enable_email_automation_post_category_support', false)) continue;

            $search = 'ch_get_terms|' . $taxonomy;

            if ('post' === $post_type && 'category' === $taxonomy) $search = 'post_categories';
            if ('post' === $post_type && 'post_tag' === $taxonomy) $search = 'post_tags';

            $rows[] = [
                'name'     => 'filter_terms[' . $taxonomy . '][]',
                'label'    => $label,
                'choices'  => ControlsHelpers::get_terms($taxonomy),
                'selected' => $filters[$taxonomy] ?? [],
                'search'   => $search,
                'taxonomy' => $taxonomy,
            ];
        }

        $rows[] = [
            'name'     => 'filter_authors[]',
            'label'    => __('Authors', 'mailoptin'),
            'choices'  => ControlsHelpers::get_authors(),
            'selected' => Automations::author_filter($email_campaign_id),
            'search'   => '',
            'taxonomy' => '',
        ];

        ob_start();
        ?>
        <div class="mo-row">
            <span class="mo-label"><?php esc_html_e('Only posts in', 'mailoptin'); ?></span>
            <div class="mo-filters">
                <input type="hidden" name="filters_shown" value="1">
                <?php foreach ($rows as $i => $row) :
                    $choices = is_array($row['choices']) ? $row['choices'] : [];
                    // Saved terms are always choices, so they stay selected beyond the first 200.
                    foreach ($row['selected'] as $id) {
                        if (isset($choices[$id])) continue;
                        $item = $row['taxonomy'] ? get_term($id, $row['taxonomy']) : get_userdata($id);
                        if ($item && ! is_wp_error($item)) $choices[$id] = $row['taxonomy'] ? $item->name : $item->display_name;
                    }
                    ?>
                    <label class="mo-filter">
                        <span><?php echo esc_html($row['label']); ?></span>
                        <select name="<?php echo esc_attr($row['name']); ?>" multiple class="mo-chosen" data-search-type="<?php echo esc_attr($row['search']); ?>" data-placeholder="<?php esc_attr_e('Any', 'mailoptin'); ?>">
                            <?php foreach ($choices as $id => $label) : ?>
                                <option value="<?php echo absint($id); ?>" <?php selected(in_array((int)$id, $row['selected'], true)); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
                <p class="mo-hint"><span><?php esc_html_e('Empty means any. A post needs one of the choices in each box you fill in.', 'mailoptin'); ?></span></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function delay_html($email_campaign_id)
    {
        $now   = null === Automations::delay_minutes($email_campaign_id);
        $digit = absint(Automations::get($email_campaign_id, 'schedule_digit'));
        $unit  = (string)Automations::get($email_campaign_id, 'schedule_type');

        ob_start();
        ?>
        <div class="mo-row">
            <span class="mo-label"><?php esc_html_e('Send', 'mailoptin'); ?></span>
            <div class="mo-when">
                <label><input type="radio" name="send_when" value="now" <?php checked($now); ?>> <?php esc_html_e('As soon as the post is published', 'mailoptin'); ?></label>
                <label class="mo-inline"><input type="radio" name="send_when" value="later" <?php checked( ! $now); ?>>
                    <span class="screen-reader-text"><?php esc_html_e('After a delay', 'mailoptin'); ?></span>
                    <input type="number" name="schedule_digit" min="1" max="999" class="small-text" value="<?php echo esc_attr($digit ?: 1); ?>" aria-label="<?php esc_attr_e('Delay', 'mailoptin'); ?>">
                    <select name="schedule_type" aria-label="<?php esc_attr_e('Unit', 'mailoptin'); ?>">
                        <option value="minutes" <?php selected($unit, 'minutes'); ?>><?php esc_html_e('minutes', 'mailoptin'); ?></option>
                        <option value="hours" <?php selected( ! in_array($unit, ['minutes', 'days'], true)); ?>><?php esc_html_e('hours', 'mailoptin'); ?></option>
                        <option value="days" <?php selected($unit, 'days'); ?>><?php esc_html_e('days', 'mailoptin'); ?></option>
                    </select>
                    <?php esc_html_e('after it\'s published', 'mailoptin'); ?>
                </label>
                <p class="mo-hint"><span><?php esc_html_e('A delay gives you time to fix a typo before it goes out. The email is made when the post is published.', 'mailoptin'); ?></span></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function next_digest_html($email_campaign_id)
    {
        $next  = Automations::next_digest_at($email_campaign_id);
        $posts = Automations::digest_posts_so_far($email_campaign_id);

        if ( ! $next) return '';

        $titles = array_map(function ($post) {
            return '"' . get_the_title($post) . '"';
        }, array_slice($posts, 0, 3));

        if (count($posts) > 3) $titles[] = sprintf(__('and %s more', 'mailoptin'), number_format_i18n(count($posts) - 3));

        $when = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next->getTimestamp() + $next->getOffset(), true);

        $label = Automations::is_active($email_campaign_id) ? __('Next digest: %s', 'mailoptin') : __('Once switched on, the next digest: %s', 'mailoptin');

        return '<p class="mo-count mo-next"><b>' . esc_html(sprintf($label, $when)) . '</b> · '
               . esc_html(empty($posts)
                ? __('no new posts yet, so it won\'t send unless some are published by then.', 'mailoptin')
                : sprintf(_n('%1$s post so far: %2$s', '%1$s posts so far: %2$s', count($posts), 'mailoptin'), number_format_i18n(count($posts)), implode(', ', $titles))) . '</p>';
    }

    protected static function audience_html($email_campaign_id)
    {
        $connection  = (string)Automations::get($email_campaign_id, 'connection_service');
        $connections = ConnectionsRepository::get_connections('email_campaign');
        $lists       = $connection && Broadcasts::connection_has_lists($connection) ? ConnectionsRepository::connection_email_list($connection) : [];
        $list_id     = (string)Automations::get($email_campaign_id, 'connection_email_list');
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
                    <button type="submit" class="button mo-no-js" name="mo_automation_action" value="save"><?php esc_html_e('Update', 'mailoptin'); ?></button>
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
            <?php elseif ($connection && Broadcasts::connection_has_lists($connection)) : ?>
                <p class="mo-count"><?php esc_html_e('No lists came back from this connection. Check it under Integrations, then reload this page.', 'mailoptin'); ?></p>
            <?php endif; ?>

            <?php if ($builtin && Segments::is_ready() && Tags::is_ready()) : ?>
                <?php echo self::builtin_audience_html($email_campaign_id); ?>
            <?php elseif ($connection && ! $builtin) : ?>
                <p class="mo-count"><?php printf(
                        esc_html__('%s sends these emails. Options of its own, like its segments, tags, products or courses, are under Recipient in the email designer.', 'mailoptin'),
                        esc_html($connections[$connection] ?? $connection)
                    ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function builtin_audience_html($email_campaign_id)
    {
        $segment_id = absint(Automations::get($email_campaign_id, Segments::SEGMENT_SETTING));
        $excluded   = (array)Segments::excluded_tag_ids($email_campaign_id);
        $available  = Segments::is_available();
        $count      = Broadcasts::recipients_count($email_campaign_id);

        ob_start();
        if ($available || $segment_id || ! empty($excluded)) : ?>
            <div class="mo-row">
                <label for="mo-segment"><?php esc_html_e('Send to', 'mailoptin'); ?></label>
                <div>
                    <select id="mo-segment" name="segment" data-autosave>
                        <option value=""><?php esc_html_e('Everyone in the list', 'mailoptin'); ?></option>
                        <?php foreach (Segments::names() as $id => $segment) : ?>
                            <option value="<?php echo absint($id); ?>" <?php selected($segment_id, (int)$id); ?>><?php echo esc_html(sprintf(__('%s (segment)', 'mailoptin'), $segment)); ?></option>
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
                        <?php foreach ($tags as $id => $tag) : ?>
                            <label><input type="checkbox" name="exclude_tags[]" value="<?php echo absint($id); ?>" data-autosave <?php checked(in_array((int)$id, $excluded, true)); ?>> <?php echo esc_html($tag); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ( ! $available) : ?>
                <p class="mo-count"><?php printf(esc_html__('Segments and leaving out tags need %sMailOptin Premium%s. Until you choose "Everyone in the list" and untick the tags, this automation doesn\'t send.', 'mailoptin'), '<a target="_blank" href="' . esc_url(CampaignAudience::upsell_url('builtin_segments_automation')) . '">', '</a>'); ?></p>
            <?php endif; ?>
        <?php else : ?>
            <p class="mo-count mo-upsell"><?php printf(
                    esc_html__('It goes to everyone in the list. %sMailOptin Premium%s lets you send to a saved segment instead and leave out people with certain tags.', 'mailoptin'),
                    '<a target="_blank" href="' . esc_url(CampaignAudience::upsell_url('builtin_segments_automation')) . '">',
                    '</a>'
                ); ?></p>
        <?php endif; ?>
        <?php if (null !== $count) : ?>
            <p class="mo-count"><?php echo is_wp_error($count)
                    ? esc_html($count->get_error_message())
                    : wp_kses(sprintf(_n('<b>Goes to %s subscriber</b> today. Worked out again each time it sends, so new subscribers are included.', '<b>Goes to %s subscribers</b> today. Worked out again each time it sends, so new subscribers are included.', $count, 'mailoptin'), number_format_i18n($count)), ['b' => []]); ?></p>
        <?php endif;

        return ob_get_clean();
    }

    protected static function status_html($email_campaign_id, $active, $checks)
    {
        $digest = Automations::is_digest($email_campaign_id);
        $logs   = Automations::recent_logs($email_campaign_id, 3);
        $names  = Automations::filter_names($email_campaign_id);
        $type   = Automations::post_type_label(Automations::post_type($email_campaign_id));

        if ($digest) {
            $next    = Automations::digest_available() ? Automations::next_digest_at($email_campaign_id) : null;
            $summary = empty($names)
                ? sprintf(__('%1$s: new items in %2$s.', 'mailoptin'), Automations::digest_schedule_label($email_campaign_id), $type)
                : sprintf(__('%1$s: new items in %2$s (%3$s).', 'mailoptin'), Automations::digest_schedule_label($email_campaign_id), $type, implode(', ', $names));
            if ($active && $next) $summary .= ' ' . sprintf(__('Next: %s.', 'mailoptin'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next->getTimestamp() + $next->getOffset(), true));
        } else {
            $summary = empty($names)
                ? sprintf(__('Sends for each new item in %1$s, %2$s.', 'mailoptin'), $type, Automations::delay_label($email_campaign_id))
                : sprintf(__('Sends for each new item in %1$s (%2$s), %3$s.', 'mailoptin'), $type, implode(', ', $names), Automations::delay_label($email_campaign_id));
            $summary .= ' ' . __('Posts published before it was switched on are never sent.', 'mailoptin');
        }

        ob_start();
        ?>
        <div class="mo-card">
            <h3><?php esc_html_e('Status', 'mailoptin'); ?></h3>
            <p class="mo-switch">
                <input type="hidden" name="active_was" value="<?php echo $active ? '1' : '0'; ?>">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" id="mo-active" name="active" value="1" class="tgl tgl-light" data-autosave <?php checked($active); ?>>
                <label for="mo-active" class="tgl-btn" aria-hidden="true"></label>
                <label for="mo-active"><b><?php echo $active ? esc_html__('Active', 'mailoptin') : esc_html__('Paused', 'mailoptin'); ?></b></label>
            </p>
            <p class="description"><?php echo esc_html($summary); ?></p>
            <h4><?php esc_html_e('Ready to send', 'mailoptin'); ?></h4>
            <ul class="mo-checks">
                <?php foreach ($checks as $check) : ?>
                    <li class="<?php echo $check['ok'] ? 'ok' : ($check['blocking'] ? 'bad' : 'warn'); ?>"><?php echo esc_html($check['label']); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ( ! empty($logs)) : ?>
                <h4><?php esc_html_e('Recent sends', 'mailoptin'); ?></h4>
                <ul class="mo-recent">
                    <?php foreach ($logs as $log) :
                        $sent = CampaignActivityRepository::is_ready() && CampaignActivityRepository::sent_count($log['id']);
                        ?>
                        <li><?php echo esc_html(($log['status_time'] ? mysql2date(get_option('date_format'), $log['status_time']) : '—') . ' · ' . $log['title']); ?>
                            <?php if ('failed' === $log['status']) : ?><span class="mo-bad"><?php esc_html_e('failed', 'mailoptin'); ?></span><?php endif; ?>
                            <?php if ('queued' === $log['status']) : ?><span class="description"><?php esc_html_e('waiting for its delay', 'mailoptin'); ?></span><?php endif; ?>
                            <?php if ($sent) : ?>· <a href="<?php echo esc_url(Campaign_Report::url($log['id'])); ?>"><?php esc_html_e('Report', 'mailoptin'); ?></a><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p><a href="<?php echo esc_url(MAILOPTIN_CAMPAIGN_LOG_SETTINGS_PAGE); ?>"><?php esc_html_e('Every send is in Logs', 'mailoptin'); ?></a></p>
            <?php endif; ?>
            <div class="mo-actions">
                <button type="submit" class="button button-primary" name="mo_automation_action" value="save"><?php esc_html_e('Save', 'mailoptin'); ?></button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------------------------------------ */

    protected static function redirect($url)
    {
        do_admin_redirect(apply_filters('mailoptin_automation_page_redirect', $url));
    }

    protected static function error($message)
    {
        set_transient('mo_automation_error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS);
    }

    /**
     * @return int[] the posted IDs in a multi-select, without empties
     */
    protected static function posted_ids($value)
    {
        return array_values(array_unique(array_filter(array_map('absint', array_filter((array)$value, 'is_scalar')))));
    }

    /**
     * The settings the main form posted. Only what was posted, so a request without a field never
     * blanks it. Values are stored as the customizer stores them.
     *
     * @return array setting => value
     */
    public static function posted_values($email_campaign_id, $post)
    {
        $values = [];
        $digest = Automations::is_digest($email_campaign_id);

        if (isset($post['subject'])) $values['email_campaign_subject'] = sanitize_text_field(wp_unslash($post['subject']));
        if (isset($post['preheader'])) $values['email_campaign_preheader'] = sanitize_text_field(wp_unslash($post['preheader']));

        // Which posts.
        $post_type = Automations::post_type($email_campaign_id);

        if (isset($post['post_type']) && Automations::post_filters_available()) {
            $posted = sanitize_key(wp_unslash($post['post_type']));

            if ($posted !== $post_type && ('post' === $posted || array_key_exists($posted, ControlsHelpers::custom_post_types()))) {
                // Each post type has its own taxonomies: start its filters empty.
                $values['custom_post_type']          = $posted;
                $values['custom_post_type_settings'] = '';
                $values['post_categories']           = [];
                $values['post_tags']                 = [];
                $post_type                           = null;
            }
        }

        // Only the taxonomies the page showed: a post type whose plugin is off shows none, and
        // keeps its filters as they are.
        if (null !== $post_type && ! empty($post['filters_shown']) && Automations::post_filters_available() && post_type_exists($post_type)) {
            $terms  = is_array($post['filter_terms'] ?? null) ? $post['filter_terms'] : [];
            $chosen = [];

            foreach (Automations::page_taxonomies($email_campaign_id, $post_type) as $taxonomy => $label) {
                $chosen[$taxonomy] = array_map('strval', self::posted_ids($terms[$taxonomy] ?? []));
            }

            if ('post' === $post_type) {
                if (apply_filters('mailoptin_enable_email_automation_post_category_support', false)) {
                    $values['post_categories'] = $chosen['category'] ?? [];
                } else {
                    // Not shown without category support: keep what is saved.
                    $chosen['category'] = [];
                }
                $values['post_tags'] = $chosen['post_tag'] ?? [];
                unset($chosen['category'], $chosen['post_tag']);
            }

            $chosen = array_filter($chosen);

            $values['custom_post_type_settings'] = empty($chosen) ? '' : wp_json_encode($chosen);
            $values['post_authors']              = array_map('strval', self::posted_ids($post['filter_authors'] ?? []));
        }

        // When.
        if ( ! $digest && isset($post['send_when'])) {
            $values['send_immediately'] = 'now' === $post['send_when'];

            if ('later' === $post['send_when']) {
                $values['schedule_digit'] = (string)max(1, min(999, absint($post['schedule_digit'] ?? 1)));
                $values['schedule_type']  = in_array($post['schedule_type'] ?? '', self::DELAY_UNITS, true) ? $post['schedule_type'] : 'hours';
            }
        }

        if ($digest) {
            if (isset($post['schedule_interval']) && in_array($post['schedule_interval'], ['every_day', 'every_week', 'every_month'], true)) {
                $values['schedule_interval'] = $post['schedule_interval'];
            }
            if (isset($post['schedule_time'])) $values['schedule_time'] = sprintf('%02d', absint($post['schedule_time']) % 24);
            if (isset($post['schedule_day'])) $values['schedule_day'] = (string)(absint($post['schedule_day']) % 7);
            if (isset($post['schedule_month_date'])) $values['schedule_month_date'] = (string)max(1, min(28, absint($post['schedule_month_date'])));
            if (isset($post['item_number'])) $values['item_number'] = max(1, min(1000, absint($post['item_number'])));
        }

        // Audience.
        $connection = isset($post['connection_service']) ? sanitize_text_field(wp_unslash($post['connection_service'])) : null;

        if (null !== $connection && array_key_exists($connection, ConnectionsRepository::get_connections('email_campaign'))) {
            $changed = $connection !== (string)Automations::get($email_campaign_id, 'connection_service');

            $values['connection_service'] = $connection;

            // A different connection's lists don't apply: choose again. The list field is missing when
            // the connection's lists couldn't be fetched, and that mustn't clear the chosen list.
            if ($changed) {
                $values['connection_email_list'] = '';
            } elseif (isset($post['connection_email_list'])) {
                $values['connection_email_list'] = sanitize_text_field(wp_unslash($post['connection_email_list']));
            }
        }

        if (isset($post['segment'])) {
            $values[Segments::SEGMENT_SETTING] = absint($post['segment']) ? (string)absint($post['segment']) : '';
        }

        if (isset($post['exclude_tags'])) {
            $values[Segments::EXCLUDE_TAGS_SETTING] = implode(',', (array)Segments::parse_tag_ids(array_filter((array)$post['exclude_tags'], 'is_scalar')));
        }

        if ( ! empty($post['test_email']) && is_email(wp_unslash($post['test_email']))) {
            $values['send_test_email_input'] = sanitize_email(wp_unslash($post['test_email']));
        }

        return $values;
    }

    protected static function save_form($email_campaign_id)
    {
        $values = self::posted_values($email_campaign_id, $_POST);
        $saved  = (array)ER::get_settings_by_id($email_campaign_id);

        // Only what differs from what is saved (the test address aside) counts as a change, so the
        // last test stays current after a save that changed nothing.
        $changes = array_filter(array_diff_key($values, ['send_test_email_input' => true]), function ($value, $key) use ($saved, $email_campaign_id) {
            return ! array_key_exists($key, $saved) ? $value != Automations::get($email_campaign_id, $key) : $value != $saved[$key];
        }, ARRAY_FILTER_USE_BOTH);

        if ( ! empty($changes)) {
            Automations::save($email_campaign_id, $values);
        } elseif ( ! empty($values)) {
            ER::update_settings_by_id($email_campaign_id, $values);
        }

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));

        if ('' !== $name && $name !== ER::get_email_campaign_name($email_campaign_id)) {
            if (ER::campaign_name_exist($name)) {
                self::error(__('Another email already has that internal name, so the name wasn\'t changed.', 'mailoptin'));
            } else {
                ER::update_campaign_name($name, $email_campaign_id);
                // The customizer saves its title back as the name: keep them the same.
                ER::update_settings_by_id($email_campaign_id, ['email_campaign_title' => $name]);
            }
        }
    }

    /**
     * Switch it on or off as the form asked. It only switches on when nothing stops it sending.
     *
     * @return string|null the notice to show
     */
    protected static function save_active($email_campaign_id)
    {
        if ( ! isset($_POST['active'], $_POST['active_was'])) return null;

        $wanted = '1' === (string)$_POST['active'];

        // Only when the switch was flipped on this page: a page opened before the automation was
        // switched on or off elsewhere mustn't undo that.
        if ($wanted === ('1' === (string)$_POST['active_was'])) return null;

        $active = Automations::is_active($email_campaign_id);

        if ($wanted === $active) return null;

        if ( ! $wanted) {
            ER::deactivate_email_campaign($email_campaign_id);

            return 'deactivated';
        }

        if ( ! Automations::can_send($email_campaign_id)) return 'blocked';

        ER::activate_email_campaign($email_campaign_id);

        return 'activated';
    }

    public function handle_actions()
    {
        if ( ! self::is_this_page() || empty($_POST['mo_automation_action'])) return;

        $email_campaign_id = self::campaign_id();

        check_admin_referer('mo_automation_' . $email_campaign_id);

        if ( ! $email_campaign_id || ! current_user_has_privilege()) wp_die(esc_html__('You are not allowed to do this.', 'mailoptin'), 403);

        $action = sanitize_key($_POST['mo_automation_action']);

        if ( ! in_array($action, ['save', 'design', 'send_test'], true)) return;

        self::save_form($email_campaign_id);

        $notice = self::save_active($email_campaign_id);

        switch ($action) {
            case 'save':
                self::redirect(self::url($email_campaign_id, ['mo_notice' => $notice ?: 'saved']));
                break;

            case 'design':
                self::redirect(self::url($email_campaign_id, ['design' => 1]));
                break;

            case 'send_test':
                $sent = Automations::send_test($email_campaign_id, sanitize_email(wp_unslash($_POST['test_email'] ?? '')));
                self::redirect(self::url($email_campaign_id, ['mo_notice' => $sent ? 'test_sent' : 'test_fail']));
                break;
        }
    }

    /* ---------------------------------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------------------------------ */

    /**
     * Character counts, saving when the post type, connection, audience or status changes, the
     * digest's day fields, searchable filters, and opening the designer over the page. Without
     * JavaScript the buttons and links still work.
     */
    protected static function script($email_campaign_id)
    {
        ob_start();
        ?>
        <script type="text/javascript">
            // After every ready handler, so the customizer loader (printed in the footer) has set itself up.
            jQuery(function ($) { setTimeout(function () {
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
                    field.name = 'mo_automation_action';
                    field.value = action;
                    form.appendChild(field);
                    form.submit();
                };

                form.querySelectorAll('[data-autosave]').forEach(function (input) {
                    input.addEventListener('change', function () { submit('save'); });
                });

                // The digest's day of the week or month, for the interval chosen.
                var schedule = form.querySelector('.mo-schedule');
                if (schedule) {
                    var interval = schedule.querySelector('#mo-interval');
                    var sync_interval = function () { schedule.setAttribute('data-interval', interval.value); };
                    interval.addEventListener('change', sync_interval);
                    sync_interval();
                }

                // Searchable filters: 200 choices load with the page, the rest by searching.
                if ($.fn.chosen) {
                    var timer;
                    $(form).find('select.mo-chosen').chosen({width: '100%', search_contains: true}).each(function () {
                        var select = $(this), type = select.data('search-type');
                        if ( ! type) return;
                        select.next('.chosen-container').on('keyup', 'input', function () {
                            var q = this.value;
                            clearTimeout(timer);
                            if (q.length < 3) return;
                            timer = setTimeout(function () {
                                $.getJSON(ajaxurl, {action: 'mailoptin_page_targeting_search', q: q, search_type: type}, function (data) {
                                    var added = false;
                                    $.each(data || {}, function (id, label) {
                                        if (typeof label === 'object' || select.find('option[value="' + parseInt(id, 10) + '"]').length) return;
                                        select.append($('<option>').val(parseInt(id, 10)).text(label));
                                        added = true;
                                    });
                                    if (added) {
                                        select.trigger('chosen:updated');
                                        select.next('.chosen-container').find('input').val(q);
                                    }
                                });
                            }, 350);
                        });
                    });
                }

                var loader = window.wp && wp.customize && wp.customize.Loader;

                // Save the page first, then open the designer over it once it reloads.
                form.querySelectorAll('[data-design]').forEach(function (link) {
                    link.addEventListener('click', function (e) {
                        if ( ! loader) return;
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
.mo-automation .mo-switch{display:flex;gap:8px;align-items:center;margin:6px 0}
.mo-automation .mo-switch .tgl-btn{margin:0}
.mo-automation .mo-filters{display:grid;gap:10px;max-width:560px}
.mo-automation .mo-filter span{display:block;font-size:12px;color:#50575e;margin-bottom:3px}
.mo-automation .mo-when label.mo-inline{display:flex}
.mo-automation .mo-next{max-width:none;background:#f6f7f7;border-color:#dcdcde}
.mo-automation .mo-recent{margin:6px 0}.mo-automation .mo-recent li{margin:0 0 4px}
.mo-automation .mo-bad{color:#b32d2e;font-weight:600}
.mo-js .mo-schedule .mo-for-week,.mo-js .mo-schedule .mo-for-month{display:none}
.mo-js .mo-schedule[data-interval=every_week] .mo-for-week,.mo-js .mo-schedule[data-interval=every_month] .mo-for-month{display:inline-block}
</style>';
    }

    /**
     * @return Automation_Page
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
