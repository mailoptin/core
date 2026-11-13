<?php

namespace MailOptin\Core\Admin\EmailEditor;

use MailOptin\Core\EmailBlocks\AbstractBlock;
use MailOptin\Core\EmailBlocks\Blocks;
use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Starters;
use MailOptin\Core\EmailBlocks\Templates;
use MailOptin\Core\EmailCampaigns\Newsletter\Templatify;
use MailOptin\Core\Repositories\CampaignSettingsRepository as Settings;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

use function MailOptin\Core\current_user_has_privilege;

/**
 * The full-page email editor for broadcasts and automation emails: blocks on the left, the real email
 * on the right, re-drawn in place by the same PHP that sends it. Post notifications and digests keep
 * the Customizer.
 *
 * admin.php?page=mailoptin-emails&view=edit-email&id=N
 */
class EmailEditor
{
    const VIEW = 'edit-email';

    public function __construct()
    {
        add_action('mailoptin_admin_settings_submenu_page_' . self::VIEW, [$this, 'render']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('admin_body_class', [$this, 'body_class']);

        foreach (['preview', 'save', 'test'] as $action) {
            add_action('wp_ajax_mo_email_editor_' . $action, [$this, 'ajax_' . $action]);
        }

        add_action('admin_post_mo_email_new', [$this, 'handle_new']);
    }

    /**
     * The template picker's styles.
     */
    public static function enqueue_picker()
    {
        wp_enqueue_style('mailoptin-email-editor', MAILOPTIN_ASSETS_URL . 'css/admin/email-editor.css', [], MAILOPTIN_VERSION_NUMBER);
    }

    /* ---------------------------------------------------------------------------------------------
     * Kinds of email
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return EmailContext[] id => context
     */
    public static function contexts()
    {
        static $contexts = null;

        if (null === $contexts) {
            $contexts = [];
            $list     = apply_filters('mailoptin_email_editor_contexts', [new Contexts\BroadcastContext(), new Contexts\AutomationContext()]);
            foreach ((array)$list as $context) {
                if ($context instanceof EmailContext) $contexts[$context->id()] = $context;
            }
        }

        return $contexts;
    }

    public static function context($id)
    {
        return self::contexts()[$id] ?? null;
    }

    /**
     * The context that owns an email.
     *
     * @return EmailContext|null
     */
    public static function context_for($email_campaign_id)
    {
        foreach (self::contexts() as $context) {
            if ($context->handles($email_campaign_id)) return $context;
        }

        return null;
    }

    /**
     * Whether an email is written as HTML by hand ("Code your own") rather than in blocks.
     */
    public static function is_code($email_campaign_id)
    {
        return ER::is_code_your_own_template($email_campaign_id);
    }

    /* ---------------------------------------------------------------------------------------------
     * Starting an email: one launcher for every kind
     * ------------------------------------------------------------------------------------------ */

    /**
     * Make an email of a kind, in a template, with the template's starter content, ready to open.
     *
     * @return int|\WP_Error the email's id
     */
    public static function start($context_id, $template, $args = [], $starter = '')
    {
        $context = self::context($context_id);

        if ( ! $context) return new \WP_Error('mo_email_context', __('Unknown kind of email.', 'mailoptin'));
        if ( ! $context->accepts_template($template)) return new \WP_Error('mo_email_template', __('Choose a template.', 'mailoptin'));

        $id = $context->create($template, $args);

        if (is_wp_error($id)) return $id;

        // A starter layout chosen in the picker: the email starts with it, not the template's default.
        $starter = (string)$starter;
        $chosen  = '' !== $starter && isset(Starters::get_instance()->for_context($context_id, $args)[$starter]) ? $starter : '';

        self::add_starter_content($id, $chosen);

        return $id;
    }

    /**
     * The template's starter content, for an email that has none yet.
     */
    public static function add_starter_content($email_campaign_id, $starter = '')
    {
        // Written by hand: a short, plain email to start from, not the Customizer's long sample.
        if (self::is_code($email_campaign_id)) {
            if ('' === (string)ER::get_customizer_value_without_default($email_campaign_id, 'code_your_own')) {
                ER::update_settings_by_id($email_campaign_id, ['code_your_own' => self::starter_html()]);
            }

            return;
        }

        // A starter layout replaces what the email was just made with (an automation's new email
        // already has the template's default).
        if ('' !== (string)$starter) {
            $content = Starters::get_instance()->content($starter, $email_campaign_id);
            ER::update_settings_by_id($email_campaign_id, ['email_newsletter_content' => wp_json_encode(Blocks::get_instance()->sanitize_content($content))]);

            return;
        }

        // Only what was saved: unsaved, the Customizer's default (paragraphs of Lorem Ipsum) shows.
        $saved = json_decode((string)ER::get_customizer_value_without_default($email_campaign_id, 'email_newsletter_content'), true);
        if ( ! empty($saved)) return;

        $template = \MailOptin\Core\Admin\Customizer\EmailCampaign\EmailCampaignFactory::make($email_campaign_id);
        $content  = $template && method_exists($template, 'starter_content') ? (array)$template->starter_content() : [];

        // Each block saved with its defaults, as a block added in the editor is.
        foreach ($content as $i => $element) {
            $block = is_array($element) ? Blocks::get_instance()->get($element['type'] ?? '') : null;
            if ($block) $content[$i]['settings'] = array_replace($block->defaults($email_campaign_id), (array)($element['settings'] ?? []));
        }

        if ($content) ER::update_settings_by_id($email_campaign_id, ['email_newsletter_content' => wp_json_encode(Blocks::get_instance()->sanitize_content($content))]);
    }

    /**
     * The template picker for a kind of email: a form that makes the email and opens the editor.
     *
     * @param array $args hidden values the context's create() takes
     * @param string $fields extra form HTML before the templates (escaped by the caller)
     */
    public static function picker_html($context_id, $args = [], $fields = '', $submit = '', $compact = false)
    {
        $context = self::context($context_id);
        if ( ! $context) return '';

        $submit = '' !== $submit ? $submit : __('Continue to the editor', 'mailoptin');

        ob_start();
        include __DIR__ . '/picker.php';

        return ob_get_clean();
    }

    /**
     * Whether a new email of this kind has more than one way to start (else the picker is skipped).
     */
    public static function has_choice($context_id)
    {
        $context = self::context($context_id);

        return $context && count($context->templates()) + ($context->allows_code() ? 1 : 0) > 1;
    }

    /**
     * The "New broadcast" page: a name and a template, then the editor.
     */
    public static function new_broadcast_page()
    {
        self::enqueue_picker();

        $error  = isset($_GET['mo_error']) ? sanitize_text_field(wp_unslash($_GET['mo_error'])) : '';
        $fields = '<p class="mo-ee-picker-field"><label for="mo-ee-new-name"><b>' . esc_html__('Broadcast name', 'mailoptin') . '</b></label>'
                  . '<input type="text" id="mo-ee-new-name" name="args[name]" class="regular-text" required maxlength="50">'
                  . '<span class="description">' . esc_html__('Only you see this. The subject is set in the editor.', 'mailoptin') . '</span></p>';

        echo '<div class="wrap mo-ee-new">';
        echo '<p><a href="' . esc_url(MAILOPTIN_EMAIL_NEWSLETTERS_SETTINGS_PAGE) . '">&larr; ' . esc_html__('Broadcasts', 'mailoptin') . '</a></p>';
        echo '<h1>' . esc_html__('New broadcast', 'mailoptin') . '</h1>';
        echo '<p class="mo-ee-new-lede">' . esc_html__('Name it and pick a template. The editor opens next; you choose who gets it and when after that.', 'mailoptin') . '</p>';
        if ('' !== $error) echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        echo self::picker_html('broadcast', [], $fields);
        echo '</div>';
    }

    /**
     * What an email written as HTML starts with: one centred column, a greeting, a button and the
     * unsubscribe link. Filter: mailoptin_email_editor_starter_html.
     */
    public static function starter_html()
    {
        $site  = esc_html(get_bloginfo('name'));
        $hello = esc_html__('Hi {{subscriber.first_name|there}},', 'mailoptin');
        $body  = esc_html__('Write your message here.', 'mailoptin');
        $more  = esc_html__('Read more', 'mailoptin');
        $url   = esc_url(home_url('/'));
        $unsub = esc_html__('Unsubscribe', 'mailoptin');

        $html = <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$site}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">
  <tr>
    <td align="center" style="padding:24px 12px;">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#333333;">
        <tr><td style="padding:28px 32px 8px;font-size:20px;font-weight:bold;">{$site}</td></tr>
        <tr><td style="padding:8px 32px;"><p style="margin:0 0 12px;">{$hello}</p><p style="margin:0;">{$body}</p></td></tr>
        <tr><td style="padding:16px 32px 28px;"><a href="{$url}" style="display:inline-block;background:#2271b1;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:4px;">{$more}</a></td></tr>
      </table>
      <p style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#71717a;margin:16px 0 0;"><a href="[unsubscribe]" style="color:#71717a;">{$unsub}</a></p>
    </td>
  </tr>
</table>
</body>
</html>
HTML;

        return (string)apply_filters('mailoptin_email_editor_starter_html', $html);
    }

