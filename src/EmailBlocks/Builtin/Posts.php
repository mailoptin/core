<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Mjml;
use MailOptin\Core\EmailBlocks\Style;

/**
 * Chosen posts, one under another or two or three side by side, drawn from MJML like every other
 * block: each post's title, meta, featured image, excerpt and "read more" link. What a post shows
 * (its link, meta, excerpt, image) comes from the template's post helpers (TemplateTrait).
 */
class Posts extends ElementBlock
{
    protected function element_class()
    {
        return 'MailOptin\\Core\\Admin\\Customizer\\CustomControls\\EmailContentBuilder\\Elements\Posts';
    }

    /** The posts are searched for in the editor (there are too many to list). */
    public function fields()
    {
        $fields = parent::fields();
        if (isset($fields['post_list'])) $fields['post_list']['search'] = 'posts';

        return $fields;
    }

    public function render($settings, $context, Style $style, $id)
    {
        $template = $style->template;
        $posts    = array_values(array_filter(array_map('absint', (array)($settings['post_list'] ?? []))));

        if ( ! $posts || ! $template || ! method_exists($template, 'post_url')) return '';

        $count = min(3, max(1, absint($settings['post_column_count'] ?? 1)));

        if (1 === $count) {
            $html = '';
            foreach ($posts as $post) $html .= $this->post($post, $settings, $style);

            return $html;
        }

        // Side by side, as the Columns block draws them: stacked on phones.
        $columns = new Columns();
        $layout  = ['columns_layout' => 2 === $count ? '50-50' : '33-33-33', 'columns_gap' => 20, 'columns_stack' => true, 'block_padding' => []];
        $widths  = Columns::pixel_widths($layout, $style->width);
        $html    = '';

        foreach (array_chunk($posts, $count) as $row) {
            $cells = [];
            foreach ($row as $i => $post) $cells[$i] = $this->post($post, $settings, $style->with_width($widths[$i]));
            $html .= $columns->render_columns($layout, $cells, '', $style->width);
        }

        return $html;
    }

    /**
     * One post's rows.
     */
    protected function post($post, $settings, Style $style)
    {
        $template = $style->template;

        if ( ! get_post($post)) return '';

        $pad     = Mjml::padding($settings['block_padding'] ?? []);
        $font    = esc_attr($style->font_stack($settings['post_font_family'] ?? ''));
        $text    = esc_attr(Fields::color($style->get('content_text_color')) ?: 'inherit');
        $url     = esc_url($template->post_url($post));
        $title_c = esc_attr(Fields::color($settings['post_title_color'] ?? '') ?: '#2F3133');
        $more_c  = esc_attr(Fields::color($settings['read_more_color'] ?? '') ?: '#3869D4');
        $side    = ['pad_r' => $pad['pad_r'], 'pad_l' => $pad['pad_l']];

        $line = function ($top, $bottom, $size, $height, $color, $content) use ($side, $font) {
            return Mjml::fill('post-text', ['pad_t' => $top, 'pad_b' => $bottom] + $side + [
                    'font_family' => $font, 'font_size' => $size, 'line_height' => $height, 'color' => $color, 'content' => $content,
                ]);
        };

        $html = $line($pad['pad_t'], 4, 22, 30, $title_c, sprintf(
            '<a href="%1$s" style="color:%2$s;text-decoration:none;"><h1 style="margin:0;font-size:22px;line-height:30px;color:%2$s;">%3$s</h1></a>',
            $url, $title_c, esc_html($template->post_title($post))
        ));

        $metas = array_intersect((array)($settings['post_metas'] ?? []), ['author', 'date', 'category']);
        if ($metas) $html .= $line(0, 8, 12, 22, $text, $template->post_meta($post, $metas));

        if (true !== ($settings['remove_feature_image'] ?? false)) {
            $room = max(1, $style->width - $pad['pad_l'] - $pad['pad_r']);
            $html .= Mjml::fill('image-link', ['pad_t' => 8, 'pad_b' => 8] + $side + [
                    'width' => $room,
                    'align' => 'center',
                    'src'   => esc_url($template->feature_image($post, $style->email_campaign_id, (string)($settings['default_image_url'] ?? ''))),
                    'alt'   => esc_attr($template->feature_image_alt($post)),
                    'href'  => $url,
                ]);
        }

        if (true !== ($settings['remove_post_content'] ?? false)) {
            // The post's own HTML, trimmed by the template (as before).
            $html .= $line(8, 8, 14, 24, $text, $template->post_content($post, $settings['post_content_length'] ?? null));

            if (true !== ($settings['remove_read_more_link'] ?? false)) {
                $html .= $line(0, 0, 14, 24, $text, sprintf('<a href="%s" style="color:%s;text-decoration:underline;">%s</a>', $url, $more_c, esc_html($settings['read_more_text'] ?? '')));
            }
        }

        // Space before the next post.
        return $html . Mjml::fill('spacer', ['pad_t' => 0, 'pad_r' => 0, 'pad_b' => $pad['pad_b'], 'pad_l' => 0, 'height' => 25]);
    }
}
