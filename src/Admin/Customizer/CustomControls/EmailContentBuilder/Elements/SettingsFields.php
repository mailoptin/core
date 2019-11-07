<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


class SettingsFields
{
    public static function tinymce($name, $setting)
    {
        echo '<div class="mo-email-content-field-tinymce-wrap">';
        printf('<textarea id="%1$s" name="%1$s" style="height: 280px" class="mo-email-content-field-tinymce">{{{mo_ece_get_field_value("%1$s", data)}}}</textarea>', $name);
        echo '</div>';
    }

    public static function text($name, $setting)
    {
        printf('<input type="text" name="%1$s" id="%1$s" value="{{mo_ece_get_field_value("%1$s", data)}}">', $name);
    }

    public static function select_image($name, $setting)
    {
        printf('<div class="mo-select-image-field"><input type="text" name="%1$s" id="%1$s" value="{{mo_ece_get_field_value("%1$s", data)}}"></div>', $name);
        printf('<div class="mo-select-image-btn"><a href="#" class="button action">%s</a></div>', esc_html__('Choose Image', 'mailoptin'));
    }

    public static function font_family($name, $setting)
    {
        $setting['choices'] = [
            ''                                        => esc_html__('Select...', 'mailoptin'),
            esc_html__('Standard Fonts', 'mailoptin') => [
                'Arial'               => esc_html__('Arial', 'mailoptin'),
                'Comic Sans MS'       => esc_html__('Comic Sans MS', 'mailoptin'),
                'Courier New'         => esc_html__('Courier New', 'mailoptin'),
                'Georgia'             => esc_html__('Georgia', 'mailoptin'),
                'Helvetica'           => esc_html__('Helvetica', 'mailoptin'),
                'Lucida Sans Unicode' => esc_html__('Lucida', 'mailoptin'),
                'Palatino'            => esc_html__('Palatino', 'mailoptin'),
                'Tahoma'              => esc_html__('Tahoma', 'mailoptin'),
                'Times New Roman'     => esc_html__('Times New Roman', 'mailoptin'),
                'Trebuchet MS'        => esc_html__('Trebuchet MS', 'mailoptin'),
                'Verdana'             => esc_html__('Verdana', 'mailoptin')
            ],
            esc_html__('Custom Fonts', 'mailoptin')   => [
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
            ]
        ];

        self::select($name, $setting);
    }

    public static function select($name, $setting = [])
    {
        $choices = $setting['choices'];

        printf('<select id="%1$s" name="%1$s">', $name);

        foreach ($choices as $key => $value) {
            if (is_array($value)) {
                echo "<optgroup label='$key'>";
                foreach ($value as $key2 => $value2) {
                    printf('<option value="%1$s" <# if(mo_ece_get_field_value("%3$s", data) == "%1$s") { #> selected <# } #>>%2$s</option>', $key2, $value2, $name);
                }
                echo "</optgroup>";
            } else {
                printf('<option value="%1$s" <# if(mo_ece_get_field_value("%3$s", data) == "%1$s") { #> selected <# } #>>%2$s</option>', $key, $value, $name);
            }
        }

        echo '</select>';
    }

    public static function range($name, $setting, $element_type)
    {
        $default     = sprintf('{{mo_email_content_builder_elements_defaults["%s"]["%s"]}}', $element_type, $name);
        echo '<div class="customize-control-mo-range">';
        echo '<div class="control-wrap">';
        printf('<input name="%1$s" type="range" min="0" max="4096" step="1" value="{{mo_ece_get_field_value("%1$s", data)}}" data-reset_value="%2$s">', $name, $default);
        printf('<input name="%1$s" type="number" min="0" max="4096" step="1" class="mo-range-input" value="{{mo_ece_get_field_value("%1$s", data)}}">', $name);
        echo '<span class="mo-reset-slider"><span class="dashicons dashicons-image-rotate"></span></span>';
        echo '</div>';
        echo '</div>';
    }

    public static function color_picker($name, $setting, $element_type)
    {
        $default     = sprintf('{{mo_email_content_builder_elements_defaults["%s"]["%s"]}}', $element_type, $name);
        $saved_value = sprintf('{{mo_ece_get_field_value("%1$s", data)}}', $name);

        printf(
            '<input name="%1$s" class="mo-color-picker-hex" type="text" maxlength="7" value="%2$s" placeholder="%3$s" data-default-color="%3$s"/>',
            $name, $saved_value, $default
        );
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