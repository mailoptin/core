<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Audience\ConfirmationThrottle;
use MailOptin\Core\Audience\Consent;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Audience\WelcomeEmail;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use W3Guy\Custom_Settings_Page_Api;

use function MailOptin\Core\current_user_has_privilege;
use function MailOptin\Core\do_admin_redirect;
use function MailOptin\Core\get_capability;

/**
 * Subscribers and Lists screens for built-in lists.
 */
class Subscribers_Page extends AbstractSettingsPage
{
    const SLUG = 'mailoptin-subscribers';
    const OPTION_NAME = 'mo_subscribers_page';

    /** @var Subscribers_List */
    protected $list_table;

    public function __construct()
    {
        add_action('mailoptin_register_menu_page', [$this, 'register_settings_page'], 25);

        add_action('mailoptin_admin_settings_page_pre', function ($active_menu) {
            if ( ! $this->is_this_page()) return;

            $instance = Custom_Settings_Page_Api::instance();
            $instance->option_name(self::OPTION_NAME);
            $headers = ['lists' => __('Lists', 'mailoptin'), 'tags' => __('Tags', 'mailoptin'), 'fields' => __('Custom fields', 'mailoptin')];
            $instance->page_header($headers[$active_menu] ?? __('Subscribers', 'mailoptin'));
            $instance->remove_h2_header();
            $this->set_settings_page_instance($instance);
        });

        add_action('mailoptin_admin_settings_submenu_page_subscribers', [$this, 'render_subscribers']);
        add_action('mailoptin_admin_settings_submenu_page_lists', [$this, 'render_lists']);
        add_action('mailoptin_admin_settings_submenu_page_tags', [$this, 'render_tags']);
        add_action('mailoptin_admin_settings_submenu_page_fields', [$this, 'render_fields']);

        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_notices', [$this, 'confirmation_limit_notice']);
    }

    public static function page_url($view = '')
    {
        $url = admin_url('admin.php?page=' . self::SLUG);

        return $view ? add_query_arg('view', $view, $url) : $url;
    }

    public static function action_url($do, $subscriber_id)
    {
        return wp_nonce_url(
            add_query_arg(['mo_subscriber_action' => $do, 'subscriber' => absint($subscriber_id)], self::page_url()),
            'mo_subscriber_' . $do . '_' . absint($subscriber_id)
        );
    }

    protected function is_this_page()
    {
        return is_admin() && isset($_GET['page']) && self::SLUG === $_GET['page'];
    }

    public function register_settings_page()
    {
        $hook = add_submenu_page(
            MAILOPTIN_SETTINGS_SETTINGS_SLUG,
            __('Subscribers - MailOptin', 'mailoptin'),
            __('Subscribers', 'mailoptin'),
            get_capability(),
            self::SLUG,
            [$this, 'admin_page_callback']
        );

        add_action("load-$hook", [$this, 'screen_option']);
    }

    public function screen_option()
    {
        if ('subscribers' !== $this->active_menu_tab() || isset($_GET['subscriber']) || isset($_GET['new'])) return;

        add_screen_option('per_page', [
            'label'   => __('Subscribers per page', 'mailoptin'),
            'default' => 20,
            'option'  => 'mo_subscribers_per_page',
        ]);

        add_filter('set-screen-option', function ($status, $option, $value) {
            return 'mo_subscribers_per_page' === $option ? absint($value) : $status;
        }, 10, 3);

        $this->list_table = new Subscribers_List();
    }

    public function default_header_menu()
    {
        return 'subscribers';
    }

    public function header_menu_tabs()
    {
        $tabs = [
            ['id' => 'subscribers', 'url' => self::page_url(), 'label' => __('Subscribers', 'mailoptin')],
            ['id' => 'lists', 'url' => self::page_url('lists'), 'label' => __('Lists', 'mailoptin')],
        ];

        if (Subscribers_Tags_Fields::is_ready()) {
            $tabs[] = ['id' => 'tags', 'url' => self::page_url('tags'), 'label' => __('Tags', 'mailoptin')];
            $tabs[] = ['id' => 'fields', 'url' => self::page_url('fields'), 'label' => __('Custom fields', 'mailoptin')];
        }

        return $tabs;
    }

    /**
     * Output below the MailOptin header. Not through Custom_Settings_Page_Api::build(), which wraps
     * the content in its own <form>: browsers drop nested forms, so these screens' forms wouldn't work.
     */
    protected function build_page($html)
    {
        echo $this->styles();
        if (Subscribers_Tags_Fields::is_ready()) echo Subscribers_Tags_Fields::styles();
        echo '<div class="wrap mo-subscribers-wrap">' . $html . '</div>';
        if (Subscribers_Tags_Fields::is_ready()) echo Subscribers_Tags_Fields::script();
    }

    /* ---------------------------------------------------------------------------------------------
     * Display helpers
     * ------------------------------------------------------------------------------------------ */

    public static function status_pill($status)
    {
        $labels = [
            Subscribers::STATUS_SUBSCRIBED   => __('Subscribed', 'mailoptin'),
            Subscribers::STATUS_PENDING      => __('Pending', 'mailoptin'),
            Subscribers::STATUS_UNSUBSCRIBED => __('Unsubscribed', 'mailoptin'),
            Subscribers::STATUS_BOUNCED      => __('Bounced', 'mailoptin'),
            Subscribers::STATUS_COMPLAINED   => __('Complained', 'mailoptin'),
        ];

        return sprintf('<span class="mo-pill mo-pill-%s">%s</span>', esc_attr($status), esc_html($labels[$status] ?? $status));
    }

    public static function source_label($subscriber)
    {
        if ( ! empty($subscriber['source_optin_id'])) {
            $name = OptinCampaignsRepository::get_optin_campaign_name($subscriber['source_optin_id']);

            return $name ?: __('Deleted form', 'mailoptin');
        }

        $labels = [
            'admin'  => __('Added by admin', 'mailoptin'),
            'import' => __('CSV import', 'mailoptin'),
            'api'    => __('API', 'mailoptin'),
        ];

        return $labels[$subscriber['source']] ?? __('Form', 'mailoptin');
    }

