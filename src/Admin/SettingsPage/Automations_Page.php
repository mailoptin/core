<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Admin\UI;
use MailOptin\Core\Automations\AbstractTrigger;
use MailOptin\Core\Automations\Engine;
use MailOptin\Core\Automations\Recipes;
use MailOptin\Core\Automations\Triggers;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;
use MailOptin\Core\Repositories\CampaignActivityRepository as Activity;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\SegmentsRepository as Segments;
use MailOptin\Core\Repositories\TagsRepository;
use MailOptin\MailOptinConnect\CampaignAudience;

use function MailOptin\Core\current_user_has_privilege;
use function MailOptin\Core\do_admin_redirect;

/**
 * Emails › Automations: the list, a new automation (from a recipe or a trigger), and the builder
 * ("What starts it", "Who's included", "Waits and emails"). Server-rendered forms; every button
 * posts the whole form, so nothing typed is lost and it works without JavaScript.
 */
class Automations_Page
{
    const VIEW = 'automations';

    const NONCE_NEW = 'mo_automation_new';

    const UNITS = ['minutes', 'hours', 'days', 'weeks'];

    public function __construct()
    {
        add_filter('mailoptin_emails_settings_page_tabs', [$this, 'tab']);
        add_action('mailoptin_admin_settings_submenu_page_' . self::VIEW, [$this, 'render']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('mailoptin_automation_email_return_url', [$this, 'email_return_url'], 10, 2);
        add_action('mailoptin_subscriber_profile_side', [$this, 'profile_card']);
    }

    /* ---------------------------------------------------------------------------------------------
     * URLs
     * ------------------------------------------------------------------------------------------ */

    public static function url($args = [])
    {
        return add_query_arg(['view' => self::VIEW] + $args, MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE);
    }

    public static function edit_url($automation_id, $args = [])
    {
        return self::url(['screen' => 'edit', 'id' => absint($automation_id)] + $args);
    }

    protected static function nonce_action($automation_id)
    {
        return 'mo_automation_' . absint($automation_id);
    }

    protected static function is_this_page()
    {
        return is_admin() && MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG === ($_GET['page'] ?? '') && self::VIEW === ($_GET['view'] ?? '');
    }

    public function tab($tabs)
    {
        $tabs[30] = ['id' => self::VIEW, 'url' => self::url(), 'label' => esc_html__('Automations', 'mailoptin')];

        return $tabs;
    }

    /**
     * Back to the automation after editing one of its emails in the customizer.
     */
    public function email_return_url($url, $email_campaign_id)
    {
        $automation_id = self::automation_of_email($email_campaign_id);

        return $automation_id ? self::edit_url($automation_id, ['mo_notice' => 'email_saved']) : self::url();
    }

    protected static function automation_of_email($email_campaign_id)
    {
        global $wpdb;

        // Email steps store their email as {"email_campaign_id":N,...}; matched exactly on the decoded value below.
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT automation_id, settings FROM ' . Repo::steps_table() . ' WHERE type = %s AND settings LIKE %s',
            Repo::STEP_EMAIL, '%' . $wpdb->esc_like('"email_campaign_id":' . absint($email_campaign_id)) . '%'
        ), 'ARRAY_A');

        foreach ((array)$rows as $row) {
            $settings = json_decode($row['settings'], true);
            if (absint($settings['email_campaign_id'] ?? 0) === absint($email_campaign_id)) return absint($row['automation_id']);
        }

