<?php

namespace MailOptin\Core;

use Html2Text\Html2Text;
use MailOptin\Core\Logging\CampaignLogPersistence;
use MailOptin\Core\Logging\CampaignLogRepository;
use MailOptin\Core\PluginSettings\Settings;
use Pelago\Emogrifier\CssInliner;
use W3Guy\Custom_Settings_Page_Api;
use MailOptin\Core\Repositories\OptinCampaignsRepository as OCR;
use MailOptin\Core\Repositories\EmailCampaignRepository;

function campaign_repository()
{
    return CampaignLogRepository::instance();
}

/**
 *
 * @return CampaignLogPersistence
 */
function persistence()
{
    return CampaignLogPersistence::instance();
}

/**
 * @param array $data
 *
 * @return Logging\CampaignLog
 */
function campaign($data)
{
    return (new Logging\CampaignLog($data));
}

/**
 * @return Custom_Settings_Page_Api
 */
function custom_settings_page_api()
{
    return Custom_Settings_Page_Api::instance();
}

/**
 * Convert HTML to plain text
 *
 * @param string $content
 *
 * @return string string
 * @throws \Html2Text\Html2TextException
 */
function html_to_text($content)
{
    // #^\[.+\](.+Bwebversion.+)# removes the webversion link if found as the first plain-text content.
    // this is done to make the email preview n email clients not show the tags as
    return preg_replace('#^\[.+\](.+Bwebversion.+)#', '', Html2Text::convert($content));

}

/**
 * http://stackoverflow.com/questions/965235/how-can-i-truncate-a-string-to-the-first-20-words-in-php
 *
 * @param string $text
 * @param int $limit
 *
 * @return string
 */
function limit_text($text, $limit = 150)
{
    $limit = ! is_int($limit) || 0 === $limit ? 150 : $limit;

    // <p> not included cos it sometimes break layout and besides wpautop adds it back
    $tags = apply_filters('mo_limit_text_tags', '<style><a><img><em><i><code><ins><del><strong><blockquote><ul><ol><li><h1><h2><h3><h4><h5><h6><b><div><span>');

    $text = strip_shortcodes(strip_tags(stripslashes($text), $tags));

    if (str_word_count($text, 0) > $limit) {

        $ellipsis = apply_filters('mailoptin_limit_text_ellipsis', '. . .');

        $words = str_word_count($text, 2);
        $pos   = array_keys($words);
        $text  = substr($text, 0, $pos[$limit]) . $ellipsis;

        // when truncated text ends with malfunctioned link eg <a href="https://hello.com, <img src="http://hey.com/img.png, remove them
        $text = preg_replace(sprintf("/<(img|a|em)[^>]+(%s)/", preg_quote($ellipsis, '/')), '$2', $text);
    }

    $text = close_tags($text);

    return $text;
}

function close_tags($content)
{
    /** @see https://stackoverflow.com/a/3810341/2648410 */
    preg_match_all('#<(?!meta|img|br|hr|input\b)\b([a-z]+)(?: .*)?(?<![/|/ ])>#iU', $content, $result);
    $openedtags = $result[1];
    preg_match_all('#</([a-z]+)>#iU', $content, $result);
    $closedtags = $result[1];
    $len_opened = count($openedtags);
    if (count($closedtags) == $len_opened) {
        return $content;
    }
    $openedtags = array_reverse($openedtags);
    for ($i = 0; $i < $len_opened; $i++) {
        if ( ! in_array($openedtags[$i], $closedtags)) {
            $content .= '</' . $openedtags[$i] . '>';
        } else {
            unset($closedtags[array_search($openedtags[$i], $closedtags)]);
        }
    }

    return $content;
}

/**
 * Array of web safe fonts.
 *
 * @return array
 */
function web_safe_font()
{
    return ['Helvetica', 'Helvetica Neue', 'Arial', 'Times New Roman', 'Lucida Sans', 'Verdana', 'Tahoma', 'Cambria', 'Trebuchet MS', 'Segoe UI'];
}

/**
 * Helper function to recursively sanitize POSTed data.
 *
 * @param $data
 *
 * @return string|array
 */
function sanitize_data($data)
{
    if (is_string($data)) {
        return sanitize_text_field($data);
    }

    $sanitized_data = array();
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if (is_array($data[$key])) {
                $sanitized_data[$key] = sanitize_data($data[$key]);
            } else {
                $sanitized_data[$key] = sanitize_text_field($data[$key]);
            }
        }
    }

    return $sanitized_data;
}

