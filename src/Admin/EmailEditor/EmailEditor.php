<?php

namespace MailOptin\Core\Admin\EmailEditor;

use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements\SettingsFields;
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

        foreach (['preview', 'save', 'test', 'search'] as $action) {
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
        $state   = ['template' => (string)ER::get_template_class($email_campaign_id), 'content' => self::text_as_typed(is_array($content) ? array_values($content) : []), 'design' => [], 'settings' => []];

        // Written by hand: the HTML is the email, no blocks or design settings.
        if (self::is_code($email_campaign_id)) {
            $state['content'] = [];
            $state['code']    = (string)ER::get_customizer_value($email_campaign_id, 'code_your_own');
            foreach (self::settings_fields() as $key => $field) $state['settings'][$key] = (string)ER::get_merged_customizer_value($email_campaign_id, $key);

            return $state;
        }

        // The template first: it adds its defaults (colours, Market's offer strip) to the email's.
        \MailOptin\Core\Admin\Customizer\EmailCampaign\EmailCampaignFactory::make($email_campaign_id);

        // What get_merged_customizer_value() gives each field, with the defaults worked out once and
        // the settings read once (it works both out again for every field).
        $defaults = (new \MailOptin\Core\Admin\Customizer\EmailCampaign\AbstractCustomizer($email_campaign_id))->customizer_defaults;
        $saved    = (array)ER::get_settings_by_id($email_campaign_id);
        $value    = function ($key) use ($defaults, $saved) {
            return isset($saved[$key]) ? $saved[$key] : (isset($defaults[$key]) ? $defaults[$key] : '');
        };

        foreach (self::design_fields($email_campaign_id) as $key => $field) $state['design'][$key] = $value($key);
        foreach (self::settings_fields() as $key => $field) $state['settings'][$key] = (string)$value($key);

        return $state;
    }

    /**
     * Text the Customizer saved with slashes added, as the author wrote it: what the editor shows,
     * and (marked once saved) what it keeps.
     */
    protected static function text_as_typed($content)
    {
        foreach ($content as $i => $element) {
            if ( ! is_array($element)) continue;
            if ('text' === ($element['type'] ?? '') && is_array($element['settings'] ?? null) && isset($element['settings']['text_content']) && is_string($element['settings']['text_content'])) {
                $content[$i]['settings']['text_content'] = \MailOptin\Core\EmailBlocks\Builtin\Text::content($element['settings']);
                $content[$i]['settings'][\MailOptin\Core\EmailBlocks\Builtin\Text::AS_TYPED] = true;
            }
            if (is_array($element['columns'] ?? null)) {
                foreach ($element['columns'] as $c => $children) {
                    if (is_array($children)) $content[$i]['columns'][$c] = self::text_as_typed($children);
                }
            }
        }

        return $content;
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

        // Blocks whose type is gone for now (its plugin off) are kept as saved, not lost.
        $keep   = $email_campaign_id ? Blocks::get_instance()->unregistered_in((string)ER::get_customizer_value_without_default($email_campaign_id, 'email_newsletter_content')) : [];
        $values = ['email_newsletter_content' => wp_json_encode(Blocks::get_instance()->sanitize_content($state['content'] ?? [], false, $keep))];

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

        $checks = Checks::run($state, $html, $known);

        // A broadcast's discount code: the same for everyone, so a coupon the shop must have.
        if (ER::NEWSLETTER === ER::get_email_campaign_type($email_campaign_id)) {
            $warn = array_merge(Checks::unavailable_block_warnings($state, $email_campaign_id), Checks::broadcast_discount_warnings($state));
            if ($warn) $checks = array_merge($warn, $checks);
        }

        return $checks;
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
        // Defaults as an object: none is {} for the editor, not [] (a list it would put settings in).
        foreach (Blocks::get_instance()->available_for($email_campaign_id) as $id => $block) {
            $blocks[$id] = ['title' => $block->title(), 'description' => $block->description(), 'defaults' => (object)$block->defaults($email_campaign_id), 'iconHtml' => \MailOptin\Core\EmailBlocks\Icons::html($block->palette_icon())];
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
                /* translators: 1: a block's place, 2: how many blocks */
                'blockOf'        => __('Block %1$s of %2$s', 'mailoptin'),
                /* translators: %s: a column's number */
                'inColumn'       => __('In Columns · column %s', 'mailoptin'),
                'chooseImage'    => __('Choose image', 'mailoptin'),
                'replaceImage'   => __('Replace', 'mailoptin'),
                'placeholder'    => __('Placeholder', 'mailoptin'),
                /* translators: shown beside a slider whose value is left to the template */
                'auto'           => __('Auto', 'mailoptin'),
                'addToColumn'    => __('+ Add to this column', 'mailoptin'),
                'insertPlaceholder' => __('Insert placeholder', 'mailoptin'),
                /* translators: %d: number of warnings */
                'toCheck'           => __('%d to check', 'mailoptin'),
                'allGood'           => __('Ready to send', 'mailoptin'),
                'show'              => __('Show', 'mailoptin'),
                'addHere'           => __('+ Add an element here', 'mailoptin'),
                /* translators: %s: a block's name, e.g. Button */
                'dropHere'          => __('Drop %s here', 'mailoptin'),
                'search'            => __('Search…', 'mailoptin'),
                'noResults'         => __('Nothing found.', 'mailoptin'),
                /* translators: %s: a post's or product's title */
                'removeItem'        => __('Remove %s', 'mailoptin'),
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
        $palette   = Blocks::get_instance()->palette_for($email_campaign_id, $blocks);
        $templates = Templates::get_instance()->for_email($email_campaign_id);

        include __DIR__ . '/view.php';
    }

    /**
     * One form field, by type. Values are filled in by the editor from the email's data.
     */
    public static function field_html($name, $field, $in_block = false)
    {
        $type = $field['type'] ?? 'text';

        // In a block's form: a unit in the label ("Font Size (px)") goes beside the slider instead.
        $unit = $in_block && 'range' === $type ? (string)($field['unit'] ?? '') : '';
        if ($in_block && 'range' === $type && preg_match('/^(.*\S)\s*\((px|%)\)\s*$/', (string)($field['label'] ?? ''), $m)) {
            $field['label'] = $m[1];
            $unit           = $m[2];
        }

        $label = esc_html($field['label'] ?? '');
        $name  = esc_attr($name);
        $attrs = ! empty($field['show_if']) && $in_block ? " data-show-if='" . esc_attr(wp_json_encode($field['show_if'])) . "'" : '';
        $html  = '<div class="mo-ee-field mo-ee-field-' . esc_attr($type) . '"' . $attrs . '>';

        if ('checkbox' !== $type && '' !== $label) $html .= '<label class="mo-ee-label">' . $label . '</label>';

        if ($in_block) {
            $control = self::block_control_html($name, $field, $type, $unit);
            if (null !== $control) {
                $html .= $control;
                if ( ! empty($field['description'])) $html .= '<p class="description">' . esc_html($field['description']) . '</p>';

                return $html . '</div>';
            }
        }

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
                // A list searched for (posts, products): chosen ones as chips, a search box to add more.
                if ( ! empty($field['search'])) {
                    $html .= '<div class="mo-ee-search" data-search="' . esc_attr($field['search']) . '">'
                             . '<select class="mo-ee-input mo-ee-search-value" data-name="' . $name . '" multiple hidden aria-hidden="true" tabindex="-1"></select>'
                             . '<ul class="mo-ee-search-chosen" aria-label="' . esc_attr__('Chosen', 'mailoptin') . '"></ul>'
                             . '<input type="search" class="mo-ee-search-input regular-text" placeholder="' . esc_attr__('Type to search…', 'mailoptin') . '" aria-label="' . esc_attr($field['label'] ?? '') . '" autocomplete="off">'
                             . '<ul class="mo-ee-search-results" role="listbox"></ul></div>';
                    break;
                }
                $html .= '<select class="mo-ee-input" data-name="' . $name . '"' . ( ! empty($field['multiple']) ? ' multiple' : '') . '>' . self::options_html($field['choices'] ?? []) . '</select>';
                break;
            default:
                $html .= '<input type="text" class="mo-ee-input regular-text" data-name="' . $name . '" placeholder="' . esc_attr($field['placeholder'] ?? '') . '">';
        }

        if ( ! empty($field['description'])) $html .= '<p class="description">' . esc_html($field['description']) . '</p>';

        return $html . '</div>';
    }

    /**
     * The friendlier controls of a block's form, over the same inputs (each still a .mo-ee-input with
     * its data-name, so filling, saving and undo work as for any field). Null: the usual control.
     */
    protected static function block_control_html($name, $field, $type, $unit)
    {
        switch ($type) {
            case 'checkbox':
                return '<label class="mo-ee-switch"><input type="checkbox" class="mo-ee-input" data-name="' . $name . '"><span class="mo-ee-switch-track" aria-hidden="true"></span><span class="mo-ee-switch-label">' . esc_html($field['checkbox_label'] ?? $field['label'] ?? '') . '</span></label>';

            case 'range':
                return sprintf(
                    '<div class="mo-ee-range"><input type="range" class="mo-ee-input" data-name="%s" min="%s" max="%s" step="%s" aria-label="%s"><output class="mo-ee-range-value" data-unit="%s"></output></div>',
                    $name, esc_attr($field['min'] ?? 0), esc_attr($field['max'] ?? 100), esc_attr($field['step'] ?? 1), esc_attr($field['label'] ?? ''), esc_attr($unit)
                );

            case 'dimension':
                // Above and below show; the sides are a click away.
                $input = function ($side, $label) use ($name) {
                    return '<label><span>' . esc_html($label) . '</span><input type="number" min="0" class="mo-ee-input" data-name="' . $name . '" data-part="' . $side . '"></label>';
                };

                return '<div class="mo-ee-dimension">' . $input('top', __('Above', 'mailoptin')) . $input('bottom', __('Below', 'mailoptin')) . '</div>'
                       . '<details class="mo-ee-sides"><summary>' . esc_html__('Sides', 'mailoptin') . '</summary><div class="mo-ee-dimension">'
                       . $input('left', __('Left', 'mailoptin')) . $input('right', __('Right', 'mailoptin')) . '</div></details>';

            case 'select_image':
                return '<div class="mo-ee-image is-empty">'
                       . '<div class="mo-ee-image-preview"><img alt=""><span class="mo-ee-image-none">' . esc_html__('No image yet', 'mailoptin') . '</span></div>'
                       . '<div class="mo-ee-image-actions"><button type="button" class="button mo-ee-pick-image-btn">' . esc_html__('Choose image', 'mailoptin') . '</button>'
                       . '<button type="button" class="button-link mo-ee-image-remove">' . esc_html__('Remove', 'mailoptin') . '</button></div>'
                       . '<details class="mo-ee-image-url"><summary>' . esc_html__('Use a URL', 'mailoptin') . '</summary><input type="url" class="mo-ee-input" data-name="' . $name . '" placeholder="https://"></details>'
                       . '</div>';

            case 'font_family':
                $choices = ! empty($field['choices']) ? $field['choices'] : SettingsFields::font_choices();
                if (isset($choices[''])) $choices[''] = __('Same as the email', 'mailoptin');

                return '<select class="mo-ee-input" data-name="' . $name . '">' . self::options_html($choices) . '</select>';

            case 'select':
                if ( ! empty($field['search']) || ! empty($field['multiple'])) return null;

                $choices = (array)($field['choices'] ?? []);
                $flat    = array_filter($choices, 'is_scalar');
                if (count($flat) !== count($choices) || count($flat) < 2 || count($flat) > 4) return null;

                // Alignment as icons; other short lists as buttons side by side.
                $align = ! array_diff(array_map('strval', array_keys($flat)), ['left', 'center', 'right']);
                foreach ($flat as $label) if ( ! $align && mb_strlen((string)$label) > 24) return null;
                if ($align) $flat = array_intersect_key(['left' => $flat['left'] ?? '', 'center' => $flat['center'] ?? '', 'right' => $flat['right'] ?? ''], $flat);

                $buttons = '';
                foreach ($flat as $value => $label) {
                    $inner = $align ? self::align_icon((string)$value) . '<span class="screen-reader-text">' . esc_html($label) . '</span>' : esc_html($label);
                    $buttons .= '<button type="button" class="mo-ee-seg-btn" data-value="' . esc_attr($value) . '" aria-pressed="false">' . $inner . '</button>';
                }

                return '<select class="mo-ee-input mo-ee-seg-source" data-name="' . $name . '" hidden aria-hidden="true" tabindex="-1">' . self::options_html($flat) . '</select>'
                       . '<div class="mo-ee-seg' . ($align ? ' mo-ee-seg-icons' : '') . ' mo-ee-seg-' . count($flat) . '" role="group" aria-label="' . esc_attr($field['label'] ?? '') . '">' . $buttons . '</div>';
        }

        return null;
    }

    protected static function align_icon($side)
    {
        $paths = ['left' => 'M4 6h16M4 12h10M4 18h14', 'center' => 'M4 6h16M7 12h10M5 18h14', 'right' => 'M4 6h16M10 12h10M6 18h14'];

        return '<svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="' . esc_attr($paths[$side] ?? $paths['left']) . '"/></svg>';
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
     * How a block's fields are shown in the editor, where that differs from the field itself: which
     * panel and section, a plainer label or description. Keyed by field name.
     */
    protected static function field_presentation()
    {
        return apply_filters('mailoptin_email_block_field_presentation', [
            'image_url'      => ['label' => __('Image', 'mailoptin')],
            'button_link'    => ['label' => __('Link', 'mailoptin')],
            'image_alt_text' => ['panel' => 'content', 'label' => __('Alt text', 'mailoptin'), 'description' => __('Read out to people who can\'t see images, and shown when images are blocked.', 'mailoptin')],
            'block_padding'  => ['label' => __('Around the block', 'mailoptin')],
            'button_padding' => ['label' => __('Inside the button', 'mailoptin')],
            'button_border_radius' => ['label' => __('Rounded corners', 'mailoptin'), 'unit' => 'px'],
            'block_background_color' => ['label' => __('Block background', 'mailoptin')],
        ]);
    }

    /** The Style panel's sections, in order. */
    protected static function style_sections()
    {
        return [
            ''        => '',
            'colours' => __('Colours', 'mailoptin'),
            'text'    => __('Text', 'mailoptin'),
            'shape'   => __('Shape and place', 'mailoptin'),
            'spacing' => __('Spacing', 'mailoptin'),
        ];
    }

    /** Where a field sits in the Style panel: its own 'section', else by what it sets. */
    protected static function section_for($name, $field)
    {
        if (isset($field['section'])) return (string)$field['section'];

        $type = $field['type'] ?? 'text';
        if ('color_picker' === $type) return 'colours';
        if ('dimension' === $type) return 'spacing';
        if ('font_family' === $type || preg_match('/font_(size|weight)|line_height/', $name)) return 'text';
        if (preg_match('/align|width|height|radius|_style$|valign/', $name)) return 'shape';

        return '';
    }

    /**
     * A block's settings form: Content and Style (what used to be "Block Settings", the spacing and
     * background around the block, is part of Style). The Style panel is in sections, and ends with
     * a way back to the template's look.
     */
    public static function block_form_html(AbstractBlock $block)
    {
        $tabs   = $block->tabs();
        $shown  = self::field_presentation();
        $panels = ['content' => [], 'style' => []];

        foreach ($block->fields() as $name => $field) {
            $field = array_replace($field, $shown[$name] ?? []);
            $tab   = (string)($field['tab'] ?? '');

            if (isset($field['panel'])) $panel = 'style' === $field['panel'] ? 'style' : 'content';
            // No tabs: what isn't plainly a look (colours, text, shape, spacing) is content, so
            // "Reset to the template's style" never takes a block's own words, links or layout.
            elseif ( ! $tabs) $panel = '' === self::section_for($name, $field) ? 'content' : 'style';
            else $panel = false !== strpos($tab, 'content') || ('' === $tab && false !== strpos((string)array_key_first($tabs), 'content')) ? 'content' : 'style';

            $panels[$panel][$name] = $field;
        }

        $html = '';
        if ($panels['content'] && $panels['style']) {
            $html .= '<div class="mo-ee-subtabs" role="tablist">'
                     . '<button type="button" class="mo-ee-subtab" role="tab" data-tab="content">' . esc_html__('Content', 'mailoptin') . '</button>'
                     . '<button type="button" class="mo-ee-subtab" role="tab" data-tab="style">' . esc_html__('Style', 'mailoptin') . '</button>'
                     . '</div>';
        }

        if ($panels['content']) {
            $html .= '<div class="mo-ee-subpanel" data-tab="content">';
            foreach ($panels['content'] as $name => $field) $html .= self::field_html($name, $field, true);
            $html .= '</div>';
        }

        if ($panels['style']) {
            $sections = array_fill_keys(array_keys(self::style_sections()), '');
            foreach ($panels['style'] as $name => $field) {
                $section = self::section_for($name, $field);
                if ( ! isset($sections[$section])) $section = '';
                $sections[$section] .= self::field_html($name, $field, true);
            }

            $html .= '<div class="mo-ee-subpanel" data-tab="style">';
            foreach (self::style_sections() as $id => $label) {
                if ('' === $sections[$id]) continue;
                $html .= '<div class="mo-ee-section-group" data-section="' . esc_attr($id) . '">' . ('' !== $label ? '<h4 class="mo-ee-section-h">' . esc_html($label) . '</h4>' : '') . $sections[$id] . '</div>';
            }
            $html .= '<button type="button" class="button-link mo-ee-reset-style">' . esc_html__('Reset to the template\'s style', 'mailoptin') . '</button>';
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

    /** A posted value as text: '' when it isn't one (a list posted where text belongs). */
    protected static function posted($key, $default = '')
    {
        $value = $_POST[$key] ?? $default;

        return is_scalar($value) ? (string)wp_unslash($value) : '';
    }

    protected static function posted_state()
    {
        $state = json_decode(self::posted('state'), true);

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

        $clean = self::clean_state($state, $email_campaign_id);
        foreach ($clean as $key => $value) {
            Settings::set_preview(Settings::TYPE_EMAIL, $email_campaign_id, $key, $value);
        }

        if ($template) Templates::set_preview($email_campaign_id, $template);

        $defaults = null;
        try {
            $html = (new Templatify($email_campaign_id))->forge();
            // Checked as drawn for someone (sample cart and products in), so the size is what's sent;
            // drawn while the on-screen settings and template apply (the cart's text colour, its font).
            $shown = self::with_samples($email_campaign_id, $html);
            // Asked for when the editor hasn't the blocks' defaults in this template yet (another
            // template chosen): new blocks and "Reset" take its colours and fonts, not the old one's.
            if ( ! empty($_POST['defaults']) && ! self::is_code($email_campaign_id)) {
                \MailOptin\Core\EmailBlocks\Builtin\ElementBlock::flush_defaults();
                $defaults = [];
                // An object: no defaults is {} for the editor, not [] (a list it would save settings into).
                foreach (Blocks::get_instance()->available_for($email_campaign_id) as $type => $block) $defaults[$type] = (object)$block->defaults($email_campaign_id);
            }
        } finally {
            Settings::clear_preview();
            Templates::clear_preview();
        }

        $data = ['html' => $shown, 'checks' => self::checks($email_campaign_id, self::checked_state($state, $clean), $shown)];
        if (null !== $defaults) $data['blocks'] = $defaults;
        // The switched design values, for the Design tab to show.
        if ($switch && is_array($state['design'] ?? null)) {
            $data['design']          = $state['design'];
            $data['design_template'] = $template;
        }

        wp_send_json_success($data);
    }

    /**
     * What the checks look at: the posted state as it would be saved (cleaned), so a block posted
     * without settings, or a setting of the wrong shape, can't trip them.
     */
    protected static function checked_state($state, $clean)
    {
        $content = json_decode((string)($clean['email_newsletter_content'] ?? ''), true);
        $checked = ['content' => is_array($content) ? $content : [], 'settings' => array_intersect_key($clean, self::settings_fields())];
        if (array_key_exists('code_your_own', $clean)) $checked['code'] = (string)$clean['code_your_own'];

        return $checked;
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
        if (empty($_POST['force']) && self::posted('version') !== self::version($email_campaign_id)) {
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
     * Posts or products for a searched list (the Posts block's posts, the Products block's products):
     * matching a search, or the titles of ids already chosen.
     */
    public function ajax_search()
    {
        self::authorize();

        $kind      = 'products' === ($_POST['kind'] ?? '') ? 'products' : 'posts';
        $post_type = 'products' === $kind ? 'product' : sanitize_key(self::posted('post_type', 'post'));
        $object    = get_post_type_object($post_type);

        // Only types that are public and that this person may read.
        if ( ! $object || ! $object->public || ! current_user_can($object->cap->read)) wp_send_json_success([]);

        // Published and not behind a password: what an email may show to everyone.
        $args = ['post_type' => $post_type, 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 20, 'no_found_rows' => true, 'suppress_filters' => false];
        $ids  = array_values(array_filter(array_map('absint', explode(',', self::posted('ids')))));

        if ($ids) {
            $args['post__in']       = array_slice($ids, 0, 100);
            $args['posts_per_page'] = count($args['post__in']);
            $args['orderby']        = 'post__in';
        } else {
            $args['s'] = sanitize_text_field(self::posted('term'));
            if (mb_strlen($args['s']) < 2) wp_send_json_success([]);
        }

        $found = [];
        foreach (get_posts($args) as $post) {
            $found[] = ['id' => (string)$post->ID, 'text' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8') ?: '#' . $post->ID];
        }

        wp_send_json_success($found);
    }

    /**
     * Send the saved email as a test.
     */
    public function ajax_test()
    {
        $email_campaign_id = self::authorize();
        $typed             = trim(self::posted('email'));
        // Left empty: to this person. Mistyped: an error, not a test quietly sent to someone else.
        $to                = '' !== $typed ? sanitize_email($typed) : wp_get_current_user()->user_email;

        if ( ! is_email($to)) wp_send_json_error(['message' => __('Enter a valid email address.', 'mailoptin')]);

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
