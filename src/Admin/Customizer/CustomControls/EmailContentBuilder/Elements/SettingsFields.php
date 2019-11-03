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

    public static function font_family($name, $setting)
    {
        $setting['choices'] = [
            'Arial'               => esc_html__('Arial', 'mailoptin'),
            'Arial Black'         => esc_html__('Arial Black', 'mailoptin'),
            'Book Antiqua'        => esc_html__('Book Antiqua', 'mailoptin'),
            'Comic Sans MS'       => esc_html__('Comic Sans MS', 'mailoptin'),
            'Courier New'         => esc_html__('Courier New', 'mailoptin'),
            'Georgia'             => esc_html__('Georgia', 'mailoptin'),
            'Geneva'              => esc_html__('Geneva', 'mailoptin'),
            'Helvetica'           => esc_html__('Helvetica', 'mailoptin'),
            'Impact'              => esc_html__('Impact', 'mailoptin'),
            'Lucida'              => esc_html__('Lucida', 'mailoptin'),
            'Lucida Console'      => esc_html__('Lucida Console', 'mailoptin'),
            'Lucida Sans Unicode' => esc_html__('Lucida Sans Unicode', 'mailoptin'),
            'Lucida Grande'       => esc_html__('Lucida Grande', 'mailoptin'),
            'Monaco'              => esc_html__('Monaco', 'mailoptin'),
            'Palatino'            => esc_html__('Palatino', 'mailoptin'),
            'Palatino Linotype'   => esc_html__('Palatino Linotype', 'mailoptin'),
            'Tahoma'              => esc_html__('Tahoma', 'mailoptin'),
            'Times New Roman'     => esc_html__('Times New Roman', 'mailoptin'),
            'Trebuchet MS'        => esc_html__('Trebuchet MS', 'mailoptin'),
            'Verdana'             => esc_html__('Verdana', 'mailoptin'),

            'Arvo'              => esc_html__('Arvo', 'mailoptin'),
            'Lato'              => esc_html__('Lato', 'mailoptin'),
            'Lora'              => esc_html__('Lora', 'mailoptin'),
            'Merriweather'      => esc_html__('Merriweather', 'mailoptin'),
            'Merriweather Sans' => esc_html__('Merriweather Sans', 'mailoptin'),
            'Noticia Text'      => esc_html__('Noticia Text', 'mailoptin'),
            'Open Sans'         => esc_html__('Open Sans', 'mailoptin'),
            'Playfair Display'  => esc_html__('Playfair Display', 'mailoptin'),
            'Roboto'            => esc_html__('Roboto', 'mailoptin'),
            'Source Sans Pro'   => esc_html__('Source Sans Pro', 'mailoptin'),
            'Oswald'            => esc_html__('Oswald', 'mailoptin'),
            'Raleway'           => esc_html__('Raleway', 'mailoptin'),
            'Permanent Marker'  => esc_html__('Permanent Marker', 'mailoptin'),
            'Pacifico'          => esc_html__('Pacifico', 'mailoptin'),
        ];

        self::select($name, $setting);
    }

    public static function select($name, $setting)
    {
        $choices = $setting['choices'];

        printf('<select id="%1$s" name="%1$s">', $name);

        foreach ($choices as $value => $label) {
            printf('<option value="%s">%s</option>', $value, $label);
        }

        echo '</select>';
    }

    public static function range($name, $setting)
    {
        echo '<div class="customize-control-mo-range">';
        echo '<div class="control-wrap">';
        printf('<input name="%s" type="range" min="0" max="4096" step="1" value="1200" data-reset_value="1200">', $name);
        printf('<input type="number" min="0" max="4096" step="1" class="mo-range-input" value="1200">');
        echo '<span class="mo-reset-slider"><span class="dashicons dashicons-image-rotate"></span></span>';
        echo '</div>';
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