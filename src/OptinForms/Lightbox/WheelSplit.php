<?php

namespace MailOptin\Core\OptinForms\Lightbox;

use MailOptin\Core\OptinForms\AbstractWheelTheme;

/** Split wheel: the wheel on the left, the form on the right; a panel along the bottom on phones. */
class WheelSplit extends AbstractWheelTheme
{
    public $optin_form_name = 'Split Wheel';

    public static $type = 'lightbox';

    public static $layout = 'split';

    public function optin_form_css()
    {
        $c = $this->scope();

        return $this->shared_css($c) . <<<CSS
$c { display: grid; grid-template-columns: 400px 1fr; width: 100%; max-width: 860px; box-shadow: 0 24px 64px rgba(0,0,0,.28); }
$c .mo-wheel-side { padding: 36px 30px; }
@media only screen and (max-width: 860px) {
    $c { grid-template-columns: 330px 1fr; }
    $c .mo-wheel-main { padding: 36px 28px 24px; }
}
CSS
        . $this->phone_sheet_css($c);
    }
}
