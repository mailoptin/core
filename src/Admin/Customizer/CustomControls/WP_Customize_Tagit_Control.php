<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls;

use WP_Customize_Control;

class WP_Customize_Tagit_Control extends WP_Customize_Control
{
    public $type = 'mailoptin_tagit';

    public $field_id = 'mo-tagit';

    public $options = [];

    public function enqueue()
    {
        wp_enqueue_script('jquery-ui-core');

        wp_enqueue_script(
            'mailoptin-tagit',
            MAILOPTIN_ASSETS_URL . 'js/customizer-controls/tagit/tag-it.min.js',
            array('jquery', 'jquery-ui-core'),
            false,
            true
        );

        wp_enqueue_script(
            'mailoptin-tagit-init',
            MAILOPTIN_ASSETS_URL . 'js/customizer-controls/tagit/tagit-init.js',
            array('mailoptin-tagit'),
            false,
            true
        );

        wp_enqueue_style(
            'mailoptin-tagit',
            MAILOPTIN_ASSETS_URL . 'js/customizer-controls/tagit/tagit.css',
            []
        );

        wp_enqueue_style(
            'mailoptin-tagit-theme',
            MAILOPTIN_ASSETS_URL . 'js/customizer-controls/tagit/tagit.ui-zendesk.css',
            ['mailoptin-tagit']
        );
    }

    public function render_content()
    {
        ?>
        <label>
            <?php if (!empty($this->label)) : ?>
                <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
            <?php endif; ?>

            <?php if (!empty($this->description)) : ?>
                <span class="description customize-control-description"><?php echo $this->description; ?></span>
            <?php endif; ?>


            <!--            <div id="--><?php //echo $this->input_id;
            ?><!--" data-block-type="ace" data-ace-lang="--><?php //echo $this->language;
            ?><!--" data-ace-theme="--><?php //echo $this->theme;
            ?><!--" style="position:relative;width:100%;height:400px;"></div>-->
            <!---->
            <!--            <textarea id="--><?php //echo $this->input_id;
            ?><!---textarea" --><?php //$this->link();
            ?><!-- style="display:none;">-->
            <!--                --><?php //$this->value();
            ?>
            <!--            </textarea>-->

            <ul data-block-type="tagit" id="<?php echo $this->field_id; ?>" data-tagit-options="<?php echo esc_attr(wp_json_encode($this->options)); ?>">
            </ul>
        </label>
        <?php
    }
}