    /**
     * admin-post.php?action=mo_email_new: the template picker's form. Makes the email and opens it.
     */
    public function handle_new()
    {
        $context_id = sanitize_key($_POST['context'] ?? '');

        if ( ! current_user_has_privilege() || ! check_admin_referer('mo_email_new_' . $context_id)) wp_die(esc_html__('You are not allowed to do this.', 'mailoptin'), 403);

        $template = preg_replace('/[^A-Za-z0-9_-]/', '', (string)wp_unslash($_POST['template'] ?? ''));
        $args     = array_map('sanitize_text_field', (array)wp_unslash($_POST['args'] ?? []));
        $starter  = sanitize_key((string)wp_unslash($_POST['starter'] ?? ''));
        $result   = self::start($context_id, $template, $args, $starter);

        if (is_wp_error($result)) {
            $back = wp_get_referer() ?: admin_url();
            wp_safe_redirect(add_query_arg(['mo_error' => rawurlencode($result->get_error_message())], $back));
            exit;
        }

        wp_safe_redirect(self::url($result));
        exit;
    }

    public static function url($email_campaign_id)
    {
        return add_query_arg(['view' => self::VIEW, 'id' => absint($email_campaign_id)], MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE);
    }

    /**
     * Emails this editor edits: broadcasts and automation emails. A broadcast that has been sent,
     * or is sending, can't be changed.
     */
    public static function edits($email_campaign_id)
    {
        $context = $email_campaign_id && ER::is_built_email($email_campaign_id) ? self::context_for($email_campaign_id) : null;

        return $context && $context->editable($email_campaign_id);
    }

