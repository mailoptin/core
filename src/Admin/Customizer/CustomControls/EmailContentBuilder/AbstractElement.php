<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder;


use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements\ElementInterface;

abstract class AbstractElement implements ElementInterface
{
    public function __construct()
    {
        add_filter('mo_email_content_elements', [$this, 'define_element']);
        add_action('customize_controls_print_footer_scripts', [$this, 'js_template']);
    }

    public function define_element($elements)
    {
        $elements[] = [
            'id'          => $this->id(),
            'title'       => $this->title(),
            'icon'        => $this->icon(),
            'description' => $this->description(),
            'tabs'        => $this->tabs(),
            'settings'    => $this->settings()
        ];

        return $elements;
    }

    public function js_template()
    {
        printf('<script type="text/html" id="tmpl-mo-email-content-element-%s">', $this->id()); ?>
        <div id="mo-email-content-settings-area">
            <div class="mo-email-content-widget-top mo-email-content-part-widget-top">
                <div class="mo-email-content-widget-title"><h3><?= $this->title() ?></h3></div>
            </div>
            <div class="mo-email-content-widget-content">
                <div class="mo-email-content-modal-tabs">
                    <ul class="tabs">
                        <?php
                        $tabs = $this->tabs();
                        if (is_array($tabs) && ! empty($tabs)) {
                            foreach ($tabs as $key => $label) { ?>
                                <li id="<?= $key ?>" class="tab is-active">
                                    <h3><?= $label ?></h3>
                                </li>
                                <?php
                            }
                        }
                        ?>
                    </ul>
                </div>
                <div class="mo-email-content-widget-form">

                    <?php foreach ($this->settings() as $setting) : ?>
                        <div class="mo-email-content-blocks" <?= ! empty($setting['tab']) ? 'id="' . $setting['tab'] . '"' : '' ?>>
                            <?php if ( ! empty($setting['label'])) : ?>
                                <label for="<?= $setting['id'] ?>" class="customize-control-title"><?= esc_html($setting['id']) ?></label>
                            <?php endif;
                            call_user_func(
                                ['MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements\SettingsFields', $setting['type']],
                                $setting['id']
                            );
                            ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="mo-email-content-footer">
                        <button class="button button-large button-primary">Apply</button>
                    </div>
                </div>
            </div>
        </div>
        <?php echo '</script>';
    }

    public static function get_instance()
    {
        static $instance = false;

        $class = get_called_class();

        if ( ! $instance) {
            $instance = new $class();
        }

        return $instance;
    }
}