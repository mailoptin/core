<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Admin\UI;
use MailOptin\Core\Audience\Sources\AbstractSource;
use MailOptin\Core\Audience\Sources\Sources;
use W3Guy\Custom_Settings_Page_Api;

use function MailOptin\Core\current_user_has_privilege;

/**
 * Settings › Customers and members: which customers, users and members can be picked as a
 * broadcast audience, and the consent box that shows where they buy or sign up.
 */
class AudienceSourcesSettings
{
    const VIEW = 'customers-members';

    public function __construct()
    {
        add_filter('mailoptin_settings_header_menu_tabs', [$this, 'tab']);

        add_action('mailoptin_admin_settings_page_pre', function ($active_menu) {
            if (self::VIEW !== $active_menu || ! $this->is_this_page()) return;

            $instance = Custom_Settings_Page_Api::instance();
            $instance->option_name(Sources::OPTION);
            $instance->page_header(__('Settings', 'mailoptin'));
            $instance->remove_h2_header();
            Settings::get_instance()->set_settings_page_instance($instance);
        });

        add_action('mailoptin_admin_settings_submenu_page_' . self::VIEW, [$this, 'render']);
        add_action('admin_init', [$this, 'save']);
        add_action('mailoptin_subscriber_profile_side', [$this, 'profile_cards']);
        add_action('admin_post_mo_withdraw_consent', [$this, 'withdraw']);
    }

    public static function url($args = [])
    {
        return add_query_arg(array_merge(['view' => self::VIEW], $args), MAILOPTIN_SETTINGS_SETTINGS_PAGE);
    }

    protected function is_this_page()
    {
        return MAILOPTIN_SETTINGS_SETTINGS_SLUG === ($_GET['page'] ?? '') && self::VIEW === ($_GET['view'] ?? '');
    }

    public function tab($tabs)
    {
        $tabs[25] = ['id' => self::VIEW, 'url' => self::url(), 'label' => esc_html__('Customers and members', 'mailoptin')];

        return $tabs;
    }

    public function save()
    {
        if ( ! $this->is_this_page() || 'POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || ! isset($_POST['mo_sources_save'])) return;

        if ( ! current_user_has_privilege()) wp_die(esc_html__('You are not allowed to do this.', 'mailoptin'), 403);

        check_admin_referer('mo_sources_save');

        Sources::save_settings((array)($_POST['sources'] ?? []));

        wp_safe_redirect(self::url(['saved' => 1]));
        exit;
    }

    public function render()
    {
        if ( ! current_user_has_privilege()) return;

        $active = Sources::active();

        echo '<div class="mo-ui-page mo-sources-settings">';
        echo '<div class="mo-ui-heading"><h1>' . esc_html__('Customers and members', 'mailoptin') . '</h1><span class="mo-ui-sub">' . esc_html__('Who among your customers, users and members can be picked as a broadcast audience. Nothing here copies them into MailOptin: each is asked of its plugin when an email goes.', 'mailoptin') . '</span></div>';
        echo '<hr class="wp-header-end">';

        if ( ! empty($_GET['saved'])) echo UI::note(esc_html__('Settings saved.', 'mailoptin'), 'ok');

        ?>
        <form method="post" action="<?php echo esc_url(self::url()); ?>">
            <?php wp_nonce_field('mo_sources_save'); ?>
            <?php foreach ($active as $id => $source) : $this->section_html($id, $source, Sources::settings($id)); endforeach; ?>
            <p class="mo-sources-note"><?php esc_html_e('Unsubscribing stops these marketing emails only: order and membership emails from those plugins still go out. Someone who has unsubscribed, bounced or complained is never emailed this way.', 'mailoptin'); ?></p>
            <p><button type="submit" name="mo_sources_save" value="1" class="button button-primary"><?php esc_html_e('Save changes', 'mailoptin'); ?></button></p>
        </form>
        <?php

        echo '</div>';
        echo self::styles();
    }

    protected function section_html($id, AbstractSource $source, $settings)
    {
        $name    = 'sources[' . $id . ']';
        $agreed  = AbstractSource::MODE_AGREED === $settings['mode'];
        $place   = $source->checkbox_place();
        ?>
        <div class="mo-ui-card mo-ui-card-pad mo-source-section">
            <div class="mo-source-head">
                <h2><?php echo esc_html($source->label()); ?></h2>
                <a href="<?php echo esc_url($source->admin_url()); ?>" target="_blank"><?php echo esc_html($source->admin_link_label()); ?> <span aria-hidden="true">↗</span><span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'mailoptin'); ?></span></a>
            </div>
            <fieldset>
                <legend><?php esc_html_e('Who can get broadcasts', 'mailoptin'); ?></legend>
                <label class="mo-choice">
                    <input type="radio" name="<?php echo esc_attr($name); ?>[mode]" value="<?php echo esc_attr(AbstractSource::MODE_AGREED); ?>" <?php checked($agreed); ?>>
                    <span><b><?php esc_html_e('Only people who agreed', 'mailoptin'); ?></b><br>
                        <span class="mo-muted"><?php echo '' !== $place
                                ? esc_html(sprintf(__('They ticked the box %s, or were already your subscribers.', 'mailoptin'), $place))
                                : esc_html__('They are already your subscribers. This plugin has no box of its own here.', 'mailoptin'); ?></span></span>
                </label>
                <label class="mo-choice">
                    <input type="radio" name="<?php echo esc_attr($name); ?>[mode]" value="<?php echo esc_attr(AbstractSource::MODE_EVERYONE); ?>" <?php checked( ! $agreed); ?>>
                    <span><b><?php esc_html_e('Everyone', 'mailoptin'); ?></b><br>
                        <span class="mo-muted"><?php esc_html_e('Only if the law where you and they are allows it, such as the UK and EU "soft opt-in" for existing customers about similar products.', 'mailoptin'); ?></span></span>
                </label>
            </fieldset>
            <?php if ('' !== $place) : ?>
                <p>
                    <label for="mo-cb-<?php echo esc_attr($id); ?>"><b><?php echo esc_html(sprintf(__('Checkbox text %s', 'mailoptin'), $place)); ?></b></label><br>
                    <input type="text" class="large-text" id="mo-cb-<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>[checkbox_text]" value="<?php echo esc_attr($settings['checkbox_text']); ?>" maxlength="500">
                    <span class="description"><?php esc_html_e('Shown unticked, and only while "Only people who agreed" is chosen. What they ticked and when is kept as their consent.', 'mailoptin'); ?></span>
                </p>
            <?php endif; ?>
            <?php echo $source->settings_fields_html($settings, $name); ?>
        </div>
        <?php
    }

