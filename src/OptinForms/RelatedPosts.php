<?php

namespace MailOptin\Core\OptinForms;

use MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_Toggle_Control;
use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;
use MailOptin\Core\Repositories\OptinCampaignStat;

/**
 * Related posts in an optin campaign ("Read next"): posts sharing the current post's categories or tags,
 * newest first, without the post being read.
 *
 * A template shows them with the [mo-related-posts] shortcode and lists its class in
 * `mailoptin_related_posts_optin_classes`. Such a campaign:
 * - gets a "Related posts" section in the Customizer;
 * - isn't shown on a page that has no related posts (except in test mode, where admins see why);
 * - counts a click on a post as a conversion in its stats.
 */
class RelatedPosts
{
    const SECTION = 'mo_related_posts_section';

    public function __construct()
    {
        add_filter('mailoptin_show_optin_form_after_targeting', [$this, 'hide_when_nothing_related'], 10, 2);

        add_filter('mo_optin_customizer_sections_ids', [$this, 'active_sections']);
        add_action('mo_optin_before_design_customizer_section', [$this, 'section'], 10, 2);
        add_action('mo_optin_customizer_settings', [$this, 'settings'], 10, 3);
        add_action('mo_optin_after_customizer_controls', [$this, 'controls'], 10, 4);
    }

    /** Template classes that show related posts. */
    public static function optin_classes()
    {
        return (array)apply_filters('mailoptin_related_posts_optin_classes', ['ReadNext']);
    }

    public static function is_related_posts_campaign($optin_campaign_id)
    {
        return in_array(OCR::get_optin_campaign_class($optin_campaign_id), self::optin_classes(), true);
    }

    /** The campaign's settings, with their defaults. */
    public static function options($optin_campaign_id)
    {
        $get = function ($key, $default) use ($optin_campaign_id) {
            return OCR::get_customizer_value($optin_campaign_id, $key, $default);
        };

        $match = (string)$get('related_posts_match', 'both');

        return [
            'count'     => min(3, max(1, absint($get('related_posts_count', 3)) ?: 3)),
            'match'     => in_array($match, ['both', 'category', 'tag'], true) ? $match : 'both',
            'images'    => self::on($get('related_posts_images', true)),
            'meta'      => self::on($get('related_posts_meta', true)),
            'email_box' => self::on($get('related_posts_email_box', false)),
        ];
    }

    protected static function on($value)
    {
        return true === $value || 'true' === $value || '1' === $value || 1 === $value;
    }

    /**
     * Posts related to $post_id: the same post type, sharing a category or tag (or, for other post types,
     * a term of their public taxonomies), published, newest first, without $post_id itself.
     *
     * @return \WP_Post[]
     */
    public static function find($post_id, $count = 3, $match = 'both')
    {
        $post = get_post($post_id);

        if ( ! $post || 'publish' !== $post->post_status) return [];

        $count = min(3, max(1, (int)$count));
        // Any post published, changed or retagged starts afresh.
        $key   = md5(implode('|', [$post->ID, $count, $match, wp_cache_get_last_changed('posts'), wp_cache_get_last_changed('terms')]));
        $ids   = wp_cache_get($key, 'mailoptin_related_posts');

        if (false === $ids) {
            $ids = self::query($post, $count, $match);
            wp_cache_set($key, $ids, 'mailoptin_related_posts', HOUR_IN_SECONDS);
        }

        if ( ! $ids) return [];

        // The posts, their meta and terms, then their images: a few queries, not a few per post.
        _prime_post_caches($ids, true, true);
        $thumbs = array_filter(array_map('get_post_thumbnail_id', $ids));
        if ($thumbs) _prime_post_caches($thumbs, false, true);

        return array_values(array_filter(array_map('get_post', $ids)));
    }

