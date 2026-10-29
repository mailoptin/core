<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\OptinCampaignsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;

/**
 * Tags and custom fields on the Subscribers screens: their own tabs, the list's filters and bulk
 * actions, and the subscriber profile. Server-rendered and usable without JavaScript; small scripts
 * add the floating bulk bar, the tag picker, drag to reorder and the merge tag preview.
 */
class Subscribers_Tags_Fields
{
    const ERROR_TRANSIENT = 'mo_subscribers_error_';

    /* ---------------------------------------------------------------------------------------------
     * Shared helpers
     * ------------------------------------------------------------------------------------------ */

    public static function is_ready()
    {
        return Tags::is_ready();
    }

    /**
     * Keep an error message (and what was submitted) for the next page load.
     */
    public static function set_error($message, $submitted = [])
    {
        set_transient(self::ERROR_TRANSIENT . get_current_user_id(), ['message' => (string)$message, 'submitted' => $submitted], 5 * MINUTE_IN_SECONDS);
    }

    /**
     * @return array|null message, submitted; cleared once read
     */
    public static function take_error()
    {
        $key   = self::ERROR_TRANSIENT . get_current_user_id();
        $error = get_transient($key);

        if (false === $error) return null;

        delete_transient($key);

        return $error;
    }

    public static function tag_chip($name, $class = '')
    {
        return sprintf('<span class="mo-tag%s">%s</span>', $class ? ' ' . esc_attr($class) : '', esc_html($name));
    }

    /**
     * Built-in form integrations, for "Applied by" and "Forms" columns.
     *
     * @return array list of [optin_campaign_id, form name, integration settings, custom field mappings for it]
     */
    protected static function builtin_integrations()
    {
        static $cache = null;

        if (null !== $cache) return $cache;

        $cache = [];

        foreach (OptinCampaignsRepository::get_optin_campaign_ids() as $optin_campaign_id) {
            $integrations = json_decode((string)OptinCampaignsRepository::get_customizer_value($optin_campaign_id, 'integrations', ''), true);

            if ( ! is_array($integrations)) continue;

            $mappings = json_decode((string)OptinCampaignsRepository::get_customizer_value($optin_campaign_id, 'custom_field_mappings', ''), true);

            foreach ($integrations as $index => $integration) {
                if (($integration['connection_service'] ?? '') !== 'MailOptinConnect') continue;

                $cache[] = [
                    (int)$optin_campaign_id,
                    OptinCampaignsRepository::get_optin_campaign_name($optin_campaign_id),
                    $integration,
                    is_array($mappings) && is_array($mappings[$index] ?? null) ? $mappings[$index] : [],
                ];
            }
        }

        return $cache;
    }

    /**
     * @return array tag_id => form names that apply it
     */
    public static function forms_per_tag()
    {
        $forms = [];

        foreach (self::builtin_integrations() as list($optin_campaign_id, $name, $integration)) {
            foreach ((array)($integration['MailOptinConnect_lead_tags'] ?? []) as $tag_id) {
                $forms[absint($tag_id)][$optin_campaign_id] = $name;
            }
        }

        return $forms;
    }

    /**
     * @return array merge_key => number of forms that map a field to it
     */
    public static function forms_per_field()
    {
        $forms = [];

        foreach (self::builtin_integrations() as list($optin_campaign_id, , , $mappings)) {
            foreach ($mappings as $merge_key => $form_field) {
                if ('' !== (string)$form_field) $forms[$merge_key][$optin_campaign_id] = true;
            }
        }

        return array_map('count', $forms);
    }

    /* ---------------------------------------------------------------------------------------------
     * Tags tab
     * ------------------------------------------------------------------------------------------ */