    protected static function is_this_page()
    {
        return is_admin() && MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG === ($_GET['page'] ?? '') && self::VIEW === ($_GET['view'] ?? '');
    }

    protected static function nonce_action($email_campaign_id)
    {
        return 'mo_email_editor_' . absint($email_campaign_id);
    }

    /**
     * Where "back" goes: the automation the email belongs to, or the broadcast's page.
     */
    public static function back_url($email_campaign_id)
    {
        $context = self::context_for($email_campaign_id);

        return $context ? $context->back_url($email_campaign_id) : MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_PAGE;
    }

    public function body_class($classes)
    {
        return self::is_this_page() ? $classes . ' mo-ee-fullscreen' : $classes;
    }

    /* ---------------------------------------------------------------------------------------------
     * What can be edited
     * ------------------------------------------------------------------------------------------ */

    /**
     * Design settings (the old Customizer's Body, Header, Content and Footer sections), with their
     * field types: shown on the Design tab and checked on save.
     */
    public static function design_fields($email_campaign_id = 0)
    {
        // Settings only the email's template has (Market's offer strip).
        $extra = [];
        if ($email_campaign_id) {
            $template = Templates::get_instance()->get(Templates::template_id_for($email_campaign_id));
            if ($template && is_callable([$template['class'], 'extra_design_fields'])) {
                $extra = (array)call_user_func([$template['class'], 'extra_design_fields']);
            }
        }

        $color = function ($label, $section) {
            return ['type' => 'color_picker', 'label' => $label, 'section' => $section];
        };

        return apply_filters('mailoptin_email_editor_design_fields', [
            'page_background_color'         => $color(__('Page background', 'mailoptin'), 'body'),
            'content_background_color'      => $color(__('Content background', 'mailoptin'), 'body'),
            'content_text_color'            => $color(__('Text colour', 'mailoptin'), 'body'),
            'content_headline_color'        => $color(__('Headline colour', 'mailoptin'), 'body'),
            'header_removal'                => ['type' => 'checkbox', 'label' => __('Hide the header', 'mailoptin'), 'section' => 'header'],
            'header_logo'                   => ['type' => 'select_image', 'label' => __('Logo', 'mailoptin'), 'section' => 'header'],
            'header_text'                   => ['type' => 'text', 'label' => __('Header text', 'mailoptin'), 'section' => 'header'],
            'header_background_color'       => $color(__('Header background', 'mailoptin'), 'header'),
            'header_text_color'             => $color(__('Header text colour', 'mailoptin'), 'header'),
            'header_web_version_link_label' => ['type' => 'text', 'label' => __('"View in browser" link text', 'mailoptin'), 'section' => 'header'],
            'footer_removal'                => ['type' => 'checkbox', 'label' => __('Hide the footer', 'mailoptin'), 'section' => 'footer'],
            'footer_copyright_line'         => ['type' => 'text', 'label' => __('Copyright line', 'mailoptin'), 'section' => 'footer'],
            'footer_description'            => ['type' => 'textarea', 'label' => __('Footer text', 'mailoptin'), 'section' => 'footer'],
            'footer_unsubscribe_line'       => ['type' => 'text', 'label' => __('Unsubscribe line', 'mailoptin'), 'section' => 'footer'],
            'footer_unsubscribe_link_label' => ['type' => 'text', 'label' => __('Unsubscribe link text', 'mailoptin'), 'section' => 'footer'],
            'footer_background_color'       => $color(__('Footer background', 'mailoptin'), 'footer'),
            'footer_text_color'             => $color(__('Footer text colour', 'mailoptin'), 'footer'),
            'footer_font_size'              => ['type' => 'range', 'label' => __('Footer text size (px)', 'mailoptin'), 'min' => 8, 'max' => 30, 'step' => 1, 'section' => 'footer'],
        ] + $extra, $email_campaign_id);
    }

