<?php

namespace MailOptin\Core\EmailCampaigns;

use WP_Post;

/**
 * The details that can show with each post in post emails (New Post, Digest and the newsletter Posts block):
 * author, date, terms, comment count, reading time, custom fields and, with WooCommerce, product details.
 *
 * Each detail has a key, saved in the email's settings as a comma-separated list in the order chosen:
 * - author, date, category, tags, comments, reading_time
 * - tax:<taxonomy>  any other taxonomy, such as tax:product_cat
 * - woo:price, woo:rating, woo:stock, woo:sku
 * - meta:<meta key> a custom field, shown as text
 */
class PostMetaFields
{
    const POSITION_BELOW_TITLE = 'below_title';
    const POSITION_BELOW_IMAGE = 'below_image';

    /** Settings key for where the details show, for New Post and Digest emails. */
    const POSITION_SETTING = 'content_post_meta_position';

    /**
     * Every detail that can be chosen, key => label, in the order they're offered.
     *
     * @param string|string[] $post_types limit taxonomies to these post types; empty for all public ones
     *
     * @return array
     */
    public static function choices($post_types = [])
    {
        $choices = [
            'author'       => __('Author', 'mailoptin'),
            'date'         => __('Date', 'mailoptin'),
            'category'     => __('Categories', 'mailoptin'),
            'tags'         => __('Tags', 'mailoptin'),
            'comments'     => __('Comment count', 'mailoptin'),
            'reading_time' => __('Reading time', 'mailoptin'),
        ];

        foreach (self::taxonomies($post_types) as $taxonomy => $label) {
            $choices['tax:' . $taxonomy] = $label;
        }

        if (self::is_woocommerce_active()) {
            $choices['woo:price']  = __('Price (WooCommerce)', 'mailoptin');
            $choices['woo:rating'] = __('Star rating (WooCommerce)', 'mailoptin');
            $choices['woo:stock']  = __('Stock status (WooCommerce)', 'mailoptin');
            $choices['woo:sku']    = __('SKU (WooCommerce)', 'mailoptin');
        }

        return apply_filters('mailoptin_post_meta_choices', $choices, $post_types);
    }

    /**
     * Public taxonomies other than the blog's categories and tags, which have their own choices.
     *
     * @return array taxonomy => label
     */
    public static function taxonomies($post_types = [])
    {
        $post_types = array_filter((array)$post_types);

        if (empty($post_types)) {
            $post_types = get_post_types(['public' => true]);
        }

        $taxonomies = [];

        foreach ($post_types as $post_type) {
            foreach (get_object_taxonomies($post_type, 'objects') as $taxonomy) {
                // Categories and tags have their own choices; shipping classes and visibility mean nothing to readers.
                if ( ! $taxonomy->public || in_array($taxonomy->name, ['category', 'post_tag', 'post_format', 'product_shipping_class', 'product_visibility'], true)) continue;

                $label = $taxonomy->labels->name;

                // Product categories and tags read better with where they come from.
                if (in_array($taxonomy->name, ['product_cat', 'product_tag'], true)) {
                    $label = sprintf(__('%s (WooCommerce)', 'mailoptin'), $label);
                }

                $taxonomies[$taxonomy->name] = $label;
            }
        }

        return $taxonomies;
    }

    public static function is_woocommerce_active()
    {
        return function_exists('wc_get_product');
    }

    /**
     * The label for a key, including custom fields and taxonomies that no longer exist.
     */
    public static function label($key, $choices = null)
    {
        $choices = null === $choices ? self::choices() : $choices;

        if (isset($choices[$key])) return $choices[$key];

        if (0 === strpos($key, 'meta:')) {
            return sprintf(__('Custom field: %s', 'mailoptin'), substr($key, 5));
        }

        return $key;
    }

    /**
     * Clean a saved or posted list of keys, keeping their order.
     *
     * @param string|array $keys
     *
     * @return string[]
     */
    public static function parse($keys)
    {
        if ( ! is_array($keys)) $keys = explode(',', (string)$keys);

        $clean = [];

        foreach ($keys as $key) {
            if ( ! is_scalar($key)) continue;

            $key = trim((string)$key);

            if (preg_match('/^(tax|meta|woo):(.+)$/', $key, $m)) {
                $name = 'meta' === $m[1] ? self::clean_meta_key($m[2]) : sanitize_key($m[2]);
                $key  = '' === $name ? '' : $m[1] . ':' . $name;
            } elseif (false !== strpos($key, ':')) {
                $key = ''; // "meta:" with no field, or an unknown kind
            } else {
                $key = sanitize_key($key);
            }

            if ('' !== $key && ! in_array($key, $clean, true)) $clean[] = $key;
        }

        return $clean;
    }

