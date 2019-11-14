<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder;

use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements;
use WP_Customize_Control;

class Customizer_Control extends WP_Customize_Control
{
    public $type = 'mailoptin-email-content';

    public $elements;

    public function __construct($manager, $id, $args = array())
    {
        parent::__construct($manager, $id, $args);

        add_action('customize_controls_print_footer_scripts', [$this, 'footer_scripts']);

        new Elements\Init();
    }

    protected function get_element_title($element_type)
    {
        $class = 'MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Elements\\' . ucfirst($element_type);
        /** @var AbstractElement $instance */
        $instance = call_user_func([$class, 'get_instance']);

        return $instance->title();
    }

    /**
     * Enqueue control related scripts/styles.
     *
     * @access public
     */
    public function enqueue()
    {
        wp_enqueue_script('mailoptin-customizer-email-content', MAILOPTIN_ASSETS_URL . 'js/customizer-controls/email-content-control/control.js', array('jquery', 'customize-base'), false, true);
        wp_enqueue_style('mailoptin-customizer-email-content', MAILOPTIN_ASSETS_URL . 'js/customizer-controls/email-content-control/style.css', null);

        wp_enqueue_script('wp-color-picker');
        wp_enqueue_style('wp-color-picker');

        wp_enqueue_script('underscore');

        wp_enqueue_script('jquery-ui-core');
        wp_enqueue_script('jquery-effects-slide');

        wp_enqueue_media();

        wp_localize_script(
            'mailoptin-customizer-email-content',
            'mo_email_content_builder_elements_defaults',
            Misc::elements_default_fields_values()
        );
    }

    public function render_content()
    {
        echo '<div class="mo-email-content-wrapper">';
        echo '<div class="mo-email-content-widget-wrapper">';

        echo '<div id="mo-email-content-element-bars-wrap">';
        // saved elements will be rendered here
        echo '</div>';
        ?>

        <div class="mo-email-content__add_new">
            <button type="button" class="button mo-add-new-email-element">
                <?php _e('Add Element', 'mailoptin') ?>
            </button>
        </div>

        <input id="mo-email-content-save-field" type="hidden" <?php $this->link(); ?>/>

        <?php
        echo '</div>';
        $this->elements_ui();
        echo '</div>';

        $this->element_settings();
    }

    public function footer_scripts()
    {
        ?>
        <script type="text/javascript">
            function mo_email_content_element_get_title(element_type) {
                var title = '';
                if (element_type === 'text') {
                    title = '<?=$this->get_element_title('text')?>';
                }
                if (element_type === 'button') {
                    title = '<?=$this->get_element_title('button')?>';
                }
                if (element_type === 'image') {
                    title = '<?=$this->get_element_title('image')?>';
                }
                if (element_type === 'divider') {
                    title = '<?=$this->get_element_title('divider')?>';
                }
                if (element_type === 'spacer') {
                    title = '<?=$this->get_element_title('spacer')?>';
                }

                return title;
            }

            function mo_email_content_element_get_preview(element_type, settings) {

                var text = '';
                if (element_type === 'text') {
                    text = settings.text_content.substring(0, 50);
                }
                if (element_type === 'button') {
                    text = settings.button_text + ' | ' + settings.button_link;
                }
                if (element_type === 'image') {
                    text = settings.image_url
                }
                if (element_type === 'divider') {
                    text = settings.divider_width + '% | ' + settings.divider_color + ' | ' + settings.divider_style;
                }
                if (element_type === 'spacer') {
                    text = settings.spacer_height + 'px' + ' | ' + settings.spacer_background_color;
                }

                return text;
            }

            function mo_ece_get_field_value(key, obj) {
                return typeof obj !== 'undefined' && typeof obj[key] !== 'undefined' ? obj[key] : '';
            }
        </script>
        <script type="text/html" id="tmpl-mo-email-content-element-bar">
            <div class="mo-email-content-widget mo-email-content-part-widget element-bar" data-element-id="{{data.id}}" data-element-type="{{data.type}}">
                <div class="mo-email-content-widget-top mo-email-content-part-widget-top">
                    <div class="mo-email-content-part-widget-title-action">
                        <button type="button" class="mo-email-content-widget-action" data-element-id="{{data.id}}" data-element-type="{{data.type}}">
                            <span class="toggle-indicator"></span>
                        </button>
                    </div>
                    <div class="mo-email-content-widget-title">
                        <h3>{{ mo_email_content_element_get_title(data.type) }}
                            <span class="mopreview">{{mo_email_content_element_get_preview(data.type, data.settings)}}</span>
                        </h3>
                    </div>
                </div>
            </div>
        </script>

        <?php
    }

    public function element_settings()
    {
        ?>
        <div class="mo-email-content-widget mo-email-content-part-widget mo-email-content-element-settings">
            <div class="mo-email-content-go-back">
                <a href="#">&lt;&lt; <?php esc_html_e('Go Back', 'mailoptin'); ?></a>
            </div>
            <!--   settings fields get appended here by js         -->
        </div>
        <?php
    }

    public function elements_ui()
    {
        $elements = apply_filters('mo_email_content_elements', []);
        ?>
        <div class="mo-email-content-elements-wrapper">
            <div class="mo-email-content-go-back">
                <a href="#">&lt;&lt; <?php esc_html_e('Go Back', 'mailoptin'); ?></a>
            </div>
            <div class="search-form">
                <span class="screen-reader-text"><?php esc_html_e('Search elements..', 'mailoptin'); ?></span>
                <input class="search" type="search" role="search" placeholder="<?php esc_html_e('Search elements..', 'mailoptin'); ?>">
            </div>

            <ul class="list list--secondary" id="items">
                <?php foreach ($elements as $element) : ?>
                    <li class="list__item element element--box mo-email-builder-add-element" data-element-type="<?= $element['id'] ?>">
                        <?php $icon_url = MAILOPTIN_ASSETS_URL . 'images/email-builder-elements/' . $element['icon']; ?>
                        <img src="<?= $icon_url ?>" class="mo-email-content-element-img">
                        <div class="element__wrap">
                            <h3 class="list__label"><?= $element['title'] ?></h3>
                            <div class="element__description"><?= $element['description'] ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }
}