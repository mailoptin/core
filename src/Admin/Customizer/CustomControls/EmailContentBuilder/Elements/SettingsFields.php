<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


class SettingsFields
{
    public static function tinymce($id)
    {
        echo '<div class="mo-email-content-field-tinymce-wrap">';
        // {{{data.%s}}}
        printf('<textarea id="%s" style="height: 280px" class="mo-email-content-field-tinymce">lll</textarea>', $id);
        echo '</div>';
    }

    public static function dimension()
    {
        ?>
        <div class="customize-control-mo-border">
            <div class="mo-border-outer-wrapper">
                <div class="input-wrapper mo-border-wrapper">

                    <ul class="mo-border-wrapper desktop active">
                        <li class="mo-border-input-item-link">
                            <span class="dashicons dashicons-admin-links mo-border-connected wp-ui-highlight" data-element-connect="astra-settings[primary-submenu-border]" title="Link Values Together"></span>
                            <span class="dashicons dashicons-editor-unlink mo-border-disconnected" data-element-connect="astra-settings[primary-submenu-border]" title="Link Values Together"></span>
                        </li>
                        <li class="mo-border-input-item">
                            <input type="number" class="mo-border-input mo-border-desktop" data-id="top" data-name="" value="2" data-element-connect="">
                            <span class="mo-border-title">Top</span>
                        </li>
                        <li class="mo-border-input-item">
                            <input type="number" class="mo-border-input mo-border-desktop" data-id="right" data-name="" value="0" data-element-connect="">
                            <span class="mo-border-title">Right</span>
                        </li>
                        <li class="mo-border-input-item">
                            <input type="number" class="mo-border-input mo-border-desktop" data-id="bottom" data-name="" value="0" data-element-connect="">
                            <span class="mo-border-title">Bottom</span>
                        </li>
                        <li class="mo-border-input-item">
                            <input type="number" class="mo-border-input mo-border-desktop" data-id="left" data-name="" value="0" data-element-connect="">
                            <span class="mo-border-title">Left</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
}