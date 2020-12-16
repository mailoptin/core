<?php
/**
 * System Form Fields
 *
 * Returns an array of system form fields
 */

if ( ! defined('ABSPATH')) {
    exit;
}


if(isset($show_system_values) && $show_system_values)
{
    return array(
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'referrer' => $_SERVER['HTTP_REFERER'],
    );
} else {
    return array(
        'ip_address' => __('IP Address', 'mailoptin'),
        'referrer' => __('HTTP Referrer', 'mailoptin'),
        'campaign_name' => __('Campaign Name', 'mailoptin')
    );
}