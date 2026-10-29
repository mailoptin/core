<?php

namespace MailOptin\MailOptinConnect;

use MailOptin\Core\Audience\CampaignRenderer;
use MailOptin\Core\Audience\Consent;
use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Connections\AbstractConnect;
use MailOptin\Core\Connections\ConnectionInterface;
use MailOptin\Core\Repositories\CustomFieldsRepository;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository;
use MailOptin\Core\Repositories\TagsRepository;
use MailOptin\Core\SendQueue\QueueRunner;

/**
 * Built-in lists: subscribers are stored in this WordPress site and emailed through the send queue.
 */
class Connect extends AbstractConnect implements ConnectionInterface
{
    /**
     * @var string key of connection service. its important all connection name ends with "Connect"
     */
    public static $connectionName = 'MailOptinConnect';

    const ENQUEUE_BATCH_SIZE = 1000;

    public function __construct()
    {
        add_filter('mailoptin_registered_connections', [$this, 'register_connection']);
        add_filter('mo_optin_form_integrations_default', [$this, 'integration_customizer_settings']);
        add_filter('mo_optin_integrations_controls_after', [$this, 'integration_customizer_controls']);
        add_action('mo_tag_merged', [$this, 'replace_tag_in_forms'], 10, 2);
        add_action('mo_tag_deleted', [$this, 'replace_tag_in_forms']);

        parent::__construct();
    }

    public static function features_support()
    {
        $features = [self::OPTIN_CAMPAIGN_SUPPORT, self::EMAIL_CAMPAIGN_SUPPORT];

        if (self::has_custom_form_fields()) $features[] = self::OPTIN_CUSTOM_FIELD_SUPPORT;

        return $features;
    }

    /**
     * Saving form fields into custom fields is a premium feature, like custom form fields themselves.
     */
    public static function has_custom_form_fields()
    {
        return defined('MAILOPTIN_DETACH_LIBSODIUM');
    }

    public static function is_connected()
    {
        return true;
    }

    public function register_connection($connections)
    {
        $connections[self::$connectionName] = __('MailOptin (built-in)', 'mailoptin');

        return $connections;
    }

    /**
     * {@unsubscribe}, {{webversion}} and subscriber merge tags are left for per-recipient rendering.
     *
     * {@inheritdoc}
     */
    public function replace_placeholder_tags($content, $type = 'html')
    {
        return $this->replace_footer_placeholder_tags($content);
    }

    public function get_email_list()
    {
        $lists = [];

        foreach (ListsRepository::get_all() as $list) {
            $lists[$list['id']] = $list['name'];
        }

        return $lists;
    }

    /**
     * Custom fields are shared by all lists, so every list offers all of them for mapping.
     *
     * @return array merge key => label
     */
    public function get_optin_fields($list_id = '')
    {
        if ( ! TagsRepository::is_ready() || ! self::has_custom_form_fields()) return [];

        return array_map('esc_html', wp_list_pluck(CustomFieldsRepository::all(), 'label', 'merge_key'));
    }

    public function integration_customizer_settings($settings)
    {
        $settings['MailOptinConnect_lead_tags'] = [];

        return $settings;
    }

    public function integration_customizer_controls($controls)
    {
        if ( ! TagsRepository::is_ready()) return $controls;

        $tags_url = add_query_arg(['page' => \MailOptin\Core\Admin\SettingsPage\Subscribers_Page::SLUG, 'tab' => 'tags'], admin_url('admin.php'));
        $tags     = array_map('esc_html', TagsRepository::names());

        if (empty($tags)) {
            $controls[] = [
                'field'   => 'custom_content',
                'name'    => 'MailOptinConnect_lead_tags',
                'content' => '<p class="description customize-control-description">' . sprintf(
                        esc_html__('Tag subscribers who sign up with this form. %sCreate a tag%s first.', 'mailoptin'),
                        '<a target="_blank" href="' . esc_url($tags_url) . '">',
                        '</a>'
                    ) . '</p>',
            ];

            return $controls;
        }

        $controls[] = [
            'field'       => 'chosen_select',
            'name'        => 'MailOptinConnect_lead_tags',
            'choices'     => $tags,
            'label'       => __('Tags', 'mailoptin'),
            'description' => sprintf(
                esc_html__('Added to everyone who signs up with this form. %sManage tags%s', 'mailoptin'),
                '<a target="_blank" href="' . esc_url($tags_url) . '">',
                '</a>'
            ),
        ];

        return $controls;
    }

    /**
     * Keep forms' Tags settings pointing at tags that exist after a merge or delete.
     *
     * @param int $tag_id
     * @param int $into_id the tag it was merged into; 0 when it was deleted
     */
    public function replace_tag_in_forms($tag_id, $into_id = 0)
    {
        $settings = \MailOptin\Core\Repositories\OptinCampaignsRepository::get_settings();
        $changed  = [];

        foreach ((array)$settings as $optin_campaign_id => $optin_settings) {
            $integrations = json_decode((string)($optin_settings['integrations'] ?? ''), true);

            if ( ! is_array($integrations)) continue;

            foreach ($integrations as $index => $integration) {
                $tags = $integration['MailOptinConnect_lead_tags'] ?? null;

                if (($integration['connection_service'] ?? '') !== 'MailOptinConnect' || ! is_array($tags) || ! in_array((string)$tag_id, array_map('strval', $tags), true)) continue;

                $tags = array_diff(array_map('strval', $tags), [(string)$tag_id]);
                if ($into_id) $tags[] = (string)$into_id;

                $integrations[$index]['MailOptinConnect_lead_tags'] = array_values(array_unique($tags));
                $changed[] = $optin_campaign_id;
            }

            if (in_array($optin_campaign_id, $changed, true)) $settings[$optin_campaign_id]['integrations'] = wp_json_encode($integrations);
        }

        if ( ! empty($changed)) {
            \MailOptin\Core\Repositories\OptinCampaignsRepository::updateSettings($settings);
            foreach (array_unique($changed) as $optin_campaign_id) \MailOptin\Core\Repositories\OptinCampaignsRepository::burst_cache($optin_campaign_id);
        }
    }

