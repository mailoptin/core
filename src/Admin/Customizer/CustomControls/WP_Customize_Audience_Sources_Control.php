<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls;

use MailOptin\Core\Audience\Sources\Sources;
use WP_Customize_Control;

/**
 * "Customers, users and members" for campaigns edited in the Customizer (post notifications and
 * digests): the same choices as the broadcast page, kept in the setting as JSON.
 */
class WP_Customize_Audience_Sources_Control extends WP_Customize_Control
{
    public $type = 'mailoptin-audience-sources';

    public function render_content()
    {
        $active = Sources::active();

        if (empty($active)) return;

        $chosen = [];
        foreach (Sources::campaign_selection($this->value()) as $item) $chosen[$item['source']] = $item['option'];
        ?>
        <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
        <?php if ( ! empty($this->description)) : ?><span class="description customize-control-description"><?php echo wp_kses_post($this->description); ?></span><?php endif; ?>
        <input type="hidden" class="mo-sources-value" <?php $this->link(); ?> value="<?php echo esc_attr((string)$this->value()); ?>">
        <div class="mo-sources-picker">
            <?php foreach ($active as $id => $source) : $options = $source->options(); ?>
                <p style="margin:6px 0">
                    <label><input type="checkbox" class="mo-source-on" data-source="<?php echo esc_attr($id); ?>" <?php checked(isset($chosen[$id])); ?>> <?php echo esc_html($source->label()); ?></label>
                    <?php if ( ! empty($options)) : ?>
                        <br><label class="screen-reader-text" for="mo-src-opt-<?php echo esc_attr($id); ?>"><?php echo esc_html($source->option_label()); ?></label>
                        <select id="mo-src-opt-<?php echo esc_attr($id); ?>" class="mo-source-option" data-source="<?php echo esc_attr($id); ?>" style="margin:4px 0 0 24px;max-width:calc(100% - 24px)">
                            <option value=""><?php echo esc_html($source->any_option_label()); ?></option>
                            <?php foreach ($options as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected(isset($chosen[$id]) && (string)$chosen[$id] === (string)$value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </p>
            <?php endforeach; ?>
        </div>
        <?php // Kept in step by CampaignAudience::script(). ?>
        <?php
    }
}