    public static function settings_fields()
    {
        return [
            'email_campaign_title'     => ['type' => 'text', 'label' => __('Subject', 'mailoptin')],
            'email_campaign_preheader' => ['type' => 'text', 'label' => __('Preview text', 'mailoptin'), 'description' => __('Shown after the subject in most inboxes.', 'mailoptin')],
        ];
    }

    /**
     * The email as the editor holds it: content (blocks), design and settings.
     */
    public static function state($email_campaign_id)
    {
        $content = json_decode((string)ER::get_customizer_value($email_campaign_id, 'email_newsletter_content'), true);
        $state   = ['template' => (string)ER::get_template_class($email_campaign_id), 'content' => is_array($content) ? array_values($content) : [], 'design' => [], 'settings' => []];

        // Written by hand: the HTML is the email, no blocks or design settings.
        if (self::is_code($email_campaign_id)) {
            $state['content'] = [];
            $state['code']    = (string)ER::get_customizer_value($email_campaign_id, 'code_your_own');
            foreach (self::settings_fields() as $key => $field) $state['settings'][$key] = (string)ER::get_merged_customizer_value($email_campaign_id, $key);

            return $state;
        }

        // The template first: it adds its defaults (colours, Market's offer strip) to the email's.
        \MailOptin\Core\Admin\Customizer\EmailCampaign\EmailCampaignFactory::make($email_campaign_id);

        foreach (self::design_fields($email_campaign_id) as $key => $field) $state['design'][$key] = ER::get_merged_customizer_value($email_campaign_id, $key);
        foreach (self::settings_fields() as $key => $field) $state['settings'][$key] = (string)ER::get_merged_customizer_value($email_campaign_id, $key);

        return $state;
    }

    /**
     * A posted state, cleaned: blocks by their own fields, design and settings by theirs.
     *
     * @return array setting => value, ready to save
     */
    public static function clean_state($state, $email_campaign_id = 0)
    {
        $state = is_array($state) ? $state : [];

        if ($email_campaign_id && self::is_code($email_campaign_id)) {
            $values = ['code_your_own' => self::clean_code((string)($state['code'] ?? ''))];
            $posted = (array)($state['settings'] ?? []);
            foreach (self::settings_fields() as $key => $field) {
                if (array_key_exists($key, $posted)) $values[$key] = Fields::sanitize($field, $posted[$key]);
            }

            return $values;
        }

        $values = ['email_newsletter_content' => wp_json_encode(Blocks::get_instance()->sanitize_content($state['content'] ?? []))];

        foreach (['design' => self::design_fields($email_campaign_id), 'settings' => self::settings_fields()] as $group => $fields) {
            $posted = (array)($state[$group] ?? []);

            foreach ($fields as $key => $field) {
                if (array_key_exists($key, $posted)) $values[$key] = Fields::sanitize($field, $posted[$key]);
            }
        }

        return $values;
    }

    /** The template a posted state's design values were made for: the one shown last, else the saved one. */
    protected static function design_template($state, $email_campaign_id)
    {
        $shown = is_string($state['design_template'] ?? null) ? $state['design_template'] : '';

        return '' !== $shown ? $shown : (string)ER::get_template_class($email_campaign_id);
    }

    /**
     * The design values an email's template gives it, for the design fields: its defaults.
     */
    public static function template_design_defaults($email_campaign_id, $template_id)
    {
        Templates::set_preview($email_campaign_id, $template_id);

        try {
            // The template adds its defaults (colours, Market's offer strip) when it is made.
            \MailOptin\Core\Admin\Customizer\EmailCampaign\EmailCampaignFactory::make($email_campaign_id);
            $defaults = (new \MailOptin\Core\Admin\Customizer\EmailCampaign\AbstractCustomizer($email_campaign_id))->customizer_defaults;
            $fields   = self::design_fields($email_campaign_id);
        } finally {
            Templates::clear_preview();
        }

        $values = [];
        foreach ($fields as $key => $field) $values[$key] = $defaults[$key] ?? '';

        return $values;
    }