    protected static function query(\WP_Post $post, $count, $match)
    {
        $tax_query = ['relation' => 'OR'];

        foreach (get_object_taxonomies($post->post_type, 'objects') as $taxonomy) {
            if ( ! $taxonomy->public || 'post_format' === $taxonomy->name) continue;
            if ('category' === $match && ! $taxonomy->hierarchical) continue;
            if ('tag' === $match && $taxonomy->hierarchical) continue;

            $terms = wp_get_object_terms($post->ID, $taxonomy->name, ['fields' => 'ids']);
            if (is_wp_error($terms) || ! $terms) continue;

            // "Uncategorized" relates everything to everything.
            if ('category' === $taxonomy->name) $terms = array_diff($terms, [(int)get_option('default_category')]);
            if ( ! $terms) continue;

            $tax_query[] = ['taxonomy' => $taxonomy->name, 'field' => 'term_id', 'terms' => array_values($terms)];
        }

        if (count($tax_query) < 2) return [];

        // WooCommerce products hidden from the shop aren't recommended either.
        if ('product' === $post->post_type && taxonomy_exists('product_visibility')) {
            $tax_query = [
                'relation' => 'AND',
                $tax_query,
                ['taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => ['exclude-from-catalog'], 'operator' => 'NOT IN'],
            ];
        }

        return get_posts([
            'post_type'           => $post->post_type,
            'post_status'         => 'publish',
            'post__not_in'        => [$post->ID],
            'posts_per_page'      => $count,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'tax_query'           => $tax_query,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'has_password'        => false,
            'fields'              => 'ids',
            'suppress_filters'    => false,
        ]);
    }

    /** The post being read, if this page is one. */
    protected static function current_post_id()
    {
        return is_singular() ? (int)get_queried_object_id() : 0;
    }

    /**
     * The posts to show: related to the post being read. In the Customizer preview (not a post), the
     * newest posts stand in so the design can be seen.
     */
    public static function posts_for($optin_campaign_id)
    {
        $options = self::options($optin_campaign_id);
        $post_id = self::current_post_id();

        if ($post_id) return self::find($post_id, $options['count'], $options['match']);

        if (is_customize_preview()) {
            return get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $options['count'], 'has_password' => false, 'ignore_sticky_posts' => true, 'no_found_rows' => true]);
        }

