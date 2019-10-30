<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


class SettingsFields
{
    public static function tinymce($name, $setting)
    {
        echo '<div class="mo-email-content-field-tinymce-wrap">';
        // {{{data.%s}}}
        printf('<textarea id="%s" style="height: 280px" class="mo-email-content-field-tinymce">lll</textarea>', $name);
        echo '</div>';
    }

    public static function text($name, $setting)
    {
        printf('<input type="text" name="%1$s" id="%1$s" value="">', $name);
    }

    public static function range($name, $setting)
    {
        echo '<div class="control-wrap">';
        printf('<input name="%s" type="range" min="0" max="4096" step="1" value="1200" data-reset_value="1200">', $name);
        printf('<input type="number" min="0" max="4096" step="1" class="oceanwp-range-input" value="1200">');
        echo '<span class="oceanwp-reset-slider"><span class="dashicons dashicons-image-rotate"></span></span>';
        echo '</div>';
    }

    public static function color_picker($name, $setting)
    {
        $default     = '#ffffff';
        $saved_value = $default;

        $defaultValue     = '#RRGGBB';
        $defaultValueAttr = '';

        if ($default && is_string($default)) {
            if ('#' !== substr($default, 0, 1)) {
                $defaultValue = '#' . $default;
            } else {
                $defaultValue = $default;
            }
            $defaultValueAttr = " data-default-color=\"$defaultValue\""; // Quotes added automatically.
        }

        echo '<input name="' . $name . '" class="mo-color-picker-hex" type="text" maxlength="7" value="' . $saved_value . '" placeholder="' . $defaultValue . '"' . $defaultValueAttr . '/>';
    }

    public static function dimension($name, $setting)
    {
        $item_link_desc = esc_html__('Link Values Together', 'mailoptin');
        ?>
        <div class="customize-control-mo-border">
            <div class="mo-border-outer-wrapper">
                <div class="input-wrapper mo-border-wrapper">

                    <ul class="mo-border-wrapper desktop active">
                        <li class="mo-border-input-item-link">
                            <span class="dashicons dashicons-admin-links mo-border-connected wp-ui-highlight" title="<?= $item_link_desc ?>"></span>
                            <span class="dashicons dashicons-editor-unlink mo-border-disconnected" title="<?= $item_link_desc ?>"></span>
                        </li>
                        <li class="mo-border-input-item">
                            <input type="number" class="mo-border-input" data-id="top" value="0" name="<?= $name ?>">
                            <span class="mo-border-title"><?= esc_html__('Top', 'mailoptin') ?></span>
                        </li>
                        <li class="mo-border-input-item">
                            <input type="number" class="mo-border-input" data-id="right" value="0" name="<?= $name ?>">
                            <span class="mo-border-title"><?= esc_html__('Right', 'mailoptin') ?></span>
                        </li>
                        <li class="mo-border-input-item">
                            <input type="number" class="mo-border-input" data-id="bottom" value="0" name="<?= $name ?>">
                            <span class="mo-border-title"><?= esc_html__('Bottom', 'mailoptin') ?></span>
                        </li>
                        <li class="mo-border-input-item">
                            <input type="number" class="mo-border-input" data-id="left" value="0" name="<?= $name ?>">
                            <span class="mo-border-title"><?= esc_html__('Left', 'mailoptin') ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
}