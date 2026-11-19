<?php

namespace MailOptin\Core\OptinForms\Lightbox;

use MailOptin\Core\OptinForms\AbstractWheelTheme;

/** Stacked wheel: a narrower pop-up, the wheel on top and the form centred under it. */
class WheelStacked extends AbstractWheelTheme
{
    public $optin_form_name = 'Stacked Wheel';

    public static $type = 'lightbox';

    public static $layout = 'stacked';

    protected function template_defaults()
    {
        return ['form_width' => 460, 'headline_font_size_desktop' => 28, 'headline_font_size_tablet' => 26];
    }

    public function optin_form_css()
    {
        $c = $this->scope();

        return $this->shared_css($c) . <<<CSS
$c { display: block; width: 100%; max-width: 460px; text-align: center; box-shadow: 0 24px 64px rgba(0,0,0,.28); }
$c .mo-wheel-side { padding: 30px 24px 14px; }
$c .mo-wheel-dial { max-width: 280px; }
$c .mo-wheel-main { padding: 22px 36px 26px; }
$c .mo-wheel-prize { text-align: left; }
CSS
        . $this->phone_sheet_css($c);
    }
}