/**
 * WordPress blog/website title.
 *
 * @return string
 */
function site_title()
{
    return get_bloginfo('name');
}

/**
 * Return currently viewed page url with query string.
 *
 * @return string
 */
function current_url_with_query_string()
{
    $protocol = 'http://';

    if ((isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1))
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')
    ) {
        $protocol = 'https://';
    }

    $url = $protocol . $_SERVER['HTTP_HOST'];

    $url .= $_SERVER['REQUEST_URI'];

    return esc_url_raw($url);
}

/**
 * Return array of countries. Typically for consumption by select dropdown.
 *
 * @param $country_type
 *
 * @return array
 */
function countries_array($country_type = 'alpha-2')
{
    return apply_filters('mailoptin_countries_array', include(dirname(__FILE__) . '/countries.php'));
}

/**
 * Get country name from code.
 *
 * @param string $code
 *
 * @return mixed|string
 */
function country_code_to_name($code)
{
    if (empty($code)) return '';

    $countries_array = countries_array();

    return $countries_array[$code];
}

function plugin_settings()
{
    return Settings::instance();
}

function array_flatten($zarray, $ignore_index = false)
{
    if ( ! is_array($zarray)) return [];

    $result = [];

    foreach ($zarray as $key => $value) {

        if (is_array($value)) {

            if ($ignore_index) {
                $result = array_merge($result, array_flatten($value));
            } else {
                // we are not doing array_merge here because we wanna keep array keys.
                // PS: The + operator is not an addition, it's a union. If the keys don't overlap then all is good.
                $result = $result + array_flatten($value);
            }
        } else {
            $result[$key] = $value;
        }
    }

    return $result;
}

function get_ip_address()
{
    $user_ip = '127.0.0.1';

    $keys = array(
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR',
    );

    foreach ($keys as $key) {
        // Bail if the key doesn't exists.
        if ( ! isset($_SERVER[$key])) {
            continue;
        }

        if ($key == 'HTTP_X_FORWARDED_FOR' && ! empty($_SERVER[$key])) {
            //to check ip is pass from proxy
            // can include more than 1 ip, first is the public one
            $_SERVER[$key] = explode(',', $_SERVER[$key]);
            $_SERVER[$key] = $_SERVER[$key][0];
        }

        // Bail if the IP is not valid.
        if ( ! filter_var(wp_unslash(trim($_SERVER[$key])), FILTER_VALIDATE_IP)) {
            continue;
        }

        $user_ip = str_replace('::1', '127.0.0.1', $_SERVER[$key]);
    }

    return apply_filters('mailoptin_get_ip', $user_ip);
}

/**
 * Check if an admin settings page is MailOptin'
 *
 * @return bool
 */
function is_mailoptin_admin_page()
{
    $mo_admin_pages_slug = array(
        MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_SLUG,
        MAILOPTIN_EMAIL_CAMPAIGNS_SETTINGS_SLUG,
        MAILOPTIN_CAMPAIGN_LOG_SETTINGS_SLUG,
        MAILOPTIN_CONNECTIONS_SETTINGS_SLUG,
        MAILOPTIN_SETTINGS_SETTINGS_SLUG,
        MAILOPTIN_ADVANCE_ANALYTICS_SETTINGS_SLUG,
        MAILOPTIN_LEAD_BANK_SETTINGS_SLUG,
        'mailoptin-premium-upgrade'
    );

    return (isset($_GET['page']) && in_array($_GET['page'], $mo_admin_pages_slug));
}

function is_mailoptin_customizer_preview()
{
    return is_customize_preview() && (isset($_GET['mailoptin_optin_campaign_id']) || isset($_GET['mailoptin_email_campaign_id']));
}

/**
 * Returns the capability to check against
 */
function get_capability()
{
    if (current_user_can('manage_mailoptin')) {
        return 'manage_mailoptin';
    }

    return 'manage_options';
}

/**
 * Checks whether the current user has permission to perform an admin task in MailOptin
 */
function current_user_has_privilege()
{
    return (current_user_can('manage_options') || current_user_can('manage_mailoptin'));
}

