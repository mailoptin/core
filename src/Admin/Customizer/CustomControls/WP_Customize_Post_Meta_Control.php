<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls;

use MailOptin\Core\EmailCampaigns\PostMetaFields;
use WP_Customize_Control;

/**
 * Choose the details shown with each post (author, date, terms, custom fields, product price…) and their
 * order. Saves a comma-separated list of {@see PostMetaFields} keys, like the checkbox list it replaces.
 */
class WP_Customize_Post_Meta_Control extends WP_Customize_Control
{
    public $type = 'mailoptin-post-meta';

    /** @var string[] post types the email sends, for taxonomies and custom field suggestions */
    public $post_types = [];

    public function enqueue()
    {
        wp_enqueue_script(
            'mailoptin-customizer-post-meta',
            MAILOPTIN_ASSETS_URL . 'js/customizer-controls/post-meta-control.js',
            ['jquery', 'jquery-ui-sortable', 'customize-controls'],
            MAILOPTIN_VERSION_NUMBER,
            true
        );

        wp_localize_script('mailoptin-customizer-post-meta', 'moPostMetaControl', [
            /* translators: %s: custom field key */
            'customField' => __('Custom field: %s', 'mailoptin'),
        ]);
    }

    public function render_content()
    {
        $saved   = PostMetaFields::parse($this->value());
        $choices = PostMetaFields::choices($this->post_types);

        // Chosen first, in their order; then the rest. Custom fields only appear once added.
        $items = [];
        foreach ($saved as $key) $items[$key] = PostMetaFields::label($key, $choices);
        foreach ($choices as $key => $label) if ( ! isset($items[$key])) $items[$key] = $label;

        $list_id = 'mo-post-meta-keys-' . esc_attr($this->id);
        $list_id = preg_replace('/[^A-Za-z0-9_\-]/', '-', $list_id);
        ?>
        <?php if ( ! empty($this->label)) : ?>
            <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
        <?php endif; ?>
        <?php if ( ! empty($this->description)) : ?>
            <span class="description customize-control-description"><?php echo wp_kses_post($this->description); ?></span>
        <?php endif; ?>

        <ul class="mo-post-meta-list">
            <?php foreach ($items as $key => $label) : ?>
                <li>
                    <span class="dashicons dashicons-menu mo-post-meta-handle" aria-hidden="true"></span>
                    <label><input type="checkbox" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $saved, true)); ?>> <?php echo esc_html($label); ?></label>
                </li>
            <?php endforeach; ?>
        </ul>

        <p class="mo-post-meta-add">
            <label class="screen-reader-text" for="<?php echo esc_attr($list_id); ?>-input"><?php esc_html_e('Custom field key', 'mailoptin'); ?></label>
            <input type="text" id="<?php echo esc_attr($list_id); ?>-input" list="<?php echo esc_attr($list_id); ?>" placeholder="<?php esc_attr_e('Custom field key, e.g. brand', 'mailoptin'); ?>">
            <button type="button" class="button"><?php esc_html_e('Add field', 'mailoptin'); ?></button>
            <datalist id="<?php echo esc_attr($list_id); ?>">
                <?php foreach (PostMetaFields::suggested_meta_keys($this->post_types) as $meta_key) : ?>
                    <option value="<?php echo esc_attr($meta_key); ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </p>

        <input type="hidden" <?php $this->link(); ?> value="<?php echo esc_attr(implode(',', $saved)); ?>">

        <style>
            .mo-post-meta-list { margin: 6px 0; }
            .mo-post-meta-list li { align-items: center; background: #fff; border: 1px solid #dcdcde; border-radius: 4px; display: flex; gap: 6px; margin: 0 0 4px; padding: 5px 8px; }
            .mo-post-meta-list .mo-post-meta-handle { color: #8c8f94; cursor: move; }
            .mo-post-meta-list label { flex: 1; }
            .mo-post-meta-add { display: flex; gap: 6px; }
            .mo-post-meta-add input { flex: 1; min-width: 0; }
        </style>
        <?php
    }
}
