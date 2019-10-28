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
}