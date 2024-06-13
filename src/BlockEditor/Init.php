<?php

namespace MailOptin\Core\BlockEditor;

use MailOptin\Core\Repositories\OptinCampaignsRepository;

define('MAILOPTIN_BLOCK_EDITOR_URL', wp_normalize_path(MAILOPTIN_ASSETS_URL . '../BlockEditor/'));

class Init
{
    public function __construct()
    {
        add_action('init', [$this, 'register_blocks']);

        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
    }

    public function register_blocks()
    {
        if ( ! function_exists('register_block_type')) {
            return;
        }

        register_block_type(__DIR__ . '/build/email-optin');

        if (function_exists('register_post_meta')) {
            /**
             * Registers our custom post meta so that it is available during REST calls
             */
            register_post_meta('', '_mo_disable_npp', array(
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'string',
                'auth_callback' => function () {
                    return current_user_can('edit_posts');
                }
            ));
        }
    }

    public function enqueue_editor_assets()
    {
        static $optin_bucket = null;

        if (is_null($optin_bucket)) {
            $optins = OptinCampaignsRepository::get_optin_campaigns_by_types(["sidebar", "inpost"]);

            $optin_bucket = [];

            foreach ($optins as $optin) {
                $optin_bucket[$optin->id] = $optin->name;
            }
        }

        wp_localize_script(
            'mailoptin-email-optin-editor-script',
            'moBlockOptinCampaigns',
            ['optins' => $optin_bucket]
        );

        $deps_asset_file = include dirname(__FILE__) . '/build/disable-email-plugin-sidebar/index.asset.php';

        wp_enqueue_script(
            'mailoptin-disable-email-plugin-sidebar',
            MAILOPTIN_BLOCK_EDITOR_URL . 'build/disable-email-plugin-sidebar/index.js',
            $deps_asset_file['dependencies'],
            $deps_asset_file['version']
        );
    }

    /**
     * @return self
     */
    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}