    /**
     * On a subscriber's profile: what each plugin knows about them (read live, not copied), and
     * the consent they gave as a customer, user or member.
     */
    public function profile_cards($subscriber)
    {
        foreach (Sources::active() as $source) {
            $summary = $source->profile_summary($subscriber['email']);

            if (empty($summary)) continue;
            ?>
            <div class="mo-card">
                <h3 style="display:flex;justify-content:space-between;align-items:baseline;gap:8px"><span><?php echo esc_html($source->label()); ?></span>
                    <a style="font-size:13px;font-weight:400" href="<?php echo esc_url($source->admin_url()); ?>" target="_blank"><?php echo esc_html($source->admin_link_label()); ?> <span aria-hidden="true">↗</span></a></h3>
                <dl class="mo-kv">
                    <?php foreach ($summary as $label => $value) : ?>
                        <dt><?php echo esc_html($label); ?></dt>
                        <dd><?php echo esc_html($value); ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
            <?php
        }

        $consents = \MailOptin\Core\Repositories\AudienceConsentsRepository::for_email($subscriber['email']);

        if (empty($consents)) return;
        ?>
        <div class="mo-card">
            <h3><?php esc_html_e('Marketing consent as a customer or member', 'mailoptin'); ?></h3>
            <?php foreach ($consents as $row) :
                $source = Sources::get($row['source']);
                $agreed = \MailOptin\Core\Repositories\AudienceConsentsRepository::STATUS_AGREED === $row['status'];
                ?>
                <p>
                    <b><?php echo esc_html($source ? $source->label() : $row['source']); ?></b>:
                    <?php echo $agreed
                        ? esc_html(sprintf(__('agreed, %s', 'mailoptin'), trim($row['reference'] . ' · ' . date_i18n(get_option('date_format'), strtotime(get_date_from_gmt($row['updated_at']))), ' ·')))
                        : esc_html(sprintf(__('withdrawn %s', 'mailoptin'), date_i18n(get_option('date_format'), strtotime(get_date_from_gmt($row['updated_at']))))); ?>
                    <?php if ('' !== (string)$row['consent_text']) : ?><br><span class="description">&ldquo;<?php echo esc_html($row['consent_text']); ?>&rdquo;</span><?php endif; ?>
                </p>
                <?php if ($agreed) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('mo_withdraw_consent_' . $row['id']); ?>
                        <input type="hidden" name="action" value="mo_withdraw_consent">
                        <input type="hidden" name="consent_id" value="<?php echo absint($row['id']); ?>">
                        <input type="hidden" name="subscriber" value="<?php echo absint($subscriber['id']); ?>">
                        <button type="submit" class="button-link"><?php esc_html_e('Withdraw', 'mailoptin'); ?></button>
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function withdraw()
    {
        $id = absint($_POST['consent_id'] ?? 0);

        if ( ! current_user_has_privilege()) wp_die(esc_html__('You are not allowed to do this.', 'mailoptin'), 403);

        check_admin_referer('mo_withdraw_consent_' . $id);

        \MailOptin\Core\Repositories\AudienceConsentsRepository::withdraw_by_id($id);

        wp_safe_redirect(add_query_arg('subscriber', absint($_POST['subscriber'] ?? 0), Subscribers_Page::page_url()));
        exit;
    }

    protected static function styles()
    {
        return '<style>
.mo-sources-settings .mo-source-section{margin-bottom:16px;max-width:860px}
.mo-sources-settings .mo-source-head{display:flex;align-items:baseline;gap:12px}
.mo-sources-settings .mo-source-head h2{flex:1;margin:0 0 8px;font-size:16px}
.mo-sources-settings fieldset{border:0;padding:0;margin:0 0 12px;display:flex;flex-direction:column;gap:8px}
.mo-sources-settings legend{font-weight:600;margin-bottom:6px}
.mo-sources-settings .mo-choice{display:flex;gap:10px;align-items:flex-start;border:1px solid #dcdcde;border-radius:4px;padding:9px 12px}
.mo-sources-settings .mo-choice input{margin-top:2px}
.mo-sources-settings .mo-choice:has(input:checked){border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
.mo-sources-settings .mo-muted,.mo-sources-settings .mo-sources-note{color:#50575e}
</style>';
    }

    /**
     * @return AudienceSourcesSettings
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
