<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\SuppressionRepository;
use MailOptin\Core\Repositories\TagsRepository;

/**
 * A page where subscribers choose their lists and update their details, reached from a signed
 * link in every email. The link is the only key, like the unsubscribe link, so nobody needs an
 * account; viewing never changes anything, saving takes a POST.
 *
 * Using the link proves the inbox is theirs, so ticking a list they left, or one still waiting
 * for confirmation, subscribes them the way a confirmation click would.
 */
class PreferenceCentre
{
    const LINK_SETTING = 'preference_centre_link';

    public function __construct()
    {
        add_action('init', [$this, 'handle_request']);
    }

    public static function url($subscriber)
    {
        return add_query_arg([
            'mo_prefs' => $subscriber['id'],
            'mo_token' => Tokens::make('preferences', [$subscriber['id'], $subscriber['email']]),
        ], home_url('/'));
    }

    /**
     * Whether emails get a "Manage preferences" link next to Unsubscribe. On unless turned off.
     */
    public static function link_in_emails()
    {
        $settings = (array)get_option(MAILOPTIN_SETTINGS_DB_OPTION_NAME, []);

        return 'off' !== ($settings[self::LINK_SETTING] ?? 'on');
    }

    /**
     * Put a "Manage preferences" link after the first unsubscribe link, unless the email already
     * has a {{preferences}} tag.
     *
     * @return string
     */
    public static function add_link($content, $is_html)
    {
        $content = (string)$content;

        if ( ! self::link_in_emails() || false !== strpos($content, '{{preferences}}') || false === strpos($content, '{{unsubscribe}}')) return $content;

        $label = __('Manage preferences', 'mailoptin');

        if ($is_html) {
            // Outlook-only copies (<!--[if mso]>…<![endif]-->) are skipped: other apps never show them.
            $hidden = [];
            if (preg_match_all('#<!--\[if[^\]]*\]>.*?<!\[endif\]-->#is', $content, $blocks, PREG_OFFSET_CAPTURE)) {
                foreach ($blocks[0] as $block) $hidden[] = [$block[1], $block[1] + strlen($block[0])];
            }

            if ( ! preg_match_all('#<a\b(?=[^>]*\shref\s*=\s*(["\'])\{\{unsubscribe\}\}\1)([^>]*)>.*?</a>#is', $content, $links, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) return $content;

            foreach ($links as $link) {
                $start = $link[0][1];
                foreach ($hidden as list($from, $to)) {
                    if ($start >= $from && $start < $to) continue 2;
                }

                // Same classes as the unsubscribe link, so it gets the template's footer link colour.
                $class = preg_match('#\sclass\s*=\s*(["\'])(.*?)\1#is', $link[2][0], $m) ? ' class="' . esc_attr($m[2]) . '"' : '';
                $extra = ' &middot; <a href="{{preferences}}"' . $class . '>' . esc_html($label) . '</a>';
                $end   = $start + strlen($link[0][0]);

                return substr($content, 0, $end) . $extra . substr($content, $end);
            }

            return $content;
        }

        $eol   = false !== strpos($content, "\r\n") ? "\r\n" : "\n";
        $lines = explode($eol, $content);

        foreach ($lines as $i => $line) {
            if (false !== strpos($line, '{{unsubscribe}}')) {
                array_splice($lines, $i + 1, 0, [$label . ': {{preferences}}']);
                break;
            }
        }

        return implode($eol, $lines);
    }

    public function handle_request()
    {
        if ( ! isset($_GET['mo_prefs'])) return;

        $subscriber = Subscribers::get(absint($_GET['mo_prefs']));
        $token      = isset($_REQUEST['mo_token']) && is_string($_REQUEST['mo_token']) ? sanitize_text_field(wp_unslash($_REQUEST['mo_token'])) : '';

        if ( ! $subscriber || ! Tokens::check('preferences', [$subscriber['id'], $subscriber['email']], $token)) {
            $this->page(__('This link has expired', 'mailoptin'), '<p>' . esc_html__('This link is no longer valid. Use the "Manage preferences" link in a recent email.', 'mailoptin') . '</p>', 404);
        }

        $result = null;

        if ('POST' === strtoupper($_SERVER['REQUEST_METHOD'] ?? '')) {
            $result     = self::save($subscriber, wp_unslash($_POST));
            $subscriber = Subscribers::get($subscriber['id']);
        }

        $this->page(__('Your email preferences', 'mailoptin'), self::form_html($subscriber, $result));
    }

    protected function page($title, $html, $status = 200)
    {
        nocache_headers();
        // Page caching plugins: a cached copy would show someone's old choices, or someone else's.
        if ( ! defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        // The link is personal; keep it out of other sites' logs.
        if ( ! headers_sent()) {
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow');
        }

        wp_die(self::styles() . $html, esc_html($title), ['response' => $status]);
    }

    /**
     * "a•••••@gmail.com", so a forwarded email doesn't show the full address.
     */
    public static function masked_email($email)
    {
        list($local, $domain) = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1) . str_repeat('•', max(3, min(8, mb_strlen($local) - 1))) . '@' . $domain;
    }

    /**
     * @return array custom fields subscribers may change
     */
    public static function editable_fields()
    {
        if ( ! TagsRepository::is_ready()) return [];

        return array_values(array_filter(Fields::all(), function ($field) {
            return $field['subscriber_editable'];
        }));
    }

    /**
     * @return array membership rows with 'list' added, for lists that still exist
     */
    protected static function memberships($subscriber_id)
    {
        $rows = [];

        foreach (Subscribers::get_memberships($subscriber_id) as $membership) {
            $list = ListsRepository::get($membership['list_id']);

            if ($list) $rows[] = $membership + ['list' => $list];
        }

        return $rows;
    }

    public static function form_html($subscriber, $result = null)
    {
        $blocked     = in_array(SuppressionRepository::get_reason($subscriber['email']), [SuppressionRepository::REASON_BOUNCED, SuppressionRepository::REASON_COMPLAINED], true)
                       || in_array($subscriber['status'], [Subscribers::STATUS_BOUNCED, Subscribers::STATUS_COMPLAINED], true);
        $memberships = self::memberships($subscriber['id']);
        $fields      = self::editable_fields();
        $values      = $fields ? Fields::get_values($subscriber['id']) : [];
        $site        = \MailOptin\Core\PluginSettings\Settings::instance()->company_name() ?: get_bloginfo('name');

        ob_start();
        if ($result && empty($result['errors'])) : ?>
            <div class="mo-p-ok" role="status"><?php echo esc_html($result['unsubscribed_all'] ? __('Saved. You won\'t get any more emails from us.', 'mailoptin') : __('Your preferences are saved.', 'mailoptin')); ?></div>
        <?php elseif ($result) : ?>
            <div class="mo-p-error" role="alert"><?php echo esc_html(__('Some changes weren\'t saved:', 'mailoptin') . ' ' . implode(' ', $result['errors'])); ?></div>
        <?php endif; ?>
        <h1 class="mo-p-h"><?php esc_html_e('Your email preferences', 'mailoptin'); ?></h1>
        <p class="mo-p-mut"><?php printf(esc_html__('For %1$s from %2$s', 'mailoptin'), esc_html(self::masked_email($subscriber['email'])), esc_html($site)); ?></p>
        <form method="post" action="<?php echo esc_url(self::url($subscriber)); ?>">
            <div class="mo-p-sec">
                <h2><?php esc_html_e('What you get', 'mailoptin'); ?></h2>
                <?php if ($blocked) : ?>
                    <p><?php esc_html_e('Emails to this address bounced or were marked as spam, so we no longer send to it.', 'mailoptin'); ?></p>
                <?php elseif (empty($memberships)) : ?>
                    <p><?php esc_html_e('You aren\'t on any of our lists.', 'mailoptin'); ?></p>
                <?php endif; ?>
                <input type="hidden" name="lists_shown" value="1">
                <?php foreach ($memberships as $membership) :
                    $list = $membership['list'];
                    $on = self::is_ticked($subscriber, $membership);
                    ?>
                    <?php // What the box showed, so saving only acts on boxes the person changed, even from a cached page. ?>
                    <input type="hidden" name="was[<?php echo absint($list['id']); ?>]" value="<?php echo $on ? 1 : 0; ?>">
                    <label class="mo-p-check">
                        <input type="checkbox" name="lists[]" value="<?php echo absint($list['id']); ?>" <?php checked($on); ?> <?php disabled($blocked); ?>>
                        <span><b><?php echo esc_html($list['name']); ?></b>
                            <?php if ( ! empty($list['description'])) : ?><small><?php echo esc_html($list['description']); ?></small><?php endif; ?>
                            <?php if (Subscribers::STATUS_PENDING === $membership['status']) : ?><small><?php esc_html_e('Not confirmed yet. Tick it and save to start getting these emails.', 'mailoptin'); ?></small><?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="mo-p-sec">
                <h2><?php esc_html_e('About you', 'mailoptin'); ?></h2>
                <div class="mo-p-field"><label for="mo-p-first"><?php esc_html_e('First name', 'mailoptin'); ?></label><input type="text" id="mo-p-first" name="first_name" maxlength="100" value="<?php echo esc_attr($subscriber['first_name']); ?>" autocomplete="given-name"></div>
                <div class="mo-p-field"><label for="mo-p-last"><?php esc_html_e('Last name', 'mailoptin'); ?></label><input type="text" id="mo-p-last" name="last_name" maxlength="100" value="<?php echo esc_attr($subscriber['last_name']); ?>" autocomplete="family-name"></div>
                <?php foreach ($fields as $field) echo self::field_html($field, Fields::form_value($field, $values[$field['id']]['value_text'] ?? null)); ?>
            </div>
            <p><button type="submit" class="mo-p-btn"><?php esc_html_e('Save preferences', 'mailoptin'); ?></button></p>
        </form>
        <?php if (Subscribers::STATUS_UNSUBSCRIBED !== $subscriber['status'] && ! $blocked && ! empty($memberships)) : ?>
            <div class="mo-p-sec"><a class="mo-p-link" href="<?php echo esc_url(Tokens::unsubscribe_url($subscriber)); ?>"><?php esc_html_e('Unsubscribe from everything', 'mailoptin'); ?></a></div>
        <?php endif; ?>
        <p class="mo-p-foot"><?php esc_html_e('This page is only for you: anyone with its link can change your preferences, so please don\'t forward our emails.', 'mailoptin'); ?></p>
        <?php if ('' !== Subscription::postal_footer()) : ?><p class="mo-p-foot"><?php echo esc_html(Subscription::postal_footer()); ?></p><?php endif; ?>
        <?php
        return ob_get_clean();
    }

    protected static function field_html($field, $value)
    {
        $id   = 'mo-p-cf-' . $field['id'];
        $name = 'fields[' . $field['id'] . ']';

        switch ($field['type']) {
            case Fields::TYPE_DROPDOWN:
                $input = sprintf('<select id="%s" name="%s"><option value="">%s</option>', esc_attr($id), esc_attr($name), esc_html__('Choose…', 'mailoptin'));
                foreach ($field['options'] as $option) {
                    $input .= sprintf('<option value="%s" %s>%s</option>', esc_attr($option), selected($value, $option, false), esc_html($option));
                }
                // A value whose option was since removed is kept until they change it.
                if ('' !== $value && ! in_array($value, $field['options'], true)) {
                    $input .= sprintf('<option value="%s" selected>%s</option>', esc_attr($value), esc_html($value));
                }
                $input .= '</select>';
                break;
            case Fields::TYPE_CHECKBOX:
                return sprintf(
                    '<div class="mo-p-field"><span>%1$s</span><label><input type="hidden" name="%2$s" value="%5$s"><input type="checkbox" id="%3$s" name="%2$s" value="1" %4$s> %6$s</label></div>',
                    esc_html($field['label']),
                    esc_attr($name),
                    esc_attr($id),
                    checked('1', $value, false),
                    '' === $value ? '' : '0',
                    esc_html__('Yes', 'mailoptin')
                );
            case Fields::TYPE_MULTISELECT:
                $boxes = sprintf('<input type="hidden" name="%s[]" value="">', esc_attr($name));
                foreach (array_unique(array_merge($field['options'], (array)$value)) as $option) {
                    $boxes .= sprintf('<label class="mo-p-inline"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>', esc_attr($name), esc_attr($option), checked(in_array($option, (array)$value, true), true, false), esc_html($option));
                }

                return sprintf('<div class="mo-p-field"><span>%s</span><div>%s</div></div>', esc_html($field['label']), $boxes);
            default:
                $types = [Fields::TYPE_NUMBER => 'number" step="any', Fields::TYPE_DATE => 'date', Fields::TYPE_URL => 'url', Fields::TYPE_PHONE => 'tel'];
                $input = sprintf('<input type="%s" id="%s" name="%s" value="%s">', $types[$field['type']] ?? 'text', esc_attr($id), esc_attr($name), esc_attr($value));
        }

        return sprintf('<div class="mo-p-field"><label for="%s">%s</label>%s</div>', esc_attr($id), esc_html($field['label']), $input);
    }

    /**
     * A list's box is ticked when they get its emails. Lists still waiting for confirmation are
     * unticked: ticking one is how they confirm, and a box ticked for them wouldn't be consent.
     */
    protected static function is_ticked($subscriber, $membership)
    {
        return Subscribers::STATUS_SUBSCRIBED === $membership['status'] && Subscribers::STATUS_UNSUBSCRIBED !== $subscriber['status'];
    }

    /**
     * Apply what the subscriber chose.
     *
     * @param array $post unslashed
     *
     * @return array ['errors' => string[], 'unsubscribed_all' => bool]
     */
    public static function save($subscriber, $post)
    {
        $subscriber_id = (int)$subscriber['id'];
        $changes       = [];
        $errors        = [];

        // Names.
        $names = [];
        foreach (['first_name', 'last_name'] as $key) {
            if ( ! isset($post[$key]) || ! is_scalar($post[$key])) continue;
            $value = mb_substr(sanitize_text_field((string)$post[$key]), 0, 100);
            // Unchanged unless it differs from the stored name as it would be saved: names from an
            // import or the admin may hold characters this cleans up.
            if ($value !== mb_substr(sanitize_text_field((string)$subscriber[$key]), 0, 100)) $names[$key] = $value;
        }
        if ( ! empty($names)) {
            Subscribers::update($subscriber_id, $names);
            foreach ($names as $key => $value) {
                $changes['fields'][] = ['field' => 'first_name' === $key ? __('First name', 'mailoptin') : __('Last name', 'mailoptin'), 'from' => (string)$subscriber[$key], 'to' => $value];
            }
        }

        // Custom fields the admin allows, and only those.
        $editable = wp_list_pluck(self::editable_fields(), 'id');
        if ( ! empty($editable) && isset($post['fields']) && is_array($post['fields'])) {
            $submitted = [];
            foreach ($post['fields'] as $field_id => $value) {
                if ( ! in_array((int)$field_id, $editable, true)) continue;
                $submitted[(int)$field_id] = is_array($value) ? array_values(array_filter(array_map('sanitize_text_field', array_filter($value, 'is_scalar')), 'strlen')) : (string)$value;
            }

            $result = Fields::set_values($subscriber_id, $submitted);
            $errors = array_values($result['errors']);

            foreach ($result['changed'] as $field_id => list($old, $new)) {
                $field               = Fields::get($field_id);
                $changes['fields'][] = ['field' => $field['label'], 'from' => Fields::display_value($field, $old), 'to' => Fields::display_value($field, $new)];
            }
        }

        // Lists.
        $unsubscribed_all = false;
        $blocked          = in_array(SuppressionRepository::get_reason($subscriber['email']), [SuppressionRepository::REASON_BOUNCED, SuppressionRepository::REASON_COMPLAINED], true)
                            || in_array($subscriber['status'], [Subscribers::STATUS_BOUNCED, Subscribers::STATUS_COMPLAINED], true);

        if ( ! empty($post['lists_shown']) && ! $blocked) {
            $wanted = array_map('absint', array_filter((array)($post['lists'] ?? []), 'is_scalar'));
            $was    = is_array($post['was'] ?? null) ? $post['was'] : [];
            $join   = [];
            $leave  = [];

            foreach (self::memberships($subscriber_id) as $membership) {
                $list_id = (int)$membership['list_id'];
                $ticked  = in_array($list_id, $wanted, true);
                $shown   = isset($was[$list_id]) && is_scalar($was[$list_id]) ? (bool)$was[$list_id] : self::is_ticked($subscriber, $membership);
                $on      = self::is_ticked($subscriber, $membership);

                // Only boxes the person changed.
                if ($ticked === $shown) continue;

                if ($ticked && ! $on) {
                    $join[] = $list_id;
                    $changes['joined'][] = $membership['list']['name'];
                } elseif ( ! $ticked && $on) {
                    $leave[] = $membership;
                }
            }

            // Joins first, so moving from one list to another never leaves them on none in between.
            // Ticking a list here proves the inbox is theirs, like a confirmation click.
            if ( ! empty($join)) {
                foreach ($join as $list_id) {
                    $membership = Subscribers::get_membership($subscriber_id, $list_id);
                    if (Subscribers::STATUS_PENDING !== ($membership['status'] ?? '')) Subscribers::set_membership($subscriber_id, $list_id, Subscribers::STATUS_PENDING);
                }
                Subscription::confirm($subscriber_id, $join);
            }

            foreach ($leave as $membership) {
                Subscription::unsubscribe($subscriber_id, (int)$membership['list_id']);
                $changes['left'][] = $membership['list']['name'];
            }

            $unsubscribed_all = Subscribers::STATUS_UNSUBSCRIBED === Subscribers::get($subscriber_id)['status'];
        }

        if ( ! empty($changes)) Subscribers::log_event($subscriber_id, Subscribers::EVENT_PREFERENCES_CHANGED, $changes);

        return ['errors' => $errors, 'unsubscribed_all' => $unsubscribed_all];
    }

    protected static function styles()
    {
        return '<style>
body#error-page{max-width:560px}
.mo-p-h{font-size:22px;margin:0 0 4px;border:0;padding:0}
.mo-p-mut{color:#646970;margin:0 0 18px}
.mo-p-sec{border-top:1px solid #dcdcde;padding:14px 0}
.mo-p-sec h2{font-size:15px;margin:0 0 8px;border:0;padding:0}
.mo-p-check{display:flex;gap:10px;align-items:flex-start;margin:10px 0}
.mo-p-check small{display:block;color:#646970}
.mo-p-field{display:grid;grid-template-columns:140px 1fr;gap:10px;align-items:center;margin:10px 0}
.mo-p-field input[type=text],.mo-p-field input[type=number],.mo-p-field input[type=date],.mo-p-field input[type=url],.mo-p-field input[type=tel],.mo-p-field select{width:100%;box-sizing:border-box;padding:6px 8px;border:1px solid #8c8f94;border-radius:4px;font-size:14px}
.mo-p-inline{display:inline-block;margin:0 12px 4px 0}
.mo-p-btn{background:#2271b1;color:#fff;border:0;border-radius:4px;padding:10px 18px;font-size:14px;cursor:pointer}
.mo-p-link{color:#b32d2e}
.mo-p-ok{background:#edfaef;border-left:4px solid #00a32a;padding:10px 12px;margin:0 0 16px}
.mo-p-error{background:#fcf0f1;border-left:4px solid #d63638;padding:10px 12px;margin:0 0 16px}
.mo-p-foot{color:#646970;font-size:12px;margin-top:18px}
@media (max-width:600px){.mo-p-field{grid-template-columns:1fr}}
</style>';
    }

    /**
     * @return PreferenceCentre
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
