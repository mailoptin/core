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
     * @return array
     *   defaults         Customizer values; digest_defaults: more for digests
     *   heading_font     body_font
     *   header           'center' (name centred, a rule under it) | 'masthead' (large, a double rule) |
     *                    'bar' (a dark bar, name on the left) | 'sender' (a small "from" line in the body)
     *   card             bool: a margin of page colour above and below the email
     *   button_radius    button_width (%), button_upper (bool)
     *   read_more        'button' | 'link' (a text link: the label and an arrow)
     *   meta_style       'upper' | 'italic' | 'plain'
     *   kicker           bool: the post's category above its title, in kicker_color (a colour setting)
     *   list             the 1-column digest: 'side' (image on the left) | 'stack'
     *   lead             bool: the newest post leads, its image across the email and its title on a band
     *                    of the header's colour (new-post emails: the post)
     *   footer_rule      a colour: a rule above the footer
     *   product          bool: a WooCommerce product's price (the regular price crossed out when on sale)
     *   sale_badge       bool: "-18%" above a product's title when on sale
     *   title_upper      bool: titles in capitals
     *   image_width      the new-post email's image width (default: the content's)
     *   offer_strip      bool: a strip of text above the header (its own Customizer setting)
     */
    abstract protected function design();

    protected function design_value($key, $default = null)
    {
        $design = $this->design();

        return $design[$key] ?? $default;
    }

    public function default_customizer_values()
    {
        $defaults = (array)$this->design_value('defaults', []);

        if ($this->is_newsletter()) {
            // A post's settings mean nothing in a broadcast (Letter's opening line, its images off).
            return array_diff_key($defaults, array_flip([
                'content_before_main_content', 'content_after_main_content', 'content_remove_feature_image',
                'content_ellipsis_button_label', 'content_title_font_size',
            ]));
        }

        return $this->is_digest() ? array_merge($defaults, (array)$this->design_value('digest_defaults', [])) : $defaults;
    }

    /**
     * The template's defaults, for this template's emails only. (The parent adds them for every email
     * drawn in the same request: a cron run sending several emails would give one template's
     * defaults, such as Letter's opening line or its images left out, to the others.) Later than
     * Lucid's filters, which apply to every email.
     */
    public function handles_default_customizer_values()
    {
        // Automation emails are drawn as broadcasts: the Customizer gives them the broadcast type.
        $type  = $this->is_digest() ? \MailOptin\Core\Repositories\EmailCampaignRepository::POSTS_EMAIL_DIGEST
            : ($this->is_newsletter() ? \MailOptin\Core\Repositories\EmailCampaignRepository::NEWSLETTER : \MailOptin\Core\Repositories\EmailCampaignRepository::NEW_PUBLISH_POST);
        $class = (new \ReflectionClass($this))->getShortName();

        // Shelf and Market draw the price themselves: no second one in the excerpt (this email only).
        if ($this->design_value('product')) {
            // The Customizer's preview draws a sample post, not a product: show a sample price and sale.
            add_filter('mailoptin_email_product_data_pre', function ($data, $post) {
                if (null !== $data || ! empty($post->ID) || ! is_customize_preview()) return $data;

                $money = function ($amount) {
                    return function_exists('wc_price') ? trim(wp_strip_all_tags(wc_price($amount))) : number_format_i18n($amount, 2);
                };

                return ['price' => $money(11.5), 'regular' => $money(14), 'percent' => 17];
            }, 20, 2);

            $id = (int)$this->email_campaign_id;
            add_filter('mo_email_automation_display_woocommerce_price', function ($show, $email_campaign_id = null) use ($id) {
                return (int)$email_campaign_id === $id ? false : $show;
            }, 10, 2);
        }

        // Market's offer strip: a setting of its own, so its default is added to the email's defaults
        // (the editor and the Customizer then show it, and save it like any other).
        if ($this->design_value('offer_strip')) {
            $strip = (string)$this->design_value('offer_strip_default', '');
            add_filter('mailoptin_template_customizer_defaults', function ($defaults, $old = null, $campaign_type = null, $template_class = null) use ($type, $class, $strip) {
                if ($campaign_type === $type && $template_class === $class) $defaults['offer_strip_text'] = $strip;

                return $defaults;
            }, 20, 4);
        }

        foreach ($this->default_customizer_values() as $tag => $value) {
            add_filter("mailoptin_{$tag}_default", function ($default, $defaults = null, $campaign_type = null, $template_class = null) use ($value, $type, $class) {
                return $campaign_type === $type && $template_class === $class ? $value : $default;
            }, 20, 4);
        }
    }

    /** Broadcasts and automation emails: the blocks go where a post's rows go. */
    protected function is_newsletter()
    {
        return $this instanceof \MailOptin\Core\EmailCampaigns\Newsletter\AbstractTemplate;
    }

    protected function is_digest()
    {
        return $this instanceof \MailOptin\Core\EmailCampaigns\PostsEmailDigest\AbstractTemplate;
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
        if ($this->design_value('offer_strip')) {
            $settings['offer_strip_text'] = ['default' => (string)$this->design_value('offer_strip_default', ''), 'type' => 'option', 'sanitize_callback' => 'sanitize_text_field'];
        }

        return self::refreshed($settings);
    }

    public function customizer_content_settings($settings)
    {
        if ($this->is_digest()) $settings['column_count'] = ['default' => '1', 'type' => 'option'];

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
        if ($this->design_value('offer_strip')) {
            $controls['offer_strip_text'] = [
                'label'       => __('Offer strip', 'mailoptin'),
                'description' => __('A line across the top of the email, such as a delivery offer. Leave empty for none.', 'mailoptin'),
                'section'     => $customizerClassInstance->campaign_header_section_id,
                'settings'    => $option_prefix . '[offer_strip_text]',
                'type'        => 'text',
                'priority'    => 5,
            ];
        }

        return $controls;
    }

    public function customizer_content_controls($controls, $wp_customize, $option_prefix, $customizerClassInstance)
    {
        if ($this->is_digest()) {
            $controls['column_count'] = [
                'label'    => __('Columns', 'mailoptin'),
                'section'  => $customizerClassInstance->campaign_content_section_id,
                'settings' => $option_prefix . '[column_count]',
                'type'     => 'select',
                'choices'  => ['1' => __('One', 'mailoptin'), '2' => __('Two', 'mailoptin'), '3' => __('Three', 'mailoptin')],
                'priority' => 21,
            ];
        }

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
                'text'        => $this->design_value('button_upper') ? '<span style="text-transform:uppercase;letter-spacing:0.06em;">' . $label . '</span>' : $label,
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

    protected function web_version_rows($align = 'center')
    {
        $color       = $this->color('header_web_version_link_color', '#666666');
        $web_version = apply_filters('mo_email_template_view_web_version', '<a class="webversion-label mo-header-web-version-label mo-header-web-version-color" href="{{webversion}}" style="color:' . esc_attr($color) . ';">[mo_header_web_version_link_label]</a>', $this);

        if ('' === trim(wp_strip_all_tags($web_version)) && false === strpos($web_version, '{{webversion}}')) return '';

        return $this->text('<div style="text-align:' . $align . ';">' . $web_version . '</div>', ['size' => 12, 'color' => $color, 'pad_b' => 12]);
    }

    protected function header_rows()
    {
        $color = $this->color('header_text_color', '#333333');
        $style = $this->design_value('header', 'center');

        if ('bar' === $style) {
            return $this->text(
                '<div class="mo-header-text mo-header-text-color" style="font-size:18px;font-weight:800;letter-spacing:0.04em;text-transform:uppercase;color:' . esc_attr($color) . ';">[mo_header_logo_text]</div>',
                ['font' => $this->heading_font(), 'size' => 18, 'color' => $color, 'pad_b' => 0]
            );
        }

        if ('sender' === $style) {
            return $this->text(
                '<div class="mo-header-text mo-sender" style="font-size:14px;color:' . esc_attr($color) . ';">[mo_header_logo_text]</div>',
                ['size' => 14, 'color' => $color, 'pad_b' => 0]
            );
        }

        if ('left' === $style) {
            return $this->text(
                '<div class="mo-header-text mo-header-text-color" style="font-size:20px;font-weight:700;color:' . esc_attr($color) . ';">[mo_header_logo_text]</div>',
                ['font' => $this->heading_font(), 'size' => 20, 'color' => $color, 'pad_b' => 0]
            );
        }

        $name_style = 'masthead' === $style ? 'font-size:34px;font-weight:700;letter-spacing:0.02em;' : 'font-size:26px;font-weight:700;';
        if ($this->design_value('name_upper')) $name_style = 'font-size:26px;font-weight:900;letter-spacing:0.03em;text-transform:uppercase;';

        return $this->web_version_rows() . $this->text(
                '<div class="mo-header-text mo-header-text-color" style="text-align:center;' . $name_style . 'color:' . esc_attr($color) . ';">[mo_header_logo_text]</div>',
                ['font' => $this->heading_font(), 'size' => 26, 'color' => $color, 'pad_b' => 0]
            );
    }

    /** The email's header: its bands. */
    protected function header_html($page, $content)
    {
        $style  = $this->design_value('header', 'center');
        $header = $this->color('header_background_color', $content);

        switch ($style) {
            case 'bar':
                // The web version link sits above the bar, on the page.
                return $this->band($this->web_version_rows(), $page, 0, 0, null, 'mo-header-container')
                    . $this->band($this->header_rows(), $header, 16, 16, 28, 'mo-header-container');
            case 'left':
                return $this->band($this->web_version_rows(), $page, 0, 0, null, 'mo-header-container')
                    . $this->band($this->header_rows(), $header, 18, 18, null, 'mo-header-container')
                    . $this->band($this->divider('#e6e8eb'), $header, 0, 0, 0, 'mo-header-container');
            case 'sender':
                // The web version link sits above the letter, on the page.
                return $this->band($this->web_version_rows('center'), $page, 0, 0, null, 'mo-header-container')
                    . $this->band($this->header_rows(), $content, 34, 0, null, 'mo-header-container');
            case 'masthead':
                return $this->band($this->header_rows(), $header, 26, 14, null, 'mo-header-container')
                    . $this->band($this->divider($this->color('header_text_color', '#333333'), 0, 0, 'double', 3), $header, 0, 0, null, 'mo-header-container');
            default:
                return $this->band($this->header_rows(), $header, 22, 20, null, 'mo-header-container')
                    . $this->band($this->divider('#e6e9ec'), $header, 0, 0, null, 'mo-header-container');
        }
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

        $rule = (string)$this->design_value('footer_rule', '');

        return ('' !== $rule ? $this->divider($rule, 0, 16) : '') . $this->text($html, ['size' => $this->size('footer_font_size', 12), 'color' => $color, 'pad_b' => 0]);
    }

    /** Text an author added before or after the posts in the Customizer. */
    protected function extra_content($key)
    {
        if ($this->is_newsletter()) return '';

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
        $footer  = $this->color('footer_background_color', $page);

        // The offer strip has its own setting: it stays when the header is removed.
        $html = $this->offer_strip_html() . ($this->setting('header_removal') ? '' : $this->header_html($page, $content));

        $html .= $this->lead_html();

        $body = $this->extra_content('content_before_main_content') . $this->body_rows() . $this->extra_content('content_after_main_content');

        // Nothing left for the body (a one-post Spotlight digest with its excerpt and button off): no empty band.
        if ('' !== trim($body)) $html .= $this->band('<tr><td class="mo-content-alignment" style="padding:0;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody>' . $body . '</tbody></table></td></tr>', $content, 'sender' === $this->design_value('header') ? 20 : 28, 20);

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
    .mo-sender img { max-height:40px;width:auto; }
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
        $upper = $this->design_value('title_upper') ? 'text-transform:uppercase;' : '';
        $inner = '<span class="mo-content-headline-color" style="color:' . esc_attr($color) . ';font-size:' . (int)$size . 'px;' . $upper . '">' . $title . '</span>';

        if ($this->show('content_remove_post_link')) {
            $inner = '<a href="' . $url . '" style="color:' . esc_attr($color) . ';text-decoration:none;">' . $inner . '</a>';
        }

        return $this->text('<div style="font-weight:' . ($this->design_value('title_upper') ? 900 : 700) . ';line-height:1.2;text-align:' . $this->alignment() . ';">' . $inner . '</div>', ['font' => $this->heading_font(), 'size' => $size, 'color' => $color, 'pad_b' => 10]);
    }

    protected function meta($meta)
    {
        if ('' === trim($meta) || ! $this->show('content_post_meta_removal')) return '';

        $style = ['upper' => 'text-transform:uppercase;letter-spacing:0.06em;', 'italic' => 'font-style:italic;font-size:14px;'][$this->design_value('meta_style', 'upper')] ?? '';

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

        if ($compact || 'link' === $this->design_value('read_more')) {
            $color = $this->accent();

            return '<tr class="mo-content-remove-ellipsis-button"><td style="padding:0;"><table border="0" cellpadding="0" cellspacing="0" role="presentation" width="100%"><tbody>'
                . $this->text('<div style="text-align:' . $this->alignment() . ';"><a href="' . $url . '" style="color:' . esc_attr($color) . ';font-weight:700;text-decoration:none;">' . $label . ' &rarr;</a></div>', ['font' => $this->body_font(), 'size' => $compact ? 14 : 15, 'color' => $color, 'pad_b' => 4])
                . '</tbody></table></td></tr>';
        }

        return $this->button($url, $label);
    }

    /**
     * The colour for links and labels on the email's body: the button's.
     */
    protected function accent()
    {
        return $this->color('content_ellipsis_button_background_color', '#333333');
    }

    /** The post's category above its title. $name is escaped, or a placeholder. */
    protected function kicker($name, $color = null)
    {
        if ( ! $this->design_value('kicker') || '' === trim($name)) return '';

        return $this->text(
            '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;text-align:' . $this->alignment() . ';">' . $name . '</div>',
            ['font' => 'Helvetica, Arial, sans-serif', 'size' => 11, 'color' => $color ?: $this->accent(), 'pad_b' => 8]
        );
    }

    /* ---------------------------------------------------------------------------------------------
     * Products (Shelf, Market)
     * ------------------------------------------------------------------------------------------ */

    /**
     * The price row: $price, and $regular crossed out when it isn't ''. Values are HTML-safe text or
     * placeholders.
     */
    protected function price_row($price, $regular, $size, $marked = false)
    {
        $color = $this->color('content_headline_color', '#222222');
        $was   = '<span style="font-size:' . max(12, (int)round($size * 0.62)) . 'px;font-weight:400;color:#7a8088;text-decoration:line-through;">' . $regular . '</span>';

        if ($marked) $was = '<!--mo-product-sale-->&nbsp; ' . $was . '<!--/mo-product-sale-->';
        elseif ('' !== $regular) $was = '&nbsp; ' . $was;
        else $was = '';

        return $this->text('<div class="mo-wc-price" style="font-weight:800;text-align:' . $this->alignment() . ';">' . $price . $was . '</div>', ['size' => $size, 'color' => $color, 'pad_b' => 10]);
    }

    /** "-18%" in a dark chip. */
    protected function sale_badge($percent, $size = 14)
    {
        if ( ! $this->design_value('sale_badge')) return '';

        return $this->text(
            '<div style="text-align:' . $this->alignment() . ';"><span style="display:inline-block;background-color:#111111;color:#ffffff;font-weight:900;padding:5px 9px;font-size:' . (int)$size . 'px;">&minus;' . $percent . '%</span></div>',
            ['font' => 'Helvetica, Arial, sans-serif', 'size' => $size, 'color' => '#ffffff', 'pad_b' => 10]
        );
    }

    /** The product's rows for a new-post email, marked so posts and full-price products lose them. */
    protected function marked_badge()
    {
        $badge = $this->sale_badge('{{product.percent_off}}');

        return '' === $badge ? '' : '<!--mo-product--><!--mo-product-sale-->' . $badge . '<!--/mo-product-sale--><!--/mo-product-->';
    }

    protected function marked_price($size)
    {
        if ( ! $this->design_value('product')) return '';

        return '<!--mo-product-->' . $this->price_row('{{product.price}}', '{{product.regular_price}}', $size, true) . '<!--/mo-product-->';
    }

    /** The strip of text above Market's header. */
    protected function offer_strip_html()
    {
        if ( ! $this->design_value('offer_strip')) return '';

        $text = trim((string)$this->setting('offer_strip_text'));

        if ('' === $text) return '';

        return $this->band($this->text(
            '<div style="text-align:center;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;">' . esc_html($text) . '</div>',
            ['font' => 'Helvetica, Arial, sans-serif', 'size' => 13, 'color' => '#ffffff', 'pad_b' => 0]
        ), '#111111', 10, 10);
    }

    /* ---------------------------------------------------------------------------------------------
     * The lead (Spotlight): the image across the email, then the title on a dark band
     * ------------------------------------------------------------------------------------------ */

    /**
     * @param array $p title, url, image, alt, category (placeholders for new-post emails)
     * @param bool $marked whether the image and category may be missing (new-post emails)
     */
    protected function lead_bands($p, $marked)
    {
        $band  = $this->color('header_background_color', '#14171a');
        $title = $this->color('header_text_color', '#ffffff');
        $html  = '';

        if ($this->show('content_remove_feature_image') && ($marked || '' !== (string)$p['image'])) {
            $image = $this->band($this->image($p['image'], $p['alt'], $this->post_href($p['url']), self::$email_width, 0), $band, 0, 0, 0);
            $html  .= $marked ? '<!--mo-feature-image-->' . $image . '<!--/mo-feature-image-->' : $image;
        }

        // On the dark band: kicker_color (Spotlight: the button's yellow text).
        $kicker = $this->kicker($p['category'], $this->color((string)$this->design_value('kicker_color', 'content_ellipsis_button_background_color'), '#f2b705'));
        if ($marked && '' !== $kicker) $kicker = '<!--mo-post-category-->' . $kicker . '<!--/mo-post-category-->';

        $inner = '<span style="color:' . esc_attr($title) . ';font-size:' . ($marked ? 30 : 24) . 'px;">' . $p['title'] . '</span>';
        if ($this->show('content_remove_post_link')) {
            $inner = '<a href="' . $p['url'] . '" style="color:' . esc_attr($title) . ';text-decoration:none;">' . $inner . '</a>';
        }

        $rows = $kicker . $this->text('<div style="font-weight:800;line-height:1.2;">' . $inner . '</div>', ['font' => $this->heading_font(), 'size' => $marked ? 30 : 24, 'color' => $title, 'pad_b' => 0]);

        return $html . $this->band($rows, $band, 22, 24, 28);
    }

    protected function lead_html()
    {
        if ( ! $this->design_value('lead') || $this->is_newsletter()) return '';

        if ( ! $this->is_digest()) {
            return $this->lead_bands([
                'title'    => '{{post.title}}',
                'url'      => '{{post.url}}',
                'image'    => '{{post.feature.image}}',
                'alt'      => '{{post.feature.image.alt}}',
                'category' => '{{post.category}}',
            ], true);
        }

        $posts = (array)$this->posts;
        if (empty($posts)) return '';

        $values = $this->post_values(reset($posts));
        $p      = ['url' => esc_url($values['url']), 'image' => esc_url($values['image']), 'alt' => esc_attr($values['alt'])] + $values;

        return $this->post_item_filters($this->lead_bands($p, false), $values);
    }

    /** The digest's posts drawn in the body: all of them, or those after the lead. */
    protected function body_posts()
    {
        $posts = array_values((array)$this->posts);

        return $this->design_value('lead') ? array_slice($posts, 1) : $posts;
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
        $lead   = (bool)$this->design_value('lead');
        $kicker = $this->kicker('{{post.category}}');
        $rows   = '';

        // Text first (Ledger): the category, title and meta above the image.
        if ('stack' === $this->design_value('list') && ! $lead) {
            $rows .= ('' !== $kicker ? '<!--mo-post-category-->' . $kicker . '<!--/mo-post-category-->' : '');
            $rows .= $this->headline('{{post.title}}', '{{post.url}}', $this->size('content_title_font_size', 26));
            if ($this->has_post_meta()) $rows .= $this->meta('{{post.meta}}');
            $rows .= $this->marked_image('{{post.feature.image}}', '{{post.feature.image.alt}}', $this->post_href('{{post.url}}'), $this->content_width(), 20);
        } elseif ( ! $lead) {
            $rows .= $this->marked_image('{{post.feature.image}}', '{{post.feature.image.alt}}', $this->post_href('{{post.url}}'), min($this->content_width(), (int)$this->design_value('image_width', $this->content_width())), 22);
            $rows .= ('' !== $kicker ? '<!--mo-post-category-->' . $kicker . '<!--/mo-post-category-->' : '');
            if ($this->has_post_meta()) $rows .= $this->meta('{{post.meta}}');
            $rows .= $this->marked_badge();
            $rows .= $this->headline('{{post.title}}', '{{post.url}}', $this->size('content_title_font_size', 26));
            $rows .= $this->marked_price($this->design_value('title_upper') ? 30 : 22);
        } elseif ($this->has_post_meta()) {
            // The lead band has the image and title.
            $rows .= $this->meta('{{post.meta}}');
        }

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
            'category' => esc_html($this->post_category($post)),
            'product' => $this->design_value('product') ? $this->product_data($post) : null,
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

        $rows .= $this->kicker($p['category']);

        if ($columns < 3) $rows .= $this->meta($p['meta']);

        if ( ! empty($p['product']['percent'])) $rows .= $this->sale_badge((int)$p['product']['percent'], 3 === $columns ? 11 : 13);

        $rows .= $this->headline($p['title'], esc_url($p['url']), 3 === $columns ? 16 : $this->size('content_title_font_size', 20));

        if ( ! empty($p['product'])) $rows .= $this->price_row($p['product']['price'], $p['product']['regular'], 3 === $columns ? 15 : 18);

        if ($columns < 3) $rows .= $this->excerpt($p['content'], 1 === $columns ? null : 15);

        if ($this->show('content_remove_ellipsis_button')) $rows .= $this->read_more(esc_url($p['url']), $columns > 1);

        // A gap under each post, so posts stacked on phones don't touch.
        return $rows . $this->spacer(16);
    }

    /** One post across the email: its image on the left (the 1-column digest). */
    protected function digest_list_item($p)
    {
        $has_image = '' !== (string)$p['image'] && $this->show('content_remove_feature_image');

        if ( ! $has_image || 'side' !== $this->design_value('list', 'side')) return $this->digest_card($p, $this->content_width(), 1);

        $image = $this->image(esc_url($p['image']), esc_attr($p['alt']), $this->post_href(esc_url($p['url'])), $this->column_room('33-67', 0), 0);
        $text  = $this->kicker($p['category'])
            . $this->meta($p['meta'])
            . ( ! empty($p['product']['percent']) ? $this->sale_badge((int)$p['product']['percent'], 13) : '')
            . $this->headline($p['title'], esc_url($p['url']), $this->size('content_title_font_size', 20))
            . ( ! empty($p['product']) ? $this->price_row($p['product']['price'], $p['product']['regular'], 18) : '')
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

        // The lead's band has its image and title; its meta, excerpt and button open the body.
        if ($this->design_value('lead') && ! empty($this->posts)) {
            $lead = $this->post_values(reset($this->posts));
            $html = $this->meta($lead['meta']) . $this->excerpt($lead['content'])
                . ($this->show('content_remove_ellipsis_button') ? $this->read_more(esc_url($lead['url'])) : '');

            if ('' !== $html) $rows[] = $this->post_item_filters($html . $this->spacer(8), $lead);
        }

        foreach (array_chunk($this->body_posts(), $count) as $group) {
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

    /* ---------------------------------------------------------------------------------------------
     * Broadcasts and automation emails: the blocks' defaults in this look
     * ------------------------------------------------------------------------------------------ */

    /** The email builder's name for a font stack: Georgia for the serif looks, Helvetica otherwise. */
    protected function builder_font($stack)
    {
        return false !== stripos($stack, 'Georgia') ? 'Georgia' : 'Helvetica';
    }

    /**
     * New blocks start in this look's fonts and colours (the email builder's
     * mo_ecb_elements_default_values filter). The bands are padded, so blocks have no side padding.
     */
    public function email_content_builder_element_defaults($defaults)
    {
        $look    = (array)$this->design_value('defaults', []);
        $accent  = $look['content_ellipsis_button_background_color'] ?? '#333333';
        $on      = $look['content_ellipsis_button_text_color'] ?? '#ffffff';
        $heading = $look['content_headline_color'] ?? '#222222';
        $body    = $this->builder_font($this->body_font());
        $head    = $this->builder_font($this->heading_font());
        $pad     = function ($top, $bottom) { return ['top' => (string)$top, 'bottom' => (string)$bottom, 'right' => '0', 'left' => '0']; };

        $defaults['text']['text_font_family'] = $body;
        $defaults['text']['text_font_size']   = (string)($look['content_body_font_size'] ?? '16');
        $defaults['text']['block_padding']    = $pad(0, 12);

        $defaults['button']['button_font_family']      = $body;
        $defaults['button']['button_background_color'] = $accent;
        $defaults['button']['button_color']            = $on;
        $defaults['button']['button_font_size']        = '16';
        $defaults['button']['button_font_weight']      = '700';
        $defaults['button']['button_border_radius']    = (string)(int)$this->design_value('button_radius', 4);
        $defaults['button']['button_width']            = (string)(int)$this->design_value('button_width', 100);
        $defaults['button']['button_alignment']        = 'center' === ($look['content_alignment'] ?? 'left') ? 'center' : 'left';
        $defaults['button']['block_padding']           = $pad(8, 16);
        $defaults['button']['button_padding']          = ['top' => '13', 'bottom' => '13', 'right' => '26', 'left' => '26'];

        foreach (['divider', 'image', 'spacer'] as $type) $defaults[$type]['block_padding'] = $pad(8, 16);

        $defaults['posts']['post_title_color'] = $heading;
        $defaults['posts']['read_more_color']  = $accent;
        $defaults['posts']['post_font_family'] = $head;
        $defaults['posts']['block_padding']    = $pad(8, 16);

        return $defaults;
    }

    /** Settings only this look has, for the email editor's Design tab (Market's offer strip). */
    public static function extra_design_fields()
    {
        return [];
    }
}