    protected function local_time($utc)
    {
        if (empty($utc)) return '';

        return get_date_from_gmt($utc, get_option('date_format') . ' · ' . get_option('time_format'));
    }

    protected function notice_html()
    {
        $notices = [
            'added'                => [__('Subscriber added.', 'mailoptin'), 'success'],
            'confirmation_sent'    => [__('Subscriber added. A confirmation email was sent; they stay pending until they click it.', 'mailoptin'), 'success'],
            'refused_unsubscribed' => [__('That address has unsubscribed, so it can\'t be added by an admin. They need to sign up again themselves.', 'mailoptin'), 'error'],
            'invalid'              => [__('Enter a valid email address and choose at least one list.', 'mailoptin'), 'error'],
            'saved'                => [__('Changes saved.', 'mailoptin'), 'success'],
            'resent'               => [__('Confirmation email sent.', 'mailoptin'), 'success'],
            'not_resent'           => [__('No confirmation email was sent. They may already be confirmed, or have no pending lists.', 'mailoptin'), 'warning'],
            'unsubscribed'         => [__('Unsubscribed.', 'mailoptin'), 'success'],
            'deleted'              => [__('Deleted.', 'mailoptin'), 'success'],
            'bulk_done'            => [__('Bulk action applied.', 'mailoptin'), 'success'],
            'bulk_no_list'         => [__('Choose a list in "List for bulk action" first.', 'mailoptin'), 'error'],
            'list_saved'           => [__('List saved.', 'mailoptin'), 'success'],
            'list_deleted'         => [__('List deleted. Its subscribers were kept.', 'mailoptin'), 'success'],
            'list_invalid'         => [__('Give the list a name.', 'mailoptin'), 'error'],
            'welcome_saved'        => [__('Welcome email saved.', 'mailoptin'), 'success'],
            'welcome_test_sent'    => [__('Test email sent. Check your inbox.', 'mailoptin'), 'success'],
            'welcome_test_failed'  => [__('The test email could not be sent. Check your site can send email, for example with an SMTP plugin.', 'mailoptin'), 'error'],
            'tag_added'            => [__('Tag added.', 'mailoptin'), 'success'],
            'tag_renamed'          => [__('Tag renamed.', 'mailoptin'), 'success'],
            'tag_merged'           => [__('Tags merged.', 'mailoptin'), 'success'],
            'tag_not_merged'       => [__('Choose a different tag to merge into.', 'mailoptin'), 'error'],
            'tag_deleted'          => [__('Tag deleted. Its subscribers were kept.', 'mailoptin'), 'success'],
            'field_added'          => [defined('MAILOPTIN_DETACH_LIBSODIUM') ? __('Field added. Map it to a form field in the form\'s Integrations settings, or use its merge tag in emails.', 'mailoptin') : __('Field added. Use its merge tag in emails, set it on profiles or in bulk, and map it from forms that send custom fields.', 'mailoptin'), 'success'],
            'field_saved'          => [__('Field saved.', 'mailoptin'), 'success'],
            'field_deleted'        => [__('Field deleted.', 'mailoptin'), 'success'],
            'fields_reordered'     => [__('Order saved.', 'mailoptin'), 'success'],
            'error'                => [__('That didn\'t work. Please try again.', 'mailoptin'), 'error'],
        ];

        $key = sanitize_key($_GET['mo_notice'] ?? '');

        // Errors with details, like which field value wasn't valid. The field panel shows its own.
        if (in_array($key, ['error', 'saved'], true) && ! ('fields' === $this->active_menu_tab() && isset($_GET['field']))) {
            $error = Subscribers_Tags_Fields::take_error();

            if ($error) {
                $prefix = 'saved' === $key ? __('Changes saved, except:', 'mailoptin') . ' ' : '';

                return sprintf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'saved' === $key ? 'warning' : 'error', esc_html($prefix . $error['message']));
            }
        }

        if ( ! isset($notices[$key])) return '';