    /**
     * Switching template: a design value still at a template's default takes the new template's
     * (a colour the person chose stays), and fields only another template has go.
     */
    public static function switch_design($state, $email_campaign_id, $to)
    {
        if ( ! is_array($state['design'] ?? null)) return $state;

        $new = self::template_design_defaults($email_campaign_id, $to);

        // A value at any template's default (the saved one's, or a look tried on screen and not
        // saved) is a default, not a choice.
        $known = [];
        foreach (array_keys(Templates::get_instance()->for_email($email_campaign_id)) as $template_id) {
            if ($template_id === $to) continue;
            foreach (self::template_design_defaults($email_campaign_id, $template_id) as $key => $value) $known[$key][] = (string)$value;
        }

        foreach ($state['design'] as $key => $value) {
            if ( ! array_key_exists($key, $new)) {
                unset($state['design'][$key]);
            } elseif (in_array((string)$value, $known[$key] ?? [], true)) {
                $state['design'][$key] = $new[$key];
            }
        }

        // Fields the new template adds (Market's offer strip) start at its default.
        foreach ($new as $key => $value) {
            if ( ! array_key_exists($key, $state['design'])) $state['design'][$key] = $value;
        }

        return $state;
    }

    /**
     * The template a posted state asks for, if this email may use it.
     */
    public static function posted_template($state, $email_campaign_id)
    {
        $id = is_array($state) && is_string($state['template'] ?? null) && ! self::is_code($email_campaign_id) ? $state['template'] : '';

        return '' !== $id && isset(Templates::get_instance()->for_email($email_campaign_id)[$id]) ? $id : null;
    }

    /**
     * An email written by hand, as saved. Those who may post unfiltered HTML keep it as written (as
     * the Customizer did). Others keep a whole email (doctype, head, style, body, table layout)
     * with what wp_kses_post allows: no scripts or event attributes.
     */
    public static function clean_code($html)
    {
        if (current_user_can('unfiltered_html')) return $html;

        $doctype = preg_match('/^\s*<!doctype[^>]*>/i', $html, $m) ? $m[0] . "\n" : '';
        $common  = ['class' => true, 'id' => true, 'style' => true, 'dir' => true, 'lang' => true];
        $table   = $common + ['width' => true, 'height' => true, 'align' => true, 'valign' => true, 'bgcolor' => true, 'border' => true, 'cellpadding' => true, 'cellspacing' => true, 'role' => true, 'background' => true];
        $allowed = array_merge(wp_kses_allowed_html('post'), [
            'html'   => ['lang' => true, 'dir' => true, 'xmlns' => true, 'xmlns:v' => true, 'xmlns:o' => true],
            'head'   => [],
            'meta'   => ['charset' => true, 'name' => true, 'content' => true, 'http-equiv' => true],
            'title'  => [],
            'style'  => ['type' => true, 'media' => true],
            'body'   => $common + ['bgcolor' => true],
            'center' => $common,
            'table'  => $table,
            'tbody'  => $table,
            'thead'  => $table,
            'tr'     => $table,
            'td'     => $table + ['colspan' => true, 'rowspan' => true],
            'th'     => $table + ['colspan' => true, 'rowspan' => true],
        ]);

        // Outlook's conditional comments (<!--[if mso]> … <![endif]-->, and the <!--[if !mso]><!-->
        // form) would be mangled: their markers are set aside and put back, what they hold is cleaned.
        $markers = [];
        $html    = preg_replace_callback('/<!--\[if ([a-z0-9 !|&()]+)\]>(<!-->)?|(<!--)?<!\[endif\]-->/i', function ($m) use (&$markers) {
            $key           = 'MOCOND' . count($markers) . 'X' . wp_generate_password(8, false) . 'X';
            $markers[$key] = $m[0];

            return $key;
        }, preg_replace('/^\s*<!doctype[^>]*>/i', '', $html));

        return $doctype . strtr(wp_kses($html, $allowed), $markers);
    }

    /**
     * The checks before sending, for an email as the editor holds it and as it's drawn.
     */
    public static function checks($email_campaign_id, $state, $html)
    {
        $context = self::context_for($email_campaign_id);
        $known   = [];

        foreach ($context ? $context->placeholders($email_campaign_id) : [] as $group) {
            foreach ($group['items'] as $item) $known[] = $item['key'];
        }

        return Checks::run($state, $html, $known);
    }