    /**
     * Values of form fields mapped to custom fields.
     *
     * @return array merge key => raw value
     */
    protected function mapped_field_values()
    {
        // Submissions made in code (not from a form) have no mappings.
        if ( ! isset($this->extras['form_custom_field_mappings']) && empty($this->extras['optin_campaign_id'])) return [];

        $mappings = $this->form_custom_field_mappings();
        $values   = [];

        if ( ! is_array($mappings)) return $values;

        foreach ($mappings as $merge_key => $form_field_id) {
            if ('' === (string)$form_field_id || ! isset($this->extras[$form_field_id])) continue;

            $value = $this->extras[$form_field_id];

            // Validated and cleaned by the field's type when saved.
            $values[$merge_key] = is_array($value) ? array_values(array_filter($value, 'is_scalar')) : (string)$value;
        }

        return $values;
    }

    /**
     * @return array tag IDs or names
     */
    protected function form_tags()
    {
        if (empty($this->extras['optin_campaign_id']) && empty($this->extras['integration_data']) && empty($this->extras['form_tags'])) return [];

        // Page builder and third-party forms pass tag names, sometimes as comma separated text.
        if ( ! empty($this->extras['form_tags'])) {
            $names = $this->extras['form_tags'];
            if (is_string($names)) $names = explode(',', $names);

            return array_values(array_filter(array_map('trim', array_map('strval', array_filter((array)$names, 'is_scalar'))), 'strlen'));
        }

        // The form's own Tags setting holds tag IDs.
        return array_values(array_filter(array_map('absint', array_filter((array)$this->get_integration_data('MailOptinConnect_lead_tags'), 'is_scalar'))));
    }

    public function subscribe($email, $name, $list_id, $extras = null)
    {
        $this->extras = is_array($extras) ? $extras : [];

        $names = self::get_first_last_names($name);

        $consent = Consent::from_form(absint($this->extras['optin_campaign_id'] ?? 0), $this->extras);

        $response = Subscription::subscribe($email, absint($list_id), [
            'consent_text'    => $consent['consent_text'],
            'consent_method'  => $consent['consent_method'],
            'first_name'      => $names[0] ?? '',
            'last_name'       => $names[1] ?? '',
            'source'          => 'form',
            'source_optin_id' => absint($this->extras['optin_campaign_id'] ?? 0),
            'ip_address'      => $this->extras['mo_ip_address'] ?? '',
            'fields'          => TagsRepository::is_ready() && self::has_custom_form_fields() ? $this->mapped_field_values() : [],
            'tags'            => TagsRepository::is_ready() ? $this->form_tags() : [],
            'meta'            => array_filter([
                'form'            => $this->extras['mo_campaign_name'] ?? '',
                'conversion_page' => esc_url_raw($this->extras['conversion_page'] ?? ''),
                'referrer'        => esc_url_raw($this->extras['referrer'] ?? ''),
            ]),
        ]);

        if (is_wp_error($response)) {
            self::save_optin_error_log(
                $response->get_error_message(),
                'mailoptin',
                $this->extras['optin_campaign_id'] ?? null,
                $this->extras['optin_campaign_type'] ?? null
            );

            return parent::ajax_failure();
        }

        return parent::ajax_success();
    }

    /**
     * Queues every subscribed member of the campaign's list. The queue marks the campaign log
     * processed when it has sent to everyone, so this returns status "sending".
     *
     * {@inheritdoc}
     */
    public function send_newsletter($email_campaign_id, $campaign_log_id, $subject, $content_html, $content_text)
    {
        $list_id = absint($this->get_email_campaign_list_id($email_campaign_id));

        if ( ! $list_id || ! ListsRepository::get($list_id)) {
            self::save_campaign_error_log(__('The selected list no longer exists.', 'mailoptin'), $campaign_log_id, $email_campaign_id);

            return ['success' => false];
        }

        CampaignRenderer::save_campaign_content($campaign_log_id, $list_id, $content_html, $content_text);

        // Resending a campaign retries its failed recipients; anyone already sent is skipped by the queue.
        SendQueueRepository::retry_failed($campaign_log_id);

        $after_id = 0;

        do {
            $recipients = SubscribersRepository::get_list_recipients($list_id, $after_id, self::ENQUEUE_BATCH_SIZE);

            if (empty($recipients)) break;

            SendQueueRepository::enqueue($campaign_log_id, $email_campaign_id, array_map(function ($recipient) {
                return [
                    'email'          => $recipient['email'],
                    'recipient_type' => CampaignRenderer::RECIPIENT_TYPE,
                    'recipient_id'   => $recipient['id'],
                ];
            }, $recipients));

            $after_id = (int)end($recipients)['id'];

        } while (count($recipients) === self::ENQUEUE_BATCH_SIZE);

        QueueRunner::get_instance()->start($campaign_log_id, $email_campaign_id, []);

        return ['success' => true, 'status' => QueueRunner::LOG_STATUS_SENDING];
    }

    /**
     * @return Connect
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