        return sprintf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notices[$key][1]), esc_html($notices[$key][0]));
    }

    /* ---------------------------------------------------------------------------------------------
     * Subscribers tab
     * ------------------------------------------------------------------------------------------ */

    public function render_subscribers()
    {
        if ( ! $this->is_this_page()) return;

        if ( ! empty($_GET['subscriber'])) {
            $this->build_page($this->notice_html() . $this->profile_html(absint($_GET['subscriber'])));

            return;
        }

        if (isset($_GET['new'])) {
            $this->build_page($this->notice_html() . $this->add_form_html());

            return;
        }

        if ( ! $this->list_table) $this->list_table = new Subscribers_List();

        $this->list_table->prepare_items();

        ob_start();
        ?>
        <?php echo $this->notice_html(); ?>
        <p><a class="button button-primary" href="<?php echo esc_url(add_query_arg('new', '1', self::page_url())); ?>"><?php esc_html_e('Add New', 'mailoptin'); ?></a></p>
        <?php $this->list_table->views(); ?>
        <?php if (Subscribers_Tags_Fields::is_ready()) echo Subscribers_Tags_Fields::filter_bar_html($this->list_table->filters); ?>
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <?php if ( ! empty($_GET['status'])) : ?>
                <input type="hidden" name="status" value="<?php echo esc_attr(sanitize_key($_GET['status'])); ?>">
            <?php endif; ?>
            <?php echo Subscriber_Filters::hidden_inputs($this->list_table->filters); ?>
            <?php $this->list_table->search_box(Subscribers_Tags_Fields::is_ready() ? __('Search name, email or any field', 'mailoptin') : __('Search email or name', 'mailoptin'), 'mo-subscriber-search'); ?>
            <?php $this->list_table->display(); ?>
        </form>
        <?php // Outside the table's form: the dialogs have forms of their own, and browsers drop nested forms. ?>
        <?php if (Subscribers_Tags_Fields::is_ready()) echo Subscribers_Tags_Fields::bulk_bar_html(); ?>
        <?php
        $this->build_page(ob_get_clean());
    }

    protected function list_checkboxes($checked = [])
    {
        $lists = ListsRepository::get_all();

        if (empty($lists)) {
            return sprintf(
                '<p>%s <a href="%s">%s</a></p>',
                esc_html__('There are no lists yet.', 'mailoptin'),
                esc_url(self::page_url('lists')),
                esc_html__('Create one', 'mailoptin')
            );
        }

        $html = '';
        foreach ($lists as $list) {
            $html .= sprintf(
                '<label class="mo-check"><input type="checkbox" name="list_ids[]" value="%d" %s> %s</label>',
                absint($list['id']),
                checked(isset($checked[$list['id']]), true, false),
                esc_html($list['name'])
            );
        }

        return $html;
    }

    protected function add_form_html()
    {
        ob_start();
        ?>
        <p><a href="<?php echo esc_url(self::page_url()); ?>">&larr; <?php esc_html_e('All subscribers', 'mailoptin'); ?></a></p>
        <div class="mo-card mo-narrow">
            <h3><?php esc_html_e('Add a subscriber', 'mailoptin'); ?></h3>
            <form method="post" action="<?php echo esc_url(self::page_url()); ?>">
                <?php wp_nonce_field('mo_add_subscriber'); ?>
                <input type="hidden" name="mo_subscriber_action" value="add">
                <p><label for="mo-email"><?php esc_html_e('Email', 'mailoptin'); ?></label><br><input class="regular-text" type="email" id="mo-email" name="email" required></p>
                <p><label for="mo-first-name"><?php esc_html_e('First name', 'mailoptin'); ?></label><br><input class="regular-text" type="text" id="mo-first-name" name="first_name"></p>
                <p><label for="mo-last-name"><?php esc_html_e('Last name', 'mailoptin'); ?></label><br><input class="regular-text" type="text" id="mo-last-name" name="last_name"></p>
                <fieldset><legend><?php esc_html_e('Lists', 'mailoptin'); ?></legend><?php echo $this->list_checkboxes(); ?></fieldset>
                <fieldset>
                    <legend><?php esc_html_e('Permission', 'mailoptin'); ?></legend>
                    <label class="mo-check"><input type="radio" name="consent" value="confirm" checked> <?php esc_html_e('Send them a confirmation email (recommended)', 'mailoptin'); ?></label>
                    <label class="mo-check"><input type="radio" name="consent" value="has_consent"> <?php esc_html_e('Mark as subscribed. I have their permission to email them.', 'mailoptin'); ?></label>
                </fieldset>
                <?php submit_button(__('Add subscriber', 'mailoptin')); ?>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    protected function event_label($event)
    {
        $meta = json_decode((string)$event['meta'], true) ?: [];

        $label = Subscribers_Tags_Fields::event_label($event, $meta);

        if (null !== $label) return $label;

        switch ($event['type']) {
            case Subscribers::EVENT_SIGNUP:
                if ( ! empty($meta['added_by_admin'])) return __('Added by an admin', 'mailoptin');

                $list  = ! empty($meta['list_id']) ? ListsRepository::get($meta['list_id']) : null;
                $label = $list ? sprintf(__('Signed up for %s', 'mailoptin'), $list['name']) : __('Signed up', 'mailoptin');
                if ( ! empty($meta['form'])) $label .= ' ' . sprintf(__('from %s', 'mailoptin'), $meta['form']);
                if ( ! empty($meta['conversion_page'])) $label .= ' ' . sprintf(__('on %s', 'mailoptin'), self::page_path($meta['conversion_page']));

                return $label . Subscribers_Tags_Fields::signup_suffix($meta);
            case Subscribers::EVENT_CONFIRMATION_SENT:
                return sprintf(__('Confirmation email sent for %s', 'mailoptin'), implode(', ', (array)($meta['lists'] ?? [])));
            case Subscribers::EVENT_CONFIRMATION_THROTTLED:
                return __('Confirmation email not sent: a sending limit was reached', 'mailoptin');
            case Subscribers::EVENT_CONFIRMED:
                return __('Confirmed their email', 'mailoptin');
            case Subscribers::EVENT_UNSUBSCRIBED:
                $list = ! empty($meta['list_id']) ? ListsRepository::get($meta['list_id']) : null;

                return $list ? sprintf(__('Unsubscribed from %s', 'mailoptin'), $list['name']) : __('Unsubscribed from all lists', 'mailoptin');
            case Subscribers::EVENT_SENT:
                return __('Sent a campaign email', 'mailoptin');
        }

        return $event['type'];
    }

    /**
     * Path and query of a page URL, so "/?page_id=6" doesn't show as "/".
     */
    public static function page_path($url)
    {
        $parts = wp_parse_url($url);

        if ( ! is_array($parts) || empty($parts['path']) && empty($parts['query'])) return $url;

        return ($parts['path'] ?? '/') . ( ! empty($parts['query']) ? '?' . $parts['query'] : '');
    }

    protected function latest_signup_page($subscriber_id)
    {
        foreach (Subscribers::get_events($subscriber_id, 50) as $event) {
            if (Subscribers::EVENT_SIGNUP !== $event['type']) continue;

            $meta = json_decode((string)$event['meta'], true) ?: [];

            return empty($meta['conversion_page']) ? '' : self::page_path($meta['conversion_page']);
        }

        return '';
    }

    protected function profile_html($subscriber_id)
    {
        $subscriber = Subscribers::get($subscriber_id);

        if ( ! $subscriber) {
            return '<p>' . esc_html__('This subscriber no longer exists.', 'mailoptin') . '</p>';
        }

        $memberships = [];
        foreach (Subscribers::get_memberships($subscriber_id) as $membership) {
            if (Subscribers::STATUS_UNSUBSCRIBED !== $membership['status']) {
                $memberships[$membership['list_id']] = $membership['status'];
            }
        }

        $has_pending = in_array(Subscribers::STATUS_PENDING, $memberships, true);

        ob_start();
        ?>
        <p><a href="<?php echo esc_url(self::page_url()); ?>">&larr; <?php esc_html_e('All subscribers', 'mailoptin'); ?></a></p>
        <div class="mo-profile-head">
            <h2><?php echo esc_html($subscriber['email']); ?> <?php echo self::status_pill($subscriber['status']); ?></h2>
            <div class="mo-profile-actions">
                <?php if ($has_pending) : ?>
                    <a class="button" href="<?php echo esc_url(self::action_url('resend', $subscriber_id)); ?>"><?php esc_html_e('Resend confirmation', 'mailoptin'); ?></a>
                <?php endif; ?>
                <?php if (Subscribers::STATUS_UNSUBSCRIBED !== $subscriber['status']) : ?>
                    <a class="button" href="<?php echo esc_url(self::action_url('unsubscribe', $subscriber_id)); ?>"><?php esc_html_e('Unsubscribe from all', 'mailoptin'); ?></a>
                <?php endif; ?>
                <a class="button mo-delete-prompt" href="<?php echo esc_url(self::action_url('delete', $subscriber_id)); ?>"><?php esc_html_e('Delete', 'mailoptin'); ?></a>
            </div>
        </div>
        <div class="mo-columns">
            <div class="mo-col-main">
                <form method="post" action="<?php echo esc_url(add_query_arg('subscriber', $subscriber_id, self::page_url())); ?>" class="mo-card" id="mo-profile-form">
                    <?php wp_nonce_field('mo_update_subscriber_' . $subscriber_id); ?>
                    <input type="hidden" name="mo_subscriber_action" value="update">
                    <input type="hidden" name="subscriber" value="<?php echo absint($subscriber_id); ?>">
                    <h3><?php esc_html_e('Details', 'mailoptin'); ?></h3>
                    <p><label for="mo-first-name"><?php esc_html_e('First name', 'mailoptin'); ?></label><br><input class="regular-text" type="text" id="mo-first-name" name="first_name" value="<?php echo esc_attr($subscriber['first_name']); ?>"></p>
                    <p><label for="mo-last-name"><?php esc_html_e('Last name', 'mailoptin'); ?></label><br><input class="regular-text" type="text" id="mo-last-name" name="last_name" value="<?php echo esc_attr($subscriber['last_name']); ?>"></p>
                    <?php if (Subscribers_Tags_Fields::is_ready()) echo Subscribers_Tags_Fields::profile_fields_html($subscriber_id); ?>
                    <h3><?php esc_html_e('Lists', 'mailoptin'); ?></h3>
                    <p class="description"><?php esc_html_e('Unticking a list unsubscribes them from it. Ticking a list adds them; if they haven\'t confirmed their email yet they stay pending.', 'mailoptin'); ?></p>
                    <?php echo $this->list_checkboxes($memberships); ?>
                    <?php submit_button(__('Save changes', 'mailoptin')); ?>
                </form>
                <div class="mo-card">
                    <h3><?php esc_html_e('Activity', 'mailoptin'); ?></h3>
                    <ul class="mo-timeline">
                        <?php foreach (Subscribers::get_events($subscriber_id, 50) as $event) : ?>
                            <li><?php echo esc_html($this->event_label($event)); ?><small><?php echo esc_html($this->local_time($event['created_at'])); ?></small></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div class="mo-col-side">
                <?php if (Subscribers_Tags_Fields::is_ready()) echo Subscribers_Tags_Fields::profile_tags_html($subscriber_id, 'mo-profile-form'); ?>
                <div class="mo-card">
                    <h3><?php esc_html_e('Consent record', 'mailoptin'); ?></h3>
                    <dl class="mo-kv">
                        <dt><?php esc_html_e('Source', 'mailoptin'); ?></dt>
                        <dd><?php echo esc_html(self::source_label($subscriber)); ?></dd>
                        <?php if ( ! empty($subscriber['consent_text'])) : ?>
                            <dt><?php esc_html_e('Agreed to', 'mailoptin'); ?></dt>
                            <dd>&ldquo;<?php echo esc_html($subscriber['consent_text']); ?>&rdquo;</dd>
                        <?php endif; ?>
                        <?php if (Consent::method_label($subscriber['consent_method'] ?? '')) : ?>
                            <dt><?php esc_html_e('How', 'mailoptin'); ?></dt>
                            <dd class="mo-consent-<?php echo esc_attr($subscriber['consent_method']); ?>"><?php echo esc_html(Consent::method_label($subscriber['consent_method'])); ?></dd>
                        <?php endif; ?>
                        <?php if ($signup_page = $this->latest_signup_page($subscriber_id)) : ?>
                            <dt><?php esc_html_e('Page', 'mailoptin'); ?></dt>
                            <dd><?php echo esc_html($signup_page); ?></dd>
                        <?php endif; ?>
                        <dt><?php esc_html_e('IP address', 'mailoptin'); ?></dt>
                        <dd><?php echo esc_html($subscriber['ip_address'] ?: '—'); ?></dd>
                        <dt><?php esc_html_e('Recorded', 'mailoptin'); ?></dt>
                        <dd><?php echo esc_html($this->local_time($subscriber['consent_at']) ?: '—'); ?></dd>
                        <dt><?php esc_html_e('Confirmed', 'mailoptin'); ?></dt>
                        <dd><?php echo esc_html($this->local_time($subscriber['confirmed_at']) ?: __('Not yet', 'mailoptin')); ?></dd>
                    </dl>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------------------------------
     * Tags and Custom fields tabs
     * ------------------------------------------------------------------------------------------ */

    public function render_tags()
    {
        if ( ! $this->is_this_page() || ! Subscribers_Tags_Fields::is_ready()) return;

        $this->build_page($this->notice_html() . Subscribers_Tags_Fields::tags_html());
    }

    public function render_fields()
    {
        if ( ! $this->is_this_page() || ! Subscribers_Tags_Fields::is_ready()) return;

        $this->build_page($this->notice_html() . Subscribers_Tags_Fields::fields_html());
    }

    /* ---------------------------------------------------------------------------------------------
     * Lists tab
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return array list_id => number of optin forms sending to it
     */
    protected function forms_per_list()
    {
        $counts = [];

        foreach (OptinCampaignsRepository::get_optin_campaign_ids() as $optin_campaign_id) {
            $integrations = json_decode((string)OptinCampaignsRepository::get_customizer_value($optin_campaign_id, 'integrations', ''), true);

            if ( ! is_array($integrations)) continue;

            foreach ($integrations as $integration) {
                if (($integration['connection_service'] ?? '') !== 'MailOptinConnect') continue;

                $list_id          = absint($integration['connection_email_list'] ?? 0);
                $counts[$list_id] = ($counts[$list_id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function render_lists()
    {
        if ( ! $this->is_this_page()) return;

        if ( ! empty($_GET['welcome']) && ($welcome_list = ListsRepository::get(absint($_GET['welcome'])))) {
            $this->build_page($this->notice_html() . $this->welcome_editor_html($welcome_list));

            return;
        }

        $editing = ! empty($_GET['edit']) ? ListsRepository::get(absint($_GET['edit'])) : null;
        $lists   = ListsRepository::get_all();
        $counts  = ListsRepository::member_counts();
        $forms   = $this->forms_per_list();

        ob_start();
        echo $this->notice_html();
        ?>
        <div class="mo-columns">
            <div class="mo-col-side mo-col-first">
                <form method="post" action="<?php echo esc_url(self::page_url('lists')); ?>" class="mo-card">
                    <?php wp_nonce_field('mo_save_list'); ?>
                    <input type="hidden" name="mo_subscriber_action" value="save_list">
                    <input type="hidden" name="list_id" value="<?php echo absint($editing['id'] ?? 0); ?>">
                    <h3><?php echo $editing ? esc_html__('Edit list', 'mailoptin') : esc_html__('Add new list', 'mailoptin'); ?></h3>
                    <p><label for="mo-list-name"><?php esc_html_e('Name', 'mailoptin'); ?></label><br><input class="widefat" type="text" id="mo-list-name" name="name" value="<?php echo esc_attr($editing['name'] ?? ''); ?>" required></p>
                    <p><label for="mo-list-description"><?php esc_html_e('Description', 'mailoptin'); ?></label><br><textarea class="widefat" id="mo-list-description" name="description" rows="3"><?php echo esc_textarea($editing['description'] ?? ''); ?></textarea></p>
                    <p>
                        <label class="mo-check"><input type="checkbox" name="double_optin" value="1" <?php checked( ! $editing || $editing['double_optin']); ?>> <?php esc_html_e('Require double opt-in', 'mailoptin'); ?></label>
                        <span class="description"><?php esc_html_e('New subscribers get a confirmation email and stay pending until they click it.', 'mailoptin'); ?></span>
                    </p>
                    <p>
                        <label class="mo-check"><input type="checkbox" name="welcome_email" value="1" <?php checked( ! empty($editing['welcome_email'])); ?>> <strong><?php esc_html_e('Send a welcome email', 'mailoptin'); ?></strong></label>
                        <span class="description"><?php esc_html_e('Sent once when someone joins this list from a form: right after they confirm, or straight away on lists without double opt-in. People you add with permission already on file don\'t get it; people you invite get it once they confirm.', 'mailoptin'); ?></span>
                    </p>
                    <?php if ($editing) : ?>
                        <p class="mo-welcome-summary">
                            <?php printf(esc_html__('Subject: %s', 'mailoptin'), '<strong>' . esc_html(WelcomeEmail::subject($editing)) . '</strong>'); ?><br>
                            <a class="button" href="<?php echo esc_url(add_query_arg('welcome', absint($editing['id']), self::page_url('lists'))); ?>"><?php esc_html_e('Edit welcome email', 'mailoptin'); ?></a>
                        </p>
                    <?php endif; ?>
                    <?php submit_button($editing ? __('Save list', 'mailoptin') : __('Add list', 'mailoptin')); ?>
                    <?php if ($editing) : ?>
                        <a href="<?php echo esc_url(self::page_url('lists')); ?>"><?php esc_html_e('Cancel', 'mailoptin'); ?></a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="mo-col-main">
                <table class="wp-list-table widefat striped mo-lists-table">
                    <thead>
                    <tr>
                        <th><?php esc_html_e('List', 'mailoptin'); ?></th>
                        <th><?php esc_html_e('Subscribed', 'mailoptin'); ?></th>
                        <th><?php esc_html_e('Pending', 'mailoptin'); ?></th>
                        <th><?php esc_html_e('Double opt-in', 'mailoptin'); ?></th>
                        <th><?php esc_html_e('Forms using it', 'mailoptin'); ?></th>
                        <th><?php esc_html_e('Welcome email', 'mailoptin'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($lists)) : ?>
                        <tr><td colspan="6"><?php esc_html_e('No lists yet. Add your first list, then connect a form to "MailOptin (built-in)".', 'mailoptin'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($lists as $list) :
                        $list_id = absint($list['id']);
                        $delete_url = wp_nonce_url(add_query_arg(['mo_subscriber_action' => 'delete_list', 'list_id' => $list_id], self::page_url('lists')), 'mo_delete_list_' . $list_id);
                        ?>
                        <tr>
                            <td>
                                <strong><a class="row-title" href="<?php echo esc_url(add_query_arg('edit', $list_id, self::page_url('lists'))); ?>"><?php echo esc_html($list['name']); ?></a></strong>
                                <?php if ( ! empty($list['description'])) : ?><br><span class="mo-muted"><?php echo esc_html($list['description']); ?></span><?php endif; ?>
                                <div class="row-actions">
                                    <a href="<?php echo esc_url(add_query_arg('edit', $list_id, self::page_url('lists'))); ?>"><?php esc_html_e('Edit', 'mailoptin'); ?></a> |
                                    <a href="<?php echo esc_url(add_query_arg('list', $list_id, self::page_url())); ?>"><?php esc_html_e('View subscribers', 'mailoptin'); ?></a> |
                                    <span class="trash"><a class="submitdelete mo-delete-prompt" href="<?php echo esc_url($delete_url); ?>"><?php esc_html_e('Delete', 'mailoptin'); ?></a></span>
                                </div>
                            </td>
                            <td><?php echo esc_html(number_format_i18n($counts[$list_id]['subscribed'] ?? 0)); ?></td>
                            <td><?php echo esc_html(number_format_i18n($counts[$list_id]['pending'] ?? 0)); ?></td>
                            <td><?php echo $list['double_optin'] ? esc_html__('On', 'mailoptin') : esc_html__('Off', 'mailoptin'); ?></td>
                            <td><?php echo esc_html(number_format_i18n($forms[$list_id] ?? 0)); ?></td>
                            <td>
                                <?php if ( ! empty($list['welcome_email'])) : ?>
                                    <span class="mo-pill mo-pill-subscribed"><?php esc_html_e('On', 'mailoptin'); ?></span>
                                    <span class="mo-muted"><?php printf(esc_html(_n('%s sent', '%s sent', WelcomeEmail::sent_count($list), 'mailoptin')), esc_html(number_format_i18n(WelcomeEmail::sent_count($list)))); ?></span>
                                <?php else : ?>
                                    <span class="mo-muted"><?php esc_html_e('Off', 'mailoptin'); ?></span>
                                <?php endif; ?>
                                <div class="row-actions"><a href="<?php echo esc_url(add_query_arg('welcome', $list_id, self::page_url('lists'))); ?>"><?php esc_html_e('Edit welcome email', 'mailoptin'); ?></a></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e('Deleting a list never deletes subscribers, only their membership of that list.', 'mailoptin'); ?></p>
            </div>
        </div>
        <?php
        $this->build_page(ob_get_clean());
    }

    protected function welcome_editor_html($list)
    {
        $list_id  = absint($list['id']);
        $back_url = add_query_arg('edit', $list_id, self::page_url('lists'));
        $test_url = wp_nonce_url(add_query_arg(['mo_subscriber_action' => 'send_welcome_test', 'list_id' => $list_id], self::page_url('lists')), 'mo_send_welcome_test_' . $list_id);
        $tags     = array_merge(['{{subscriber.first_name|there}}', '{{subscriber.first_name}}', '{{subscriber.last_name}}', '{{subscriber.email}}', '{{list.name}}'], Subscribers_Tags_Fields::merge_tag_list());

        ob_start();
        ?>
        <p><a href="<?php echo esc_url($back_url); ?>">&larr; <?php echo esc_html($list['name']); ?></a></p>
        <?php if ('' === Subscription::postal_footer()) : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e('Add your company name and postal address under MailOptin Settings › Email Campaign. Anti-spam law (CAN-SPAM) requires a postal address in marketing email, and it\'s added to the footer automatically.', 'mailoptin'); ?></p></div>
        <?php endif; ?>
        <?php if (empty($list['welcome_email'])) : ?>
            <div class="notice notice-info inline"><p><?php esc_html_e('The welcome email is off for this list. Turn it on in the list settings, or save here with "Send this email" ticked.', 'mailoptin'); ?></p></div>
        <?php endif; ?>
        <div class="mo-columns">
            <form method="post" action="<?php echo esc_url(add_query_arg('welcome', $list_id, self::page_url('lists'))); ?>" class="mo-card mo-col-main">
                <?php wp_nonce_field('mo_save_welcome_' . $list_id); ?>
                <input type="hidden" name="mo_subscriber_action" value="save_welcome">
                <input type="hidden" name="list_id" value="<?php echo $list_id; ?>">
                <h3><?php printf(esc_html__('Welcome email for %s', 'mailoptin'), esc_html($list['name'])); ?></h3>
                <p><label class="mo-check"><input type="checkbox" name="welcome_email" value="1" <?php checked( ! empty($list['welcome_email'])); ?>> <?php esc_html_e('Send this email to new subscribers of this list', 'mailoptin'); ?></label></p>
                <p><label for="mo-welcome-subject"><?php esc_html_e('Subject', 'mailoptin'); ?></label><br><input class="widefat" type="text" id="mo-welcome-subject" name="welcome_subject" value="<?php echo esc_attr(WelcomeEmail::subject($list)); ?>"></p>
                <p><label for="mo_welcome_body"><?php esc_html_e('Message', 'mailoptin'); ?></label></p>
                <?php wp_editor(WelcomeEmail::body($list), 'mo_welcome_body', ['textarea_name' => 'welcome_body', 'textarea_rows' => 12, 'media_buttons' => true]); ?>
                <p class="description"><?php esc_html_e('Your company name, postal address and an unsubscribe link are added at the bottom automatically.', 'mailoptin'); ?></p>
                <p>
                    <?php submit_button(__('Save', 'mailoptin'), 'primary', 'submit', false); ?>
                    <a class="button" href="<?php echo esc_url($test_url); ?>"><?php printf(esc_html__('Send test to %s', 'mailoptin'), esc_html(wp_get_current_user()->user_email)); ?></a>
                </p>
                <p class="description"><?php esc_html_e('The test uses the saved version, so save your changes first.', 'mailoptin'); ?></p>
            </form>
            <div class="mo-card mo-col-side">
                <h3><?php esc_html_e('Merge tags', 'mailoptin'); ?></h3>
                <p class="description"><?php esc_html_e('Click to insert into the message.', 'mailoptin'); ?></p>
                <p><?php foreach ($tags as $tag) : ?><button type="button" class="button-link mo-merge-tag" data-tag="<?php echo esc_attr($tag); ?>"><code><?php echo esc_html($tag); ?></code></button><br><?php endforeach; ?></p>
                <p class="description"><?php esc_html_e('{{subscriber.first_name|there}} shows "there" for people who didn\'t give a first name.', 'mailoptin'); ?></p>
                <h3><?php esc_html_e('Delivery', 'mailoptin'); ?></h3>
                <p class="description"><?php esc_html_e('Goes through the send queue like campaigns, so it counts toward the hourly limit and is retried if sending fails. Each person gets it once per list.', 'mailoptin'); ?></p>
                <p class="description"><?php printf(esc_html__('Sent so far: %s', 'mailoptin'), esc_html(number_format_i18n(WelcomeEmail::sent_count($list)))); ?></p>
            </div>
        </div>
        <script>
            document.addEventListener('click', function (e) {
                var button = e.target.closest('.mo-merge-tag');
                if (!button) return;
                window.wpActiveEditor = 'mo_welcome_body';
                if (window.send_to_editor) window.send_to_editor(button.getAttribute('data-tag'));
            });
        </script>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------------------------------------ */

    /**
     * do_admin_redirect() only exits when headers aren't sent yet, so callers return its result.
     *
     * @return true
     */
    protected function redirect($notice, $url = '')
    {
        do_admin_redirect(add_query_arg('mo_notice', $notice, $url ?: self::page_url()));

        return true;
    }

    public function handle_actions()
    {
        if ( ! $this->is_this_page() || ! current_user_has_privilege()) return;

        if ($this->handle_bulk_action()) return;

        $action = sanitize_key($_REQUEST['mo_subscriber_action'] ?? '');

        if (empty($action)) return;

        $subscriber_id = absint($_REQUEST['subscriber'] ?? 0);

        if (Subscribers_Tags_Fields::is_ready()) {
            $handled = Subscribers_Tags_Fields::handle_action($action);

            if ($handled) return $this->redirect($handled[0], $handled[1]);
        }

        switch ($action) {
            case 'add':
                check_admin_referer('mo_add_subscriber');

                $result = Subscription::add_by_admin(
                    wp_unslash($_POST['email'] ?? ''),
                    $_POST['list_ids'] ?? [],
                    ['first_name' => wp_unslash($_POST['first_name'] ?? ''), 'last_name' => wp_unslash($_POST['last_name'] ?? '')],
                    'has_consent' === ($_POST['consent'] ?? '')
                );

                if (is_wp_error($result)) return $this->redirect('invalid', add_query_arg('new', '1', self::page_url()));

                if (Subscription::ADMIN_REFUSED_UNSUBSCRIBED === $result) return $this->redirect($result, add_query_arg('new', '1', self::page_url()));

                $subscriber = Subscribers::get_by_email(wp_unslash($_POST['email']));
                return $this->redirect($result, add_query_arg('subscriber', $subscriber['id'], self::page_url()));
                break;

            case 'update':
                check_admin_referer('mo_update_subscriber_' . $subscriber_id);
                $this->update_subscriber($subscriber_id);
                return $this->redirect('saved', add_query_arg('subscriber', $subscriber_id, self::page_url()));
                break;

            case 'resend':
                check_admin_referer('mo_subscriber_resend_' . $subscriber_id);
                $sent = Subscription::send_confirmation($subscriber_id, '', true);
                return $this->redirect($sent ? 'resent' : 'not_resent', add_query_arg('subscriber', $subscriber_id, self::page_url()));
                break;

            case 'unsubscribe':
                check_admin_referer('mo_subscriber_unsubscribe_' . $subscriber_id);
                Subscription::unsubscribe($subscriber_id);
                return $this->redirect('unsubscribed', wp_get_referer() ?: self::page_url());
                break;

            case 'delete':
                check_admin_referer('mo_subscriber_delete_' . $subscriber_id);
                Subscribers::delete($subscriber_id);
                return $this->redirect('deleted');
                break;

            case 'save_list':
                check_admin_referer('mo_save_list');
                $this->save_list();
                break;

            case 'save_welcome':
                $list_id = absint($_POST['list_id'] ?? 0);
                check_admin_referer('mo_save_welcome_' . $list_id);
                WelcomeEmail::save($list_id, ! empty($_POST['welcome_email']), wp_unslash($_POST['welcome_subject'] ?? ''), wp_unslash($_POST['welcome_body'] ?? ''));

                return $this->redirect('welcome_saved', add_query_arg('welcome', $list_id, self::page_url('lists')));

            case 'send_welcome_test':
                $list_id = absint($_GET['list_id'] ?? 0);
                check_admin_referer('mo_send_welcome_test_' . $list_id);
                $sent = WelcomeEmail::send_test($list_id, wp_get_current_user()->user_email);

                return $this->redirect($sent ? 'welcome_test_sent' : 'welcome_test_failed', add_query_arg('welcome', $list_id, self::page_url('lists')));

            case 'delete_list':
                $list_id = absint($_GET['list_id'] ?? 0);
                check_admin_referer('mo_delete_list_' . $list_id);
                ListsRepository::delete($list_id);
                return $this->redirect('list_deleted', self::page_url('lists'));
                break;
        }
    }

    protected function update_subscriber($subscriber_id)
    {
        $subscriber = Subscribers::get($subscriber_id);

        if ( ! $subscriber) return;

        Subscribers::update($subscriber_id, [
            'first_name' => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
            'last_name'  => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
        ]);

        if (Subscribers_Tags_Fields::is_ready()) {
            $errors = Subscribers_Tags_Fields::save_profile($subscriber_id, $_POST);

            if ( ! empty($errors)) Subscribers_Tags_Fields::set_error(implode(' ', $errors));
        }

        $wanted  = array_map('absint', (array)($_POST['list_ids'] ?? []));
        $current = [];
        $left    = [];

        foreach (Subscribers::get_memberships($subscriber_id) as $membership) {
            if (Subscribers::STATUS_UNSUBSCRIBED === $membership['status']) {
                $left[] = (int)$membership['list_id'];
            } else {
                $current[] = (int)$membership['list_id'];
            }
        }

        // Someone who unsubscribed from a list can only rejoin it themselves, like the bulk "Add to list".
        $wanted = array_diff($wanted, $left);

        // Add before removing: moving someone from one list to another must not leave them on no list
        // in between, which would unsubscribe and suppress them.
        $status = Subscribers::STATUS_SUBSCRIBED === $subscriber['status'] ? Subscribers::STATUS_SUBSCRIBED : Subscribers::STATUS_PENDING;

        foreach (array_diff($wanted, $current) as $list_id) {
            if (ListsRepository::get($list_id)) Subscribers::set_membership($subscriber_id, $list_id, $status);
        }

        Subscribers::sync_status($subscriber_id);

        foreach (array_diff($current, $wanted) as $list_id) {
            Subscription::unsubscribe($subscriber_id, $list_id);
        }
    }

    protected function save_list()
    {
        $list_id = absint($_POST['list_id'] ?? 0);
        $name    = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));

        if ('' === $name) return $this->redirect('list_invalid', self::page_url('lists'));

        $data = [
            'name'         => $name,
            'description'  => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'double_optin' => empty($_POST['double_optin']) ? 0 : 1,
            'welcome_email' => empty($_POST['welcome_email']) ? 0 : 1,
        ];

        $list_id ? ListsRepository::update($list_id, $data) : ListsRepository::create($data);

        // Lists are cached for a minute in the form and campaign customizers.
        delete_transient('mo_connection_email_list_MailOptinConnect');

        return $this->redirect('list_saved', self::page_url('lists'));
    }

    /**
     * @return bool true when a bulk action was handled
     */
    protected function handle_bulk_action()
    {
        if (empty($_REQUEST['subscriber_ids'])) return false;

        $action = $_REQUEST['action'] ?? '-1';
        if ('-1' === $action) $action = $_REQUEST['action2'] ?? '-1';
        $action = sanitize_key($action);

        if ('-1' === $action || '' === $action) return false;

        check_admin_referer('bulk-subscribers');

        $ids     = array_map('absint', (array)$_REQUEST['subscriber_ids']);
        $list_id = absint($_REQUEST['mo_bulk_list'] ?? 0);
        $back    = remove_query_arg(['action', 'action2', 'subscriber_ids', '_wpnonce', '_wp_http_referer', 'mo_bulk_list', 'mo_notice'], wp_get_referer() ?: self::page_url());

        if (in_array($action, ['add_to_list', 'remove_from_list'], true) && ! ListsRepository::get($list_id)) {
            return $this->redirect('bulk_no_list', $back);
        }

        if (Subscribers_Tags_Fields::is_ready()) {
            $notice = Subscribers_Tags_Fields::handle_bulk($action, array_values(array_filter(array_map(function ($id) {
                return Subscribers::get($id) ? $id : 0;
            }, $ids))), $_REQUEST);

            if (null !== $notice) return $this->redirect($notice, $back);
        }

        foreach ($ids as $subscriber_id) {
            $subscriber = Subscribers::get($subscriber_id);
            if ( ! $subscriber) continue;

            switch ($action) {
                case 'add_to_list':
                    $status = Subscribers::STATUS_SUBSCRIBED === $subscriber['status'] ? Subscribers::STATUS_SUBSCRIBED : Subscribers::STATUS_PENDING;
                    // Never re-add someone who unsubscribed from this list.
                    $membership = Subscribers::get_membership($subscriber_id, $list_id);
                    if ($membership && Subscribers::STATUS_UNSUBSCRIBED === $membership['status']) break;
                    Subscribers::set_membership($subscriber_id, $list_id, $status);
                    Subscribers::sync_status($subscriber_id);
                    break;
                case 'remove_from_list':
                    Subscription::unsubscribe($subscriber_id, $list_id);
                    break;
                case 'resend_confirmation':
                    Subscription::send_confirmation($subscriber_id, '', true);
                    break;
                case 'unsubscribe':
                    Subscription::unsubscribe($subscriber_id);
                    break;
                case 'delete':
                    Subscribers::delete($subscriber_id);
                    break;
            }
        }

        return $this->redirect('bulk_done', $back);
    }

    /**
     * Shown on MailOptin screens when the hourly limit on confirmation emails was reached in the last day.
     */
    public function confirmation_limit_notice()
    {
        if ( ! current_user_has_privilege()) return;

        $screen = get_current_screen();
        if ( ! $screen || false === strpos($screen->id, 'mailoptin')) return;

        $reached_at = (int)get_option(ConfirmationThrottle::SITE_LIMIT_REACHED_OPTION);
        if ($reached_at < time() - DAY_IN_SECONDS) return;

        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('Unusually many signups needed confirmation emails in one hour, so some were not sent. This is often a bot using your form. Those signups are kept as pending and you can resend from their profile.', 'mailoptin'),
            esc_url(add_query_arg('status', Subscribers::STATUS_PENDING, self::page_url())),
            esc_html__('Review pending subscribers', 'mailoptin')
        );
    }

    protected function styles()
    {
        return '<style>
.mo-pill{display:inline-block;padding:1px 8px;border-radius:99px;font-size:12px;font-weight:600;background:#f0f0f1;color:#50575e}
.mo-pill-subscribed{background:#edfaef;color:#00701a}
.mo-pill-pending{background:#fcf9e8;color:#8a6100}
.mo-pill-unsubscribed{background:#fcf0f1;color:#b32d2e}
.mo-subscribers-wrap{margin:20px 20px 0 2px}
.mo-muted{color:#646970}
.mo-card{background:#fff;border:1px solid #c3c4c7;padding:4px 16px 12px;margin-bottom:16px}
.mo-narrow{max-width:520px}
.mo-check{display:block;margin:4px 0}
.mo-columns{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap}
.mo-col-main{flex:2 1 420px;min-width:0}
.mo-col-side{flex:1 1 260px;min-width:0}
.mo-profile-head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.mo-profile-actions{display:flex;gap:6px;flex-wrap:wrap}
.mo-timeline{margin:0;padding-left:16px;border-left:2px solid #dcdcde}
.mo-timeline li{margin:0 0 10px}
.mo-timeline small{display:block;color:#646970}
.mo-kv{display:grid;grid-template-columns:110px 1fr;gap:6px 10px;margin:0}
.mo-kv dt{color:#646970}
.mo-kv dd{margin:0}
.mo-consent-checkbox{color:#00701a;font-weight:600}
.mo-consent-checkbox_not_ticked{color:#b32d2e;font-weight:600}
.wp-list-table.subscribers .column-email{width:22%}
.wp-list-table.subscribers .column-name{width:13%}
.wp-list-table.subscribers .column-status{width:110px}
.wp-list-table.subscribers .column-source{width:11%}
.wp-list-table.subscribers .column-created_at{width:130px}
.mo-lists-table th{white-space:nowrap}
.mo-lists-table th:first-child{width:30%}
fieldset{margin:12px 0}
fieldset legend{font-weight:600}
</style>';
    }

    /**
     * @return Subscribers_Page
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
