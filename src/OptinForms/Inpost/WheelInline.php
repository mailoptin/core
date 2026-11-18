<?php

namespace MailOptin\Core\OptinForms\Inpost;

use MailOptin\Core\OptinForms\AbstractWheelTheme;

/** In-post wheel: the wheel beside the form in the post; stacked on narrow screens. No close button. */
class WheelInline extends AbstractWheelTheme
{
    public $optin_form_name = 'Inline Wheel';

    public static $type = 'inpost';

    public static $layout = 'inline';

    protected function template_defaults()
    {
        return ['headline_font_size_desktop' => 26, 'headline_font_size_tablet' => 24, 'headline_font_size_mobile' => 22, 'form_width' => 760];
    }

    protected function close_html()
    {
        return '';
    }

    protected function no_thanks_html()
    {
        return '';
    }

    public function optin_form_css()
    {
        $c = $this->scope();

        return $this->shared_css($c) . <<<CSS
$c { display: grid; grid-template-columns: 300px 1fr; width: 100%; max-width: 760px; margin: 24px auto; border: 1px solid rgba(127,127,127,.25); }
$c .mo-wheel-side { padding: 26px 22px; }
$c .mo-wheel-main { padding: 30px 30px 26px; }
@media only screen and (max-width: 640px) {
    $c { grid-template-columns: 1fr; text-align: center; }
    $c .mo-wheel-dial { max-width: 240px; }
    $c .mo-wheel-main { padding: 18px 20px 22px; }
    $c .mo-wheel-prize { text-align: left; }
}
CSS;
    }
}
