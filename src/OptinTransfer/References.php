<?php

namespace MailOptin\Core\OptinTransfer;

use MailOptin\Core\Repositories\CustomFieldsRepository;
use MailOptin\Core\Repositories\TagsRepository;

/**
 * Settings that point at things on one site (posts, terms, tags, custom fields, images, coupons), and how they
 * are described in an export file and found again on import.
 */
class References
{
    /** Settings holding post IDs: display rules, WooCommerce products, product offers. */
    public static function post_settings()
    {
        return apply_filters('mailoptin_transfer_post_settings', [
            'posts_never_load',
            'pages_never_load',
            'cpt_never_load',
            'exclusive_post_types_posts_load',
            'woocommerce_show_specific_woo_products',
            'wc_atc_products',
            'po_product',
        ]);
    }

    /** Settings holding term IDs, with their taxonomy. */
    public static function term_settings()
    {
        return apply_filters('mailoptin_transfer_term_settings', [
            'post_categories_load'                 => 'category',
            'post_categories_hide'                 => 'category',
            'post_tags_load'                       => 'post_tag',
            'post_tags_hide'                       => 'post_tag',
            'woocommerce_show_specific_categories' => 'product_cat',
            'woocommerce_show_specific_tags'       => 'product_tag',
        ]);
    }

    /** Settings holding a WooCommerce coupon code. Codes are kept as written; a missing one is flagged. */
    public static function coupon_settings()
    {
        return apply_filters('mailoptin_transfer_coupon_settings', ['po_coupon']);
    }

    /**
     * A setting's IDs as a list, whether stored as an array, a single ID or comma separated text.
     *
     * @return int[]
     */
    public static function ids($value)
    {
        if (is_array($value)) {
            $list = $value;
        } elseif (is_scalar($value) && '' !== (string)$value) {
            $list = explode(',', (string)$value);
        } else {
            return [];
        }

        return array_values(array_filter(array_map('absint', array_filter($list, 'is_scalar'))));
    }

    /** Put mapped IDs back in the shape the setting had. */
    public static function reshape($original, array $ids)
    {
        if (is_array($original)) return array_values($ids);
        if (is_string($original) && false !== strpos($original, ',')) return implode(',', $ids);

        return empty($ids) ? '' : (is_int($original) ? (int)$ids[0] : (string)$ids[0]);
    }

    /** The integrations list, decoded. */
    public static function integrations($settings)
    {
        $value = $settings['integrations'] ?? '';
        $list  = is_string($value) ? json_decode($value, true) : $value;

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** Merge key => form field, from an integration's mappings. */
    public static function field_mappings($integration)
    {
        $value = $integration['custom_field_mappings'] ?? '';
        $map   = is_string($value) ? json_decode($value, true) : $value;

        return is_array($map) ? $map : [];
    }

    /** How a post is found again: its type, path, title, and SKU for products. */
    public static function describe_post($post_id)
    {
        $post = get_post($post_id);
        if ( ! $post) return null;

        $ref = [
            'type'  => $post->post_type,
            'path'  => is_post_type_hierarchical($post->post_type) ? get_page_uri($post) : $post->post_name,
            'title' => $post->post_title,
        ];

        if ('product' === $post->post_type && function_exists('wc_get_product')) {
            $product = wc_get_product($post_id);
            if ($product && $product->get_sku()) $ref['sku'] = $product->get_sku();
        }

        return $ref;
    }

    /** The matching post on this site, or 0. Products by SKU first, then any post by its path. */
    public static function find_post(array $ref)
    {
        $type = sanitize_key($ref['type'] ?? '');
        if ('' === $type || ! post_type_exists($type)) return 0;

        if ( ! empty($ref['sku']) && function_exists('wc_get_product_id_by_sku')) {
            $id = wc_get_product_id_by_sku((string)$ref['sku']);
            if ($id) return (int)$id;
        }

        if ( ! empty($ref['path'])) {
            $post = get_page_by_path((string)$ref['path'], OBJECT, $type);
            if ($post && 'trash' !== $post->post_status) return (int)$post->ID;
        }

        return 0;
    }

    public static function describe_term($term_id, $taxonomy)
    {
        $term = get_term((int)$term_id, $taxonomy);

        return $term && ! is_wp_error($term) ? ['taxonomy' => $taxonomy, 'slug' => $term->slug, 'name' => $term->name] : null;
    }

    public static function find_term(array $ref)
    {
        $taxonomy = sanitize_key($ref['taxonomy'] ?? '');
        if ('' === $taxonomy || ! taxonomy_exists($taxonomy) || empty($ref['slug'])) return 0;

        $term = get_term_by('slug', (string)$ref['slug'], $taxonomy);

        return $term ? (int)$term->term_id : 0;
    }

    /** A custom field's definition, enough to create it on another site. */
    public static function describe_field($merge_key)
    {
        if ( ! TagsRepository::is_ready()) return null;

        $field = CustomFieldsRepository::get_by_key($merge_key);
        if ( ! $field) return null;

        $options = $field['options'] ?? [];
        if (is_string($options)) $options = json_decode($options, true);

        return ['label' => $field['label'], 'type' => $field['type'], 'options' => is_array($options) ? $options : []];
    }

    /** Uploaded images on this site that a campaign's settings use. */
    public static function images($settings)
    {
        $uploads = wp_get_upload_dir();
        $base    = set_url_scheme($uploads['baseurl'] ?? '', 'https');
        if ('' === $base) return [];

        $found = [];
        array_walk_recursive($settings, function ($value) use (&$found, $base) {
            if ( ! is_string($value) || '' === $value) return;
            if (preg_match_all('#https?://[^\s"\'()<>]+\.(?:jpe?g|png|gif|webp|svg|avif)#i', $value, $m)) {
                foreach ($m[0] as $url) {
                    if (0 === strpos(set_url_scheme($url, 'https'), $base)) $found[$url] = true;
                }
            }
        });

        return array_keys($found);
    }
}