function is_ninja_form_shortcode($optin_campaign_id)
{
    if (OCR::get_merged_customizer_value($optin_campaign_id, 'use_custom_html')) {
        $content = OCR::get_customizer_value($optin_campaign_id, 'custom_html_content');
        if ( ! empty($content) && strpos($content, '[ninja_form') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Checks whether to show the "disable new post notifications" metabox
 */
function post_can_new_post_notification($post)
{
    $npps = EmailCampaignRepository::get_by_email_campaign_type(
        EmailCampaignRepository::NEW_PUBLISH_POST
    );

    if (empty($npps)) return false;

    $post_type = get_post_type($post);

    foreach ($npps as $npp) {
        $email_campaign_id = absint($npp['id']);

        //Ensure the automation is active
        if ( ! EmailCampaignRepository::is_campaign_active($email_campaign_id)) {
            continue;
        }

        //And supports this post type
        if ($post_type == EmailCampaignRepository::get_merged_customizer_value($email_campaign_id, 'custom_post_type')) {
            return true;
        }

    }

    return false;
}

function emogrify($content)
{
    if (apply_filters('mo_disable_email_emogrify', false)) return $content;

    // check if emogrifier library is supported.
    if ( ! class_exists('DOMDocument')) return $content;

    try {

        $content = CssInliner::fromHtml($content)->inlineCss('')->render();

    } catch (\Exception $e) {
    }

    return $content;
}

/**
 * strtotime uses the default timezone set in PHP which may or may not be UTC.
 *
 * @param $time
 * @param null|int $now
 *
 * @return false|int
 */
function strtotime_utc($time, $now = null)
{
    if (is_null($now)) $now = time();

    $old = date_default_timezone_get();

    date_default_timezone_set('UTC');
    $val = strtotime($time, $now);

    date_default_timezone_set($old);

    return $val;
}

/**
 * @param $bucket
 * @param $key
 * @param bool $default
 * @param bool $empty
 *
 * @return bool|mixed
 */
function moVar($bucket, $key, $default = false, $empty = false)
{
    if ($empty) {
        return ! empty($bucket[$key]) ? $bucket[$key] : $default;
    }

    return isset($bucket[$key]) ? $bucket[$key] : $default;
}

function moVarObj($bucket, $key, $default = false, $empty = false)
{
    if ($empty) {
        return ! empty($bucket->$key) ? $bucket->$key : $default;
    }

    return isset($bucket->$key) ? $bucket->$key : $default;
}

function moVarPOST($key, $default = false, $empty = false)
{
    if ($empty) {
        return ! empty($_POST[$key]) ? $_POST[$key] : $default;
    }

    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function moVarGET($key, $default = false, $empty = false)
{
    if ($empty) {
        return ! empty($_GET[$key]) ? $_GET[$key] : $default;
    }

    return isset($_GET[$key]) ? $_GET[$key] : $default;
}

function mo_test_admin_email()
{
    return apply_filters('mailoptin_email_campaign_test_admin_email', get_option('admin_email'));
}

function is_valid_data($value)
{
    return is_boolean($value) || is_int($value) || ! empty($value);
}

function is_boolean($maybe_bool)
{
    if (is_bool($maybe_bool)) {
        return true;
    }

    if (is_string($maybe_bool)) {
        $maybe_bool = strtolower($maybe_bool);

        $valid_boolean_values = array(
            'false',
            'true',
            '0',
            '1',
        );

        return in_array($maybe_bool, $valid_boolean_values, true);
    }

    if (is_int($maybe_bool)) {
        return in_array($maybe_bool, array(0, 1), true);
    }

    return false;
}

function cache_transform($cache_key, $callback)
{
    if (is_customize_preview()) return $callback();

    static $mo_cache_transform_bucket = [];

    $result = moVar($mo_cache_transform_bucket, $cache_key, false);

    if ( ! $result) {

        $result = $callback();

        $mo_cache_transform_bucket[$cache_key] = $result;
    }

    return $result;
}

/**
 * Array of system fields for field mapping UI
 *
 * @return array
 */
function system_form_fields()
{
    return apply_filters('mailoptin_system_form_fields_array', array(
            'mo_ip_address'    => __('IP Address', 'mailoptin'),
            'mo_campaign_name' => __('Optin Campaign Name', 'mailoptin'),
            'referrer'         => __('Referrer URL', 'mailoptin'),
            'conversion_page'  => __('Conversion Page', 'mailoptin'),
        )
    );
}

function is_http_code_success($code)
{
    $code = intval($code);

    return $code >= 200 && $code <= 299;
}

/**
 * @see https://gist.github.com/tripflex/c6518efc1753cf2392559866b4bd1a53
 *
 * Remove Class Filter Without Access to Class Object
 *
 * In order to use the core WordPress remove_filter() on a filter added with the callback
 * to a class, you either have to have access to that class object, or it has to be a call
 * to a static method.  This method allows you to remove filters with a callback to a class
 * you don't have access to.
 *
 * Works with WordPress 1.2+ (4.7+ support added 9-19-2016)
 * Updated 2-27-2017 to use internal WordPress removal for 4.7+ (to prevent PHP warnings output)
 *
 * @param string $tag Filter to remove
 * @param string $class_name Class name for the filter's callback
 * @param string $method_name Method name for the filter's callback
 * @param int $priority Priority of the filter (default 10)
 *
 * @return bool Whether the function is removed.
 */
function _remove_class_filter($tag, $class_name = '', $method_name = '', $priority = 10)
{
    global $wp_filter;

    // Check that filter actually exists first
    if ( ! isset($wp_filter[$tag])) {
        return false;
    }

    /**
     * If filter config is an object, means we're using WordPress 4.7+ and the config is no longer
     * a simple array, rather it is an object that implements the ArrayAccess interface.
     *
     * To be backwards compatible, we set $callbacks equal to the correct array as a reference (so $wp_filter is updated)
     *
     * @see https://make.wordpress.org/core/2016/09/08/wp_hook-next-generation-actions-and-filters/
     */
    if (is_object($wp_filter[$tag]) && isset($wp_filter[$tag]->callbacks)) {
        // Create $fob object from filter tag, to use below
        $fob       = $wp_filter[$tag];
        $callbacks = &$wp_filter[$tag]->callbacks;
    } else {
        $callbacks = &$wp_filter[$tag];
    }

    // Exit if there aren't any callbacks for specified priority
    if ( ! isset($callbacks[$priority]) || empty($callbacks[$priority])) {
        return false;
    }

    // Loop through each filter for the specified priority, looking for our class & method
    foreach ((array)$callbacks[$priority] as $filter_id => $filter) {

        // Filter should always be an array - array( $this, 'method' ), if not goto next
        if ( ! isset($filter['function']) || ! is_array($filter['function'])) {
            continue;
        }

        // If first value in array is not an object, it can't be a class
        if ( ! is_object($filter['function'][0])) {
            continue;
        }

        // Method doesn't match the one we're looking for, goto next
        if ($filter['function'][1] !== $method_name) {
            continue;
        }

        // Method matched, now let's check the Class
        if (get_class($filter['function'][0]) === $class_name) {

            // WordPress 4.7+ use core remove_filter() since we found the class object
            if (isset($fob)) {
                // Handles removing filter, reseting callback priority keys mid-iteration, etc.
                $fob->remove_filter($tag, $filter['function'], $priority);

            } else {
                // Use legacy removal process (pre 4.7)
                unset($callbacks[$priority][$filter_id]);
                // and if it was the only filter in that priority, unset that priority
                if (empty($callbacks[$priority])) {
                    unset($callbacks[$priority]);
                }
                // and if the only filter for that tag, set the tag to an empty array
                if (empty($callbacks)) {
                    $callbacks = array();
                }
                // Remove this filter from merged_filters, which specifies if filters have been sorted
                unset($GLOBALS['merged_filters'][$tag]);
            }

            return true;
        }
    }

    return false;
}

/**
 * Remove Class Action Without Access to Class Object
 *
 * In order to use the core WordPress remove_action() on an action added with the callback
 * to a class, you either have to have access to that class object, or it has to be a call
 * to a static method.  This method allows you to remove actions with a callback to a class
 * you don't have access to.
 *
 * Works with WordPress 1.2+ (4.7+ support added 9-19-2016)
 *
 * @param string $tag Action to remove
 * @param string $class_name Class name for the action's callback
 * @param string $method_name Method name for the action's callback
 * @param int $priority Priority of the action (default 10)
 *
 * @return bool               Whether the function is removed.
 */
function _remove_class_action($tag, $class_name = '', $method_name = '', $priority = 10)
{
    return _remove_class_filter($tag, $class_name, $method_name, $priority);
}