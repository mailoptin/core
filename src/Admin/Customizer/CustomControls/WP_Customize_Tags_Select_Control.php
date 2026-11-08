<?php

namespace MailOptin\Core\Admin\Customizer\CustomControls;

use MailOptin\Core\Repositories\SegmentsRepository;

/**
 * A searchable multi-select of subscriber tags (Chosen), for settings saved as comma-separated tag IDs
 * like "3,12". A list of checkboxes gets too long once a site has dozens of tags.
 */
class WP_Customize_Tags_Select_Control extends WP_Customize_Chosen_Select_Control
{
    public $type = 'mailoptin_tags_select';

    // Not one of chosen.js's AJAX search types: typing filters the tags already listed.
    public $search_type = 'mo_subscriber_tags';

    public function enqueue()
    {
        parent::enqueue();

        wp_add_inline_script('mailoptin-customizer-chosen-control', self::sync_script());
    }

    /**
     * The select isn't linked to its setting the usual way: WordPress would hand it the saved "3,12"
     * string, which matches no option. Changes are written back as the same comma-separated string.
     */
    protected static function sync_script()
    {
        return "jQuery(document).on('change', '.mo-tags-select', function () {
            var id = this.getAttribute('data-mo-setting'), setting = wp.customize(id);
            if (setting) setting.set((jQuery(this).val() || []).join(','));
        });";
    }

    public function render_content()
    {
        $selected = (array)SegmentsRepository::parse_tag_ids($this->value());
        $setting  = $this->settings['default']->id ?? '';
        ?>
        <label>
            <?php if ( ! empty($this->label)) : ?>
                <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
            <?php endif; ?>
            <select class="mailoptin-chosen mo-tags-select" data-search-type="<?php echo esc_attr($this->search_type); ?>" data-mo-setting="<?php echo esc_attr($setting); ?>" data-placeholder="<?php esc_attr_e('Choose tags…', 'mailoptin'); ?>" multiple>
                <?php foreach ($this->choices as $id => $name) : ?>
                    <option value="<?php echo absint($id); ?>" <?php selected(in_array((int)$id, $selected, true)); ?>><?php echo esc_html($name); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ( ! empty($this->description)) : ?>
            <span class="description customize-control-description"><?php echo wp_kses_post($this->description); ?></span>
        <?php endif;
    }
}