        return [];
    }

    public function hide_when_nothing_related($show, $optin_campaign_id)
    {
        if ( ! $show || ! self::is_related_posts_campaign($optin_campaign_id)) return $show;

        return ! empty(self::posts_for($optin_campaign_id));
    }

    /**
     * The list of posts ([mo-related-posts]).
     *
     * @param AbstractOptinForm $form
     */
    public static function render($form, $atts = [])
    {
        $options = self::options($form->optin_campaign_id);
        $posts   = self::posts_for($form->optin_campaign_id);
        $class   = isset($atts['class']) ? ' ' . esc_attr($atts['class']) : '';

        if ( ! $posts) {
            // Only reached in test mode (or a template outside the campaign rules): say why it's empty.
            return '<p class="mo-related-posts-none' . $class . '">' . esc_html__('No related posts for this page. Visitors only see this campaign on posts that have some.', 'mailoptin') . '</p>';
        }

        $classes = 'mo-related-posts mo-related-posts--count-' . count($posts) . ($options['images'] ? ' mo-related-posts--images' : '') . $class;
        $html    = '<ul class="' . esc_attr($classes) . '">';

        foreach ($posts as $post) {
            $html .= '<li class="mo-related-post"><a class="mo-related-post-link" href="' . esc_url(get_permalink($post)) . '">';

            if ($options['images']) {
                $image = get_the_post_thumbnail($post, 'thumbnail', ['class' => 'mo-related-post-img', 'alt' => '', 'loading' => 'lazy']);
                $html  .= '<span class="mo-related-post-image' . ($image ? '' : ' mo-related-post-image--none') . '" aria-hidden="true">' . $image . '</span>';
            }

            $html .= '<span class="mo-related-post-text"><span class="mo-related-post-title">' . esc_html(wp_strip_all_tags(get_the_title($post))) . '</span>';

            if ($options['meta']) {
                $meta = array_filter([self::first_term($post), get_the_date('', $post)]);
                $html .= '<span class="mo-related-post-meta">' . esc_html(implode(' · ', $meta)) . '</span>';
            }

            $html .= '</span></a></li>';
        }

        return $html . '</ul>';
    }

    /** The post's first category (or first term of its first hierarchical taxonomy), not "Uncategorized". */
    protected static function first_term(\WP_Post $post)
    {
        foreach (get_object_taxonomies($post->post_type, 'objects') as $taxonomy) {
            if ( ! $taxonomy->public || ! $taxonomy->hierarchical) continue;

            $terms = get_the_terms($post, $taxonomy->name);
            if ( ! $terms || is_wp_error($terms)) continue;

            foreach ($terms as $term) {
                if ('category' === $taxonomy->name && (int)$term->term_id === (int)get_option('default_category')) continue;

                return $term->name;
            }
        }

        return '';
    }

    /**
     * A click on a post counts as a conversion: the script that reports it (in the page with the campaign).
     *
     * @param AbstractOptinForm $form
     */
    public static function click_script($form)
    {
        // An admin testing the campaign isn't a reader.
        if (OCR::is_test_mode($form->optin_campaign_id)) return '';

        $uuid = esc_js($form->optin_campaign_uuid);

        return <<<HTML
<script type="text/javascript">
(function () {
    if (window.moRelatedPostsClick && window.moRelatedPostsClick['$uuid']) return;
    window.moRelatedPostsClick = window.moRelatedPostsClick || {};
    window.moRelatedPostsClick['$uuid'] = true;
    document.addEventListener('click', function (e) {
        var link = e.target && e.target.closest ? e.target.closest('#$uuid .mo-related-post-link') : null;
        var g = window.mailoptin_globals;
        // One conversion per page view, however many posts are opened (in new tabs, say).
        if (!link || !g || g.is_customize_preview === 'true' || window.moRelatedPostsClick['$uuid'] === 'sent') return;
        window.moRelatedPostsClick['$uuid'] = 'sent';
        var data = new FormData(), url = String(g.mailoptin_ajaxurl).replace('%%endpoint%%', 'track_optin_click');
        data.append('stat_data[optin_uuid]', '$uuid');
        if (navigator.sendBeacon && navigator.sendBeacon(url, data)) return;
        if (window.fetch) fetch(url, {method: 'POST', body: data, keepalive: true, credentials: 'same-origin'});
    });
})();
</script>
HTML;
    }

    /** The click (from click_script): a conversion for a related-posts campaign. */
    public static function track_click($payload)
    {
        if ( ! is_array($payload) || empty($payload['optin_uuid']) || ! is_string($payload['optin_uuid'])) return false;

        $optin_campaign_id = absint(OCR::get_optin_campaign_id_by_uuid($payload['optin_uuid']));

        if ( ! $optin_campaign_id || ! self::is_related_posts_campaign($optin_campaign_id) || ! OCR::is_activated($optin_campaign_id)) return false;

        // At most one click conversion per visitor (by IP) per campaign every 30 minutes, so the
        // endpoint can't be used to lift a campaign's (or a split-test variant's) conversion rate.
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
        $key = 'mo_rp_click_' . md5($optin_campaign_id . '|' . $ip . '|' . wp_salt('nonce'));
        if (get_transient($key)) return false;
        set_transient($key, 1, apply_filters('mailoptin_related_posts_click_window', 30 * MINUTE_IN_SECONDS));

        (new OptinCampaignStat($optin_campaign_id))->save('conversion');

        do_action('mailoptin_track_click_conversions', $optin_campaign_id, $payload);

        return true;
    }

    // -- Customizer -- //

    protected static function customizer_campaign($customizer)
    {
        return isset($customizer->optin_campaign_id) && self::is_related_posts_campaign($customizer->optin_campaign_id);
    }

    public function active_sections($sections)
    {
        $sections[] = self::SECTION;

        return $sections;
    }

    public function section($wp_customize, $customizer)
    {
        if ( ! self::customizer_campaign($customizer)) return;

        $wp_customize->add_section(self::SECTION, [
            'title'       => __('Related posts', 'mailoptin'),
            'description' => __('Shows only on posts that have related posts, newest first, without the post being read. A click on a post counts as a conversion in this campaign\'s stats.', 'mailoptin'),
            'priority'    => 4,
        ]);
    }

    protected static function setting_args()
    {
        return [
            'related_posts_count'     => ['default' => '3', 'sanitize_callback' => function ($v) { return (string)min(3, max(1, absint($v) ?: 3)); }],
            'related_posts_match'     => ['default' => 'both', 'sanitize_callback' => function ($v) { return in_array($v, ['both', 'category', 'tag'], true) ? $v : 'both'; }],
            'related_posts_images'    => ['default' => true, 'sanitize_callback' => [__CLASS__, 'sanitize_bool']],
            'related_posts_meta'      => ['default' => true, 'sanitize_callback' => [__CLASS__, 'sanitize_bool']],
            'related_posts_email_box' => ['default' => false, 'sanitize_callback' => [__CLASS__, 'sanitize_bool']],
        ];
    }

    public static function sanitize_bool($value)
    {
        return self::on($value);
    }

    public function settings($wp_customize, $option_prefix, $customizer)
    {
        if ( ! self::customizer_campaign($customizer)) return;

        foreach (self::setting_args() as $key => $args) {
            $wp_customize->add_setting($option_prefix . '[' . $key . ']', $args + ['type' => 'option', 'transport' => 'refresh']);
        }
    }

    public function controls($instance, $wp_customize, $option_prefix, $customizer)
    {
        if ( ! self::customizer_campaign($customizer)) return;

        $id = function ($key) use ($option_prefix) { return $option_prefix . '[' . $key . ']'; };

        $wp_customize->add_control($id('related_posts_count'), [
            'type'     => 'select',
            'label'    => __('How many posts', 'mailoptin'),
            'section'  => self::SECTION,
            'settings' => $id('related_posts_count'),
            'choices'  => ['1' => '1', '2' => '2', '3' => '3'],
            'priority' => 10,
        ]);

        $wp_customize->add_control($id('related_posts_match'), [
            'type'        => 'select',
            'label'       => __('Related by', 'mailoptin'),
            'section'     => self::SECTION,
            'settings'    => $id('related_posts_match'),
            'choices'     => [
                'both'     => __('Categories and tags', 'mailoptin'),
                'category' => __('Categories only', 'mailoptin'),
                'tag'      => __('Tags only', 'mailoptin'),
            ],
            'priority'    => 20,
        ]);

        $toggles = [
            'related_posts_images'    => [__('Show images', 'mailoptin'), ''],
            'related_posts_meta'      => [__('Show category and date', 'mailoptin'), ''],
            'related_posts_email_box' => [__('Email box under the list', 'mailoptin'), __('Uses this campaign\'s email list and success message, like any opt-in.', 'mailoptin')],
        ];

        $priority = 30;
        foreach ($toggles as $key => $labels) {
            $wp_customize->add_control(new WP_Customize_Toggle_Control($wp_customize, $id($key), [
                'label'       => $labels[0],
                'description' => $labels[1],
                'section'     => self::SECTION,
                'settings'    => $id($key),
                'type'        => 'light',
                'priority'    => $priority += 10,
            ]));
        }
    }

    public static function get_instance()
    {
        static $instance = null;

        if (null === $instance) $instance = new self();

        return $instance;
    }
}