    /**
     * Meta keys keep their case (ACF fields, _price), but nothing that would break the comma-separated list.
     */
    public static function clean_meta_key($key)
    {
        return substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$key), 0, 100);
    }

    /**
     * Custom field keys used by the latest posts of these types, for suggestions. Hidden ones (starting
     * with _) are left out; they can still be typed in.
     *
     * @return string[]
     */
    public static function suggested_meta_keys($post_types = [])
    {
        global $wpdb;

        $post_types = array_filter((array)$post_types) ?: ['post'];

        $cache_key = 'mo_post_meta_keys_' . md5(implode(',', $post_types));
        $keys      = get_transient($cache_key);

        if (false === $keys) {
            $in = implode(',', array_fill(0, count($post_types), '%s'));

            // Only the latest posts' fields: a DISTINCT over all of postmeta is slow on big stores.
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status = 'publish' ORDER BY ID DESC LIMIT 300",
                $post_types
            ));

            $keys = [];

            if ( ! empty($ids)) {
                $ids  = implode(',', array_map('absint', $ids));
                $keys = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id IN ($ids) AND meta_key NOT LIKE %s LIMIT 200",
                    $wpdb->esc_like('_') . '%'
                ));
                sort($keys);
            }

            $keys = array_values(array_filter(array_map([__CLASS__, 'clean_meta_key'], (array)$keys)));

            set_transient($cache_key, $keys, HOUR_IN_SECONDS);
        }

        return apply_filters('mailoptin_post_meta_suggested_keys', $keys, $post_types);
    }

    /**
     * The chosen details for one post, each as escaped HTML, in the order chosen. Details with nothing to
     * show for this post (no tags, no price) are left out.
     *
     * @param WP_Post $post
     * @param string[] $keys
     *
     * @return string[]
     */
    public static function render($post, $keys)
    {
        $post = get_post($post);

        if ( ! $post) return [];

        $bucket = [];

        foreach (self::parse($keys) as $key) {
            $value = self::value($post, $key);

            if ('' !== $value) $bucket[] = '<span>' . $value . '</span>';
        }

        return $bucket;
    }

    /**
     * One detail for one post, as escaped HTML, or '' when there's nothing to show.
     */
    public static function value(WP_Post $post, $key)
    {
        $value = '';

        switch (true) {
            case 'author' === $key:
                $value = esc_html(wp_strip_all_tags(get_the_author_meta('display_name', $post->post_author)));
                break;

            case 'date' === $key:
                $value = esc_html(get_the_date(get_option('date_format'), $post));
                break;

            case 'category' === $key:
                $value = self::terms($post, 'category');
                break;

            case 'tags' === $key:
                $value = self::terms($post, 'post_tag');
                break;

            case 'comments' === $key:
                $count = (int)get_comments_number($post);
                $value = $count ? esc_html(sprintf(_n('%s comment', '%s comments', $count, 'mailoptin'), number_format_i18n($count))) : '';
                break;

            case 'reading_time' === $key:
                // Any script's words, not only Latin ones (str_word_count() counts 0 for Cyrillic).
                $words   = (int)preg_match_all('/[\p{L}\p{N}]+/u', wp_strip_all_tags(strip_shortcodes($post->post_content)));
                $minutes = max(1, (int)ceil($words / (int)apply_filters('mailoptin_post_meta_words_per_minute', 200)));
                $value   = $words ? esc_html(sprintf(_n('%s min read', '%s min read', $minutes, 'mailoptin'), number_format_i18n($minutes))) : '';
                break;

            case 0 === strpos($key, 'tax:'):
                $value = self::terms($post, substr($key, 4));
                break;

            case 0 === strpos($key, 'meta:'):
                $meta  = get_post_meta($post->ID, substr($key, 5), true);
                $meta  = is_array($meta) ? implode(', ', array_filter($meta, 'is_scalar')) : (is_scalar($meta) ? (string)$meta : '');
                $value = esc_html(wp_strip_all_tags($meta));
                break;

            case 0 === strpos($key, 'woo:'):
                $value = self::woo($post, substr($key, 4));
                break;
        }

        return (string)apply_filters('mailoptin_post_meta_value', $value, $key, $post);
    }

    protected static function terms(WP_Post $post, $taxonomy)
    {
        if ( ! taxonomy_exists($taxonomy)) return '';

        $terms = get_the_terms($post, $taxonomy);

        if (empty($terms) || is_wp_error($terms)) return '';

        return esc_html(implode(', ', wp_list_pluck($terms, 'name')));
    }

    protected static function woo(WP_Post $post, $what)
    {
        if ( ! self::is_woocommerce_active()) return '';

        $product = wc_get_product($post->ID);

        if ( ! $product) return '';

        switch ($what) {
            case 'price':
                // Screen reader text ("Original price was: …") would show in an email: drop it with its words.
                $price = preg_replace('#<span[^>]*class=["\'][^"\']*screen-reader-text[^"\']*["\'][^>]*>.*?</span>#is', '', (string)$product->get_price_html());

                // Keeps the sale price's strike-through, without the underline email clients give <ins>.
                $price = trim(wp_kses($price, ['del' => [], 'ins' => [], 'span' => [], 'bdi' => []]));

                return str_replace('<ins>', '<ins style="text-decoration:none">', $price);

            case 'rating':
                $rating = (float)$product->get_average_rating();

                return $rating > 0
                    ? esc_html(str_repeat('★', (int)round($rating)) . str_repeat('☆', 5 - (int)round($rating)) . ' ' . number_format_i18n($rating, 1))
                    : '';

            case 'stock':
                $statuses = function_exists('wc_get_product_stock_status_options') ? wc_get_product_stock_status_options() : [];

                return esc_html($statuses[$product->get_stock_status()] ?? '');

            case 'sku':
                return esc_html((string)$product->get_sku());
        }

        return '';
    }
}
