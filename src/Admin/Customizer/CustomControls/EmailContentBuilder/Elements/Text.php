<?php

namespace MailOptin\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;


use MailOptin\Admin\Customizer\CustomControls\EmailContentBuilder\AbstractElement;

class Text extends AbstractElement
{
    public function title()
    {
        return __('Text', 'mailoptin');
    }
}