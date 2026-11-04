<?php

namespace MailOptin\Core\Admin;

use MailOptin\Core\Admin\SettingsPage\Subscribers_Import_Page;
use MailOptin\Core\Admin\SettingsPage\Subscribers_Page;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\OptinCampaignsRepository;

use function MailOptin\Core\get_capability;

/**
 * Five-step setup: first list, connect forms, sender and postal address, test email, what next.
 *
 * Opens by itself once, after a fresh install. "Run setup wizard" in Settings > General opens it any time.
 * Server-rendered: every step is a plain form that posts, saves and redirects to the next step.
 */
class SetupWizard
{
    const SLUG = 'mailoptin-setup';

    /** Set on a fresh install; the next admin page load opens the wizard and deletes it. */
    const PENDING_OPTION = 'mailoptin_setup_wizard_pending';

    /** What the wizard did, for its checklist: list_id, forms, sender, test. */
    const STATE_OPTION = 'mailoptin_setup_wizard';

    const STEPS = ['list', 'forms', 'sender', 'test', 'done'];

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_page'], 99);
        add_action('admin_init', [$this, 'maybe_redirect_after_install'], 1);
        add_action('admin_init', [$this, 'handle_post']);
    }

    public static function url($step = '')
    {
        return add_query_arg(array_filter(['page' => self::SLUG, 'step' => $step]), admin_url('admin.php'));
    }

    /**
     * Called from the activation routine on a fresh install only (no earlier install date).
     */
    public static function flag_new_install()
    {
        add_option(self::PENDING_OPTION, '1', '', false);
    }

    public function register_page()
    {
        // No parent: reachable by URL and from Settings, but not listed in the menu.
        add_submenu_page('', __('Set up MailOptin', 'mailoptin'), __('Set up MailOptin', 'mailoptin'), get_capability(), self::SLUG, [$this, 'render']);
    }

    public function maybe_redirect_after_install()
    {
        if ('1' !== get_option(self::PENDING_OPTION)) return;

        // Wait for an ordinary admin page view by someone who can run it.
        if (wp_doing_ajax() || wp_doing_cron() || is_network_admin() || isset($_GET['activate-multi']) || ! current_user_can(get_capability())) return;
        if (defined('WP_CLI') && WP_CLI) return;

        delete_option(self::PENDING_OPTION);

        if (wp_safe_redirect(self::url())) exit;
    }

    protected static function state()
    {
        return wp_parse_args((array)get_option(self::STATE_OPTION, []), ['list_id' => 0, 'forms' => 0, 'sender' => false, 'test' => '']);
    }

    protected static function save_state($changes)
    {
        update_option(self::STATE_OPTION, array_merge(self::state(), $changes), false);
    }

    protected static function current_step()
    {
        $step = sanitize_key($_GET['step'] ?? '');

        return in_array($step, self::STEPS, true) ? $step : 'list';
    }

    protected static function next_step($step)
    {
        $i = array_search($step, self::STEPS, true);

        return self::STEPS[min($i + 1, count(self::STEPS) - 1)];
    }

    /* ---------------------------------------------------------------------------------------------
     * Saving
     * ------------------------------------------------------------------------------------------ */

    public function handle_post()
    {
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || self::SLUG !== ($_GET['page'] ?? '') || empty($_POST['mo_setup_step'])) return;

        if ( ! current_user_can(get_capability())) wp_die(esc_html__('You are not allowed to do this.', 'mailoptin'), 403);

        $step = sanitize_key($_POST['mo_setup_step']);
        check_admin_referer('mo_setup_' . $step);

        $result = $this->save_step($step, wp_unslash($_POST));

        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('mo_error', rawurlencode($result->get_error_message()), self::url($step)));
            exit;
        }

        $target = 'test' === $step && ! empty($_POST['mo_send_test']) ? 'test' : self::next_step($step);
        wp_safe_redirect(self::url($target));
        exit;
    }

    /**
     * @return true|\WP_Error
     */
    public function save_step($step, $data)
    {
        switch ($step) {
            case 'list':
                $list_id = absint($data['list_id'] ?? 0);

                if ( ! $list_id || ! ListsRepository::get($list_id)) {
                    $name = sanitize_text_field($data['list_name'] ?? '');
                    if ('' === $name) return new \WP_Error('name', __('Give your list a name.', 'mailoptin'));

                    $list_id = ListsRepository::create(['name' => $name, 'double_optin' => empty($data['double_optin']) ? 0 : 1]);
                    if ( ! $list_id) return new \WP_Error('create', __('The list could not be created.', 'mailoptin'));
                }

                self::save_state(['list_id' => (int)$list_id]);

                return true;

            case 'forms':
                $list_id = (int)self::state()['list_id'];
                $done    = 0;

                if ($list_id && ListsRepository::get($list_id)) {
                    foreach (array_map('absint', (array)($data['form_ids'] ?? [])) as $optin_campaign_id) {
                        if ($optin_campaign_id && self::connect_form($optin_campaign_id, $list_id)) $done++;
                    }
                }

                self::save_state(['forms' => self::state()['forms'] + $done]);

                return true;

            case 'sender':
                $email = sanitize_email($data['from_email'] ?? '');
                if ( ! is_email($email)) return new \WP_Error('email', __('Enter a valid From email address.', 'mailoptin'));

                $settings = (array)get_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, []);
                foreach (['from_name', 'company_name', 'company_address', 'company_city', 'company_zip', 'company_country'] as $key) {
                    if (isset($data[$key])) $settings[$key] = sanitize_text_field($data[$key]);
                }
                $settings['from_email'] = $email;
                update_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, $settings);

                self::save_state(['sender' => true]);

                return true;

            case 'test':
                if (empty($data['mo_send_test'])) return true;

                $to = sanitize_email($data['test_email'] ?? '');
                if ( ! is_email($to)) return new \WP_Error('email', __('Enter a valid email address for the test.', 'mailoptin'));

                $settings = (array)get_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, []);
                $from     = sprintf('From: %s <%s>', str_replace(['"', "\r", "\n"], '', $settings['from_name'] ?? get_bloginfo('name')), sanitize_email($settings['from_email'] ?? get_bloginfo('admin_email')));

                $sent = wp_mail(
                    $to,
                    __('MailOptin test email', 'mailoptin'),
                    __("This is a test from MailOptin's setup. If you are reading it, your site can send email.\n\nIf it landed in spam, use a sending service (Settings > Sending) or an SMTP plugin.", 'mailoptin'),
                    [$from]
                );

                self::save_state(['test' => $sent ? $to : '']);

                return $sent ? true : new \WP_Error('send', __('WordPress could not send the email. Your host may block outgoing mail: try an SMTP plugin or a sending service.', 'mailoptin'));
        }

        return true;
    }

    /**
     * Send a form's signups to a built-in list. Replaces an empty integration row; otherwise adds one,
     * so the form keeps sending to the services it already uses.
     */
    public static function connect_form($optin_campaign_id, $list_id)
    {
        if ( ! absint($optin_campaign_id) || ! OptinCampaignsRepository::get_optin_campaign_name($optin_campaign_id)) return false;

        $integrations = json_decode((string)OptinCampaignsRepository::get_customizer_value($optin_campaign_id, 'integrations', ''), true);
        if ( ! is_array($integrations)) $integrations = [];

        foreach ($integrations as $integration) {
            if (($integration['connection_service'] ?? '') === 'MailOptinConnect' && (int)($integration['connection_email_list'] ?? 0) === (int)$list_id) return false;
        }

        $row = ['connection_service' => 'MailOptinConnect', 'connection_email_list' => (string)$list_id];

        $empty = null;
        foreach ($integrations as $index => $integration) {
            if (empty($integration['connection_service'])) {
                $empty = $index;
                break;
            }
        }

        if (null === $empty) {
            $integrations[] = $row;
        } else {
            $integrations[$empty] = array_merge($integrations[$empty], $row);
        }

        OptinCampaignsRepository::update_settings_by_id($optin_campaign_id, ['integrations' => wp_json_encode(array_values($integrations))]);
        OptinCampaignsRepository::burst_cache($optin_campaign_id);

        return true;
    }

    /**
     * @return array forms not yet sending to the built-in list: id => name
     */
    protected static function unconnected_forms($list_id)
    {
        $forms = [];

        foreach (OptinCampaignsRepository::get_optin_campaign_ids() as $optin_campaign_id) {
            $integrations = json_decode((string)OptinCampaignsRepository::get_customizer_value($optin_campaign_id, 'integrations', ''), true);
            $connected    = false;

            foreach ((array)$integrations as $integration) {
                if (($integration['connection_service'] ?? '') === 'MailOptinConnect' && (int)($integration['connection_email_list'] ?? 0) === (int)$list_id) $connected = true;
            }

            if ( ! $connected) $forms[$optin_campaign_id] = OptinCampaignsRepository::get_optin_campaign_name($optin_campaign_id);
        }

        return $forms;
    }

    /* ---------------------------------------------------------------------------------------------
     * Screens
     * ------------------------------------------------------------------------------------------ */

    public function render()
    {
        $step  = self::current_step();
        $index = array_search($step, self::STEPS, true);
        $state = self::state();
        ?>
        <div class="mo-setup">
            <div class="mo-setup-top">
                <img src="<?php echo esc_url(MAILOPTIN_ASSETS_URL . 'images/mailoptin.svg'); ?>" alt="">
                <strong><?php esc_html_e('Set up MailOptin', 'mailoptin'); ?></strong>
                <?php if ('done' !== $step) : ?>
                    <a class="mo-ui-btn mo-ui-ghost mo-setup-skip" href="<?php echo esc_url(admin_url('admin.php?page=mailoptin-settings')); ?>"><?php esc_html_e('Skip setup', 'mailoptin'); ?></a>
                <?php endif; ?>
            </div>
            <div class="mo-setup-body">
                <ol class="mo-setup-steps" aria-label="<?php esc_attr_e('Setup progress', 'mailoptin'); ?>">
                    <?php foreach (self::STEPS as $i => $s) : ?>
                        <li class="<?php echo $i <= $index ? 'is-done' : ''; ?>" <?php echo $i === $index ? 'aria-current="step"' : ''; ?>><span class="screen-reader-text"><?php echo esc_html(self::step_title($s)); ?></span></li>
                    <?php endforeach; ?>
                </ol>
                <p class="mo-setup-count"><?php echo esc_html(sprintf(__('Step %1$d of %2$d', 'mailoptin'), $index + 1, count(self::STEPS))); ?></p>

                <div class="mo-ui-card mo-setup-card">
                    <?php if ( ! empty($_GET['mo_error'])) echo UI::note(esc_html(sanitize_text_field(wp_unslash($_GET['mo_error']))), 'bad'); ?>
                    <?php $this->{'step_' . $step}($state); ?>
                </div>

                <?php if ('done' !== $step) echo $this->checklist($state, $index); ?>
            </div>
        </div>
        <?php
        echo self::styles();
    }

    protected static function step_title($step)
    {
        return [
            'list'   => __('Create your first list', 'mailoptin'),
            'forms'  => __('Connect a form', 'mailoptin'),
            'sender' => __('Sender and postal address', 'mailoptin'),
            'test'   => __('Send a test email', 'mailoptin'),
            'done'   => __('What to try next', 'mailoptin'),
        ][$step];
    }

    protected function form_open($step)
    {
        printf('<form method="post" action="%s">', esc_url(self::url($step)));
        echo '<input type="hidden" name="mo_setup_step" value="' . esc_attr($step) . '">';
        wp_nonce_field('mo_setup_' . $step);
    }

    protected function footer($step, $next_label, $hint = '')
    {
        $index = array_search($step, self::STEPS, true);
        echo '<div class="mo-setup-foot">';
        echo $index > 0 ? UI::button(__('← Back', 'mailoptin'), self::url(self::STEPS[$index - 1])) : '<span></span>';
        echo '<span class="mo-setup-foot-end">';
        if ($hint) echo '<span class="mo-setup-hint">' . esc_html($hint) . '</span>';
        echo '<button type="submit" class="mo-ui-btn mo-ui-primary">' . esc_html($next_label) . '</button>';
        echo '</span></div></form>';
    }

    protected function step_list($state)
    {
        $lists = ListsRepository::get_all();
        $this->form_open('list');
        ?>
        <h2><?php esc_html_e('Create your first list', 'mailoptin'); ?></h2>
        <p class="mo-setup-lead"><?php esc_html_e('A list is a group of people who signed up for the same thing, like a newsletter. MailOptin keeps it on your site: no outside service needed, and no limit on lists or subscribers.', 'mailoptin'); ?></p>
        <?php if ( ! empty($lists)) : ?>
            <div class="mo-setup-field">
                <label for="mo-setup-list"><?php esc_html_e('Use a list you already have', 'mailoptin'); ?></label>
                <select id="mo-setup-list" name="list_id">
                    <option value=""><?php esc_html_e('No, create a new list', 'mailoptin'); ?></option>
                    <?php foreach ($lists as $list) : ?>
                        <option value="<?php echo absint($list['id']); ?>" <?php selected((int)$state['list_id'], (int)$list['id']); ?>><?php echo esc_html($list['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="mo-setup-field">
            <label for="mo-setup-list-name"><?php empty($lists) ? esc_html_e('List name', 'mailoptin') : esc_html_e('New list name', 'mailoptin'); ?></label>
            <input type="text" id="mo-setup-list-name" name="list_name" value="<?php echo esc_attr(empty($lists) ? __('Newsletter', 'mailoptin') : ''); ?>">
        </div>
        <div class="mo-setup-field">
            <label class="mo-setup-check"><input type="checkbox" name="double_optin" value="1" checked> <?php esc_html_e('Ask new subscribers to confirm their email (double opt-in)', 'mailoptin'); ?></label>
            <p class="mo-setup-help"><?php esc_html_e('Recommended: it keeps fake and mistyped addresses off your list.', 'mailoptin'); ?></p>
        </div>
        <?php
        $this->footer('list', __('Continue', 'mailoptin'), __('Next: connect a form', 'mailoptin'));
    }

    protected function step_forms($state)
    {
        $list  = ListsRepository::get((int)$state['list_id']);
        $forms = $list ? self::unconnected_forms($list['id']) : [];
        $this->form_open('forms');
        ?>
        <h2><?php esc_html_e('Connect a form', 'mailoptin'); ?></h2>
        <?php if ( ! $list) : ?>
            <p class="mo-setup-lead"><?php esc_html_e('Create a list first, then come back to this step.', 'mailoptin'); ?></p>
        <?php elseif (empty($forms)) : ?>
            <p class="mo-setup-lead"><?php echo esc_html(sprintf(__('You have no forms to connect yet. After setup, create an optin form and choose "MailOptin (built-in)" and %s as where signups go.', 'mailoptin'), $list['name'])); ?></p>
        <?php else : ?>
            <p class="mo-setup-lead"><?php echo esc_html(sprintf(__('Tick the forms that should add people to %s. Forms already sending to another service keep doing so as well.', 'mailoptin'), $list['name'])); ?></p>
            <div class="mo-setup-options">
                <?php foreach ($forms as $id => $name) : ?>
                    <label class="mo-setup-check"><input type="checkbox" name="form_ids[]" value="<?php echo absint($id); ?>"> <?php echo esc_html($name); ?></label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
        $this->footer('forms', __('Continue', 'mailoptin'), __('Next: who your emails are from', 'mailoptin'));
    }

    protected function step_sender($state)
    {
        $s = wp_parse_args((array)get_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, []), [
            'from_name'       => get_bloginfo('name'),
            'from_email'      => get_bloginfo('admin_email'),
            'company_name'    => '',
            'company_address' => '',
            'company_city'    => '',
            'company_zip'     => '',
            'company_country' => '',
        ]);
        $this->form_open('sender');
        $field = function ($key, $label, $help = '', $type = 'text') use ($s) {
            printf(
                '<div class="mo-setup-field"><label for="mo-setup-%1$s">%2$s</label><input type="%5$s" id="mo-setup-%1$s" name="%1$s" value="%3$s">%4$s</div>',
                esc_attr($key), esc_html($label), esc_attr($s[$key]), $help ? '<p class="mo-setup-help">' . esc_html($help) . '</p>' : '', esc_attr($type)
            );
        };
        ?>
        <h2><?php esc_html_e('Who are your emails from?', 'mailoptin'); ?></h2>
        <p class="mo-setup-lead"><?php esc_html_e('This is what subscribers see in their inbox. You can change it any time in Settings.', 'mailoptin'); ?></p>
        <?php
        $field('from_name', __('From name', 'mailoptin'), __('Usually your site or business name.', 'mailoptin'));
        $field('from_email', __('From email', 'mailoptin'), __('Use an address at your own domain, so fewer emails land in spam.', 'mailoptin'), 'email');
        ?>
        <fieldset class="mo-setup-fieldset">
            <legend><?php esc_html_e('Postal address', 'mailoptin'); ?></legend>
            <p class="mo-setup-help"><?php esc_html_e('Anti-spam laws (CAN-SPAM, GDPR) require one in every marketing email. It goes in the footer.', 'mailoptin'); ?></p>
            <?php
            $field('company_name', __('Company or organization', 'mailoptin'));
            $field('company_address', __('Address', 'mailoptin'));
            ?>
            <div class="mo-setup-row">
                <?php
                $field('company_city', __('City', 'mailoptin'));
                $field('company_zip', __('Zip / postal code', 'mailoptin'));
                ?>
                <div class="mo-setup-field">
                    <label for="mo-setup-company_country"><?php esc_html_e('Country', 'mailoptin'); ?></label>
                    <select id="mo-setup-company_country" name="company_country">
                        <option value=""><?php esc_html_e('Choose…', 'mailoptin'); ?></option>
                        <?php foreach (\MailOptin\Core\countries_array() as $code => $name) : ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($s['company_country'], $code); ?>><?php echo esc_html($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </fieldset>
        <div class="mo-setup-field">
            <span class="mo-setup-label"><?php esc_html_e('How emails are sent', 'mailoptin'); ?></span>
            <div class="mo-setup-choices">
                <div class="mo-setup-choice is-on"><b><?php esc_html_e('Through your web host (wp_mail)', 'mailoptin'); ?></b><span><?php esc_html_e('Fine to start with, and for small lists. Works with any SMTP plugin.', 'mailoptin'); ?></span></div>
                <div class="mo-setup-choice"><b><?php esc_html_e('A sending service', 'mailoptin'); ?> <?php echo UI::pro_pill(); ?></b><span><?php esc_html_e('Amazon SES, Postmark, SendGrid, Mailgun or Brevo, with bounce handling. Set it up later in Settings > Sending.', 'mailoptin'); ?></span></div>
            </div>
        </div>
        <?php
        $this->footer('sender', __('Continue', 'mailoptin'), __('Next: send yourself a test', 'mailoptin'));
    }

    protected function step_test($state)
    {
        $this->form_open('test');
        ?>
        <h2><?php esc_html_e('Send yourself a test', 'mailoptin'); ?></h2>
        <p class="mo-setup-lead"><?php esc_html_e('Check that your site can send email, and see how it looks in your inbox.', 'mailoptin'); ?></p>
        <?php if ('' !== $state['test']) echo UI::note(esc_html(sprintf(__('Sent to %s. Check your inbox, and the spam folder too.', 'mailoptin'), $state['test'])), 'ok'); ?>
        <div class="mo-setup-field">
            <label for="mo-setup-test-email"><?php esc_html_e('Send it to', 'mailoptin'); ?></label>
            <span class="mo-setup-inline">
                <input type="email" id="mo-setup-test-email" name="test_email" value="<?php echo esc_attr('' !== $state['test'] ? $state['test'] : wp_get_current_user()->user_email); ?>">
                <button type="submit" class="mo-ui-btn" name="mo_send_test" value="1"><?php esc_html_e('Send test email', 'mailoptin'); ?></button>
            </span>
        </div>
        <?php
        $this->footer('test', '' !== $state['test'] ? __('Continue', 'mailoptin') : __('Skip for now', 'mailoptin'), __('Next: what to try next', 'mailoptin'));
    }

    protected function step_done($state)
    {
        $list = ListsRepository::get((int)$state['list_id']);
        ?>
        <h2><?php esc_html_e('You\'re set up', 'mailoptin'); ?></h2>
        <p class="mo-setup-lead"><?php esc_html_e('Here is what to try next. You can run this setup again any time from Settings > General.', 'mailoptin'); ?></p>
        <?php echo $this->checklist($state, count(self::STEPS), false); ?>
        <div class="mo-setup-next">
            <?php echo UI::button(__('Create an optin form', 'mailoptin'), MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE, 'primary'); ?>
            <?php echo UI::button(__('Import subscribers', 'mailoptin'), Subscribers_Import_Page::import_url()); ?>
            <?php echo UI::button($list ? __('See your lists', 'mailoptin') : __('Go to Subscribers', 'mailoptin'), $list ? Subscribers_Page::page_url('lists') : Subscribers_Page::page_url()); ?>
        </div>
        <?php
    }

    protected function checklist($state, $index, $card = true)
    {
        $list  = ListsRepository::get((int)$state['list_id']);
        $items = [
            [(bool)$list, $list ? sprintf(__('First list: %s', 'mailoptin'), $list['name']) : self::step_title('list')],
            [$state['forms'] > 0, $state['forms'] > 0 ? sprintf(_n('%d form connected', '%d forms connected', $state['forms'], 'mailoptin'), $state['forms']) : self::step_title('forms')],
            [(bool)$state['sender'], self::step_title('sender')],
            ['' !== $state['test'], '' !== $state['test'] ? sprintf(__('Test sent to %s', 'mailoptin'), $state['test']) : self::step_title('test')],
        ];

        $html = '<ul class="mo-setup-checklist">';
        foreach ($items as $i => $item) {
            // A step that was passed without doing it shows as skipped rather than to-do.
            $class = $item[0] ? 'is-done' : ($i < $index ? 'is-skipped' : '');
            $mark  = $item[0] ? '✓' : '';
            $html  .= sprintf('<li class="%s"><i aria-hidden="true">%s</i> %s%s</li>', esc_attr($class), $mark, esc_html($item[1]), 'is-skipped' === $class ? ' <span class="mo-muted">' . esc_html__('(skipped)', 'mailoptin') . '</span>' : '');
        }
        $html .= '</ul>';

        return $card ? '<div class="mo-ui-card mo-ui-card-pad mo-setup-progress"><b>' . esc_html__('Your setup', 'mailoptin') . '</b>' . $html . '</div>' : $html;
    }

    protected static function styles()
    {
        return '<style>
#wpcontent{padding-left:0}
.mo-setup{color:#1d2327;font-size:13px}
.mo-setup-top{display:flex;align-items:center;gap:12px;background:#fff;border-bottom:1px solid #dcdcde;height:60px;padding:0 28px}
.mo-setup-top img{width:30px;height:30px;padding:6px;box-sizing:border-box;border-radius:7px;background:#2271b1}
.mo-setup-top strong{font-size:16px}
.mo-setup-skip{margin-left:auto}
.mo-setup-body{max-width:760px;margin:24px auto;padding:0 16px}
.mo-setup-steps{display:flex;gap:6px;list-style:none;margin:0 0 10px;padding:0}
.mo-setup-steps li{flex:1;height:5px;margin:0;border-radius:99px;background:#dcdcde}
.mo-setup-steps li.is-done{background:#2271b1}
.mo-setup-count{color:#646970;margin:0 0 8px}
.mo-setup-card{padding:26px 30px}
.mo-setup-card h2{font-size:20px;margin:4px 0 6px}
.mo-setup-lead{color:#646970;font-size:14px;margin:0 0 18px;max-width:62ch}
.mo-setup-field{margin:14px 0}
.mo-setup-field>label,.mo-setup-label{display:block;font-weight:600;margin-bottom:5px}
.mo-setup-field input[type=text],.mo-setup-field input[type=email],.mo-setup-field select{width:100%;max-width:520px;min-height:34px;border-radius:6px}
.mo-setup-help{color:#646970;margin:4px 0 0}
.mo-setup-check{display:block;margin:6px 0}
.mo-setup-fieldset{border:0;margin:18px 0 0;padding:0}
.mo-setup-fieldset legend{font-weight:600;font-size:14px;padding:0}
.mo-setup-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;max-width:620px}
.mo-setup-row .mo-setup-field{margin:0}
.mo-setup-inline{display:flex;gap:8px;max-width:520px}
.mo-setup-inline input{flex:1}
.mo-setup-choices{display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:620px}
.mo-setup-choice{border:1px solid #dcdcde;border-radius:8px;padding:12px 14px}
.mo-setup-choice.is-on{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;background:#f6faff}
.mo-setup-choice b{display:block;margin-bottom:2px}
.mo-setup-choice span{color:#646970}
.mo-setup-options{display:grid;gap:2px;max-height:280px;overflow:auto}
.mo-setup-foot{display:flex;justify-content:space-between;align-items:center;margin-top:22px;padding-top:16px;border-top:1px solid #f0f0f1}
.mo-setup-foot-end{display:flex;gap:10px;align-items:center}
.mo-setup-hint{color:#646970}
.mo-setup-progress{margin-top:16px}
.mo-setup-checklist{list-style:none;margin:6px 0 0;padding:0}
.mo-setup-checklist li{display:flex;gap:10px;align-items:center;margin:0;padding:6px 0}
.mo-setup-checklist i{width:18px;height:18px;flex:none;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-style:normal;font-size:11px;border:2px solid #c3c4c7;box-sizing:border-box}
.mo-setup-checklist li.is-done i{background:#00a32a;border-color:#00a32a;color:#fff}
.mo-setup-checklist li.is-skipped{color:#646970}
.mo-setup-next{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}
.mo-setup .mo-ui-note{margin-bottom:14px}
@media (max-width:600px){.mo-setup-card{padding:18px}.mo-setup-row,.mo-setup-choices{grid-template-columns:1fr}.mo-setup-foot{flex-wrap:wrap;gap:10px}.mo-setup-hint{display:none}.mo-setup-top{padding:0 16px}}
</style>';
    }

    /**
     * @return SetupWizard
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
