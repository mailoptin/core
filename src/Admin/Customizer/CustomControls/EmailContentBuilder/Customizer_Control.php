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
    }

    public function render_content()
    {
        $collapse_text = __('Collapse all', 'mailoptin');
        $expand_text   = __('Expand all', 'mailoptin');
        echo '<div class="mo-email-content-wrapper">';
        echo '<div class="mo-email-content-widget-wrapper">';
        printf(
            '<div class="mo-email-content-expand-collapse-wrap"><a href="#" class="mo-email-content-expand-collapse-all mo-expand" data-collapse-text="%1$s" data-expand-text="%2$s">%2$s</a></div>',
            $collapse_text, $expand_text
        );
        ?>
        <div class="mo-email-content-widget mo-email-content-part-widget">
            <div class="mo-email-content-widget-top mo-email-content-part-widget-top">
                <div class="mo-email-content-part-widget-title-action">
                    <button type="button" class="mo-email-content-widget-action">
                        <span class="toggle-indicator"></span>
                    </button>
                </div>
                <div class="mo-email-content-widget-title">
                    <h3>Name</h3>
                </div>
            </div>
            <div class="mo-email-content-widget-content">
                <div class="mo-email-content-modal-tabs">
                    <ul class="tabs">
                        <li class="tab is-active">
                            <h3>General</h3>
                        </li>
                        <li class="tab">
                            <h3>Colors</h3>
                        </li>
                        <li class="tab">
                            <h3>Attributes</h3>
                        </li>
                    </ul>
                </div>
                <div class="mo-email-content-widget-form">
                    <div class="mo-email-content-block">
                        <label for="mo_optin_campaign[109][name_field_placeholder]" class="customize-control-title">Title</label>
                        <div class="customize-control-notifications-container"></div>
                        <input id="mo_optin_campaign[109][name_field_placeholder]" type="text" value="Enter your name here..." data-customize-setting-link="mo_optin_campaign[109][name_field_placeholder]">
                    </div>
                </div>
            </div>
        </div>
        <div class="mo-email-content__add_new">
            <button type="button" class="button mo-add-new-email-element">
                <?php _e('Add Element', 'mailoptin') ?>
            </button>
        </div>
        <input class="mo-email-content-save-field" id="<?= '_customize-input-' . $this->id; ?>" type="hidden" <?php $this->link(); ?>/>
        <?php
        echo '</div>';
        $this->elements_ui();
        echo '</div>';
    }

    public function elements_ui()
    {
        new Elements\Init();
        $elements = apply_filters('mo_email_content_elements', []);
        ?>
        <div class="mo-email-content-elements-wrapper">
            <div class="mo-email-content-elements-back">
                <a href="#">&lt;&lt; <?php esc_html_e('Go Back', 'mailoptin'); ?></a>
            </div>
            <div class="search-form">
                <span class="screen-reader-text"><?php esc_html_e('Search elements..', 'mailoptin'); ?></span>
                <input class="search" type="search" role="search" placeholder="<?php esc_html_e('Search elements..', 'mailoptin'); ?>">
            </div>

            <ul class="list list--secondary" id="items">
                <?php foreach ($elements as $element) : ?>
                    <li class="list__item element element--box">
                        <?= $element['icon'] ?>
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