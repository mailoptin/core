<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


class SettingsFields
{
    public static function tinymce($id)
    {
        // {{{data.%s}}}
        printf('<textarea id="%s" style="height: 300px" class="mo-email-content-field-tinymce">lll</textarea>', $id);
    }
}