        return 0;
    }

    public function enqueue()
    {
        if ( ! self::is_this_page()) return;

        wp_enqueue_style('mailoptin-chosen', MAILOPTIN_ASSETS_URL . 'chosen/chosen.min.css');
        wp_enqueue_script('mailoptin-chosen', MAILOPTIN_ASSETS_URL . 'chosen/chosen.jquery.min.js', ['jquery'], null, true);
        wp_add_inline_script('mailoptin-chosen', self::script());
    }

    /* ---------------------------------------------------------------------------------------------
     * Pages
     * ------------------------------------------------------------------------------------------ */

    public function render()
    {
        echo self::styles();
        echo '<div class="wrap mo-auto">';

        if ( ! Engine::is_available()) {
            echo self::upsell_html();
            echo '</div>';

            return;
        }

        echo self::notice_html();

        $screen = sanitize_key($_GET['screen'] ?? '');

        if ('new' === $screen) {
            echo self::new_html();
        } elseif ('edit' === $screen) {
            echo self::edit_html(absint($_GET['id'] ?? 0));
        } else {
            echo self::list_html();
        }

        echo '</div>';
    }

    protected static function upsell_html()
    {
        ob_start();
        ?>
        <h1><?php esc_html_e('Automations', 'mailoptin'); ?> <?php echo UI::pro_pill(); ?></h1>
        <div class="mo-upsell-box">
            <h3><?php esc_html_e('Emails that send themselves, at the right moment', 'mailoptin'); ?></h3>
            <ul>
                <li><?php esc_html_e('Welcome new subscribers with a series of emails over their first week', 'mailoptin'); ?></li>
                <li><?php esc_html_e('Ask WooCommerce customers for a review after their order arrives', 'mailoptin'); ?></li>
                <li><?php esc_html_e('Win back customers who haven\'t ordered in a while, and greet subscribers on their birthday', 'mailoptin'); ?></li>
                <li><?php esc_html_e('Waits, sending hours and "only if" checks, with opens and clicks for every email', 'mailoptin'); ?></li>
            </ul>
            <p><a class="button button-primary" target="_blank" href="<?php echo esc_url(CampaignAudience::upsell_url('builtin_automations')); ?>"><?php esc_html_e('Get MailOptin Premium', 'mailoptin'); ?></a></p>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function notice_html()
    {
        $notices = [
            'saved'        => [__('Automation saved.', 'mailoptin'), 'ok'],
            'email_saved'  => [__('Email saved.', 'mailoptin'), 'ok'],
            'live'         => [__('The automation is live. It starts for people from now on.', 'mailoptin'), 'ok'],
            'stopped'      => [__('The automation is stopped. Nobody new starts it, and people part-way through get no more emails until it is live again.', 'mailoptin'), 'warn'],
            'deleted'      => [__('Automation deleted.', 'mailoptin'), 'ok'],
            'duplicated'   => [__('Automation duplicated as a draft.', 'mailoptin'), 'ok'],
            'test_sent'    => [__('Test email sent.', 'mailoptin'), 'ok'],
            'test_fail'    => [__('The test email could not be sent. Check your sending settings.', 'mailoptin'), 'bad'],
        ];

        $html = '';
        $key  = sanitize_key($_GET['mo_notice'] ?? '');

        if (isset($notices[$key])) $html .= UI::note(esc_html($notices[$key][0]), $notices[$key][1]);

        $error = get_transient('mo_automation_error_' . get_current_user_id());

        if ($error) {
            delete_transient('mo_automation_error_' . get_current_user_id());
            $html .= UI::note(esc_html($error), 'bad');
        }

        return $html;
    }

    protected static function list_html()
    {
        $status      = sanitize_key($_GET['status'] ?? '');
        $all         = Repo::all();
        $automations = $status ? array_filter($all, function ($a) use ($status) { return $a['status'] === $status; }) : $all;
        $stats       = Repo::list_stats();
        $counts      = array_count_values(wp_list_pluck($all, 'status'));

        ob_start();
        ?>
        <h1 class="wp-heading-inline"><?php esc_html_e('Automations', 'mailoptin'); ?></h1>
        <a href="<?php echo esc_url(self::url(['screen' => 'new'])); ?>" class="page-title-action"><?php esc_html_e('Add New', 'mailoptin'); ?></a>
        <hr class="wp-header-end">

        <?php if (empty($all)) : ?>
            <div class="mo-auto-empty">
                <h2><?php esc_html_e('No automations yet', 'mailoptin'); ?></h2>
                <p><?php esc_html_e('An automation sends emails by itself when something happens: someone joins a list, an order is completed, a birthday comes round.', 'mailoptin'); ?></p>
                <p><a class="button button-primary" href="<?php echo esc_url(self::url(['screen' => 'new'])); ?>"><?php esc_html_e('Create your first automation', 'mailoptin'); ?></a></p>
            </div>
            <?php return ob_get_clean(); ?>
        <?php endif; ?>

        <ul class="subsubsub">
            <?php
            $views = ['' => [__('All', 'mailoptin'), count($all)], Repo::STATUS_LIVE => [__('Live', 'mailoptin'), $counts[Repo::STATUS_LIVE] ?? 0], Repo::STATUS_DRAFT => [__('Draft', 'mailoptin'), $counts[Repo::STATUS_DRAFT] ?? 0]];
            $links = [];
            foreach ($views as $key => $view) {
                $links[] = sprintf('<li><a href="%s"%s>%s <span class="count">(%s)</span></a>', esc_url(self::url($key ? ['status' => $key] : [])), $key === $status ? ' class="current"' : '', esc_html($view[0]), number_format_i18n($view[1]));
            }
            echo implode(' |</li>', $links) . '</li>';
            ?>
        </ul>
        <table class="wp-list-table widefat fixed striped mo-auto-list">
            <thead>
            <tr>
                <th class="mo-col-status"><?php esc_html_e('Status', 'mailoptin'); ?></th>
                <th class="mo-col-name"><?php esc_html_e('Name', 'mailoptin'); ?></th>
                <th class="mo-col-starts"><?php esc_html_e('Starts when', 'mailoptin'); ?></th>
                <th class="num"><?php esc_html_e('In progress', 'mailoptin'); ?></th>
                <th class="num"><?php esc_html_e('Sent (30 days)', 'mailoptin'); ?></th>
                <th class="num"><?php esc_html_e('Opened', 'mailoptin'); ?></th>
                <th class="num"><?php esc_html_e('Clicked', 'mailoptin'); ?></th>
                <th class="mo-col-actions"><span class="screen-reader-text"><?php esc_html_e('Actions', 'mailoptin'); ?></span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($automations as $automation) :
                $id = $automation['id'];
                $trigger = Triggers::get_instance()->get($automation['trigger_id']);
                $steps = Repo::steps($id);
                $rates = self::rates(array_filter($steps, function ($s) { return Repo::STEP_EMAIL === $s['type']; }));
                $live = Repo::STATUS_LIVE === $automation['status'];
                ?>
                <tr>
                    <td><?php echo $live ? UI::badge(__('Live', 'mailoptin'), 'ok') : UI::badge(__('Draft', 'mailoptin')); ?></td>
                    <td>
                        <strong><a class="row-title" href="<?php echo esc_url(self::edit_url($id)); ?>"><?php echo esc_html($automation['name'] ?: __('(no name)', 'mailoptin')); ?></a></strong>
                        <div class="mo-auto-envelopes" aria-hidden="true"><?php
                            foreach ($steps as $step) {
                                if (Repo::STEP_EMAIL !== $step['type']) continue;
                                echo '<span class="dashicons dashicons-email-alt' . ( ! empty($step['settings']['paused']) ? ' off' : '') . '"></span>';
                            }
                            ?></div>
                    </td>
                    <td><?php echo esc_html(self::starts_when($automation, $trigger)); ?></td>
                    <td class="num"><?php echo esc_html(number_format_i18n($stats[$id]['active'] ?? 0)); ?></td>
                    <td class="num"><?php echo esc_html(number_format_i18n($stats[$id]['sent_30'] ?? 0)); ?></td>
                    <td class="num"><?php echo esc_html($rates['opened']); ?></td>
                    <td class="num"><?php echo esc_html($rates['clicked']); ?></td>
                    <td class="mo-col-actions">
                        <form method="post" action="<?php echo esc_url(self::edit_url($id)); ?>" class="mo-inline-form">
                            <?php wp_nonce_field(self::nonce_action($id)); ?>
                            <input type="hidden" name="id" value="<?php echo absint($id); ?>">
                            <?php if ($live) : ?>
                                <button class="button button-small" name="mo_automation_action" value="stop"><?php esc_html_e('Stop', 'mailoptin'); ?></button>
                            <?php else : ?>
                                <button class="button button-small button-primary" name="mo_automation_action" value="go_live"><?php esc_html_e('Go live', 'mailoptin'); ?></button>
                            <?php endif; ?>
                            <button class="button button-small" name="mo_automation_action" value="duplicate"><?php esc_html_e('Duplicate', 'mailoptin'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description"><?php esc_html_e('One envelope per email; a grey one is paused. Opened and clicked are for subscribers, whose opens and clicks are tracked.', 'mailoptin'); ?></p>
        <?php
        return ob_get_clean();
    }

    /**
     * Open and click rates over some email steps, for subscribers (only their emails are tracked).
     *
     * @return array ['opened' => string, 'clicked' => string]
     */
    protected static function rates($email_steps)
    {
        $logs = [];

        foreach ($email_steps as $step) {
            $log_id = Engine::existing_log_id(absint($step['settings']['email_campaign_id'] ?? 0));
            if ($log_id) $logs[] = $log_id;
        }

        $sent = $openers = $clickers = 0;

        foreach (Activity::is_ready() ? Activity::stats_for($logs) : [] as $stats) {
            $sent     += $stats[Activity::METRIC_SENT];
            $openers  += $stats[Activity::METRIC_OPENERS];
            $clickers += $stats[Activity::METRIC_CLICKERS];
        }

        if ( ! $sent) return ['opened' => '—', 'clicked' => '—'];

        return [
            'opened'  => round(100 * $openers / $sent) . '%',
            'clicked' => round(100 * $clickers / $sent) . '%',
        ];
    }

    protected static function starts_when($automation, $trigger)
    {
        if ( ! $trigger) return __('A trigger that is no longer available', 'mailoptin');

        $summary = $trigger->label();

        foreach ($trigger->settings_fields() as $key => $field) {
            if ( ! in_array($field['type'], ['select'], true)) continue;

            $value   = $automation['trigger_settings'][$key] ?? ($field['default'] ?? '');
            $options = AbstractTrigger::field_options($field);

            if (isset($options[$value])) $summary .= ': ' . $options[$value];
        }

        if ( ! $trigger->is_available()) $summary .= ' ' . __('(not available: is its plugin active?)', 'mailoptin');

        return $summary;
    }

    protected static function new_html()
    {
        ob_start();
        ?>
        <h1 class="wp-heading-inline"><?php esc_html_e('New automation', 'mailoptin'); ?></h1>
        <a href="<?php echo esc_url(self::url()); ?>" class="page-title-action"><?php esc_html_e('Back to Automations', 'mailoptin'); ?></a>
        <hr class="wp-header-end">

        <?php $recipes = Recipes::all(); if ( ! empty($recipes)) : ?>
            <h2 class="mo-auto-h"><?php esc_html_e('Start from a recipe', 'mailoptin'); ?></h2>
            <p class="mo-auto-lead"><?php esc_html_e('A recipe fills in what starts it, the waits and the emails. You can change all of it before it goes live.', 'mailoptin'); ?></p>
            <form method="post" action="<?php echo esc_url(self::url(['screen' => 'new'])); ?>">
                <?php wp_nonce_field(self::NONCE_NEW); ?>
                <input type="hidden" name="mo_automation_action" value="create">
                <div class="mo-auto-cards">
                    <?php foreach ($recipes as $recipe_id => $recipe) : ?>
                        <div class="mo-auto-card">
                            <h3><?php echo esc_html($recipe['title']); ?></h3>
                            <p><?php echo esc_html($recipe['description']); ?></p>
                            <p class="mo-auto-flow"><?php echo esc_html($recipe['flow']); ?></p>
                            <button class="button" name="recipe" value="<?php echo esc_attr($recipe_id); ?>"><?php esc_html_e('Use this recipe', 'mailoptin'); ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </form>
        <?php endif; ?>

        <h2 class="mo-auto-h"><?php esc_html_e('Or start from scratch', 'mailoptin'); ?></h2>
        <p class="mo-auto-lead"><?php esc_html_e('Choose what starts the automation. You add the waits and emails next.', 'mailoptin'); ?></p>
        <form method="post" action="<?php echo esc_url(self::url(['screen' => 'new'])); ?>" class="mo-auto-triggers">
            <?php wp_nonce_field(self::NONCE_NEW); ?>
            <input type="hidden" name="mo_automation_action" value="create">
            <?php $first = true; foreach (Triggers::get_instance()->grouped() as $group => $triggers) : ?>
                <fieldset class="mo-auto-group">
                    <legend><?php echo esc_html($group); ?></legend>
                    <?php foreach ($triggers as $trigger_id => $trigger) : ?>
                        <label class="mo-auto-opt">
                            <input type="radio" name="trigger" value="<?php echo esc_attr($trigger_id); ?>" <?php checked($first); $first = false; ?>>
                            <span><b><?php echo esc_html($trigger->label()); ?></b><span><?php echo esc_html($trigger->description()); ?></span></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>
            <div class="mo-auto-group"><button class="button button-primary"><?php esc_html_e('Continue', 'mailoptin'); ?></button></div>
        </form>
        <?php
        return ob_get_clean();
    }

    protected static function edit_html($automation_id)
    {
        $automation = Repo::get($automation_id);

        if ( ! $automation) {
            return '<p>' . esc_html__('This automation no longer exists.', 'mailoptin') . '</p><p><a href="' . esc_url(self::url()) . '">&larr; ' . esc_html__('Automations', 'mailoptin') . '</a></p>';
        }

        $trigger = Triggers::get_instance()->get($automation['trigger_id']);
        $live    = Repo::STATUS_LIVE === $automation['status'];

        ob_start();
        ?>
        <p class="mo-back"><a href="<?php echo esc_url(self::url()); ?>">&larr; <?php esc_html_e('Automations', 'mailoptin'); ?></a></p>
        <h1 class="screen-reader-text"><?php esc_html_e('Edit automation', 'mailoptin'); ?></h1>
        <hr class="wp-header-end">
        <form method="post" action="<?php echo esc_url(self::edit_url($automation_id)); ?>" id="mo-auto-form">
            <?php wp_nonce_field(self::nonce_action($automation_id)); ?>
            <input type="hidden" name="id" value="<?php echo absint($automation_id); ?>">
            <?php // Enter in a text field saves, rather than pressing the first button in the form. ?>
            <button type="submit" name="mo_automation_action" value="save" class="screen-reader-text" tabindex="-1"><?php esc_html_e('Save', 'mailoptin'); ?></button>

            <div class="mo-auto-head">
                <label for="mo-auto-name" class="screen-reader-text"><?php esc_html_e('Automation name', 'mailoptin'); ?></label>
                <input type="text" id="mo-auto-name" name="name" value="<?php echo esc_attr($automation['name']); ?>" maxlength="190" required placeholder="<?php esc_attr_e('Automation name', 'mailoptin'); ?>">
            </div>

            <div class="mo-auto-layout">
                <div class="mo-auto-main">
                    <section class="postbox">
                        <h2 class="mo-auto-box-h"><?php esc_html_e('1. What starts it', 'mailoptin'); ?></h2>
                        <div class="inside">
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="mo-auto-trigger"><?php esc_html_e('Trigger', 'mailoptin'); ?></label></th>
                                    <td>
                                        <?php if ($live) : ?>
                                            <strong><?php echo esc_html($trigger ? $trigger->label() : $automation['trigger_id']); ?></strong>
                                            <p class="description"><?php esc_html_e('Stop the automation to choose another trigger.', 'mailoptin'); ?></p>
                                        <?php else : ?>
                                            <select id="mo-auto-trigger" name="trigger_id" data-autosave>
                                                <?php foreach (Triggers::get_instance()->grouped() as $group => $triggers) : ?>
                                                    <optgroup label="<?php echo esc_attr($group); ?>">
                                                        <?php foreach ($triggers as $trigger_id => $t) : ?>
                                                            <option value="<?php echo esc_attr($trigger_id); ?>" <?php selected($trigger_id, $automation['trigger_id']); ?>><?php echo esc_html($t->label()); ?></option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                <?php endforeach; ?>
                                            </select>
                                            <noscript><button class="button" name="mo_automation_action" value="save"><?php esc_html_e('Change', 'mailoptin'); ?></button></noscript>
                                            <?php if ($trigger) : ?><p class="description"><?php echo esc_html($trigger->description()); ?></p><?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if ($trigger) echo self::fields_html($trigger->settings_fields(), 'trigger', $automation['trigger_settings']); ?>
                            </table>
                        </div>
                    </section>

                    <section class="postbox">
                        <h2 class="mo-auto-box-h"><?php esc_html_e("2. Who's included", 'mailoptin'); ?></h2>
                        <div class="inside">
                            <?php echo self::audience_html($automation, $trigger); ?>
                        </div>
                    </section>

                    <section class="postbox">
                        <h2 class="mo-auto-box-h"><?php esc_html_e('3. Waits and emails', 'mailoptin'); ?></h2>
                        <div class="inside">
                            <p class="description"><?php esc_html_e('Steps run from top to bottom for each person. "Edit email" opens the email in the designer.', 'mailoptin'); ?></p>
                            <?php echo self::steps_html($automation, $trigger, $live); ?>
                            <?php echo self::placeholders_html($trigger); ?>
                        </div>
                    </section>
                </div>

                <div class="mo-auto-side">
                    <section class="postbox">
                        <h2 class="mo-auto-box-h"><?php esc_html_e('Status', 'mailoptin'); ?></h2>
                        <div class="inside">
                            <p><?php echo $live ? UI::badge(__('Live', 'mailoptin'), 'ok') : UI::badge(__('Draft', 'mailoptin')); ?></p>
                            <p class="description"><?php echo $live
                                    ? esc_html__('Changes apply straight away, also to people part-way through. Emails already sent are never sent again.', 'mailoptin')
                                    : esc_html__('Nothing is sent while it is a draft.', 'mailoptin'); ?></p>
                            <?php echo self::report_html($automation); ?>
                        </div>
                        <div class="mo-auto-actions">
                            <button class="button" name="mo_automation_action" value="save"><?php esc_html_e('Save', 'mailoptin'); ?></button>
                            <?php if ($live) : ?>
                                <button class="button" name="mo_automation_action" value="stop"><?php esc_html_e('Stop', 'mailoptin'); ?></button>
                            <?php else : ?>
                                <button class="button button-primary" name="mo_automation_action" value="go_live"><?php esc_html_e('Save and go live', 'mailoptin'); ?></button>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="postbox">
                        <h2 class="mo-auto-box-h"><?php esc_html_e('Stops early when', 'mailoptin'); ?></h2>
                        <div class="inside">
                            <p><?php echo esc_html($trigger ? $trigger->stops_when() : __('They unsubscribe.', 'mailoptin')); ?></p>
                            <p class="description"><?php esc_html_e('Or when they don\'t pass an "Only if" step.', 'mailoptin'); ?></p>
                        </div>
                    </section>

                    <section class="postbox">
                        <h2 class="mo-auto-box-h"><?php esc_html_e('Sending hours', 'mailoptin'); ?></h2>
                        <div class="inside">
                            <?php $from = (string)($automation['settings']['send_from'] ?? ''); $to = (string)($automation['settings']['send_to'] ?? ''); ?>
                            <p><label><input type="checkbox" name="settings[hours_on]" value="1" <?php checked('' !== $from && '' !== $to); ?> data-toggle="#mo-auto-hours"> <?php esc_html_e('Only send between', 'mailoptin'); ?></label></p>
                            <p id="mo-auto-hours">
                                <label class="screen-reader-text" for="mo-auto-from"><?php esc_html_e('From', 'mailoptin'); ?></label>
                                <input type="time" id="mo-auto-from" name="settings[send_from]" value="<?php echo esc_attr($from ?: '08:00'); ?>">
                                <?php esc_html_e('and', 'mailoptin'); ?>
                                <label class="screen-reader-text" for="mo-auto-to"><?php esc_html_e('To', 'mailoptin'); ?></label>
                                <input type="time" id="mo-auto-to" name="settings[send_to]" value="<?php echo esc_attr($to ?: '20:00'); ?>">
                            </p>
                            <p class="description"><?php printf(esc_html__('Site time (%s). Emails due outside these hours wait for the start of the next window.', 'mailoptin'), esc_html(wp_timezone_string())); ?></p>
                        </div>
                    </section>

                    <section class="postbox">
                        <h2 class="mo-auto-box-h"><?php esc_html_e('Send a test', 'mailoptin'); ?></h2>
                        <div class="inside">
                            <p><label for="mo-auto-test"><?php esc_html_e('Send test emails to', 'mailoptin'); ?></label>
                                <input type="email" id="mo-auto-test" class="widefat" name="test_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>"></p>
                            <p class="description"><?php esc_html_e('Use "Send a test" on an email. It is filled in from the latest person who started this automation, or with sample details if nobody has yet.', 'mailoptin'); ?></p>
                        </div>
                    </section>

                    <p class="mo-auto-delete">
                        <button class="button-link mo-auto-danger" name="mo_automation_action" value="delete" formnovalidate data-confirm="<?php echo esc_attr__('Delete this automation and its emails? People part-way through get no more of its emails. This can\'t be undone.', 'mailoptin'); ?>"><?php esc_html_e('Delete automation', 'mailoptin'); ?></button>
                    </p>
                </div>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Rows for a trigger's settings or audience fields.
     */
    protected static function fields_html($fields, $name, $values)
    {
        $html = '';

        foreach ($fields as $key => $field) {
            $id    = 'mo-auto-' . $name . '-' . sanitize_key($key);
            $input = $name . '[' . $key . ']';
            $value = $values[$key] ?? ($field['default'] ?? '');
            $opts  = AbstractTrigger::field_options($field);

            ob_start();
            echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($field['label']) . '</label></th><td>';

            switch ($field['type']) {
                case 'select':
                    echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($input) . '">';
                    if (empty($opts)) echo '<option value="">' . esc_html__('None to choose from yet', 'mailoptin') . '</option>';
                    elseif ( ! isset($opts[$value])) echo '<option value="">' . esc_html__('Choose…', 'mailoptin') . '</option>';
                    foreach ($opts as $k => $label) echo '<option value="' . esc_attr($k) . '"' . selected((string)$k, (string)$value, false) . '>' . esc_html($label) . '</option>';
                    echo '</select>';
                    break;
                case 'multiselect':
                    echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($input) . '[]" multiple class="mo-auto-chosen" data-placeholder="' . esc_attr__('Any', 'mailoptin') . '">';
                    foreach ($opts as $k => $label) echo '<option value="' . esc_attr($k) . '"' . (in_array((string)$k, array_map('strval', (array)$value), true) ? ' selected' : '') . '>' . esc_html($label) . '</option>';
                    echo '</select>';
                    break;
                case 'radio':
                    foreach ($opts as $k => $label) {
                        echo '<label class="mo-auto-radio"><input type="radio" name="' . esc_attr($input) . '" value="' . esc_attr($k) . '"' . checked((string)$k, (string)$value, false) . '> ' . esc_html($label) . '</label> ';
                    }
                    break;
                case 'checkbox':
                    echo '<label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($input) . '" value="1"' . checked( ! empty($value), true, false) . '> ' . esc_html($field['text'] ?? $field['label']) . '</label>';
                    break;
                case 'number':
                    echo '<input type="number" id="' . esc_attr($id) . '" class="small-text" name="' . esc_attr($input) . '" value="' . esc_attr($value) . '" min="0" step="any">';
                    if ( ! empty($field['suffix'])) echo ' ' . esc_html($field['suffix']);
                    break;
                case 'time':
                    echo '<input type="time" id="' . esc_attr($id) . '" name="' . esc_attr($input) . '" value="' . esc_attr($value) . '">';
                    break;
                default:
                    echo '<input type="text" id="' . esc_attr($id) . '" class="regular-text" name="' . esc_attr($input) . '" value="' . esc_attr($value) . '">';
            }

            if ( ! empty($field['description'])) echo '<p class="description">' . esc_html($field['description']) . '</p>';

            echo '</td></tr>';
            $html .= ob_get_clean();
        }

        return $html;
    }

    protected static function audience_html($automation, $trigger)
    {
        $audience = $automation['audience'];
        $segments = Segments::is_ready() ? Segments::all() : [];
        $tags     = TagsRepository::is_ready() ? TagsRepository::names() : [];
        $excluded = array_map('absint', (array)($audience['exclude_tag_ids'] ?? []));

        ob_start();
        ?>
        <table class="form-table" role="presentation">
            <?php if ($trigger) echo self::fields_html($trigger->audience_fields(), 'audience', $audience); ?>
            <?php if ( ! empty($segments)) : ?>
                <tr>
                    <th scope="row"><label for="mo-auto-segment"><?php esc_html_e('Segment', 'mailoptin'); ?></label></th>
                    <td>
                        <select id="mo-auto-segment" name="audience[segment_id]">
                            <option value="0"><?php esc_html_e('None: everyone', 'mailoptin'); ?></option>
                            <?php foreach ($segments as $segment) : ?>
                                <option value="<?php echo absint($segment['id']); ?>" <?php selected(absint($segment['id']), absint($audience['segment_id'] ?? 0)); ?>><?php echo esc_html($segment['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($trigger && ! $trigger->requires_subscription()) : ?>
                            <p class="description"><?php esc_html_e('With a segment, only customers who are subscribers in it are included.', 'mailoptin'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ( ! empty($tags)) : ?>
                <tr>
                    <th scope="row"><label for="mo-auto-exclude"><?php esc_html_e('Leave out people tagged', 'mailoptin'); ?></label></th>
                    <td>
                        <select id="mo-auto-exclude" name="audience[exclude_tag_ids][]" multiple class="mo-auto-chosen" data-placeholder="<?php esc_attr_e('Choose tags', 'mailoptin'); ?>">
                            <?php foreach ($tags as $tag_id => $name) : ?>
                                <option value="<?php echo absint($tag_id); ?>" <?php echo in_array(absint($tag_id), $excluded, true) ? 'selected' : ''; ?>><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        <?php
        $count = $trigger ? $trigger->count_recent($automation) : null;

        if ($count) : ?>
            <div class="mo-auto-count">
                <span><?php echo wp_kses(sprintf(
                        /* translators: 1: number matching, 2: number in total, 3: what was counted */
                        __('<b>%1$s</b> of the %2$s %3$s match.', 'mailoptin'),
                        number_format_i18n($count['matched']), number_format_i18n($count['total']), esc_html($count['label'])
                    ), ['b' => []]); ?></span>
                <span class="description"><?php esc_html_e('Updated when you save. Unsubscribed and suppressed people are always left out.', 'mailoptin'); ?></span>
            </div>
        <?php else : ?>
            <p class="description"><?php esc_html_e('Unsubscribed and suppressed people are always left out.', 'mailoptin'); ?></p>
        <?php endif;

        return ob_get_clean();
    }

    protected static function steps_html($automation, $trigger, $live)
    {
        $steps  = Repo::steps($automation['id']);
        $sent   = Repo::step_sent_counts($automation['id']);
        $checks = $trigger ? Engine::checks($trigger) : [];
        $n      = 0;

        ob_start();
        echo '<ol class="mo-auto-steps">';

        if (empty($steps)) {
            echo '<li class="mo-auto-step-empty">' . esc_html__('No steps yet. Add an email to start.', 'mailoptin') . '</li>';
        }

        foreach ($steps as $i => $step) {
            $sid    = absint($step['id']);
            $prefix = 'steps[' . $i . ']';
            $tools  = self::move_buttons($sid, $i, count($steps));

            echo '<li class="mo-auto-step mo-auto-step-' . esc_attr($step['type']) . '">';
            echo '<input type="hidden" name="' . esc_attr($prefix) . '[id]" value="' . $sid . '"><input type="hidden" name="' . esc_attr($prefix) . '[type]" value="' . esc_attr($step['type']) . '">';

            switch ($step['type']) {
                case Repo::STEP_EMAIL:
                    $n++;
                    $email_id = absint($step['settings']['email_campaign_id'] ?? 0);
                    $paused   = ! empty($step['settings']['paused']);
                    $subject  = $email_id ? (string)ER::get_customizer_value($email_id, 'email_campaign_title') : '';
                    echo '<span class="mo-auto-num" aria-hidden="true">' . $n . '</span><div class="mo-auto-step-body">';
                    echo '<strong>' . esc_html('' !== $subject ? $subject : __('(no subject)', 'mailoptin')) . '</strong>';
                    echo '<div class="description">' . esc_html(sprintf(__('Email %d', 'mailoptin'), $n)) . ($paused ? ' · ' . UI::badge(__('Paused: people pass by it', 'mailoptin'), 'warn') : '') . '</div>';
                    if (isset($sent[$sid]) || $live) echo self::email_stats_html($email_id, $sent[$sid] ?? 0);
                    echo '</div><div class="mo-auto-tools">';
                    if ($email_id) {
                        echo '<button class="button button-small" name="mo_automation_action" value="edit_email:' . $sid . '">' . esc_html__('Edit email', 'mailoptin') . '</button> ';
                        echo '<button class="button button-small" name="mo_automation_action" value="test:' . $sid . '">' . esc_html__('Send a test', 'mailoptin') . '</button> ';
                    }
                    echo '<details class="mo-auto-more"><summary class="button button-small" aria-label="' . esc_attr__('More', 'mailoptin') . '">&hellip;</summary><div>';
                    echo '<button class="button-link" name="mo_automation_action" value="' . ($paused ? 'resume' : 'pause') . ':' . $sid . '">' . ($paused ? esc_html__('Resume this email', 'mailoptin') : esc_html__('Pause this email', 'mailoptin')) . '</button>';
                    echo '<button class="button-link" name="mo_automation_action" value="duplicate_email:' . $sid . '">' . esc_html__('Duplicate this email', 'mailoptin') . '</button>';
                    echo '</div></details>';
                    echo $tools . '</div>';
                    break;

                case Repo::STEP_WAIT:
                    $s     = $step['settings'];
                    $until = (string)($s['until'] ?? '');
                    echo '<span class="dashicons dashicons-clock" aria-hidden="true"></span><div class="mo-auto-step-body">';
                    echo '<label>' . esc_html__('Wait', 'mailoptin') . ' <input type="number" class="small-text" min="0" step="1" name="' . esc_attr($prefix) . '[amount]" value="' . esc_attr((int)($s['amount'] ?? 1)) . '"></label> ';
                    echo '<label class="screen-reader-text" for="mo-auto-unit-' . $sid . '">' . esc_html__('Unit', 'mailoptin') . '</label><select id="mo-auto-unit-' . $sid . '" name="' . esc_attr($prefix) . '[unit]">';
                    foreach (self::unit_labels() as $unit => $label) echo '<option value="' . esc_attr($unit) . '"' . selected($unit, $s['unit'] ?? 'days', false) . '>' . esc_html($label) . '</option>';
                    echo '</select> &nbsp; ';
                    echo '<label><input type="checkbox" name="' . esc_attr($prefix) . '[until_on]" value="1"' . checked('' !== $until, true, false) . '> ' . esc_html__('then until', 'mailoptin') . '</label> ';
                    echo '<label class="screen-reader-text" for="mo-auto-until-' . $sid . '">' . esc_html__('Time of day', 'mailoptin') . '</label><input type="time" id="mo-auto-until-' . $sid . '" name="' . esc_attr($prefix) . '[until]" value="' . esc_attr($until ?: '09:00') . '"> ';
                    echo '<label><input type="checkbox" name="' . esc_attr($prefix) . '[weekdays_only]" value="1"' . checked( ! empty($s['weekdays_only']), true, false) . '> ' . esc_html__('on a weekday', 'mailoptin') . '</label>';
                    echo '</div><div class="mo-auto-tools">' . $tools . '</div>';
                    break;

                case Repo::STEP_CHECK:
                    $current = (string)($step['settings']['check'] ?? '');
                    echo '<span class="dashicons dashicons-filter" aria-hidden="true"></span><div class="mo-auto-step-body">';
                    echo '<label for="mo-auto-check-' . $sid . '">' . esc_html__('Continue only if', 'mailoptin') . '</label> <select id="mo-auto-check-' . $sid . '" name="' . esc_attr($prefix) . '[check]">';
                    foreach ($checks as $check_id => $check) echo '<option value="' . esc_attr($check_id) . '"' . selected($check_id, $current, false) . '>' . esc_html($check['label']) . '</option>';
                    echo '</select><div class="description">' . esc_html__("Anyone who doesn't pass stops here.", 'mailoptin') . '</div>';
                    echo '</div><div class="mo-auto-tools">' . $tools . '</div>';
                    break;
            }

            echo '</li>';
        }

        echo '</ol>';
        echo '<div class="mo-auto-adds">';
        echo '<button class="button" name="mo_automation_action" value="add_wait">' . esc_html__('+ Wait', 'mailoptin') . '</button> ';
        echo '<button class="button" name="mo_automation_action" value="add_email">' . esc_html__('+ Email', 'mailoptin') . '</button> ';
        if ( ! empty($checks)) echo '<button class="button" name="mo_automation_action" value="add_check">' . esc_html__('+ Only if…', 'mailoptin') . '</button>';
        echo '</div>';

        return ob_get_clean();
    }

    protected static function move_buttons($step_id, $index, $count)
    {
        $html = '';

        if ($index > 0) $html .= '<button class="mo-auto-icon" name="mo_automation_action" value="up:' . $step_id . '" aria-label="' . esc_attr__('Move up', 'mailoptin') . '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>';
        if ($index < $count - 1) $html .= '<button class="mo-auto-icon" name="mo_automation_action" value="down:' . $step_id . '" aria-label="' . esc_attr__('Move down', 'mailoptin') . '"><span class="dashicons dashicons-arrow-down-alt2"></span></button>';

        return $html . '<button class="mo-auto-icon" name="mo_automation_action" value="remove:' . $step_id . '" aria-label="' . esc_attr__('Remove step', 'mailoptin') . '" data-confirm="' . esc_attr__('Remove this step? Emails it has queued are cancelled.', 'mailoptin') . '"><span class="dashicons dashicons-trash"></span></button>';
    }

    protected static function unit_labels()
    {
        return ['minutes' => __('minutes', 'mailoptin'), 'hours' => __('hours', 'mailoptin'), 'days' => __('days', 'mailoptin'), 'weeks' => __('weeks', 'mailoptin')];
    }

    protected static function email_stats_html($email_campaign_id, $sent)
    {
        $log_id = Engine::existing_log_id($email_campaign_id);
        $stats  = $log_id && Activity::is_ready() ? Activity::stats($log_id) : null;
        $rate   = function ($n) use ($stats) {
            return $stats && $stats[Activity::METRIC_SENT] ? round(100 * $n / $stats[Activity::METRIC_SENT]) . '%' : '—';
        };

        return '<div class="mo-auto-stats">'
               . '<span>' . esc_html__('Sent', 'mailoptin') . ' <b>' . esc_html(number_format_i18n($sent)) . '</b></span>'
               . '<span>' . esc_html__('Opened', 'mailoptin') . ' <b>' . esc_html($rate($stats[Activity::METRIC_OPENERS] ?? 0)) . '</b></span>'
               . '<span>' . esc_html__('Clicked', 'mailoptin') . ' <b>' . esc_html($rate($stats[Activity::METRIC_CLICKERS] ?? 0)) . '</b></span>'
               . '</div>';
    }

    protected static function report_html($automation)
    {
        $stats   = Repo::list_stats()[$automation['id']] ?? [];
        $reasons = Repo::stop_reasons($automation['id']);

        if (empty($stats) && empty($reasons)) return '';

        $html = '<dl class="mo-auto-report">';
        $html .= '<dt>' . esc_html__('In progress', 'mailoptin') . '</dt><dd>' . esc_html(number_format_i18n($stats['active'] ?? 0)) . '</dd>';
        $html .= '<dt>' . esc_html__('Emails sent (30 days)', 'mailoptin') . '</dt><dd>' . esc_html(number_format_i18n($stats['sent_30'] ?? 0)) . '</dd>';
        $html .= '</dl>';

        if ( ! empty($reasons)) {
            $html .= '<details class="mo-auto-reasons"><summary>' . esc_html__('Why people stopped early', 'mailoptin') . '</summary><ul>';
            foreach (array_slice($reasons, 0, 8, true) as $reason => $n) {
                $html .= '<li>' . esc_html(self::reason_label($reason)) . ' <b>' . esc_html(number_format_i18n($n)) . '</b></li>';
            }
            $html .= '</ul></details>';
        }

        return $html;
    }

    public static function reason_label($reason)
    {
        $labels = [
            'suppressed'         => __('Unsubscribed or suppressed', 'mailoptin'),
            'not_subscribed'     => __('No longer subscribed', 'mailoptin'),
            'left_list'          => __('Left the list', 'mailoptin'),
            'tag_removed'        => __('Tag removed', 'mailoptin'),
            'ordered'            => __('Placed an order', 'mailoptin'),
            'order_deleted'      => __('Order deleted', 'mailoptin'),
            'order_refunded'     => __('Order refunded', 'mailoptin'),
            'order_cancelled'    => __('Order cancelled', 'mailoptin'),
            'order_failed'       => __('Order failed', 'mailoptin'),
            'email_missing'      => __('An email was deleted', 'mailoptin'),
            'automation_deleted' => __('Automation deleted', 'mailoptin'),
            'erased'             => __('Personal data erased', 'mailoptin'),
            'error'              => __('An error', 'mailoptin'),
        ];

        if (isset($labels[$reason])) return $labels[$reason];

        if (0 === strpos($reason, 'check:')) return __('Didn\'t pass an "Only if" step', 'mailoptin');

        return $reason;
    }

    protected static function placeholders_html($trigger)
    {
        if ( ! $trigger) return '';

        $rows = [];

        foreach (Engine::placeholders($trigger) as $key => $placeholder) $rows['{{' . $key . '}}'] = $placeholder['label'];

        if ($trigger->requires_subscription()) {
            $rows['{{subscriber.first_name|there}}'] = __('First name, or "there" when it is empty', 'mailoptin');
            $rows['{{subscriber.email}}']            = __('Email address', 'mailoptin');
        }

        $rows['{{unsubscribe}}'] = __('Unsubscribe link', 'mailoptin');

        $html = '<details class="mo-auto-placeholders"><summary>' . esc_html__('Placeholders you can use in these emails', 'mailoptin') . '</summary>';
        $html .= '<p class="description">' . esc_html__('Type them in an email\'s subject or text. Add a fallback after a | for when the value is empty.', 'mailoptin') . '</p><table class="widefat striped"><tbody>';

        foreach ($rows as $tag => $label) $html .= '<tr><td><code>' . esc_html($tag) . '</code></td><td>' . esc_html($label) . '</td></tr>';

        return $html . '</tbody></table></details>';
    }

    /**
     * The automations someone is in, on their subscriber profile.
     */
    public function profile_card($subscriber)
    {
        if ( ! Repo::is_ready() || empty($subscriber['email'])) return;

        $runs = Repo::runs_for_email($subscriber['email'], 10);

        if (empty($runs)) return;

        $sent_counts = Repo::sent_counts_for_runs(wp_list_pluck($runs, 'id'));

        echo '<div class="mo-card"><h3>' . esc_html__('Automations', 'mailoptin') . '</h3><ul class="mo-auto-journey">';

        foreach ($runs as $run) {
            $automation = ! empty($run['automation_name']) ? ['id' => $run['automation_id'], 'name' => $run['automation_name']] : null;
            $name       = $automation ? $automation['name'] : __('(deleted automation)', 'mailoptin');
            $label      = Repo::RUN_ACTIVE === $run['status'] ? __('In progress', 'mailoptin') : (Repo::RUN_COMPLETED === $run['status'] ? __('Finished', 'mailoptin') : sprintf(__('Stopped: %s', 'mailoptin'), self::reason_label($run['stop_reason'])));
            $sent       = $sent_counts[$run['id']] ?? 0;

            echo '<li>' . ($automation ? '<a href="' . esc_url(self::edit_url($automation['id'])) . '">' . esc_html($name) . '</a>' : esc_html($name));
            echo '<br><span class="description">' . esc_html($label) . ' · ' . esc_html(sprintf(_n('%s email sent', '%s emails sent', $sent, 'mailoptin'), number_format_i18n($sent)));
            if (Repo::RUN_ACTIVE === $run['status'] && ! empty($run['next_run_at'])) {
                echo ' · ' . esc_html(sprintf(__('next step %s', 'mailoptin'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($run['next_run_at'] . ' UTC'))));
            }
            echo '</span></li>';
        }

        echo '</ul></div>';
    }

    /* ---------------------------------------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------------------------------------ */

    protected static function redirect($url)
    {
        do_admin_redirect(apply_filters('mailoptin_automations_page_redirect', $url));
    }

    protected static function error($message)
    {
        set_transient('mo_automation_error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS);
    }

    public function handle_actions()
    {
        if ( ! self::is_this_page() || empty($_POST['mo_automation_action'])) return;

        if ( ! current_user_has_privilege()) wp_die(esc_html__('You are not allowed to do this.', 'mailoptin'), 403);

        $action = sanitize_text_field(wp_unslash($_POST['mo_automation_action']));

        if ('create' === $action) {
            check_admin_referer(self::NONCE_NEW);
            if ( ! Engine::is_available()) return;

            $this->create();

            return;
        }

        $automation_id = absint($_POST['id'] ?? 0);

        check_admin_referer(self::nonce_action($automation_id));

        if ( ! Engine::is_available()) return;

        $automation = Repo::get($automation_id);

        if ( ! $automation) {
            self::redirect(self::url());

            return;
        }

        // Actions with a step: "remove:12".
        $step_id = 0;
        if (false !== strpos($action, ':')) {
            list($action, $step_id) = explode(':', $action, 2);
            $step_id = absint($step_id);
        }

        $action = sanitize_key($action);

        switch ($action) {
            case 'stop':
                Repo::update($automation_id, ['status' => Repo::STATUS_DRAFT]);
                self::redirect(self::edit_url($automation_id, ['mo_notice' => 'stopped']));

                return;

            case 'duplicate':
                $copy = self::duplicate($automation);
                self::redirect($copy ? self::edit_url($copy, ['mo_notice' => 'duplicated']) : self::url());

                return;

            case 'delete':
                self::delete($automation);
                self::redirect(self::url(['mo_notice' => 'deleted']));

                return;
        }

        // Everything else is from the builder: save what was posted first, so nothing typed is lost.
        if (isset($_POST['name'])) self::save_form($automation);

        $automation = Repo::get($automation_id);

        switch ($action) {
            case 'go_live':
                $problem = self::live_problem($automation);

                if ($problem) {
                    self::error($problem);
                    self::redirect(self::edit_url($automation_id));

                    return;
                }

                Repo::update($automation_id, ['status' => Repo::STATUS_LIVE]);
                self::redirect(self::edit_url($automation_id, ['mo_notice' => 'live']));

                return;

            case 'add_wait':
            case 'add_email':
            case 'add_check':
                self::add_step($automation, substr($action, 4));
                self::redirect(self::edit_url($automation_id) . '#mo-auto-steps-end');

                return;

            case 'up':
            case 'down':
            case 'remove':
            case 'pause':
            case 'resume':
            case 'duplicate_email':
                self::change_step($automation, $action, $step_id);
                self::redirect(self::edit_url($automation_id));

                return;

            case 'edit_email':
                $step = self::step_of($automation_id, $step_id, Repo::STEP_EMAIL);
                $email_campaign_id = $step ? absint($step['settings']['email_campaign_id'] ?? 0) : 0;
                self::redirect($email_campaign_id ? Email_Campaign_List::_campaign_customize_url($email_campaign_id) : self::edit_url($automation_id));

                return;

            case 'test':
                $step = self::step_of($automation_id, $step_id, Repo::STEP_EMAIL);
                $to   = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
                $sent = $step && is_email($to) && Engine::send_test($automation, $step, $to);
                self::redirect(self::edit_url($automation_id, ['mo_notice' => $sent ? 'test_sent' : 'test_fail']));

                return;
        }

        self::redirect(self::edit_url($automation_id, ['mo_notice' => 'saved']));
    }

    protected function create()
    {
        $recipe = sanitize_key(wp_unslash($_POST['recipe'] ?? ''));

        if ('' !== $recipe) {
            $automation_id = Recipes::create($recipe);
        } else {
            $trigger = Triggers::get_instance()->get(sanitize_key(wp_unslash($_POST['trigger'] ?? '')));

            if ( ! $trigger || ! $trigger->is_available()) {
                self::redirect(self::url(['screen' => 'new']));

                return;
            }

            $defaults = [];
            foreach ($trigger->settings_fields() as $key => $field) {
                if (isset($field['default'])) $defaults[$key] = $field['default'];
            }

            $automation_id = Repo::create([
                'name'             => $trigger->label(),
                'trigger_id'       => $trigger->id(),
                'trigger_settings' => $defaults,
                'settings'         => Recipes::default_settings(),
                'status'           => Repo::STATUS_DRAFT,
            ]);
        }

        self::redirect($automation_id ? self::edit_url($automation_id) : self::url(['screen' => 'new']));
    }

    /**
     * Save the builder form: name, trigger and its settings, audience, sending hours and steps.
     */
    protected static function save_form($automation)
    {
        $automation_id = $automation['id'];
        $data          = ['name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')) ?: $automation['name']];
        $trigger       = Triggers::get_instance()->get($automation['trigger_id']);

        // The trigger can change only on a draft; its settings then start from its defaults.
        if (Repo::STATUS_LIVE !== $automation['status'] && isset($_POST['trigger_id'])) {
            $new = Triggers::get_instance()->get(sanitize_key(wp_unslash($_POST['trigger_id'])));

            if ($new && $new->is_available() && $new->id() !== $automation['trigger_id']) {
                $data['trigger_id'] = $new->id();
                $trigger            = $new;
                $_POST['trigger']   = [];
            }
        }

        if ($trigger) {
            $data['trigger_settings'] = self::clean_fields($trigger->settings_fields(), wp_unslash((array)($_POST['trigger'] ?? [])));
            $audience                 = self::clean_fields($trigger->audience_fields(), wp_unslash((array)($_POST['audience'] ?? [])));
        } else {
            $audience = [];
        }

        $posted_audience             = wp_unslash((array)($_POST['audience'] ?? []));
        $audience['segment_id']      = absint($posted_audience['segment_id'] ?? 0);
        $audience['exclude_tag_ids'] = array_values(array_unique(array_filter(array_map('absint', array_filter((array)($posted_audience['exclude_tag_ids'] ?? []), 'is_scalar')))));

        if ($audience['segment_id'] && ! Segments::get($audience['segment_id'])) $audience['segment_id'] = 0;

        $data['audience'] = $audience;

        $posted_settings  = wp_unslash((array)($_POST['settings'] ?? []));
        $data['settings'] = array_merge($automation['settings'], [
            'send_from' => ! empty($posted_settings['hours_on']) ? self::clean_time($posted_settings['send_from'] ?? '') : '',
            'send_to'   => ! empty($posted_settings['hours_on']) ? self::clean_time($posted_settings['send_to'] ?? '') : '',
        ]);

        Repo::update($automation_id, $data);

        if (isset($_POST['steps'])) {
            Repo::save_steps($automation_id, self::posted_steps($automation_id, $trigger));
        }
    }

    /**
     * The steps as posted, in order. Only what the form edits is taken from it; the email a step
     * sends and whether it is paused come from what is saved.
     */
    protected static function posted_steps($automation_id, $trigger)
    {
        $saved  = [];
        $checks = $trigger ? Engine::checks($trigger) : [];
        $steps  = [];

        foreach (Repo::steps($automation_id) as $step) $saved[$step['id']] = $step;

        foreach ((array)wp_unslash($_POST['steps']) as $posted) {
            $id = absint($posted['id'] ?? 0);

            if ( ! isset($saved[$id])) continue;

            $step = $saved[$id];

            switch ($step['type']) {
                case Repo::STEP_WAIT:
                    $until            = ! empty($posted['until_on']) ? self::clean_time($posted['until'] ?? '') : '';
                    $step['settings'] = [
                        'amount'        => max(0, min(10000, (int)($posted['amount'] ?? 0))),
                        'unit'          => in_array($posted['unit'] ?? '', self::UNITS, true) ? $posted['unit'] : 'days',
                        'until'         => $until,
                        'weekdays_only' => ! empty($posted['weekdays_only']),
                    ];
                    break;
                case Repo::STEP_CHECK:
                    $check = sanitize_key($posted['check'] ?? '');
                    if (isset($checks[$check])) $step['settings'] = ['check' => $check];
                    break;
            }

            $steps[] = $step;
            unset($saved[$id]);
        }

        // Steps the form didn't include (added in another tab) are kept at the end.
        return array_merge($steps, array_values($saved));
    }

    protected static function clean_time($value)
    {
        $value = (string)$value;

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : '';
    }

    /**
     * Values for a trigger's fields, each checked against its type and options.
     */
    protected static function clean_fields($fields, $posted)
    {
        $clean = [];

        foreach ($fields as $key => $field) {
            $value   = $posted[$key] ?? null;
            $options = in_array($field['type'], ['select', 'multiselect', 'radio'], true) ? AbstractTrigger::field_options($field) : [];

            switch ($field['type']) {
                case 'select':
                case 'radio':
                    $value = is_scalar($value) && array_key_exists((string)$value, array_map('strval', array_combine(array_map('strval', array_keys($options)), array_keys($options)))) ? (string)$value : ($field['default'] ?? '');
                    break;
                case 'multiselect':
                    $allowed = array_map('strval', array_keys($options));
                    $value   = array_values(array_intersect(array_map('strval', array_filter((array)$value, 'is_scalar')), $allowed));
                    break;
                case 'checkbox':
                    $value = ! empty($value);
                    break;
                case 'number':
                    $value = is_numeric($value) ? max(0, 0 + $value) : ($field['default'] ?? '');
                    break;
                case 'time':
                    $value = self::clean_time($value) ?: ($field['default'] ?? '');
                    break;
                default:
                    $value = is_scalar($value) ? sanitize_text_field((string)$value) : '';
            }

            // Select options keyed by numbers (list, tag, field IDs) are stored as numbers.
            if (is_string($value) && 'select' === $field['type'] && ctype_digit($value)) $value = (int)$value;

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * Why an automation can't go live yet, or '' when it can.
     */
    protected static function live_problem($automation)
    {
        $trigger = Triggers::get_instance()->get($automation['trigger_id']);

        if ( ! $trigger || ! $trigger->is_available()) return __('Its trigger isn\'t available on this site. Is the plugin it needs active?', 'mailoptin');

        foreach ($trigger->settings_fields() as $key => $field) {
            if ('select' === $field['type'] && empty($automation['trigger_settings'][$key])) {
                return sprintf(__('Choose "%s" under "What starts it" first.', 'mailoptin'), $field['label']);
            }
        }

        $emails = 0;

        foreach (Repo::steps($automation['id']) as $step) {
            if (Repo::STEP_EMAIL !== $step['type']) continue;

            $email_id = absint($step['settings']['email_campaign_id'] ?? 0);

            if ( ! $email_id || ! ER::get_email_campaign_by_id($email_id)) return __('One of its emails was deleted. Remove that step first.', 'mailoptin');

            if ('' === trim((string)ER::get_customizer_value($email_id, 'email_campaign_title'))) return __('Give every email a subject first ("Edit email", then Settings).', 'mailoptin');

            $emails++;
        }

        if ( ! $emails) return __('Add at least one email first.', 'mailoptin');

        return '';
    }

    protected static function step_of($automation_id, $step_id, $type = '')
    {
        $step = Repo::get_step($step_id);

        if ( ! $step || (int)$step['automation_id'] !== (int)$automation_id || ! empty($step['deleted'])) return null;

        return '' === $type || $type === $step['type'] ? $step : null;
    }

    protected static function add_step($automation, $type)
    {
        $steps = Repo::steps($automation['id']);

        switch ($type) {
            case 'wait':
                $steps[] = ['type' => Repo::STEP_WAIT, 'settings' => ['amount' => 1, 'unit' => 'days', 'until' => '', 'weekdays_only' => false]];
                break;
            case 'check':
                $trigger = Triggers::get_instance()->get($automation['trigger_id']);
                $checks  = $trigger ? array_keys(Engine::checks($trigger)) : [];
                if (empty($checks)) return;
                $steps[] = ['type' => Repo::STEP_CHECK, 'settings' => ['check' => $checks[0]]];
                break;
            case 'email':
                $email_id = Recipes::create_email($automation['id'], __('New email', 'mailoptin'));
                if ( ! $email_id) return;
                $steps[] = ['type' => Repo::STEP_EMAIL, 'settings' => ['email_campaign_id' => $email_id]];
                break;
            default:
                return;
        }

        Repo::save_steps($automation['id'], $steps);
    }

    protected static function change_step($automation, $action, $step_id)
    {
        $steps = Repo::steps($automation['id']);
        $index = null;

        foreach ($steps as $i => $step) {
            if ((int)$step['id'] === $step_id) $index = $i;
        }

        if (null === $index) return;

        switch ($action) {
            case 'up':
            case 'down':
                $to = 'up' === $action ? $index - 1 : $index + 1;
                if ( ! isset($steps[$to])) return;
                $moving        = $steps[$index];
                $steps[$index] = $steps[$to];
                $steps[$to]    = $moving;
                break;
            case 'remove':
                array_splice($steps, $index, 1);
                break;
            case 'pause':
            case 'resume':
                if (Repo::STEP_EMAIL !== $steps[$index]['type']) return;
                $steps[$index]['settings']['paused'] = 'pause' === $action;
                break;
            case 'duplicate_email':
                if (Repo::STEP_EMAIL !== $steps[$index]['type']) return;
                $copy = self::clone_email(absint($steps[$index]['settings']['email_campaign_id'] ?? 0), $automation['id']);
                if ( ! $copy) return;
                array_splice($steps, $index + 1, 0, [['type' => Repo::STEP_EMAIL, 'settings' => ['email_campaign_id' => $copy]]]);
                break;
        }

        Repo::save_steps($automation['id'], $steps);
    }

    /**
     * A copy of an automation email, for another step or automation.
     *
     * @return int the copy's email campaign ID, 0 on failure
     */
    protected static function clone_email($email_campaign_id, $automation_id)
    {
        if ( ! $email_campaign_id || ! ER::get_email_campaign_by_id($email_campaign_id)) return 0;

        $subject = (string)ER::get_customizer_value($email_campaign_id, 'email_campaign_title');
        $copy    = Recipes::create_email($automation_id, $subject, '', ER::get_template_class($email_campaign_id) ?: 'Lucid');

        if ( ! $copy) return 0;

        $settings = ER::get_settings_by_id($email_campaign_id);

        ER::save_settings_by_id($copy, is_array($settings) ? $settings : []);

        return $copy;
    }

    /**
     * @return int the copy's ID
     */
    protected static function duplicate($automation)
    {
        $copy = Repo::create([
            'name'             => sprintf(__('%s (copy)', 'mailoptin'), $automation['name']),
            'trigger_id'       => $automation['trigger_id'],
            'trigger_settings' => $automation['trigger_settings'],
            'audience'         => $automation['audience'],
            'settings'         => $automation['settings'],
            'status'           => Repo::STATUS_DRAFT,
        ]);

        if ( ! $copy) return 0;

        $steps = [];

        foreach (Repo::steps($automation['id']) as $step) {
            if (Repo::STEP_EMAIL === $step['type']) {
                $email = self::clone_email(absint($step['settings']['email_campaign_id'] ?? 0), $copy);
                if ( ! $email) continue;
                $step['settings']['email_campaign_id'] = $email;
            }

            $steps[] = ['type' => $step['type'], 'settings' => $step['settings']];
        }

        Repo::save_steps($copy, $steps);

        return $copy;
    }

    protected static function delete($automation)
    {
        $emails = [];

        foreach (Repo::steps($automation['id'], true) as $step) {
            if (Repo::STEP_EMAIL === $step['type'] && ! empty($step['settings']['email_campaign_id'])) $emails[] = absint($step['settings']['email_campaign_id']);
        }

        Repo::delete($automation['id']);

        foreach (array_unique($emails) as $email_campaign_id) {
            if (ER::is_automation_email($email_campaign_id)) ER::delete_campaign_by_id($email_campaign_id);
        }
    }

    /* ---------------------------------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------------------------------ */

    /**
     * Saving when the trigger changes, confirmations, and the chosen multi-selects. Every button
     * still works without it.
     */
    protected static function script()
    {
        return <<<'JS'
jQuery(function ($) {
    $('.mo-auto-chosen').chosen({width: '100%'});
    $('#mo-auto-form [data-autosave]').on('change', function () {
        var form = this.form, b = document.createElement('input');
        b.type = 'hidden'; b.name = 'mo_automation_action'; b.value = 'save';
        form.appendChild(b); form.submit();
    });
    $(document).on('click', '[data-confirm]', function (e) {
        if (!window.confirm(this.getAttribute('data-confirm'))) e.preventDefault();
    });
    $('[data-toggle]').each(function () {
        var box = this, target = $(this.getAttribute('data-toggle'));
        var sync = function () { target.toggle(box.checked); };
        $(box).on('change', sync); sync();
    });
});
JS;
    }

    protected static function styles()
    {
        return '<style>
.mo-auto{max-width:1200px}
.mo-auto .mo-back{margin:12px 0 4px}
.mo-auto .num{text-align:right;font-variant-numeric:tabular-nums}
.mo-auto-list .mo-col-status{width:80px}.mo-auto-list .mo-col-name{width:22%}.mo-auto-list .mo-col-starts{width:24%}.mo-auto-list .mo-col-actions{width:170px}
.mo-auto-envelopes{margin-top:4px}.mo-auto-envelopes .dashicons{color:#2271b1;font-size:16px;width:16px;height:16px;margin-right:2px}.mo-auto-envelopes .dashicons.off{color:#c3c4c7}
.mo-auto .mo-inline-form{display:inline}
.mo-auto-empty{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:24px 28px;max-width:640px;margin-top:16px}
.mo-auto-empty h2{margin-top:0}
.mo-auto-h{font-size:16px;margin:24px 0 4px}
.mo-auto-lead{color:#646970;margin:0 0 12px;max-width:760px}
.mo-auto-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.mo-auto-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:14px 16px;display:flex;flex-direction:column;gap:6px}
.mo-auto-card h3{margin:0;font-size:14px}.mo-auto-card p{margin:0;color:#50575e}.mo-auto-card .mo-auto-flow{font-size:12px;color:#646970}
.mo-auto-card .button{align-self:flex-start;margin-top:auto}
.mo-auto-triggers{background:#fff;border:1px solid #c3c4c7;border-radius:4px;max-width:760px}
.mo-auto-group{padding:4px 16px 12px;border:0;border-top:1px solid #f0f0f1;margin:0}.mo-auto-group:first-child{border-top:0}
.mo-auto-group legend{padding-top:12px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#646970}
.mo-auto-group:last-child{padding-top:12px}
.mo-auto-opt{display:flex;gap:10px;padding:8px 10px;border-radius:4px;align-items:flex-start;cursor:pointer}
.mo-auto-opt:has(input:checked){background:#f0f6fc;outline:1px solid #72aee6}
.mo-auto-opt input{margin-top:3px}.mo-auto-opt b{display:block}.mo-auto-opt span span{color:#646970}
.mo-auto-head input{width:100%;font-size:1.4em;padding:4px 10px;margin:8px 0 14px}
.mo-auto-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:20px;align-items:start}
.mo-auto .postbox{margin-bottom:16px}
.mo-auto-box-h{font-size:14px;margin:0;padding:10px 16px;border-bottom:1px solid #f0f0f1}
.mo-auto .postbox .inside{margin:0;padding:4px 16px 14px}
.mo-auto .form-table th{width:190px;padding:12px 10px 12px 0}.mo-auto .form-table td{padding:10px 0}
.mo-auto-radio{margin-right:12px;display:inline-block}
.mo-auto-count{margin:6px 0 0;padding:10px 12px;background:#f0f6fc;border-left:4px solid #72aee6;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
.mo-auto-steps{list-style:none;margin:10px 0 0;padding:0}
.mo-auto-step{display:grid;grid-template-columns:34px minmax(0,1fr) auto;gap:12px;align-items:center;border:1px solid #dcdcde;border-radius:4px;padding:10px 12px;margin:0 0 8px;background:#fff}
.mo-auto-step-wait,.mo-auto-step-check{background:#f6f7f7}
.mo-auto-step>.dashicons{justify-self:center;color:#646970}
.mo-auto-step-empty{color:#646970;padding:8px 0}
.mo-auto-num{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:12px;background:#2271b1;color:#fff}
.mo-auto-step-body input[type=time]{vertical-align:middle}
.mo-auto-tools{display:flex;gap:4px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.mo-auto-icon{background:none;border:0;padding:2px;color:#787c82;cursor:pointer}.mo-auto-icon:hover{color:#2271b1}
.mo-auto-more{position:relative}.mo-auto-more summary{list-style:none}.mo-auto-more summary::-webkit-details-marker{display:none}
.mo-auto-more>div{position:absolute;right:0;top:28px;z-index:5;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:6px 10px;display:flex;flex-direction:column;gap:6px;white-space:nowrap;box-shadow:0 2px 6px rgba(0,0,0,.08)}
.mo-auto-stats{display:flex;gap:16px;color:#50575e;font-size:12px;margin-top:3px;font-variant-numeric:tabular-nums}
.mo-auto-adds{display:flex;gap:6px;margin-top:4px}
.mo-auto-placeholders{margin-top:14px}.mo-auto-placeholders summary{cursor:pointer;color:#2271b1}
.mo-auto-placeholders table{margin-top:6px;max-width:640px}
.mo-auto-actions{display:flex;justify-content:space-between;gap:8px;padding:10px 16px;border-top:1px solid #f0f0f1;background:#f6f7f7}
.mo-auto-report{display:grid;grid-template-columns:1fr auto;gap:4px 12px;margin:10px 0}.mo-auto-report dd{margin:0;font-weight:600;text-align:right}
.mo-auto-reasons summary{cursor:pointer;color:#2271b1}.mo-auto-reasons ul{margin:6px 0 0 16px;list-style:disc}
.mo-auto-delete{text-align:right}.mo-auto-danger{color:#b32d2e!important}
.mo-auto-journey{margin:0}.mo-auto-journey li{margin:0 0 8px}
.mo-upsell-box{max-width:72ch;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #7e3bd0;padding:4px 20px 8px;margin:12px 0 16px}
.mo-upsell-box ul{list-style:disc;margin-left:20px}
@media (max-width:960px){.mo-auto-layout{grid-template-columns:1fr}.mo-auto-step{grid-template-columns:28px minmax(0,1fr)}.mo-auto-tools{grid-column:2;justify-content:flex-start}}
@media (max-width:782px){.mo-auto .form-table th{width:auto;padding-bottom:0}.mo-auto-list .mo-col-name{width:auto}}
</style>';
    }

    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) $instance = new self();

        return $instance;
    }
}