    /**
     * What the saved email is now, to tell whether it changed since the editor loaded it. Only the
     * settings this editor shows count: a change made elsewhere (say, the broadcast's audience)
     * isn't a conflict.
     */
    public static function version($email_campaign_id)
    {
        $saved = (array)Settings::get_saved(Settings::TYPE_EMAIL, $email_campaign_id);
        $keys  = array_merge(['email_newsletter_content', 'code_your_own'], array_keys(self::design_fields($email_campaign_id)), array_keys(self::settings_fields()));

        return md5(wp_json_encode([ER::get_template_class($email_campaign_id), array_intersect_key($saved, array_flip($keys))]));
    }

    /* ---------------------------------------------------------------------------------------------
     * Page
     * ------------------------------------------------------------------------------------------ */

    public function enqueue()
    {
        if ( ! self::is_this_page()) return;

        $email_campaign_id = absint($_GET['id'] ?? 0);

        if ( ! self::edits($email_campaign_id)) return;

        wp_enqueue_media();
        wp_enqueue_editor();
        // WordPress's own CodeMirror, for emails written as HTML.
        $code_editor = self::is_code($email_campaign_id) ? wp_enqueue_code_editor(['type' => 'text/html']) : false;
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('mailoptin-email-editor', MAILOPTIN_ASSETS_URL . 'js/admin/email-editor.js', ['jquery', 'jquery-ui-sortable', 'wp-color-picker', 'wp-util'], MAILOPTIN_VERSION_NUMBER, true);
        wp_enqueue_style('mailoptin-email-editor', MAILOPTIN_ASSETS_URL . 'css/admin/email-editor.css', ['wp-color-picker'], MAILOPTIN_VERSION_NUMBER);

        $blocks = [];
        foreach (Blocks::get_instance()->available_for($email_campaign_id) as $id => $block) {
            $blocks[$id] = ['title' => $block->title(), 'description' => $block->description(), 'defaults' => $block->defaults($email_campaign_id)];
        }

        wp_localize_script('mailoptin-email-editor', 'moEmailEditor', [
            'id'      => $email_campaign_id,
            'ajax'    => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::nonce_action($email_campaign_id)),
            'state'   => self::state($email_campaign_id),
            'version' => self::version($email_campaign_id),
            'blocks'  => $blocks,
            'code'    => self::is_code($email_campaign_id),
            'placeholders' => ($context = self::context_for($email_campaign_id)) ? $context->placeholders($email_campaign_id) : [],
            'codeEditor' => $code_editor ?: null,
            'i18n'    => [
                'saved'      => __('Saved', 'mailoptin'),
                'saving'     => __('Saving…', 'mailoptin'),
                'unsaved'    => __('Unsaved changes', 'mailoptin'),
                'updating'   => __('Updating…', 'mailoptin'),
                'failed'     => __('Couldn\'t save. Check your connection and try again.', 'mailoptin'),
                'conflict'   => __('This email was changed somewhere else since you opened it. OK loads that version; Cancel keeps yours and saves over it.', 'mailoptin'),
                'draft'      => __('There are changes from an earlier visit that weren\'t saved. Restore them?', 'mailoptin'),
                'draftStale' => __('There are unsaved changes from an earlier visit, but the email has been saved since. Restore them? Saving will then ask which version to keep.', 'mailoptin'),
                'leave'      => __('Leave without saving?', 'mailoptin'),
                'delete'     => __('Delete this element?', 'mailoptin'),
                'duplicate'      => __('Duplicate', 'mailoptin'),
                'remove'         => __('Delete', 'mailoptin'),
                /* translators: 1: number of columns, 2: number of elements in them */
                'columnsSummary' => __('%1$s columns, %2$s elements', 'mailoptin'),
                /* translators: %s: column number */
                'column'         => __('Column %s', 'mailoptin'),
                'addToColumn'    => __('+ Add to this column', 'mailoptin'),
                'insertPlaceholder' => __('Insert placeholder', 'mailoptin'),
                /* translators: %d: number of warnings */
                'toCheck'           => __('%d to check', 'mailoptin'),
                'allGood'           => __('Ready to send', 'mailoptin'),
                'show'              => __('Show', 'mailoptin'),
                'addHere'           => __('+ Add an element here', 'mailoptin'),
                'search'            => __('Search…', 'mailoptin'),
                'fallback'          => __('If empty, show', 'mailoptin'),
                'fallbackHint'      => __('Optional, e.g. "there" in "Hi {{…|there}}".', 'mailoptin'),
                'testSent'   => __('Test sent.', 'mailoptin'),
                'testFailed' => __('The test couldn\'t be sent.', 'mailoptin'),
                'untitled'   => __('(no subject)', 'mailoptin'),
            ],
        ]);
    }

    public function render()
    {
        $email_campaign_id = absint($_GET['id'] ?? 0);

        if ( ! self::edits($email_campaign_id) || ! current_user_has_privilege()) {
            echo '<div class="wrap"><p>' . esc_html__('This email can\'t be edited here.', 'mailoptin') . '</p></div>';

            return;
        }

        $blocks    = Blocks::get_instance()->available_for($email_campaign_id);
        $templates = Templates::get_instance()->for_email($email_campaign_id);

        include __DIR__ . '/view.php';
    }

    /**
     * One form field, by type. Values are filled in by the editor from the email's data.
     */
    public static function field_html($name, $field)
    {
        $label = esc_html($field['label'] ?? '');
        $name  = esc_attr($name);
        $type  = $field['type'] ?? 'text';
        $html  = '<div class="mo-ee-field mo-ee-field-' . esc_attr($type) . '">';

        if ('checkbox' !== $type && '' !== $label) $html .= '<label class="mo-ee-label">' . $label . '</label>';

        switch ($type) {
            case 'tinymce':
                $html .= '<textarea class="mo-ee-input mo-ee-richtext" data-name="' . $name . '" rows="8"></textarea>';
                break;
            case 'textarea':
                $html .= '<textarea class="mo-ee-input" data-name="' . $name . '" rows="4"></textarea>';
                break;
            case 'checkbox':
                $html .= '<label><input type="checkbox" class="mo-ee-input" data-name="' . $name . '"> ' . esc_html($field['checkbox_label'] ?? $field['label'] ?? '') . '</label>';
                break;
            case 'range':
            case 'number':
                $html .= sprintf('<input type="number" class="mo-ee-input small-text" data-name="%s" min="%s" max="%s" step="%s">', $name, esc_attr($field['min'] ?? ''), esc_attr($field['max'] ?? ''), esc_attr($field['step'] ?? 1));
                break;
            case 'color_picker':
                $html .= '<input type="text" class="mo-ee-input mo-ee-color" data-name="' . $name . '">';
                break;
            case 'dimension':
                $html .= '<div class="mo-ee-dimension">';
                foreach (['top' => __('Top', 'mailoptin'), 'right' => __('Right', 'mailoptin'), 'bottom' => __('Bottom', 'mailoptin'), 'left' => __('Left', 'mailoptin')] as $side => $side_label) {
                    $html .= '<label><span>' . esc_html($side_label) . '</span><input type="number" min="0" class="mo-ee-input" data-name="' . $name . '" data-part="' . $side . '"></label>';
                }
                $html .= '</div>';
                break;
            case 'select_image':
                $html .= '<div class="mo-ee-image"><input type="url" class="mo-ee-input" data-name="' . $name . '"> <button type="button" class="button mo-ee-pick-image">' . esc_html__('Choose image', 'mailoptin') . '</button></div>';
                break;
            case 'select':
            case 'font_family':
                $html .= '<select class="mo-ee-input" data-name="' . $name . '"' . ( ! empty($field['multiple']) ? ' multiple' : '') . '>' . self::options_html($field['choices'] ?? []) . '</select>';
                break;
            default:
                $html .= '<input type="text" class="mo-ee-input regular-text" data-name="' . $name . '" placeholder="' . esc_attr($field['placeholder'] ?? '') . '">';
        }

        if ( ! empty($field['description'])) $html .= '<p class="description">' . esc_html($field['description']) . '</p>';

        return $html . '</div>';
    }

    protected static function options_html($choices)
    {
        $html = '';

        foreach ((array)$choices as $value => $label) {
            if (is_array($label)) {
                $html .= '<optgroup label="' . esc_attr($value) . '">' . self::options_html($label) . '</optgroup>';
            } else {
                $html .= '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
            }
        }

        return $html;
    }

    /**
     * A block's settings form, by tab.
     */
    public static function block_form_html(AbstractBlock $block)
    {
        $tabs   = $block->tabs() ?: ['' => ''];
        $fields = $block->fields();
        $html   = '';

        if (count($tabs) > 1) {
            $html .= '<div class="mo-ee-subtabs">';
            foreach ($tabs as $tab => $label) $html .= '<button type="button" class="mo-ee-subtab" data-tab="' . esc_attr($tab) . '">' . esc_html($label) . '</button>';
            $html .= '</div>';
        }

        foreach ($tabs as $tab => $label) {
            $html .= '<div class="mo-ee-subpanel" data-tab="' . esc_attr($tab) . '">';
            foreach ($fields as $name => $field) {
                if ('' === $tab || ($field['tab'] ?? '') === $tab || ( ! isset($tabs[$field['tab'] ?? '']) && $tab === array_key_first($tabs))) {
                    $html .= self::field_html($name, $field);
                }
            }
            $html .= '</div>';
        }

        return $html;
    }

    /* ---------------------------------------------------------------------------------------------
     * Requests
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return int the email campaign ID, after checking who asks and the nonce
     */
    protected static function authorize()
    {
        $email_campaign_id = absint($_POST['id'] ?? 0);

        if ( ! check_ajax_referer(self::nonce_action($email_campaign_id), 'nonce', false) || ! current_user_has_privilege() || ! self::edits($email_campaign_id)) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'mailoptin')], 403);
        }

        return $email_campaign_id;
    }

    protected static function posted_state()
    {
        $state = json_decode(wp_unslash((string)($_POST['state'] ?? '')), true);

        return is_array($state) ? $state : [];
    }

    /**
     * The email drawn from what is on screen, without saving it.
     */
    public function ajax_preview()
    {
        $email_campaign_id = self::authorize();
        $state             = self::posted_state();

        // Another template chosen, not saved yet: drawn in it, with its colours where the old ones were.
        $template = self::posted_template($state, $email_campaign_id);
        // Once per switch: the editor then says which template its design values are for.
        $switch   = $template && self::design_template($state, $email_campaign_id) !== $template;
        if ($switch) $state = self::switch_design($state, $email_campaign_id, $template);

        foreach (self::clean_state($state, $email_campaign_id) as $key => $value) {
            Settings::set_preview(Settings::TYPE_EMAIL, $email_campaign_id, $key, $value);
        }

        if ($template) Templates::set_preview($email_campaign_id, $template);

        try {
            $html = (new Templatify($email_campaign_id))->forge();
        } finally {
            Settings::clear_preview();
            Templates::clear_preview();
        }

        // Checked as drawn for someone (sample cart and products in), so the size is what's sent.
        $shown = self::with_samples($email_campaign_id, $html);

        $data = ['html' => $shown, 'checks' => self::checks($email_campaign_id, $state, $shown)];
        // The switched design values, for the Design tab to show.
        if ($switch && is_array($state['design'] ?? null)) {
            $data['design']          = $state['design'];
            $data['design_template'] = $template;
        }

        wp_send_json_success($data);
    }

    /**
     * An automation email's placeholders, filled with sample values for the preview.
     */
    protected static function with_samples($email_campaign_id, $html)
    {
        $context = self::context_for($email_campaign_id);

        return $context ? $context->preview_html($email_campaign_id, $html) : $html;
    }

    public function ajax_save()
    {
        $email_campaign_id = self::authorize();

        // Saved somewhere else since this editor loaded it: say so, unless told to save over it.
        if (empty($_POST['force']) && (string)($_POST['version'] ?? '') !== self::version($email_campaign_id)) {
            wp_send_json_error(['conflict' => true, 'state' => self::state($email_campaign_id), 'version' => self::version($email_campaign_id)], 409);
        }

        $state      = self::posted_state();
        $old_fields = self::design_fields($email_campaign_id);
        $posted     = self::posted_template($state, $email_campaign_id);

        // Design values made for another template than the one being saved: switched first.
        if ($posted && self::design_template($state, $email_campaign_id) !== $posted) $state = self::switch_design($state, $email_campaign_id, $posted);

        $template = $posted && $posted !== ER::get_template_class($email_campaign_id) ? $posted : null;

        // Saved with the template in place, so the design fields are the new template's.
        if ($template) Templates::set_for_email($email_campaign_id, $template);

        ER::update_settings_by_id($email_campaign_id, self::clean_state($state, $email_campaign_id));

        // Fields differ between templates: the editor reloads to show the new one's.
        $reload = $template && array_keys(self::design_fields($email_campaign_id)) !== array_keys($old_fields);

        wp_send_json_success(['version' => self::version($email_campaign_id), 'state' => self::state($email_campaign_id), 'reload' => $reload]);
    }

    /**
     * Send the saved email as a test.
     */
    public function ajax_test()
    {
        $email_campaign_id = self::authorize();
        $to                = sanitize_email(wp_unslash($_POST['email'] ?? '')) ?: wp_get_current_user()->user_email;

        $context = self::context_for($email_campaign_id);

        $context && $context->send_test($email_campaign_id, $to) ? wp_send_json_success() : wp_send_json_error();
    }

    public static function get_instance()
    {
        static $instance = null;

        if (null === $instance) $instance = new self();

        return $instance;
    }
}