    public static function tags_html()
    {
        $search  = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $tags    = Tags::all($search);
        $forms   = self::forms_per_tag();
        $editing = absint($_GET['rename'] ?? 0);
        $merging = absint($_GET['merge'] ?? 0);
        $names   = Tags::names();
        $url     = Subscribers_Page::page_url('tags');

        ob_start();
        ?>
        <p class="mo-intro"><?php esc_html_e('Tags are your own labels. Subscribers never see them, so use them freely: where someone came from, what they bought, what they\'re interested in. Filter by them, apply them from a form, or add them in bulk from the subscribers list.', 'mailoptin'); ?></p>
        <div class="mo-toolbar">
            <form method="post" action="<?php echo esc_url($url); ?>" class="mo-inline-form">
                <?php wp_nonce_field('mo_add_tag'); ?>
                <input type="hidden" name="mo_subscriber_action" value="add_tag">
                <label class="screen-reader-text" for="mo-new-tag"><?php esc_html_e('New tag', 'mailoptin'); ?></label>
                <input type="text" id="mo-new-tag" name="name" maxlength="<?php echo Tags::MAX_LENGTH; ?>" placeholder="<?php esc_attr_e('New tag name', 'mailoptin'); ?>" required>
                <?php submit_button(__('Add tag', 'mailoptin'), 'primary', 'submit', false); ?>
            </form>
            <form method="get" class="mo-inline-form mo-toolbar-end">
                <input type="hidden" name="page" value="<?php echo esc_attr(Subscribers_Page::SLUG); ?>">
                <input type="hidden" name="view" value="tags">
                <label class="screen-reader-text" for="mo-tag-search"><?php esc_html_e('Search tags', 'mailoptin'); ?></label>
                <input type="search" id="mo-tag-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search tags', 'mailoptin'); ?>">
                <?php submit_button(__('Search', 'mailoptin'), '', '', false); ?>
            </form>
        </div>
        <table class="wp-list-table widefat striped mo-tags-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Tag', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Subscribers', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Applied by', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Created', 'mailoptin'); ?></th>
                <th class="mo-actions-col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'mailoptin'); ?></span></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($tags)) : ?>
                <tr><td colspan="5"><?php echo '' !== $search ? esc_html__('No tags match your search.', 'mailoptin') : esc_html__('No tags yet. Add one above, or type a new tag when tagging subscribers.', 'mailoptin'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($tags as $tag) :
                $tag_id = (int)$tag['id'];
                $applied = $forms[$tag_id] ?? [];
                $delete_url = wp_nonce_url(add_query_arg(['mo_subscriber_action' => 'delete_tag', 'tag' => $tag_id], $url), 'mo_delete_tag_' . $tag_id);
                ?>
                <tr id="mo-tag-<?php echo $tag_id; ?>">
                    <td>
                        <?php if ($editing === $tag_id) : ?>
                            <form method="post" action="<?php echo esc_url($url); ?>" class="mo-inline-form">
                                <?php wp_nonce_field('mo_rename_tag_' . $tag_id); ?>
                                <input type="hidden" name="mo_subscriber_action" value="rename_tag">
                                <input type="hidden" name="tag" value="<?php echo $tag_id; ?>">
                                <label class="screen-reader-text" for="mo-rename-<?php echo $tag_id; ?>"><?php esc_html_e('Tag name', 'mailoptin'); ?></label>
                                <input type="text" id="mo-rename-<?php echo $tag_id; ?>" name="name" value="<?php echo esc_attr($tag['name']); ?>" maxlength="<?php echo Tags::MAX_LENGTH; ?>" required autofocus>
                                <?php submit_button(__('Save', 'mailoptin'), 'primary small', 'submit', false); ?>
                                <a href="<?php echo esc_url($url); ?>"><?php esc_html_e('Cancel', 'mailoptin'); ?></a>
                            </form>
                        <?php else : ?>
                            <?php echo self::tag_chip($tag['name']); ?>
                        <?php endif; ?>
                    </td>
                    <td><a href="<?php echo esc_url(add_query_arg(['tags' => [$tag_id]], Subscribers_Page::page_url())); ?>"><?php echo esc_html(number_format_i18n($tag['subscribers'])); ?></a></td>
                    <td class="mo-muted"><?php echo empty($applied) ? '&mdash;' : esc_html(count($applied) > 2 ? sprintf(_n('%s form', '%s forms', count($applied), 'mailoptin'), number_format_i18n(count($applied))) : implode(', ', $applied)); ?></td>
                    <td class="mo-muted"><?php echo esc_html(get_date_from_gmt($tag['created_at'], get_option('date_format'))); ?></td>
                    <td class="mo-actions-col">
                        <?php if ($merging === $tag_id && count($names) > 1) : ?>
                            <form method="post" action="<?php echo esc_url($url); ?>" class="mo-inline-form">
                                <?php wp_nonce_field('mo_merge_tag_' . $tag_id); ?>
                                <input type="hidden" name="mo_subscriber_action" value="merge_tag">
                                <input type="hidden" name="tag" value="<?php echo $tag_id; ?>">
                                <label for="mo-merge-<?php echo $tag_id; ?>"><?php esc_html_e('Merge into', 'mailoptin'); ?></label>
                                <select id="mo-merge-<?php echo $tag_id; ?>" name="into" required>
                                    <option value=""><?php esc_html_e('Choose a tag…', 'mailoptin'); ?></option>
                                    <?php foreach ($names as $id => $name) : if ((int)$id === $tag_id) continue; ?>
                                        <option value="<?php echo absint($id); ?>"><?php echo esc_html($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php submit_button(__('Merge', 'mailoptin'), 'small', 'submit', false); ?>
                                <a href="<?php echo esc_url($url); ?>"><?php esc_html_e('Cancel', 'mailoptin'); ?></a>
                            </form>
                        <?php else : ?>
                            <a class="button button-small" href="<?php echo esc_url(add_query_arg('rename', $tag_id, $url) . '#mo-tag-' . $tag_id); ?>"><?php esc_html_e('Rename', 'mailoptin'); ?></a>
                            <?php if (count($names) > 1) : ?>
                                <a class="button button-small" href="<?php echo esc_url(add_query_arg('merge', $tag_id, $url) . '#mo-tag-' . $tag_id); ?>"><?php esc_html_e('Merge into…', 'mailoptin'); ?></a>
                            <?php endif; ?>
                            <a class="button button-small mo-button-danger mo-confirm" data-confirm="<?php echo esc_attr(sprintf(_n('Delete the tag "%1$s"? It is removed from %2$s subscriber. The subscribers are kept.', 'Delete the tag "%1$s"? It is removed from %2$s subscribers. The subscribers are kept.', (int)$tag['subscribers'], 'mailoptin'), $tag['name'], number_format_i18n($tag['subscribers']))); ?>" href="<?php echo esc_url($delete_url); ?>"><?php esc_html_e('Delete', 'mailoptin'); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description"><?php printf(esc_html(_n('%s tag', '%s tags', count($tags), 'mailoptin')), esc_html(number_format_i18n(count($tags)))); ?></p>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------------------------------
     * Custom fields tab
     * ------------------------------------------------------------------------------------------ */

    public static function fields_html()
    {
        $fields = Fields::all();
        $types  = Fields::types();
        $forms  = self::forms_per_field();
        $counts = Fields::value_counts();
        $url    = Subscribers_Page::page_url('fields');
        $panel  = isset($_GET['field']) ? ('new' === $_GET['field'] ? 'new' : Fields::get(absint($_GET['field']))) : null;

        ob_start();
        ?>
        <p class="mo-intro"><?php esc_html_e('Extra details you keep for each subscriber, shared by all lists. Map form fields to them, filter by them, and use their merge tag in any email. Email, name and lists are built in.', 'mailoptin'); ?></p>
        <p><a class="button button-primary" href="<?php echo esc_url(add_query_arg('field', 'new', $url)); ?>"><?php esc_html_e('Add field', 'mailoptin'); ?></a></p>
        <form method="post" action="<?php echo esc_url($url); ?>" id="mo-reorder-fields">
            <?php wp_nonce_field('mo_reorder_fields'); ?>
            <input type="hidden" name="mo_subscriber_action" value="reorder_fields">
            <input type="hidden" name="order" value="">
        </form>
        <table class="wp-list-table widefat striped mo-fields-table">
            <thead>
            <tr>
                <th class="mo-handle-col"><span class="screen-reader-text"><?php esc_html_e('Order', 'mailoptin'); ?></span></th>
                <th><?php esc_html_e('Field', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Merge tag', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Type', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Options', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Subscribers with a value', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Subscribers can edit', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Forms', 'mailoptin'); ?></th>
                <th class="mo-actions-col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'mailoptin'); ?></span></th>
            </tr>
            </thead>
            <tbody class="mo-sortable">
            <?php if (empty($fields)) : ?>
                <tr><td colspan="9"><?php esc_html_e('No custom fields yet. Add one for anything you want to know about subscribers, like their company or birthday.', 'mailoptin'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($fields as $i => $field) :
                $move = function ($dir) use ($field, $url) {
                    return wp_nonce_url(add_query_arg(['mo_subscriber_action' => 'move_field', 'field_id' => $field['id'], 'dir' => $dir], $url), 'mo_move_field_' . $field['id']);
                };
                ?>
                <tr data-id="<?php echo absint($field['id']); ?>">
                    <td class="mo-handle-col">
                        <span class="mo-handle" aria-hidden="true" title="<?php esc_attr_e('Drag to reorder', 'mailoptin'); ?>">&#8942;&#8942;</span>
                        <span class="mo-move">
                            <?php if ($i > 0) : ?><a href="<?php echo esc_url($move('up')); ?>" aria-label="<?php echo esc_attr(sprintf(__('Move %s up', 'mailoptin'), $field['label'])); ?>">&uarr;</a><?php endif; ?>
                            <?php if ($i < count($fields) - 1) : ?><a href="<?php echo esc_url($move('down')); ?>" aria-label="<?php echo esc_attr(sprintf(__('Move %s down', 'mailoptin'), $field['label'])); ?>">&darr;</a><?php endif; ?>
                        </span>
                    </td>
                    <td><strong><a class="row-title" href="<?php echo esc_url(add_query_arg('field', $field['id'], $url)); ?>"><?php echo esc_html($field['label']); ?></a></strong></td>
                    <td><code>{{subscriber.<?php echo esc_html($field['merge_key']); ?>}}</code></td>
                    <td><?php echo esc_html($types[$field['type']] ?? $field['type']); ?></td>
                    <td class="mo-muted"><?php echo empty($field['options']) ? '&mdash;' : esc_html(wp_trim_words(implode(', ', $field['options']), 8, ' …')); ?></td>
                    <td class="mo-muted"><?php echo esc_html(number_format_i18n($counts[$field['id']] ?? 0)); ?></td>
                    <td><?php echo $field['subscriber_editable'] ? esc_html__('Yes', 'mailoptin') : '<span class="mo-muted">' . esc_html__('No', 'mailoptin') . '</span>'; ?></td>
                    <td class="mo-muted"><?php echo esc_html(number_format_i18n($forms[$field['merge_key']] ?? 0)); ?></td>
                    <td class="mo-actions-col"><a class="button button-small" href="<?php echo esc_url(add_query_arg('field', $field['id'], $url)); ?>"><?php esc_html_e('Edit', 'mailoptin'); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (count($fields) > 1) : ?>
            <p class="description"><?php esc_html_e('Drag rows, or use the arrows, to change the order fields appear in on profiles and in forms\' field mapping.', 'mailoptin'); ?></p>
        <?php endif; ?>
        <?php
        if ($panel) echo self::field_panel_html('new' === $panel ? null : $panel, $counts);

        return ob_get_clean();
    }

    protected static function field_panel_html($field, $counts)
    {
        $url       = Subscribers_Page::page_url('fields');
        $error     = self::take_error();
        $submitted = $error['submitted'] ?? [];
        $is_new    = null === $field;
        $types     = Fields::types();
        $type      = $is_new ? sanitize_key($submitted['type'] ?? Fields::TYPE_TEXT) : $field['type'];
        $label     = $submitted['label'] ?? ($field['label'] ?? '');
        $key       = $submitted['merge_key'] ?? ($field['merge_key'] ?? '');
        $options   = $submitted['options'] ?? implode("\n", $field['options'] ?? []);
        $icons     = ['text' => 'Aa', 'number' => '#', 'date' => '31', 'dropdown' => '&#9662;', 'checkbox' => '&#10003;', 'multiselect' => '&#9776;', 'url' => '&#8599;', 'phone' => '&#9742;'];

        ob_start();
        ?>
        <a class="mo-panel-dim" href="<?php echo esc_url($url); ?>" aria-label="<?php esc_attr_e('Close', 'mailoptin'); ?>"></a>
        <aside class="mo-panel" role="dialog" aria-labelledby="mo-panel-title">
            <form method="post" action="<?php echo esc_url($url); ?>">
                <header>
                    <h2 id="mo-panel-title"><?php echo $is_new ? esc_html__('Add field', 'mailoptin') : esc_html(sprintf(__('Edit %s', 'mailoptin'), $field['label'])); ?></h2>
                    <a href="<?php echo esc_url($url); ?>" class="mo-panel-close" aria-label="<?php esc_attr_e('Close', 'mailoptin'); ?>">&times;</a>
                </header>
                <div class="mo-panel-body">
                    <?php if ($error) : ?>
                        <div class="notice notice-error inline"><p><?php echo esc_html($error['message']); ?></p></div>
                    <?php endif; ?>
                    <?php wp_nonce_field('mo_save_field'); ?>
                    <input type="hidden" name="mo_subscriber_action" value="save_field">
                    <input type="hidden" name="field_id" value="<?php echo absint($field['id'] ?? 0); ?>">

                    <fieldset>
                        <legend><?php esc_html_e('Type', 'mailoptin'); ?></legend>
                        <?php if ($is_new) : ?>
                            <div class="mo-types">
                                <?php foreach ($types as $value => $type_label) : ?>
                                    <label><input type="radio" name="type" value="<?php echo esc_attr($value); ?>" <?php checked($type, $value); ?>><span><b><?php echo $icons[$value]; ?></b><?php echo esc_html($type_label); ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <p><?php echo esc_html($types[$type] ?? $type); ?> <span class="mo-muted"><?php esc_html_e('(can\'t be changed: stored values depend on it)', 'mailoptin'); ?></span></p>
                        <?php endif; ?>
                    </fieldset>

                    <p>
                        <label for="mo-field-label"><strong><?php esc_html_e('Label', 'mailoptin'); ?></strong></label><br>
                        <input class="widefat" type="text" id="mo-field-label" name="label" value="<?php echo esc_attr($label); ?>" maxlength="200" required>
                    </p>

                    <p>
                        <label for="mo-field-key"><strong><?php esc_html_e('Merge tag', 'mailoptin'); ?></strong></label><br>
                        <?php if ($is_new) : ?>
                            <span class="mo-key-input"><code>{{subscriber.</code><input type="text" id="mo-field-key" name="merge_key" value="<?php echo esc_attr($key); ?>" maxlength="<?php echo Fields::MAX_KEY_LENGTH; ?>" pattern="[a-z][a-z0-9_]*" placeholder="<?php esc_attr_e('made from the label', 'mailoptin'); ?>"><code>}}</code></span>
                            <span class="description"><?php esc_html_e('Lowercase letters, numbers and underscores. Leave it empty to make it from the label. It can\'t change later, because emails and forms use it.', 'mailoptin'); ?></span>
                        <?php else : ?>
                            <code>{{subscriber.<?php echo esc_html($field['merge_key']); ?>}}</code><br>
                            <span class="description"><?php esc_html_e('Use it in any email. Add a fallback for subscribers without a value:', 'mailoptin'); ?> <code>{{subscriber.<?php echo esc_html($field['merge_key']); ?>|<?php esc_html_e('fallback', 'mailoptin'); ?>}}</code></span>
                        <?php endif; ?>
                    </p>

                    <p class="mo-options-row" <?php echo Fields::has_options($type) || $is_new ? '' : 'hidden'; ?>>
                        <label for="mo-field-options"><strong><?php esc_html_e('Options', 'mailoptin'); ?></strong></label><br>
                        <textarea class="widefat" id="mo-field-options" name="options" rows="6"><?php echo esc_textarea($options); ?></textarea>
                        <span class="description"><?php esc_html_e('For dropdown and multi-select: one option per line, in the order to show them.', 'mailoptin'); ?></span>
                    </p>

                    <p>
                        <input type="hidden" name="subscriber_editable" value="0">
                        <label class="mo-check"><input type="checkbox" name="subscriber_editable" value="1" <?php checked( ! empty($submitted) ? ! empty($submitted['subscriber_editable']) : ! empty($field['subscriber_editable'])); ?>> <?php esc_html_e('Subscribers can change it in the preference centre', 'mailoptin'); ?></label>
                        <span class="description"><?php esc_html_e('Off: only you can see and change it.', 'mailoptin'); ?></span>
                    </p>

                    <?php if ( ! $is_new) : $with_value = $counts[$field['id']] ?? 0; ?>
                        <p class="mo-panel-danger">
                            <a class="button-link mo-button-danger mo-confirm" data-confirm="<?php echo esc_attr(sprintf(_n('Delete "%1$s"? %2$s subscriber has a value for it, which will be deleted too. Emails that use its merge tag will show their fallback instead.', 'Delete "%1$s"? %2$s subscribers have a value for it, which will be deleted too. Emails that use its merge tag will show their fallback instead.', $with_value, 'mailoptin'), $field['label'], number_format_i18n($with_value))); ?>"
                               href="<?php echo esc_url(wp_nonce_url(add_query_arg(['mo_subscriber_action' => 'delete_field', 'field_id' => $field['id']], $url), 'mo_delete_field_' . $field['id'])); ?>"><?php esc_html_e('Delete field', 'mailoptin'); ?></a>
                        </p>
                    <?php endif; ?>
                </div>
                <footer>
                    <a class="button" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Cancel', 'mailoptin'); ?></a>
                    <?php submit_button($is_new ? __('Add field', 'mailoptin') : __('Save field', 'mailoptin'), 'primary', 'submit', false); ?>
                </footer>
            </form>
        </aside>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------------------------------
     * Subscribers list: filters and bulk actions
     * ------------------------------------------------------------------------------------------ */

    /**
     * Active filter chips and the "Add filter" control, above the subscribers table.
     */
    public static function filter_bar_html($filters)
    {
        $tags   = Tags::names();
        $fields = Fields::all();

        if (empty($tags) && empty($fields)) return '';

        $base   = remove_query_arg(['tags', 'tag_match', 'f', 'paged', 'mo_notice', 'mo_add_filter']);
        $adding = sanitize_text_field($_GET['mo_add_filter'] ?? '');

        ob_start();
        ?>
        <div class="mo-filter-bar">
            <?php echo Subscriber_Filters::chips_html($filters, $base); ?>
            <details class="mo-add-filter" <?php echo '' !== $adding ? 'open' : ''; ?>>
                <summary><?php esc_html_e('+ Add filter', 'mailoptin'); ?></summary>
                <div class="mo-add-filter-body">
                    <?php if ('' === $adding) : ?>
                        <form method="get">
                            <?php echo self::preserved_inputs($filters); ?>
                            <label for="mo-add-filter-what"><?php esc_html_e('Filter by', 'mailoptin'); ?></label>
                            <select id="mo-add-filter-what" name="mo_add_filter" class="mo-autosubmit" required>
                                <option value=""><?php esc_html_e('Choose…', 'mailoptin'); ?></option>
                                <?php if ( ! empty($tags)) : ?><option value="tags"><?php esc_html_e('Tags', 'mailoptin'); ?></option><?php endif; ?>
                                <?php if ( ! empty($fields)) : ?>
                                    <optgroup label="<?php esc_attr_e('Custom fields', 'mailoptin'); ?>">
                                        <?php foreach ($fields as $field) : ?><option value="<?php echo absint($field['id']); ?>"><?php echo esc_html($field['label']); ?></option><?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                            <?php submit_button(__('Next', 'mailoptin'), 'small mo-no-js', '', false); ?>
                        </form>
                    <?php elseif ('tags' === $adding) : ?>
                        <form method="get">
                            <?php echo self::preserved_inputs($filters, true); ?>
                            <p>
                                <label for="mo-filter-tag-match"><?php esc_html_e('Subscribers with', 'mailoptin'); ?></label>
                                <select id="mo-filter-tag-match" name="tag_match">
                                    <option value="any" <?php selected($filters['tag_match'], 'any'); ?>><?php esc_html_e('any of these tags', 'mailoptin'); ?></option>
                                    <option value="all" <?php selected($filters['tag_match'], 'all'); ?>><?php esc_html_e('all of these tags', 'mailoptin'); ?></option>
                                    <option value="none" <?php selected($filters['tag_match'], 'none'); ?>><?php esc_html_e('none of these tags', 'mailoptin'); ?></option>
                                </select>
                            </p>
                            <div class="mo-check-grid">
                                <?php foreach ($tags as $id => $name) : ?>
                                    <label class="mo-check"><input type="checkbox" name="tags[]" value="<?php echo absint($id); ?>" <?php checked(in_array((int)$id, $filters['tag_ids'], true)); ?>> <?php echo esc_html($name); ?></label>
                                <?php endforeach; ?>
                            </div>
                            <?php submit_button(__('Apply filter', 'mailoptin'), 'primary small', '', false); ?>
                            <a href="<?php echo esc_url(remove_query_arg('mo_add_filter')); ?>"><?php esc_html_e('Cancel', 'mailoptin'); ?></a>
                        </form>
                    <?php elseif ($field = Fields::get(absint($adding))) : ?>
                        <form method="get" class="mo-field-filter" data-type="<?php echo esc_attr($field['type']); ?>">
                            <?php echo self::preserved_inputs($filters); ?>
                            <?php $i = count($filters['field_conditions']); ?>
                            <input type="hidden" name="f[<?php echo $i; ?>][field]" value="<?php echo absint($field['id']); ?>">
                            <p>
                                <strong><?php echo esc_html($field['label']); ?></strong>
                                <label class="screen-reader-text" for="mo-filter-op"><?php esc_html_e('Comparison', 'mailoptin'); ?></label>
                                <select id="mo-filter-op" name="f[<?php echo $i; ?>][op]">
                                    <?php foreach (Fields::operators($field['type']) as $op => $op_label) : ?>
                                        <option value="<?php echo esc_attr($op); ?>"><?php echo esc_html($op_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <?php echo self::filter_value_inputs($field, $i); ?>
                            <?php submit_button(__('Apply filter', 'mailoptin'), 'primary small', '', false); ?>
                            <a href="<?php echo esc_url(remove_query_arg('mo_add_filter')); ?>"><?php esc_html_e('Cancel', 'mailoptin'); ?></a>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
            <?php if (Subscribers_Segments::is_ready()) echo Subscribers_Segments::save_html($filters); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Inputs that keep the current view (status, list, search) and filters in a filter form.
     */
    protected static function preserved_inputs($filters, $skip_tags = false)
    {
        $html = sprintf('<input type="hidden" name="page" value="%s">', esc_attr(Subscribers_Page::SLUG));

        foreach (['status', 'list', 's', 'segment'] as $key) {
            if ( ! empty($_GET[$key])) {
                $html .= sprintf('<input type="hidden" name="%s" value="%s">', esc_attr($key), esc_attr(sanitize_text_field(wp_unslash($_GET[$key]))));
            }
        }

        return $html . Subscriber_Filters::hidden_inputs($filters, $skip_tags);
    }

    protected static function filter_value_inputs($field, $i)
    {
        $name = "f[$i]";

        switch ($field['type']) {
            case Fields::TYPE_CHECKBOX:
                return '';
            case Fields::TYPE_DROPDOWN:
            case Fields::TYPE_MULTISELECT:
                $html = '<div class="mo-check-grid mo-filter-value">';
                foreach ($field['options'] as $option) {
                    $html .= sprintf('<label class="mo-check"><input type="checkbox" name="%s[value][]" value="%s"> %s</label>', esc_attr($name), esc_attr($option), esc_html($option));
                }

                return $html . '</div>';
            case Fields::TYPE_NUMBER:
            case Fields::TYPE_DATE:
                $type = Fields::TYPE_NUMBER === $field['type'] ? 'number" step="any' : 'date';

                return sprintf(
                    '<p class="mo-filter-value"><label class="screen-reader-text" for="mo-filter-value">%1$s</label><input type="%2$s" id="mo-filter-value" name="%3$s[value]"> <span class="mo-between">%4$s <label class="screen-reader-text" for="mo-filter-value2">%5$s</label><input type="%2$s" id="mo-filter-value2" name="%3$s[value2]"> <span class="description">%6$s</span></span></p>',
                    esc_html__('Value', 'mailoptin'),
                    $type,
                    esc_attr($name),
                    esc_html__('and', 'mailoptin'),
                    esc_html__('End of range', 'mailoptin'),
                    esc_html__('(only for "is between")', 'mailoptin')
                );
            default:
                return sprintf(
                    '<p class="mo-filter-value"><label class="screen-reader-text" for="mo-filter-value">%s</label><input type="text" id="mo-filter-value" name="%s[value]"></p>',
                    esc_html__('Value', 'mailoptin'),
                    esc_attr($name)
                );
        }
    }

    /**
     * @return array bulk action => label
     */
    public static function bulk_actions()
    {
        return [
            'add_tags'    => __('Add tags', 'mailoptin'),
            'remove_tags' => __('Remove tags', 'mailoptin'),
            'set_field'   => __('Set a custom field', 'mailoptin'),
        ];
    }

    /**
     * Inputs for the tag and field bulk actions, in the table's top bar. The floating bulk bar fills
     * them in from its tag picker and field dialog.
     */
    public static function bulk_inputs_html()
    {
        $tags   = Tags::names();
        $fields = Fields::all();

        ob_start();
        ?>
        <div class="alignleft actions mo-bulk-inputs">
            <label class="screen-reader-text" for="mo-bulk-tags"><?php esc_html_e('Tags for "Add tags" and "Remove tags", separated by commas', 'mailoptin'); ?></label>
            <input type="text" id="mo-bulk-tags" name="mo_bulk_tags" list="mo-tag-names" placeholder="<?php esc_attr_e('Tags for bulk action, comma separated', 'mailoptin'); ?>">
            <datalist id="mo-tag-names">
                <?php foreach ($tags as $name) : ?><option value="<?php echo esc_attr($name); ?>"></option><?php endforeach; ?>
            </datalist>
            <?php if ( ! empty($fields)) : ?>
                <label class="screen-reader-text" for="mo-bulk-field"><?php esc_html_e('Field for "Set a custom field"', 'mailoptin'); ?></label>
                <select id="mo-bulk-field" name="mo_bulk_field">
                    <option value=""><?php esc_html_e('Field for bulk action…', 'mailoptin'); ?></option>
                    <?php foreach ($fields as $field) : ?>
                        <option value="<?php echo absint($field['id']); ?>" data-type="<?php echo esc_attr($field['type']); ?>" data-options="<?php echo esc_attr(wp_json_encode($field['options'])); ?>"><?php echo esc_html($field['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="screen-reader-text" for="mo-bulk-value"><?php esc_html_e('New value; leave empty to clear it', 'mailoptin'); ?></label>
                <input type="text" id="mo-bulk-value" name="mo_bulk_value" placeholder="<?php esc_attr_e('Value (empty clears it)', 'mailoptin'); ?>">
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * The floating bar shown while rows are ticked, and its dialogs. Only works with JavaScript; the
     * table's own bulk actions do the same without it.
     */
    public static function bulk_bar_html()
    {
        $tags   = Tags::all();
        $fields = Fields::all();

        ob_start();
        ?>
        <div class="mo-bulk-bar" hidden>
            <span class="mo-bulk-count"></span>
            <span class="mo-sep"></span>
            <button type="button" class="button" data-dialog="add_tags"><?php esc_html_e('Add tags', 'mailoptin'); ?></button>
            <button type="button" class="button" data-dialog="remove_tags"><?php esc_html_e('Remove tags', 'mailoptin'); ?></button>
            <button type="button" class="button" data-dialog="add_to_list"><?php esc_html_e('Add to list', 'mailoptin'); ?></button>
            <?php if ( ! empty($fields)) : ?><button type="button" class="button" data-dialog="set_field"><?php esc_html_e('Edit field…', 'mailoptin'); ?></button><?php endif; ?>
            <span class="mo-sep"></span>
            <button type="button" class="button mo-button-danger" data-action="delete"><?php esc_html_e('Delete', 'mailoptin'); ?></button>
            <button type="button" class="button mo-bulk-clear" aria-label="<?php esc_attr_e('Clear selection', 'mailoptin'); ?>">&times;</button>
        </div>

        <dialog class="mo-dialog" id="mo-tag-dialog">
            <form method="dialog">
                <h3 class="mo-dialog-title"></h3>
                <div class="mo-tag-input">
                    <span class="mo-tag-picked"></span>
                    <label class="screen-reader-text" for="mo-tag-search-input"><?php esc_html_e('Search or create a tag', 'mailoptin'); ?></label>
                    <input type="text" id="mo-tag-search-input" autocomplete="off" placeholder="<?php esc_attr_e('Type to search or create…', 'mailoptin'); ?>">
                </div>
                <ul class="mo-tag-options">
                    <?php foreach ($tags as $tag) : ?>
                        <li><button type="button" data-name="<?php echo esc_attr($tag['name']); ?>"><span><?php echo esc_html($tag['name']); ?></span><span class="mo-muted"><?php echo esc_html(number_format_i18n($tag['subscribers'])); ?></span></button></li>
                    <?php endforeach; ?>
                    <li class="mo-tag-create" hidden><button type="button"></button></li>
                </ul>
                <div class="mo-dialog-actions">
                    <button type="submit" value="cancel" class="button"><?php esc_html_e('Cancel', 'mailoptin'); ?></button>
                    <button type="submit" value="ok" class="button button-primary mo-dialog-ok"></button>
                </div>
            </form>
        </dialog>

        <dialog class="mo-dialog" id="mo-list-dialog">
            <form method="dialog">
                <h3 class="mo-dialog-title"></h3>
                <p><select class="widefat mo-dialog-list">
                        <?php foreach (\MailOptin\Core\Repositories\ListsRepository::get_all() as $list) : ?>
                            <option value="<?php echo absint($list['id']); ?>"><?php echo esc_html($list['name']); ?></option>
                        <?php endforeach; ?>
                    </select></p>
                <p class="description"><?php esc_html_e('People who unsubscribed from the list aren\'t added back.', 'mailoptin'); ?></p>
                <div class="mo-dialog-actions">
                    <button type="submit" value="cancel" class="button"><?php esc_html_e('Cancel', 'mailoptin'); ?></button>
                    <button type="submit" value="ok" class="button button-primary"><?php esc_html_e('Add to list', 'mailoptin'); ?></button>
                </div>
            </form>
        </dialog>

        <?php if ( ! empty($fields)) : ?>
            <dialog class="mo-dialog" id="mo-field-dialog">
                <form method="dialog">
                    <h3 class="mo-dialog-title"></h3>
                    <p><label for="mo-dialog-field"><?php esc_html_e('Field', 'mailoptin'); ?></label><br>
                        <select class="widefat" id="mo-dialog-field">
                            <?php foreach ($fields as $field) : ?>
                                <option value="<?php echo absint($field['id']); ?>"><?php echo esc_html($field['label']); ?></option>
                            <?php endforeach; ?>
                        </select></p>
                    <p><label for="mo-dialog-value"><?php esc_html_e('New value', 'mailoptin'); ?></label><br>
                        <span class="mo-dialog-value-wrap"></span>
                        <span class="description"><?php esc_html_e('Leave empty to clear the field for these subscribers.', 'mailoptin'); ?></span></p>
                    <div class="mo-dialog-actions">
                        <button type="submit" value="cancel" class="button"><?php esc_html_e('Cancel', 'mailoptin'); ?></button>
                        <button type="submit" value="ok" class="button button-primary"><?php esc_html_e('Save', 'mailoptin'); ?></button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * @return string|null notice key, or null if it isn't one of these actions
     */
    public static function handle_bulk($action, $ids, $request)
    {
        if ( ! isset(self::bulk_actions()[$action])) return null;

        if ('set_field' === $action) {
            $result = Fields::set_value_for_subscribers(absint($request['mo_bulk_field'] ?? 0), wp_unslash($request['mo_bulk_value'] ?? ''), $ids);

            if (is_wp_error($result)) {
                self::set_error('mo_field_not_found' === $result->get_error_code() ? __('Choose a field in "Field for bulk action" first.', 'mailoptin') : $result->get_error_message());

                return 'error';
            }

            $field = Fields::get(absint($request['mo_bulk_field']));

            Subscribers::log_events(Subscribers::EVENT_FIELDS_CHANGED, array_fill_keys($ids, ['fields' => [$field['label']], 'by_admin' => get_current_user_id()]));

            return 'bulk_done';
        }

        $names = array_filter(array_map('trim', explode(',', is_scalar($request['mo_bulk_tags'] ?? '') ? (string)wp_unslash($request['mo_bulk_tags'] ?? '') : '')), 'strlen');

        if (empty($names)) {
            self::set_error(__('Enter the tags to add or remove in "Tags for bulk action" first.', 'mailoptin'));

            return 'error';
        }

        if ('add_tags' === $action) {
            $changed = Tags::add_to_subscribers(Tags::ensure($names), $ids);
            $event   = Subscribers::EVENT_TAGS_ADDED;
        } else {
            // Removing never creates tags.
            $tag_ids = array_filter(array_map(function ($name) {
                return (int)(Tags::get_by_name($name)['id'] ?? 0);
            }, $names));
            $changed = Tags::remove_from_subscribers($tag_ids, $ids);
            $event   = Subscribers::EVENT_TAGS_REMOVED;
        }

        $all = Tags::names();

        Subscribers::log_events($event, array_map(function ($tag_ids) use ($all) {
            return ['tags' => array_values(array_intersect_key($all, array_flip($tag_ids))), 'by_admin' => get_current_user_id()];
        }, $changed));

        return 'bulk_done';
    }

    /* ---------------------------------------------------------------------------------------------
     * Profile
     * ------------------------------------------------------------------------------------------ */

    public static function profile_fields_html($subscriber_id)
    {
        $fields = Fields::all();

        ob_start();
        ?>
        <h3 class="mo-heading-with-link"><?php esc_html_e('Custom fields', 'mailoptin'); ?> <a href="<?php echo esc_url(Subscribers_Page::page_url('fields')); ?>"><?php esc_html_e('Manage fields', 'mailoptin'); ?></a></h3>
        <?php if (empty($fields)) : ?>
            <p class="description"><?php printf(esc_html__('No custom fields yet. %sAdd one%s to keep details like company or birthday.', 'mailoptin'), '<a href="' . esc_url(add_query_arg('field', 'new', Subscribers_Page::page_url('fields'))) . '">', '</a>'); ?></p>
        <?php else : ?>
            <div class="mo-field-grid">
                <?php
                $values = Fields::get_values($subscriber_id);
                foreach ($fields as $field) {
                    echo self::field_input_html($field, Fields::form_value($field, $values[$field['id']]['value_text'] ?? null));
                }
                ?>
            </div>
        <?php endif;

        return ob_get_clean();
    }

    protected static function field_input_html($field, $value)
    {
        $id   = 'mo-cf-' . $field['id'];
        $name = 'fields[' . $field['id'] . ']';

        $label = sprintf('<label for="%s">%s</label>', esc_attr($id), esc_html($field['label']));

        switch ($field['type']) {
            case Fields::TYPE_DROPDOWN:
                $input = sprintf('<select id="%s" name="%s"><option value="">%s</option>', esc_attr($id), esc_attr($name), esc_html__('—', 'mailoptin'));
                foreach ($field['options'] as $option) {
                    $input .= sprintf('<option value="%s" %s>%s</option>', esc_attr($option), selected($value, $option, false), esc_html($option));
                }
                // A value that is no longer one of the options is kept until changed.
                if ('' !== $value && ! in_array($value, $field['options'], true)) {
                    $input .= sprintf('<option value="%s" selected>%s</option>', esc_attr($value), esc_html($value));
                }
                $input .= '</select>';
                break;
            case Fields::TYPE_CHECKBOX:
                $label = sprintf('<span>%s</span>', esc_html($field['label']));
                // Unticked means "No" only once it has a value; otherwise saving the profile would set
                // every checkbox field, and form signups (which only fill empty fields) couldn't any more.
                $input = sprintf('<input type="hidden" name="%2$s" value="%5$s"><label class="mo-check"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>', esc_attr($id), esc_attr($name), checked('1', $value, false), esc_html__('Yes', 'mailoptin'), '' === $value ? '' : '0');
                break;
            case Fields::TYPE_MULTISELECT:
                $label = sprintf('<span>%s</span>', esc_html($field['label']));
                $input = sprintf('<input type="hidden" name="%s[]" value=""><div class="mo-check-grid">', esc_attr($name));
                foreach (array_unique(array_merge($field['options'], (array)$value)) as $option) {
                    $input .= sprintf('<label class="mo-check"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>', esc_attr($name), esc_attr($option), checked(in_array($option, (array)$value, true), true, false), esc_html($option));
                }
                $input .= '</div>';
                break;
            default:
                $types = [Fields::TYPE_NUMBER => 'number" step="any', Fields::TYPE_DATE => 'date', Fields::TYPE_URL => 'url', Fields::TYPE_PHONE => 'tel'];
                $input = sprintf('<input class="regular-text" type="%s" id="%s" name="%s" value="%s">', $types[$field['type']] ?? 'text', esc_attr($id), esc_attr($name), esc_attr($value));
        }

        return '<div class="mo-field-row">' . $label . '<div>' . $input . '</div></div>';
    }

    /**
     * Tags on the profile. The inputs belong to the profile's form, so one Save covers both.
     */
    public static function profile_tags_html($subscriber_id, $form_id)
    {
        $current = Tags::for_subscriber($subscriber_id);

        ob_start();
        ?>
        <div class="mo-card">
            <h3><?php esc_html_e('Tags', 'mailoptin'); ?></h3>
            <input type="hidden" name="tags_submitted" value="1" form="<?php echo esc_attr($form_id); ?>">
            <div class="mo-profile-tags">
                <?php foreach ($current as $id => $name) : ?>
                    <label class="mo-tag mo-tag-removable"><input type="checkbox" name="tag_ids[]" value="<?php echo absint($id); ?>" form="<?php echo esc_attr($form_id); ?>" checked> <?php echo esc_html($name); ?></label>
                <?php endforeach; ?>
                <?php if (empty($current)) : ?><span class="mo-muted"><?php esc_html_e('No tags yet.', 'mailoptin'); ?></span><?php endif; ?>
            </div>
            <p>
                <label for="mo-profile-new-tags"><?php esc_html_e('Add tags', 'mailoptin'); ?></label><br>
                <input type="text" class="widefat" id="mo-profile-new-tags" name="new_tags" list="mo-tag-names" form="<?php echo esc_attr($form_id); ?>" placeholder="<?php esc_attr_e('Comma separated; new tags are created', 'mailoptin'); ?>">
                <datalist id="mo-tag-names">
                    <?php foreach (array_diff(Tags::names(), $current) as $name) : ?><option value="<?php echo esc_attr($name); ?>"></option><?php endforeach; ?>
                </datalist>
            </p>
            <p class="description"><?php esc_html_e('Untick a tag to remove it. Changes are saved with "Save changes".', 'mailoptin'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Save custom fields and tags from the profile form, and add them to the timeline.
     *
     * @return string[] error messages for values that weren't saved
     */
    public static function save_profile($subscriber_id, $post)
    {
        $errors = [];
        $admin  = get_current_user_id();

        if (isset($post['fields']) && is_array($post['fields'])) {
            $submitted = [];
            foreach (wp_unslash($post['fields']) as $field_id => $value) {
                $submitted[absint($field_id)] = is_array($value) ? array_values(array_filter(array_map('sanitize_text_field', $value), 'strlen')) : (string)$value;
            }

            $result = Fields::set_values($subscriber_id, $submitted);
            $errors = array_values($result['errors']);

            if ( ! empty($result['changed'])) {
                $changes = [];
                foreach ($result['changed'] as $field_id => list($old, $new)) {
                    $field     = Fields::get($field_id);
                    $changes[] = ['field' => $field['label'], 'from' => Fields::display_value($field, $old), 'to' => Fields::display_value($field, $new)];
                }

                Subscribers::log_event($subscriber_id, Subscribers::EVENT_FIELDS_CHANGED, ['changes' => $changes, 'by_admin' => $admin]);
            }
        }

        if ( ! empty($post['tags_submitted'])) {
            // Only tags that still exist; one could have been deleted while the profile was open.
            $tag_ids = array_values(array_intersect(array_map('absint', array_filter((array)($post['tag_ids'] ?? []), 'is_scalar')), array_keys(Tags::names())));
            $new     = array_filter(array_map('trim', explode(',', (string)wp_unslash(is_scalar($post['new_tags'] ?? '') ? $post['new_tags'] ?? '' : ''))), 'strlen');
            $tag_ids = array_merge($tag_ids, Tags::ensure($new));

            $result = Tags::set_for_subscriber($subscriber_id, $tag_ids);
            $names  = Tags::names();

            if ( ! empty($result['added'])) {
                Subscribers::log_event($subscriber_id, Subscribers::EVENT_TAGS_ADDED, ['tags' => array_values(array_intersect_key($names, array_flip($result['added']))), 'by_admin' => $admin]);
            }

            if ( ! empty($result['removed'])) {
                Subscribers::log_event($subscriber_id, Subscribers::EVENT_TAGS_REMOVED, ['tags' => array_values(array_intersect_key($names, array_flip($result['removed']))), 'by_admin' => $admin]);
            }
        }

        return $errors;
    }

    /**
     * Timeline text for tag and field events; null for other events.
     */
    public static function event_label($event, $meta)
    {
        $who = ! empty($meta['by_admin']) ? self::admin_name($meta['by_admin']) : '';

        switch ($event['type']) {
            case Subscribers::EVENT_TAGS_ADDED:
                $text = sprintf(_n('Tag %s added', 'Tags %s added', count((array)($meta['tags'] ?? [])), 'mailoptin'), implode(', ', (array)($meta['tags'] ?? [])));
                break;
            case Subscribers::EVENT_TAGS_REMOVED:
                $text = sprintf(_n('Tag %s removed', 'Tags %s removed', count((array)($meta['tags'] ?? [])), 'mailoptin'), implode(', ', (array)($meta['tags'] ?? [])));
                break;
            case Subscribers::EVENT_PREFERENCES_CHANGED:
                $parts = [];
                if ( ! empty($meta['left'])) $parts[] = sprintf(__('left %s', 'mailoptin'), implode(', ', (array)$meta['left']));
                if ( ! empty($meta['joined'])) $parts[] = sprintf(__('joined %s', 'mailoptin'), implode(', ', (array)$meta['joined']));
                foreach ((array)($meta['fields'] ?? []) as $change) {
                    $parts[] = '' === $change['to'] ? sprintf(__('%s cleared', 'mailoptin'), $change['field']) : sprintf(__('%1$s set to %2$s', 'mailoptin'), $change['field'], $change['to']);
                }

                return sprintf(__('Changed their preferences: %s', 'mailoptin'), implode('; ', $parts));
            case Subscribers::EVENT_IMPORTED:
                $lists = array_filter(array_map(function ($list_id) {
                    $list = \MailOptin\Core\Repositories\ListsRepository::get($list_id);

                    return $list ? $list['name'] : '';
                }, (array)($meta['list_ids'] ?? [])));

                $text = sprintf(__('Imported from %s', 'mailoptin'), $meta['file'] ?? __('a CSV file', 'mailoptin'));
                if ( ! empty($lists)) $text .= ' ' . sprintf(__('into %s', 'mailoptin'), implode(', ', $lists));
                if ( ! empty($meta['tags'])) $text .= ', ' . sprintf(__('tagged %s', 'mailoptin'), implode(', ', (array)$meta['tags']));
                break;
            case Subscribers::EVENT_FIELDS_CHANGED:
                if ( ! empty($meta['changes'])) {
                    $parts = array_map(function ($change) {
                        if ('' === $change['to']) return sprintf(__('%s cleared', 'mailoptin'), $change['field']);
                        if ('' === $change['from']) return sprintf(__('%1$s set to %2$s', 'mailoptin'), $change['field'], $change['to']);

                        return sprintf(__('%1$s changed from %2$s to %3$s', 'mailoptin'), $change['field'], $change['from'], $change['to']);
                    }, (array)$meta['changes']);
                    $text = implode('; ', $parts);
                } else {
                    $text = sprintf(__('%s changed in bulk', 'mailoptin'), implode(', ', (array)($meta['fields'] ?? [])));
                }
                break;
            default:
                return null;
        }

        return $who ? sprintf(__('%1$s by %2$s', 'mailoptin'), $text, $who) : $text;
    }

    protected static function admin_name($user_id)
    {
        if ((int)$user_id === get_current_user_id()) return __('you', 'mailoptin');

        $user = get_userdata($user_id);

        return $user ? $user->display_name : __('an admin', 'mailoptin');
    }

    /**
     * What a signup added, for the end of its timeline line.
     */
    public static function signup_suffix($meta)
    {
        $parts = [];

        if ( ! empty($meta['tags'])) $parts[] = sprintf(__('tagged %s', 'mailoptin'), implode(', ', (array)$meta['tags']));
        if ( ! empty($meta['field_errors'])) $parts[] = sprintf(__('some field values weren\'t saved: %s', 'mailoptin'), implode(' ', (array)$meta['field_errors']));

        return empty($parts) ? '' : ', ' . implode(', ', $parts);
    }

    /* ---------------------------------------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return array|null [notice, url] after a tag or field action; null if it isn't one
     */
    public static function handle_action($action)
    {
        $tags_url   = Subscribers_Page::page_url('tags');
        $fields_url = Subscribers_Page::page_url('fields');

        switch ($action) {
            case 'add_tag':
                check_admin_referer('mo_add_tag');
                $name = wp_unslash($_POST['name'] ?? '');
                if (Tags::get_by_name($name)) {
                    self::set_error(sprintf(__('There is already a tag called "%s".', 'mailoptin'), Tags::sanitize_name($name)));

                    return ['error', $tags_url];
                }
                $result = Tags::create($name);
                if (is_wp_error($result)) {
                    self::set_error($result->get_error_message());

                    return ['error', $tags_url];
                }

                return ['tag_added', $tags_url];

            case 'rename_tag':
                $tag_id = absint($_POST['tag'] ?? 0);
                check_admin_referer('mo_rename_tag_' . $tag_id);
                $result = Tags::rename($tag_id, wp_unslash($_POST['name'] ?? ''));
                if (is_wp_error($result)) {
                    self::set_error($result->get_error_message());

                    return ['error', add_query_arg('rename', $tag_id, $tags_url)];
                }

                return ['tag_renamed', $tags_url];

            case 'merge_tag':
                $tag_id = absint($_POST['tag'] ?? 0);
                check_admin_referer('mo_merge_tag_' . $tag_id);

                return [Tags::merge($tag_id, absint($_POST['into'] ?? 0)) ? 'tag_merged' : 'tag_not_merged', $tags_url];

            case 'delete_tag':
                $tag_id = absint($_GET['tag'] ?? 0);
                check_admin_referer('mo_delete_tag_' . $tag_id);
                Tags::delete($tag_id);

                return ['tag_deleted', $tags_url];

            case 'save_field':
                check_admin_referer('mo_save_field');
                $field_id = absint($_POST['field_id'] ?? 0);
                $data     = [
                    'type'      => sanitize_key($_POST['type'] ?? ''),
                    'label'     => wp_unslash($_POST['label'] ?? ''),
                    'merge_key' => sanitize_text_field(wp_unslash($_POST['merge_key'] ?? '')),
                    'options'   => wp_unslash($_POST['options'] ?? ''),

                    'subscriber_editable' => ! empty($_POST['subscriber_editable']),
                ];

                if ($field_id) {
                    // Options are only sent for types that have them.
                    $field = Fields::get($field_id);
                    if ($field && ! Fields::has_options($field['type'])) unset($data['options']);
                    unset($data['type'], $data['merge_key']);
                    $result = Fields::update($field_id, $data);
                } else {
                    if ( ! Fields::has_options($data['type'])) unset($data['options']);
                    $result = Fields::create($data);
                }

                if (is_wp_error($result)) {
                    self::set_error($result->get_error_message(), $data);

                    return ['error', add_query_arg('field', $field_id ?: 'new', $fields_url)];
                }

                return [$field_id ? 'field_saved' : 'field_added', $fields_url];

            case 'delete_field':
                $field_id = absint($_GET['field_id'] ?? 0);
                check_admin_referer('mo_delete_field_' . $field_id);
                Fields::delete($field_id);

                return ['field_deleted', $fields_url];

            case 'reorder_fields':
                check_admin_referer('mo_reorder_fields');
                Fields::reorder(explode(',', sanitize_text_field(wp_unslash($_POST['order'] ?? ''))));

                return ['fields_reordered', $fields_url];

            case 'move_field':
                $field_id = absint($_GET['field_id'] ?? 0);
                check_admin_referer('mo_move_field_' . $field_id);

                $ids   = wp_list_pluck(Fields::all(), 'id');
                $index = array_search($field_id, $ids, true);
                $swap  = 'up' === ($_GET['dir'] ?? '') ? $index - 1 : $index + 1;

                if (false !== $index && isset($ids[$swap])) {
                    list($ids[$index], $ids[$swap]) = [$ids[$swap], $ids[$index]];
                    Fields::reorder($ids);
                }

                return ['fields_reordered', $fields_url];
        }

        return null;
    }

    public static function merge_tag_list()
    {
        return array_map(function ($field) {
            return '{{subscriber.' . $field['merge_key'] . '}}';
        }, self::is_ready() ? Fields::all() : []);
    }

    /* ---------------------------------------------------------------------------------------------
     * Styles and scripts
     * ------------------------------------------------------------------------------------------ */

    public static function styles()
    {
        return '<style>
.mo-intro{max-width:72ch;color:#50575e}
.mo-toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:0 0 12px}
.mo-toolbar-end{margin-left:auto}
.mo-inline-form{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0}
.mo-tag{display:inline-block;padding:1px 8px;margin:1px 4px 1px 0;border-radius:4px;background:#f0f0f1;border:1px solid #dcdcde;font-size:12px;line-height:18px;color:#1d2327}
.mo-tag-removable{cursor:pointer}
.mo-tag-removable input{margin:0 2px 0 0}
.mo-tags-table td,.mo-fields-table td{vertical-align:middle}
.mo-actions-col{text-align:right;white-space:nowrap}
.mo-button-danger,.mo-button-danger:hover{color:#b32d2e!important;border-color:#b32d2e!important}
.mo-handle-col{width:56px}
.mo-handle{display:none;color:#a7aaad;cursor:grab;letter-spacing:-3px;padding-right:4px;user-select:none}
.mo-js .mo-handle{display:inline}
.mo-js .mo-move{display:none}
.mo-move a{text-decoration:none;padding:0 3px}
.mo-sortable tr.mo-dragging{opacity:.4}
.mo-panel-dim{position:fixed;inset:32px 0 0 0;background:rgba(0,0,0,.25);z-index:9990}
.mo-panel{position:fixed;top:32px;right:0;bottom:0;width:420px;max-width:100%;background:#fff;z-index:9991;box-shadow:-4px 0 24px rgba(0,0,0,.15);display:flex}
.mo-panel form{display:flex;flex-direction:column;width:100%}
.mo-panel header{display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-bottom:1px solid #dcdcde}
.mo-panel header h2{margin:0;font-size:16px}
.mo-panel-close{font-size:24px;text-decoration:none;color:#50575e}
.mo-panel-body{padding:8px 20px;overflow:auto;flex:1}
.mo-panel footer{display:flex;justify-content:flex-end;gap:8px;padding:12px 20px;border-top:1px solid #dcdcde}
.mo-panel-danger{margin-top:24px;border-top:1px solid #f0f0f1;padding-top:12px}
.mo-types{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}
.mo-types label{display:block}
.mo-types input{position:absolute;opacity:0}
.mo-types span{display:block;border:1px solid #c3c4c7;border-radius:4px;padding:8px 4px;text-align:center;font-size:12px;cursor:pointer}
.mo-types b{display:block;font-size:15px;margin-bottom:2px}
.mo-types input:checked+span{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;background:#f0f6fc}
.mo-types input:focus-visible+span{outline:2px solid #2271b1}
.mo-key-input{display:flex;align-items:center;gap:2px}
.mo-key-input input{flex:1;font-family:monospace}
.mo-filter-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:flex-start;margin:8px 0}
.mo-filter-chip{display:inline-flex;gap:4px;align-items:center;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:99px;padding:3px 6px 3px 10px;font-size:13px}
.mo-filter-chip a{text-decoration:none;font-size:15px;line-height:1;padding:0 4px;color:#50575e}
.mo-add-filter{position:relative}
.mo-add-filter summary{cursor:pointer;color:#2271b1;padding:4px 6px;list-style:none}
.mo-add-filter summary::-webkit-details-marker{display:none}
.mo-add-filter-body{position:absolute;z-index:50;top:30px;left:0;min-width:300px;background:#fff;border:1px solid #c3c4c7;box-shadow:0 4px 16px rgba(0,0,0,.12);padding:10px 14px;border-radius:4px}
.mo-check-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:2px 12px;max-height:220px;overflow:auto;margin:6px 0}
.mo-js .mo-no-js{display:none}
.mo-js .mo-field-filter:not(.mo-between-on) .mo-between{display:none}
.mo-field-filter .mo-between .description{display:none}
.mo-bulk-bar{position:sticky;bottom:12px;z-index:60;display:flex;gap:6px;align-items:center;flex-wrap:wrap;background:#1d2327;color:#fff;padding:8px 12px;border-radius:6px;margin:12px 0;box-shadow:0 6px 20px rgba(0,0,0,.25);width:fit-content;max-width:100%}
.mo-bulk-bar .button{background:#2c3338;border-color:#50575e;color:#fff}
.mo-bulk-bar .button:hover{background:#3c434a;color:#fff}
.mo-bulk-bar .mo-button-danger{color:#ffabaf!important;border-color:#8a2424!important}
.mo-bulk-bar[hidden]{display:none}
.mo-sep{width:1px;height:20px;background:#50575e;margin:0 4px}
.mo-dialog{border:1px solid #c3c4c7;border-radius:6px;padding:16px 20px;width:380px;max-width:92vw;box-shadow:0 8px 32px rgba(0,0,0,.2)}
.mo-dialog::backdrop{background:rgba(0,0,0,.25)}
.mo-dialog h3{margin:0 0 10px}
.mo-tag-input{display:flex;flex-wrap:wrap;gap:4px;align-items:center;border:1px solid #8c8f94;border-radius:4px;padding:4px 6px}
.mo-tag-input input{border:0!important;box-shadow:none!important;flex:1;min-width:120px;padding:2px!important}
.mo-tag-picked .mo-tag button{border:0;background:none;padding:0 0 0 4px;cursor:pointer;color:#50575e}
.mo-tag-options{list-style:none;margin:8px 0;padding:0;max-height:220px;overflow:auto;border:1px solid #f0f0f1;border-radius:4px}
.mo-tag-options li{margin:0}
.mo-tag-options button{display:flex;justify-content:space-between;width:100%;border:0;background:none;padding:6px 10px;cursor:pointer;text-align:left}
.mo-tag-options button:hover,.mo-tag-options button:focus{background:#f0f6fc}
.mo-tag-create button{color:#2271b1}
.mo-dialog-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}
.mo-field-grid{display:grid;gap:10px;margin-bottom:12px}
.mo-field-row{display:grid;grid-template-columns:150px 1fr;gap:10px;align-items:start}
.mo-field-row>label,.mo-field-row>span{padding-top:5px;color:#50575e}
.mo-heading-with-link{display:flex;gap:12px;align-items:baseline}
.mo-heading-with-link a{font-size:13px;font-weight:400}
.mo-profile-tags{margin-bottom:6px}
.wp-list-table.subscribers .column-tags{width:16%}
.wp-list-table.subscribers .column-lists{width:12%}
.wp-list-table.subscribers [class*="column-field_"]{width:10%}
.wp-list-table.subscribers td{overflow-wrap:break-word}
@media (max-width:782px){.mo-panel-dim,.mo-panel{top:46px}.mo-field-row{grid-template-columns:1fr}.mo-types{grid-template-columns:repeat(2,1fr)}}
</style>';
    }

    public static function script()
    {
        ob_start();
        ?>
        <script>
            (function () {
                if (typeof HTMLDialogElement !== 'undefined') document.documentElement.classList.add('mo-js');
                var q = function (sel, root) { return (root || document).querySelector(sel); };
                var qa = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

                // Confirm destructive links.
                document.addEventListener('click', function (e) {
                    var link = e.target.closest('.mo-confirm');
                    if (link && !window.confirm(link.getAttribute('data-confirm'))) e.preventDefault();
                });

                // Submit a select's form as soon as it changes.
                qa('.mo-autosubmit').forEach(function (select) {
                    select.addEventListener('change', function () { if (select.value) select.form.submit(); });
                });

                // Range inputs only for "is between".
                qa('.mo-field-filter').forEach(function (form) {
                    var op = q('select[name$="[op]"]', form);
                    var valueRow = q('.mo-filter-value', form);
                    var sync = function () {
                        form.classList.toggle('mo-between-on', op.value === 'between');
                        if (valueRow) valueRow.hidden = ['empty', 'not_empty', 'checked', 'unchecked'].indexOf(op.value) !== -1;
                    };
                    op.addEventListener('change', sync);
                    sync();
                });

                // Merge tag from the label, until it's edited by hand.
                var label = q('#mo-field-label'), key = q('#mo-field-key');
                if (label && key) {
                    var touched = key.value !== '';
                    key.addEventListener('input', function () { touched = key.value !== ''; });
                    label.addEventListener('input', function () {
                        if (touched) return;
                        var slug = label.value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 64);
                        if (slug && !/^[a-z]/.test(slug)) slug = ('field_' + slug).slice(0, 64);
                        key.value = slug;
                    });
                }

                // Options only for dropdown and multi-select.
                var optionsRow = q('.mo-options-row');
                qa('.mo-types input').forEach(function (radio) {
                    var sync = function () { if (radio.checked && optionsRow) optionsRow.hidden = ['dropdown', 'multiselect'].indexOf(radio.value) === -1; };
                    radio.addEventListener('change', sync);
                    sync();
                });

                // Close the field panel with Escape.
                document.addEventListener('keydown', function (e) {
                    var close = q('.mo-panel-close');
                    if (e.key === 'Escape' && close && !q('dialog[open]')) window.location = close.href;
                });

                // Drag to reorder custom fields.
                var tbody = q('.mo-sortable'), reorder = q('#mo-reorder-fields');
                if (tbody && reorder && tbody.querySelectorAll('tr[data-id]').length > 1) {
                    var dragging = null;
                    qa('tr[data-id]', tbody).forEach(function (row) {
                        var handle = q('.mo-handle', row);
                        handle.addEventListener('mousedown', function () { row.draggable = true; });
                        row.addEventListener('dragstart', function (e) { dragging = row; row.classList.add('mo-dragging'); e.dataTransfer.effectAllowed = 'move'; });
                        row.addEventListener('dragend', function () {
                            row.classList.remove('mo-dragging'); row.draggable = false; dragging = null;
                            reorder.elements.order.value = qa('tr[data-id]', tbody).map(function (r) { return r.getAttribute('data-id'); }).join(',');
                            reorder.submit();
                        });
                        row.addEventListener('dragover', function (e) {
                            if (!dragging || dragging === row) return;
                            e.preventDefault();
                            var after = e.clientY > row.getBoundingClientRect().top + row.offsetHeight / 2;
                            tbody.insertBefore(dragging, after ? row.nextSibling : row);
                        });
                    });
                }

                // Floating bulk bar on the subscribers list.
                var bar = q('.mo-bulk-bar');
                var table = q('.wp-list-table.subscribers');
                if (!bar || !table || typeof HTMLDialogElement === 'undefined') return;

                var form = table.closest('form');
                var boxes = function () { return qa('tbody input[name="subscriber_ids[]"]:checked', table); };
                var i18n = <?php echo wp_json_encode([
                    'selected'   => __('%d selected', 'mailoptin'),
                    'addTo'      => __('Add tags to %d subscribers', 'mailoptin'),
                    'removeFrom' => __('Remove tags from %d subscribers', 'mailoptin'),
                    'addTag'     => __('Add tags', 'mailoptin'),
                    'removeTag'  => __('Remove tags', 'mailoptin'),
                    'create'     => __('+ Create tag "%s"', 'mailoptin'),
                    'list'       => __('Add %d subscribers to a list', 'mailoptin'),
                    'field'      => __('Set a field for %d subscribers', 'mailoptin'),
                    'delete'     => __('Delete %d subscribers? This can\'t be undone. They can sign up again later.', 'mailoptin'),
                ]); ?>;
                var fmt = function (text, value) { return text.replace('%d', value).replace('%s', value); };

                var sync = function () {
                    var count = boxes().length;
                    bar.hidden = count === 0;
                    q('.mo-bulk-count', bar).textContent = fmt(i18n.selected, count);
                };
                table.addEventListener('change', sync);
                sync();

                var submit = function (action) {
                    qa('select[name="action"], select[name="action2"]', form).forEach(function (s) { s.value = '-1'; });
                    var select = q('select[name="action"]', form);
                    if (!q('option[value="' + action + '"]', select)) return;
                    select.value = action;
                    form.submit();
                };

                q('.mo-bulk-clear', bar).addEventListener('click', function () {
                    qa('input[type="checkbox"]', table).forEach(function (b) { b.checked = false; });
                    sync();
                });

                q('[data-action="delete"]', bar).addEventListener('click', function () {
                    if (window.confirm(fmt(i18n.delete, boxes().length))) submit('delete');
                });

                // Tag picker.
                var tagDialog = q('#mo-tag-dialog'), picked = [], mode = 'add_tags';
                var search = q('#mo-tag-search-input', tagDialog), pickedEl = q('.mo-tag-picked', tagDialog), create = q('.mo-tag-create', tagDialog);
                var renderPicked = function () {
                    pickedEl.innerHTML = '';
                    picked.forEach(function (name, index) {
                        var chip = document.createElement('span');
                        chip.className = 'mo-tag';
                        chip.textContent = name;
                        var remove = document.createElement('button');
                        remove.type = 'button';
                        remove.setAttribute('aria-label', 'Remove ' + name);
                        remove.textContent = '×';
                        remove.addEventListener('click', function () { picked.splice(index, 1); renderPicked(); });
                        chip.appendChild(remove);
                        pickedEl.appendChild(chip);
                    });
                    q('.mo-dialog-ok', tagDialog).disabled = picked.length === 0;
                };
                var pick = function (name) {
                    name = name.trim();
                    if (name && picked.map(function (p) { return p.toLowerCase(); }).indexOf(name.toLowerCase()) === -1) picked.push(name);
                    search.value = '';
                    filter();
                    renderPicked();
                    search.focus();
                };
                var filter = function () {
                    var term = search.value.trim().toLowerCase(), exact = false;
                    qa('.mo-tag-options li:not(.mo-tag-create)', tagDialog).forEach(function (li) {
                        var name = q('button', li).getAttribute('data-name');
                        li.hidden = term !== '' && name.toLowerCase().indexOf(term) === -1;
                        if (name.toLowerCase() === term) exact = true;
                    });
                    create.hidden = mode !== 'add_tags' || term === '' || exact;
                    q('button', create).textContent = fmt(i18n.create, search.value.trim());
                };
                search.addEventListener('input', filter);
                search.addEventListener('keydown', function (e) {
                    if ((e.key === 'Enter' || e.key === ',') && search.value.trim()) {
                        e.preventDefault();
                        var match = qa('.mo-tag-options li:not([hidden]):not(.mo-tag-create) button', tagDialog)[0];
                        pick(mode === 'remove_tags' && match ? match.getAttribute('data-name') : search.value);
                    }
                });
                qa('.mo-tag-options button', tagDialog).forEach(function (button) {
                    button.addEventListener('click', function () { pick(button.getAttribute('data-name') || search.value); });
                });
                tagDialog.addEventListener('close', function () {
                    if (tagDialog.returnValue !== 'ok' || !picked.length) return;
                    q('#mo-bulk-tags').value = picked.join(',');
                    submit(mode);
                });

                // List dialog.
                var listDialog = q('#mo-list-dialog');
                listDialog.addEventListener('close', function () {
                    if (listDialog.returnValue !== 'ok') return;
                    q('#mo-bulk-list').value = q('.mo-dialog-list', listDialog).value;
                    submit('add_to_list');
                });

                // Field dialog: the value input matches the field's type.
                var fieldDialog = q('#mo-field-dialog');
                var valueInput = function () {
                    var option = q('#mo-bulk-field option[value="' + q('#mo-dialog-field').value + '"]');
                    var type = option.getAttribute('data-type'), options = JSON.parse(option.getAttribute('data-options') || '[]');
                    var wrap = q('.mo-dialog-value-wrap', fieldDialog), input;
                    wrap.innerHTML = '';
                    if (type === 'dropdown' || type === 'checkbox') {
                        input = document.createElement('select');
                        var choices = type === 'checkbox' ? [['', '—'], ['1', <?php echo wp_json_encode(__('Yes', 'mailoptin')); ?>], ['0', <?php echo wp_json_encode(__('No', 'mailoptin')); ?>]] : [['', '—']].concat(options.map(function (o) { return [o, o]; }));
                        choices.forEach(function (c) { var o = document.createElement('option'); o.value = c[0]; o.textContent = c[1]; input.appendChild(o); });
                    } else {
                        input = document.createElement('input');
                        input.type = {number: 'number', date: 'date', url: 'url', phone: 'tel'}[type] || 'text';
                        if (type === 'number') input.step = 'any';
                        if (type === 'multiselect') input.placeholder = options.join(', ');
                    }
                    input.id = 'mo-dialog-value';
                    input.className = 'widefat';
                    wrap.appendChild(input);
                };
                if (fieldDialog) {
                    q('#mo-dialog-field').addEventListener('change', valueInput);
                    fieldDialog.addEventListener('close', function () {
                        if (fieldDialog.returnValue !== 'ok') return;
                        q('#mo-bulk-field').value = q('#mo-dialog-field').value;
                        q('#mo-bulk-value').value = q('#mo-dialog-value').value;
                        submit('set_field');
                    });
                }

                qa('[data-dialog]', bar).forEach(function (button) {
                    button.addEventListener('click', function () {
                        var count = boxes().length, what = button.getAttribute('data-dialog');
                        if (what === 'add_tags' || what === 'remove_tags') {
                            mode = what;
                            picked = [];
                            renderPicked();
                            q('.mo-dialog-title', tagDialog).textContent = fmt(what === 'add_tags' ? i18n.addTo : i18n.removeFrom, count);
                            q('.mo-dialog-ok', tagDialog).textContent = what === 'add_tags' ? i18n.addTag : i18n.removeTag;
                            search.value = '';
                            filter();
                            tagDialog.showModal();
                            search.focus();
                        } else if (what === 'add_to_list') {
                            q('.mo-dialog-title', listDialog).textContent = fmt(i18n.list, count);
                            listDialog.showModal();
                        } else if (what === 'set_field' && fieldDialog) {
                            q('.mo-dialog-title', fieldDialog).textContent = fmt(i18n.field, count);
                            valueInput();
                            fieldDialog.showModal();
                        }
                    });
                });
            })();
        </script>
        <?php
        return ob_get_clean();
    }
}
