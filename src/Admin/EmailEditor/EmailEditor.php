<?php

namespace MailOptin\Core\Admin\EmailEditor;

use MailOptin\Core\Automations\Engine;
use MailOptin\Core\Automations\Triggers;
use MailOptin\Core\EmailBlocks\AbstractBlock;
use MailOptin\Core\EmailBlocks\Blocks;
use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts;
use MailOptin\Core\EmailCampaigns\Newsletter\Templatify;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;
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
        return $email_campaign_id && ER::is_built_email($email_campaign_id) && ! ER::is_code_your_own_template($email_campaign_id)
               && ! \MailOptin\Core\EmailCampaigns\Newsletter\Broadcasts::is_done($email_campaign_id);
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
        if (ER::is_automation_email($email_campaign_id)) {
            $owner = Repo::email_owner($email_campaign_id);

            return $owner ? \MailOptin\Core\Admin\SettingsPage\Automations_Page::edit_url($owner[0]['id']) : \MailOptin\Core\Admin\SettingsPage\Automations_Page::url();
        }

        return \MailOptin\Core\Admin\SettingsPage\Broadcast_Page::url($email_campaign_id);
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
    public static function design_fields()
    {
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
        ]);
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
        $state   = ['content' => is_array($content) ? array_values($content) : [], 'design' => [], 'settings' => []];

        foreach (self::design_fields() as $key => $field) $state['design'][$key] = ER::get_merged_customizer_value($email_campaign_id, $key);
        foreach (self::settings_fields() as $key => $field) $state['settings'][$key] = (string)ER::get_merged_customizer_value($email_campaign_id, $key);

        return $state;
    }

    /**
     * A posted state, cleaned: blocks by their own fields, design and settings by theirs.
     *
     * @return array setting => value, ready to save
     */
    public static function clean_state($state)
    {
        $state  = is_array($state) ? $state : [];
        $values = ['email_newsletter_content' => wp_json_encode(Blocks::get_instance()->sanitize_content($state['content'] ?? []))];

        foreach (['design' => self::design_fields(), 'settings' => self::settings_fields()] as $group => $fields) {
            $posted = (array)($state[$group] ?? []);

            foreach ($fields as $key => $field) {
                if (array_key_exists($key, $posted)) $values[$key] = Fields::sanitize($field, $posted[$key]);
            }
        }

        return $values;
    }

    /**
     * What the saved email is now, to tell whether it changed since the editor loaded it. Only the
     * settings this editor shows count: a change made elsewhere (say, the broadcast's audience)
     * isn't a conflict.
     */
    public static function version($email_campaign_id)
    {
        $saved = (array)Settings::get_saved(Settings::TYPE_EMAIL, $email_campaign_id);
        $keys  = array_merge(['email_newsletter_content'], array_keys(self::design_fields()), array_keys(self::settings_fields()));

        return md5(wp_json_encode(array_intersect_key($saved, array_flip($keys))));
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

        $blocks = Blocks::get_instance()->available_for($email_campaign_id);

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

        foreach (self::clean_state(self::posted_state()) as $key => $value) {
            Settings::set_preview(Settings::TYPE_EMAIL, $email_campaign_id, $key, $value);
        }

        $html = (new Templatify($email_campaign_id))->forge();

        Settings::clear_preview();

        wp_send_json_success(['html' => self::with_samples($email_campaign_id, $html)]);
    }

    /**
     * An automation email's placeholders, filled with sample values for the preview.
     */
    protected static function with_samples($email_campaign_id, $html)
    {
        if ( ! ER::is_automation_email($email_campaign_id) || ! class_exists(Engine::class)) return $html;

        $owner   = Repo::email_owner($email_campaign_id);
        $trigger = $owner ? Triggers::get_instance()->get($owner[0]['trigger_id']) : null;

        if ( ! $trigger) return $html;

        $run = ['id' => 0, 'automation_id' => $owner[0]['id'], 'email' => '', 'subscriber_id' => 0, 'context' => [], 'started_at' => Repo::now()];

        $html = Engine::replace_placeholders($html, $trigger, $run, true, true);

        // Subscriber fields show their fallback ("Hi {{subscriber.first_name|there}}" reads "Hi there").
        return \MailOptin\Core\Audience\CampaignRenderer::replace_fallback_tags($html, ['id' => 0, 'email' => '', 'first_name' => '', 'last_name' => ''], true, []);
    }

    public function ajax_save()
    {
        $email_campaign_id = self::authorize();

        // Saved somewhere else since this editor loaded it: say so, unless told to save over it.
        if (empty($_POST['force']) && (string)($_POST['version'] ?? '') !== self::version($email_campaign_id)) {
            wp_send_json_error(['conflict' => true, 'state' => self::state($email_campaign_id), 'version' => self::version($email_campaign_id)], 409);
        }

        ER::update_settings_by_id($email_campaign_id, self::clean_state(self::posted_state()));

        wp_send_json_success(['version' => self::version($email_campaign_id), 'state' => self::state($email_campaign_id)]);
    }

    /**
     * Send the saved email as a test.
     */
    public function ajax_test()
    {
        $email_campaign_id = self::authorize();
        $to                = sanitize_email(wp_unslash($_POST['email'] ?? '')) ?: wp_get_current_user()->user_email;

        if (ER::is_automation_email($email_campaign_id)) {
            $owner = Repo::email_owner($email_campaign_id);
            $sent  = $owner && Engine::send_test($owner[0], $owner[1], $to);
        } else {
            $sent = Broadcasts::send_test($email_campaign_id, $to);
        }

        $sent ? wp_send_json_success() : wp_send_json_error();
    }

    public static function get_instance()
    {
        static $instance = null;

        if (null === $instance) $instance = new self();

        return $instance;
    }
}
