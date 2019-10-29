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

    public static function dimension($id)
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
                            <input type="number" class="mo-border-input" data-id="top" value="0" name="<?= $id ?>">
                            <span class="mo-border-title">Top</span>
                        </li>
                        <li class="mo-border-input-item">
                            <input type="number" class="mo-border-input" data-id="right" value="0" name="<?= $id ?>">
                            <span class="mo-border-title">Right</span>
                        </li>
                        <li class="mo-border-input-item">
                            <input type="number" class="mo-border-input" data-id="bottom" value="0" name="<?= $id ?>">
                            <span class="mo-border-title">Bottom</span>
                        </li>
                        <li class="mo-border-input-item">
                            <input type="number" class="mo-border-input" data-id="left" value="0" name="<?= $id ?>">
                            <span class="mo-border-title">Left</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
}