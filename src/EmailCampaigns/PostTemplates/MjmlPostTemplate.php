<?php

namespace MailOptin\Core\EmailCampaigns\PostTemplates;

use MailOptin\Core\EmailBlocks\Builtin\Columns;
use MailOptin\Core\EmailBlocks\Mjml;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * The post-notification and digest templates built from MJML (Folio and the others): an email made
 * of the email editor's compiled MJML pieces (text, image, button, divider, spacer, columns) inside
 * full-width bands (bin/email-blocks/build.js, "section"). Lucid is unchanged.
 *
 * A template says how it looks in design() and may draw a post its own way; everything else (the
 * Customizer's settings and their defaults, the header, footer, shortcodes and placeholders) is here.
 * The Customizer's settings are the ones Lucid uses, so an email can switch between them.
 *
 * Used by classes extending NewPublishPost's or PostsEmailDigest's AbstractTemplate.
 */
trait MjmlPostTemplate
{
    /** The email's width, and the bands' side padding (posts are drawn in what's left). */
    public static $email_width = 600;

    /**
     * How the template looks.
     *
     * @return array name; defaults (Customizer values); heading_font, body_font; header ('center'|'masthead'|'bar'|'none');
     *               card (bool: the body is a white card on the page colour); button_radius; button_width (%);
     *               meta_upper (bool); link_label (a "Read more →" text link instead of a button when not empty)
     */
    abstract protected function design();

    protected function design_value($key, $default = null)
    {
        $design = $this->design();

        return $design[$key] ?? $default;
    }

    public function default_customizer_values()
    {
        return (array)$this->design_value('defaults', []);
    }

    public function content_width()
    {
        return self::$email_width - 2 * $this->side_padding();
    }

    protected function side_padding()
    {
        return (int)$this->design_value('side_padding', 32);
    }

    /* ---------------------------------------------------------------------------------------------
     * Customizer: Lucid's settings. The preview is redrawn on every change (refresh), as the
     * colours are set on the email's elements rather than through classes the preview script updates.
     * ------------------------------------------------------------------------------------------ */

    protected static function refreshed($settings)
    {
        foreach ($settings as $key => $setting) {
            if (is_array($setting)) $settings[$key]['transport'] = 'refresh';
        }

        return $settings;
    }

    public function customizer_page_settings($settings)
    {
        return self::refreshed($settings);
    }

    public function customizer_header_settings($settings)
    {
        return self::refreshed($settings);
    }

    public function customizer_content_settings($settings)
    {
        return self::refreshed($settings);
    }

    public function customizer_footer_settings($settings)
    {
        return self::refreshed($settings);
    }

    public function customizer_page_controls($controls, $wp_customize, $option_prefix, $customizerClassInstance)
    {
        return $controls;
    }

    public function customizer_header_controls($controls, $wp_customize, $option_prefix, $customizerClassInstance)
    {
        return $controls;
    }

    public function customizer_content_controls($controls, $wp_customize, $option_prefix, $customizerClassInstance)
    {
        return $controls;
    }

    public function customizer_footer_controls($controls, $wp_customize, $option_prefix, $customizerClassInstance)
    {
        return $controls;
    }

    public function get_script()
    {
    }

    /* ---------------------------------------------------------------------------------------------
     * Pieces
     * ------------------------------------------------------------------------------------------ */

    /** @var array Customizer values already looked up for this email. */
    protected $mjml_settings = [];

    /** A Customizer value, looked up once per email (each lookup costs database queries). */
    protected function setting($key)
    {
        if ( ! array_key_exists($key, $this->mjml_settings)) {
            $this->mjml_settings[$key] = ER::get_merged_customizer_value($this->email_campaign_id, $key);
        }

        return $this->mjml_settings[$key];
    }

    /** The Customizer's "Content alignment" for the post's text and button. */
    protected function alignment()
    {
        $align = (string)$this->setting('content_alignment');

        return in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    }

    /** The logo's size in the Customizer's cropper: fits the header. */
    protected function register_logo_size()
    {
        add_filter('mailoptin_template_customizer_header_logo_args', function ($args) {
            $args['width']  = 300;
            $args['height'] = 80;

            return $args;
        });
    }

    /** A colour setting, or the fallback when unset or not a colour. */
    protected function color($key, $fallback = 'transparent')
    {
        $value = sanitize_hex_color((string)$this->setting($key));

        if ( ! $value) {
            // Not a colour: the template's own.
            $defaults = (array)$this->design_value('defaults', []);
            $value    = sanitize_hex_color((string)($defaults[$key] ?? '')) ?: $fallback;
        }

        return $value;
    }

    protected function size($key, $fallback)
    {
        $value = absint($this->setting($key));

        return $value ?: $fallback;
    }

    protected function heading_font()
    {
        return (string)$this->design_value('heading_font', 'Helvetica, Arial, sans-serif');
    }

    protected function body_font()
    {
        return (string)$this->design_value('body_font', 'Helvetica, Arial, sans-serif');
    }

    /**
     * A band across the email: its own background, padding and rows.
     */
    protected function band($rows, $background, $pad_t, $pad_b, $pad_x = null, $class = '')
    {
        if ('' === trim($rows)) return '';

        $pad_x = null === $pad_x ? $this->side_padding() : $pad_x;

        $html = Mjml::fill('section', [
            'container'   => self::$email_width,
            'pad_t'       => $pad_t,
            'pad_r'       => $pad_x,
            'pad_b'       => $pad_b,
            'pad_l'       => $pad_x,
            'inner_width' => self::$email_width - 2 * $pad_x,
            'background'  => esc_attr($background),
            'rows'        => $rows,
        ]);

        // Hidden by the Customizer's "remove header/footer" switches through these classes.
        return '' === $class ? $html : '<div class="' . esc_attr($class) . '">' . $html . '</div>';
    }

    /**
     * A row of text. $html is used as given (shortcodes and placeholders included).
     */
    protected function text($html, $args = [])
    {
        $args = array_merge(['size' => 16, 'color' => 'inherit', 'font' => $this->body_font(), 'pad_t' => 0, 'pad_b' => 12, 'line' => 0], $args);

        $template = $args['line'] ? 'post-text' : 'text';

        return Mjml::fill($template, [
            'pad_t'       => $args['pad_t'],
            'pad_r'       => 0,
            'pad_b'       => $args['pad_b'],
            'pad_l'       => 0,
            'font_family' => esc_attr($args['font']),
            'font_size'   => $args['size'],
            'line_height' => $args['line'],
            'color'       => esc_attr($args['color']),
            'content'     => $html,
        ]);
    }

    protected function image($src, $alt, $href, $width, $pad_b = 16)
    {
        return Mjml::fill('' === $href ? 'image' : 'image-link', [
            'pad_t' => 0,
            'pad_r' => 0,
            'pad_b' => $pad_b,
            'pad_l' => 0,
            'width' => $width,
            'align' => 'center',
            'src'   => $src,
            'alt'   => $alt,
            'href'  => $href,
        ]);
    }

    protected function button($href, $label, $args = [])
    {
        $args = array_merge(['width' => (int)$this->design_value('button_width', 100), 'align' => $this->alignment(), 'size' => 16, 'pad_t' => 8, 'pad_b' => 8], $args);

        // Hidden by the Customizer's "remove read more button" switch through this class.
        return '<tr class="mo-content-remove-ellipsis-button"><td style="padding:0;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody>'
            . Mjml::fill('button', [
                'pad_t'       => $args['pad_t'],
                'pad_r'       => 0,
                'pad_b'       => $args['pad_b'],
                'pad_l'       => 0,
                'in_t'        => 13,
                'in_r'        => 26,
                'in_b'        => 13,
                'in_l'        => 26,
                'width'       => $args['width'],
                'align'       => $args['align'],
                'href'        => $href,
                'background'  => esc_attr($this->color('content_ellipsis_button_background_color', '#333333')),
                'color'       => esc_attr($this->color('content_ellipsis_button_text_color', '#ffffff')),
                'font_family' => esc_attr($this->body_font()),
                'font_size'   => $args['size'],
                'font_weight' => 'bold',
                'radius'      => (int)$this->design_value('button_radius', 4),
                'text'        => $label,
            ])
            . '</tbody></table></td></tr>';
    }

    protected function divider($color, $pad_t = 0, $pad_b = 0, $style = 'solid', $thickness = 1, $width = 100)
    {
        return Mjml::fill('divider', [
            'pad_t'         => $pad_t,
            'pad_r'         => 0,
            'pad_b'         => $pad_b,
            'pad_l'         => 0,
            'width'         => $width,
            'thickness'     => $thickness,
            'style'         => $style,
            'color'         => esc_attr($color),
            'outlook_width' => Mjml::number($this->content_width() * $width / 100),
        ]);
    }

    protected function spacer($height)
    {
        return Mjml::fill('spacer', ['pad_t' => 0, 'pad_r' => 0, 'pad_b' => 0, 'pad_l' => 0, 'height' => $height]);
    }

    /**
     * Rows side by side ("50-50", "33-67", "33-33-33"), stacked on phones.
     *
     * @param string[] $columns each column's rows
     */
    protected function columns($layout, $columns, $gap = 24, $valign = 'top')
    {
        return (new Columns())->render_columns([
            'columns_layout' => $layout,
            'columns_valign' => $valign,
            'columns_gap'    => $gap,
            'columns_stack'  => true,
            'block_padding'  => [],
        ], $columns, '', $this->content_width());
    }

    /** The width each column of a layout leaves for its content. */
    protected function column_room($layout, $index, $gap = 24)
    {
        $share = Columns::pixel_widths(['columns_layout' => $layout, 'columns_gap' => $gap], $this->content_width());

        return (int)floor($share[$index] ?? $this->content_width());
    }

    /* ---------------------------------------------------------------------------------------------
     * The email
     * ------------------------------------------------------------------------------------------ */

    protected function header_rows()
    {
        $web_version = apply_filters('mo_email_template_view_web_version', '<a class="webversion-label mo-header-web-version-label mo-header-web-version-color" href="{{webversion}}" style="color:' . esc_attr($this->color('header_web_version_link_color', '#666666')) . ';">[mo_header_web_version_link_label]</a>', $this);

        $rows = '' !== trim(wp_strip_all_tags($web_version)) || false !== strpos($web_version, '{{webversion}}')
            ? $this->text('<div style="text-align:center;">' . $web_version . '</div>', ['size' => 12, 'color' => $this->color('header_web_version_link_color', '#666666'), 'pad_b' => 12])
            : '';

        $name_style = 'masthead' === $this->design_value('header')
            ? 'font-size:34px;font-weight:700;letter-spacing:0.02em;'
            : 'font-size:26px;font-weight:700;';

        return $rows . $this->text(
                '<div class="mo-header-text mo-header-text-color" style="text-align:center;' . $name_style . 'color:' . esc_attr($this->color('header_text_color', '#333333')) . ';">[mo_header_logo_text]</div>',
                ['font' => $this->heading_font(), 'size' => 26, 'color' => $this->color('header_text_color', '#333333'), 'pad_b' => 0]
            );
    }

    protected function footer_rows()
    {
        $color = $this->color('footer_text_color', '#666666');
        $link  = apply_filters('mo_email_template_unsubscribe_link', '<a class="unsubscribe mo-footer-unsubscribe-link-label mo-footer-unsubscribe-link-color" href="{{unsubscribe}}" style="color:' . esc_attr($this->color('footer_unsubscribe_link_color', $color)) . ';">[mo_footer_unsubscribe_link_label]</a>');

        $html = '<div class="mo-footer-text-color" style="text-align:center;line-height:1.6;">'
            . '<p class="mo-footer-copyright-line" style="margin:0 0 8px;">[mo_footer_copyright_line]</p>'
            . '<p class="mo-footer-description" style="margin:0 0 8px;">[mo_footer_description]</p>'
            . '<p style="margin:0;"><span class="unsubscribe-line mo-footer-unsubscribe-line">[mo_footer_unsubscribe_line]</span> ' . $link . '.</p>'
            . '</div>';

        return $this->text($html, ['size' => $this->size('footer_font_size', 12), 'color' => $color, 'pad_b' => 0]);
    }

    /** Text an author added before or after the posts in the Customizer. */
    protected function extra_content($key)
    {
        $html = (string)$this->setting($key);

        if ('' === trim($html)) return '';

        $class = 'content_before_main_content' === $key ? 'mo-before-main-content' : 'mo-after-main-content';

        return $this->text('<div class="' . $class . '">' . $html . '</div>', ['color' => $this->color('content_text_color', '#444444'), 'size' => $this->size('content_body_font_size', 16), 'pad_b' => 16]);
    }

    /**
     * The rows of the email's body: the post (new-post emails) or posts (digests).
     */
    abstract protected function body_rows();

    public function get_body()
    {
        $page    = $this->color('page_background_color', '#f2f4f6');
        $content = $this->color('content_background_color', '#ffffff');
        $header  = $this->color('header_background_color', $content);
        $footer  = $this->color('footer_background_color', $page);

        $html = '';

        if ('none' !== $this->design_value('header') && ! $this->setting('header_removal')) {
            $html .= $this->band($this->header_rows(), $header, 'masthead' === $this->design_value('header') ? 26 : 22, 20, null, 'mo-header-container');

            // The rule under the header.
            $rule = 'masthead' === $this->design_value('header') ? $this->divider($this->color('header_text_color', '#333333'), 0, 0, 'double', 3) : $this->divider('#e6e9ec');
            $html .= $this->band($rule, $header, 0, 0);
        }

        $body = $this->extra_content('content_before_main_content') . $this->body_rows() . $this->extra_content('content_after_main_content');

        $html .= $this->band('<tr><td class="mo-content-alignment" style="padding:0;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody>' . $body . '</tbody></table></td></tr>', $content, 28, 20);

        if ( ! $this->setting('footer_removal')) {
            $html .= $this->band($this->footer_rows(), $footer, 22, 26, null, 'mo-footer-container');
        }

        $outer = '<div class="mo-page-bg-color" style="background-color:' . esc_attr($page) . ';padding:' . ($this->design_value('card', true) ? '24px 0' : '0') . ';">' . $html . '</div>';

        return apply_filters('mailoptin_mjml_post_template_body', $outer, $this);
    }

    /**
     * MJML's page reset and its rules for phones (from its <head>); the columns' widths are added to
     * the email by the Templatify classes after inlining.
     */
    public function get_styles()
    {
        $font = $this->body_font();

        return <<<CSS
    #outlook a { padding:0; }
    body { margin:0;padding:0;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;font-family:$font; }
    table, td { border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt; }
    img { border:0;height:auto;line-height:100%;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic; }
    p { display:block;margin:13px 0; }
    a { text-decoration:none; }
    .mo-post-meta { font-size:12px;line-height:1.5; }
    .mo-header-text img { max-width:100%;height:auto; }
    .mo-wc-price ins { text-decoration:none; }
    .mo-wc-price .screen-reader-text { display:none; }
    @media only screen and (max-width:480px) {
      table.mj-full-width-mobile { width: 100% !important; }
      td.mj-full-width-mobile { width: auto !important; }
    }
CSS;
    }

    /* ---------------------------------------------------------------------------------------------
     * A post
     * ------------------------------------------------------------------------------------------ */

    protected function show($switch)
    {
        return ! $this->setting($switch);
    }

    protected function headline($title, $url, $size)
    {
        $color = $this->color('content_headline_color', '#222222');
        // The size is set here, not through the Customizer's size class: digests draw smaller titles in columns.
        $inner = '<span class="mo-content-headline-color" style="color:' . esc_attr($color) . ';font-size:' . (int)$size . 'px;">' . $title . '</span>';

        if ($this->show('content_remove_post_link')) {
            $inner = '<a href="' . $url . '" style="color:' . esc_attr($color) . ';text-decoration:none;">' . $inner . '</a>';
        }

        return $this->text('<div style="font-weight:700;line-height:1.25;text-align:' . $this->alignment() . ';">' . $inner . '</div>', ['font' => $this->heading_font(), 'size' => $size, 'color' => $color, 'pad_b' => 10]);
    }

    protected function meta($meta)
    {
        if ('' === trim($meta) || ! $this->show('content_post_meta_removal')) return '';

        $style = $this->design_value('meta_upper', true) ? 'text-transform:uppercase;letter-spacing:0.06em;' : '';

        return $this->text('<div class="mo-post-meta" style="' . $style . 'text-align:' . $this->alignment() . ';">' . $meta . '</div>', ['size' => 12, 'color' => $this->color('footer_text_color', '#666666'), 'pad_b' => 8]);
    }

    protected function excerpt($content, $size = null)
    {
        if ( ! $this->show('content_remove_post_body')) return '';

        $size = $size ?: $this->size('content_body_font_size', 16);

        return $this->text('<div class="mo-content-text-color" style="line-height:1.6;font-size:' . (int)$size . 'px;text-align:' . $this->alignment() . ';">' . $content . '</div>', ['size' => $size, 'color' => $this->color('content_text_color', '#444444'), 'pad_b' => 12]);
    }

    /** The post's "read more": a button, or a text link for templates that use one. */
    protected function read_more($url, $compact = false)
    {
        $label = esc_html((string)$this->setting('content_ellipsis_button_label'));
        $link  = (string)$this->design_value('link_label', '');

        if ($compact || '' !== $link) {
            $color = $this->color('content_ellipsis_button_background_color', '#333333');

            return '<tr class="mo-content-remove-ellipsis-button"><td style="padding:0;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody>'
                . $this->text('<div style="text-align:' . $this->alignment() . ';"><a href="' . $url . '" style="color:' . esc_attr($color) . ';font-weight:700;text-decoration:none;">' . ('' !== $link ? esc_html($link) : $label . ' &rarr;') . '</a></div>', ['size' => 14, 'color' => $color, 'pad_b' => 4])
                . '</tbody></table></td></tr>';
        }

        return $this->button($url, $label);
    }

    /* ---------------------------------------------------------------------------------------------
     * New-post emails: the post as the placeholders New Post's Templatify fills in
     * ------------------------------------------------------------------------------------------ */

    protected function has_post_meta()
    {
        return ! empty($this->setting('content_post_meta'));
    }

    /** The post's image, marked so it is left out when the post has none. */
    protected function marked_image($src, $alt, $href, $width, $pad_b = 20)
    {
        if ( ! $this->show('content_remove_feature_image')) return '';

        return '<!--mo-feature-image-->' . $this->image($src, $alt, $href, $width, $pad_b) . '<!--/mo-feature-image-->';
    }

    protected function post_href($url)
    {
        return $this->show('content_remove_post_link') ? $url : '';
    }

    protected function new_post_rows()
    {
        $rows = $this->marked_image('{{post.feature.image}}', '{{post.feature.image.alt}}', $this->post_href('{{post.url}}'), $this->content_width(), 22);

        if ($this->has_post_meta()) $rows .= $this->meta('{{post.meta}}');

        $rows .= $this->headline('{{post.title}}', '{{post.url}}', $this->size('content_title_font_size', 26));
        $rows .= $this->excerpt('{{post.content}}');

        if ($this->show('content_remove_ellipsis_button')) $rows .= $this->read_more('{{post.url}}');

        return $rows;
    }

    /* ---------------------------------------------------------------------------------------------
     * Digests: each post drawn here, with the same filters Lucid's posts go through
     * ------------------------------------------------------------------------------------------ */

    public function single_post_item()
    {
        return '';
    }

    public function delimiter()
    {
        return '';
    }

    public function column_count()
    {
        $count = (int)$this->setting('column_count');

        return $count >= 1 && $count <= 3 ? $count : 1;
    }

    /**
     * A post's title, excerpt, image, alt text, link and meta, as the digest's filters leave them.
     */
    protected function post_values($post)
    {
        $id = $this->email_campaign_id;

        return [
            'title' => apply_filters('mo_posts_email_digest_post_title', $this->post_title($post), $post, $id),
            'content' => apply_filters('mo_posts_email_digest_post_content', $this->post_content($post), $post, $id),
            'image' => apply_filters('mo_posts_email_digest_post_feature_image', $this->feature_image($post), $post, $id),
            'alt' => apply_filters('mo_posts_email_digest_post_feature_image_alt', $this->feature_image_alt($post), $post, $id),
            'url' => apply_filters('mo_posts_email_digest_post_url', $this->post_url($post), $post, $id),
            'meta' => (string)apply_filters('mo_posts_email_digest_post_meta', $this->post_meta($post), $post, $id),
            'post' => $post,
        ];
    }

    /**
     * The hooks Lucid's posts go through (parsed_post_list), so placeholders and changes added by
     * other code reach Folio's posts too.
     */
    protected function post_item_filters($html, $p)
    {
        $post = $p['post'];
        $id   = $this->email_campaign_id;

        $search  = apply_filters('mo_email_campaign_ped_search_args', ['{{post.title}}', '{{post.content}}', '{{post.feature.image}}', '{{post.feature.image.alt}}', '{{post.url}}', '{{post.meta}}'], $post, $id, $this);
        $replace = apply_filters('mo_email_campaign_ped_replace_args', [$p['title'], $p['content'], $p['image'], $p['alt'], $p['url'], $p['meta']], $post, $id, $this);

        return apply_filters('mo_email_campaign_ped_single_post_item', str_replace($search, $replace, $html), $post, $id, $this);
    }

    /** One post in a column $room pixels wide, with $columns posts to a row. */
    protected function digest_card($p, $room, $columns)
    {
        $rows = '';

        if ('' !== (string)$p['image'] && $this->show('content_remove_feature_image')) {
            $rows .= $this->image(esc_url($p['image']), esc_attr($p['alt']), $this->post_href(esc_url($p['url'])), $room, 14);
        }

        if ($columns < 3) $rows .= $this->meta($p['meta']);

        $rows .= $this->headline($p['title'], esc_url($p['url']), 3 === $columns ? 16 : $this->size('content_title_font_size', 20));

        if ($columns < 3) $rows .= $this->excerpt($p['content'], 1 === $columns ? null : 15);

        if ($this->show('content_remove_ellipsis_button')) $rows .= $this->read_more(esc_url($p['url']), $columns > 1);

        // A gap under each post, so posts stacked on phones don't touch.
        return $rows . $this->spacer(16);
    }

    /** One post across the email: its image on the left (the 1-column digest). */
    protected function digest_list_item($p)
    {
        $has_image = '' !== (string)$p['image'] && $this->show('content_remove_feature_image');

        if ( ! $has_image) return $this->digest_card($p, $this->content_width(), 1);

        $image = $this->image(esc_url($p['image']), esc_attr($p['alt']), $this->post_href(esc_url($p['url'])), $this->column_room('33-67', 0), 0);
        $text  = $this->meta($p['meta'])
            . $this->headline($p['title'], esc_url($p['url']), $this->size('content_title_font_size', 20))
            . $this->excerpt($p['content'], 15)
            . ($this->show('content_remove_ellipsis_button') ? $this->read_more(esc_url($p['url']), true) : '')
            . $this->spacer(16);

        return $this->columns('33-67', [$image, $text]);
    }

    protected function digest_rows()
    {
        $count   = $this->column_count();
        $layout  = [2 => '50-50', 3 => '33-33-33'][$count] ?? '';
        $rows    = [];

        foreach (array_chunk((array)$this->posts, $count) as $group) {
            $values = array_map([$this, 'post_values'], $group);

            if (1 === $count) {
                $rows[] = $this->post_item_filters($this->digest_list_item($values[0]), $values[0]);
                continue;
            }

            $columns = [];
            foreach ($values as $i => $p) {
                $columns[] = $this->post_item_filters($this->digest_card($p, $this->column_room($layout, $i), $count), $p);
            }

            $rows[] = $this->columns($layout, $columns);
        }

        return implode($this->divider('#e6e9ec', 0, 24), $rows);
    }
}
