<?php

namespace MailOptin\Core\BlockEditor;

use MailOptin\Core\Repositories\OptinCampaignsRepository;

class Init
{
    public function __construct()
    {
        add_action('init', [$this, 'register_blocks']);

        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
    }

    public function register_blocks()
    {
        register_block_type(__DIR__ . '/build/email-optin');
    }

    public function enqueue_editor_assets()
    {
        static $optin_bucket = null;

        if(is_null($optin_bucket)) {

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