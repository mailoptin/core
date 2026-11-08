<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls;

use WP_Customize_Control;

/**
 * A tags box: type a value and press Enter or comma. Built on Tagify (https://github.com/yairEO/tagify), which
 * replaced the jQuery UI tag-it plugin. The class name, the "mailoptin_tagit" type and the saved value (an
 * array of strings) are unchanged, so existing settings and callers keep working.
 *
 * Options, as callers already pass them:
 * - placeholderText: shown in the empty box
 * - availableTags: suggestions offered while typing
 * - showAutocompleteOnFocus: show the suggestions as soon as the box is focused
 * - singleField: accepted and ignored (the value is always one array)
 */
class WP_Customize_Tagit_Control extends WP_Customize_Control
{
    public $type = 'mailoptin_tagit';

    public $field_id = 'mo-tagit';

    public $options = [];

    public function enqueue()
    {
        wp_enqueue_style('mailoptin-tagify', MAILOPTIN_ASSETS_URL . 'js/customizer-controls/tagify/tagify.css', [], '4.31.3');
        wp_enqueue_script('mailoptin-tagify', MAILOPTIN_ASSETS_URL . 'js/customizer-controls/tagify/tagify.min.js', [], '4.31.3', true);
        wp_enqueue_script('mailoptin-tagify-init', MAILOPTIN_ASSETS_URL . 'js/customizer-controls/tagify/tagify-init.js', ['mailoptin-tagify', 'customize-controls'], MAILOPTIN_VERSION_NUMBER, true);
    }

    /**
     * The saved tags, cleaned: an array of non-empty strings.
     */
    public function tags()
    {
        $value = $this->value();

        if (is_string($value)) $value = explode(',', $value);

        return array_values(array_filter(array_map('trim', array_filter((array)$value, 'is_scalar')), 'strlen'));
    }

    public function render_content()
    {
        $options = [
            'placeholder' => (string)($this->options['placeholderText'] ?? ''),
            'whitelist'   => array_values(array_map('strval', (array)($this->options['availableTags'] ?? []))),
            'onFocus'     => ! empty($this->options['showAutocompleteOnFocus']),
        ];
        ?>
        <label>
            <?php if ( ! empty($this->label)) : ?>
                <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
            <?php endif; ?>

            <?php if ( ! empty($this->description)) : ?>
                <span class="description customize-control-description"><?php echo wp_kses_post($this->description); ?></span>
            <?php endif; ?>

            <input type="text" class="mo-tagify" id="<?php echo esc_attr($this->field_id); ?>"
                   value="<?php echo esc_attr(implode(',', $this->tags())); ?>"
                   data-tagify-options="<?php echo esc_attr(wp_json_encode($options)); ?>">
        </label>
        <?php
    }
}
