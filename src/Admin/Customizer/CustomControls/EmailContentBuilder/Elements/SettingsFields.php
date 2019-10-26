<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


class SettingsFields
{
    public static function tinymce($id)
    {
        // {{{data.%s}}}
        printf('<textarea style="height: 300px" class="mo-email-content-field-tinymce"></textarea>', $id);
    }
}