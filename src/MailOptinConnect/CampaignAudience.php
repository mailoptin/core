<?php

namespace MailOptin\MailOptinConnect;

use MailOptin\Core\Audience\Sources\Sources;
use MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_Custom_Content;
use MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_Multiple_Checkbox;
use MailOptin\Core\Admin\SettingsPage\Subscribers_Page;
use MailOptin\Core\Repositories\EmailCampaignRepository;
use MailOptin\Core\Repositories\SegmentsRepository as Segments;
use MailOptin\Core\Repositories\SubscribersRepository;
use MailOptin\Core\Repositories\TagsRepository;

use function MailOptin\Core\current_user_has_privilege;

/**
 * Who a campaign sending to a built-in list goes to: everyone in the list, or a saved segment of
 * it, leaving out people with some tags. Premium; free sites see what it does instead.
 */
class CampaignAudience
{
    const NONCE = 'mo_campaign_audience';

    public function __construct()
    {
        add_filter('mailoptin_email_campaign_customizer_page_settings', [$this, 'settings']);
        add_filter('mailoptin_email_campaign_customizer_settings_controls', [$this, 'controls'], 10, 4);
        // After WordPress prints the customizer scripts, at priority 20.
        add_action('customize_controls_print_footer_scripts', [$this, 'script'], 30);
        add_action('wp_ajax_mo_campaign_audience_count', [$this, 'ajax_count']);
    }

    public static function upsell_url($campaign)
    {
        return 'https://mailoptin.io/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=' . $campaign;
    }

    public function settings($settings)
    {
        $settings[Segments::SEGMENT_SETTING] = [
            'default'           => '',
            'type'              => 'option',
            'transport'         => 'postMessage',
            'sanitize_callback' => function ($value) {
                return absint($value) ? (string)absint($value) : '';
            },
        ];

        $settings[Sources::CAMPAIGN_SETTING] = [
            'default'           => '',
            'type'              => 'option',
            'transport'         => 'postMessage',
            'sanitize_callback' => function ($value) {
                return Sources::encode_selection(json_decode(wp_unslash((string)$value), true));
            },
        ];

        $settings[Segments::EXCLUDE_TAGS_SETTING] = [
            'default'           => '',
            'type'              => 'option',
            'transport'         => 'postMessage',
            'sanitize_callback' => function ($value) {
                return implode(',', (array)Segments::parse_tag_ids($value));
            },
        ];

        return $settings;
    }

    public function controls($controls, $wp_customize, $option_prefix, $customizer)
    {
        $section = $customizer->campaign_settings_section_id;

        // Customers, users and members, as on the broadcast page. Not for the email editor's own
        // overlay of a broadcast: the broadcast page has these.
        // Automations send to the people they start for: no audience to pick.
        if ( ! empty(Sources::active()) && ! EmailCampaignRepository::is_automation_email($customizer->email_campaign_id)) {
            $controls[Sources::CAMPAIGN_SETTING] = new \MailOptin\Core\Admin\Customizer\CustomControls\WP_Customize_Audience_Sources_Control($wp_customize, $option_prefix . '[' . Sources::CAMPAIGN_SETTING . ']', [
                'label'       => __('Customers, users and members', 'mailoptin'),
                'description' => sprintf(
                    esc_html__('As well as, or instead of, the list. Someone in two places gets it once. Who counts as agreeing: %sSettings › Customers and members%s.', 'mailoptin'),
                    '<a target="_blank" href="' . esc_url(\MailOptin\Core\Admin\SettingsPage\AudienceSourcesSettings::url()) . '">',
                    '</a>'
                ),
                'section'     => $section,
                'settings'    => $option_prefix . '[' . Sources::CAMPAIGN_SETTING . ']',
                // After the list (60), before "Send to" (61, added after this).
                'priority'    => 61,
            ]);
        }

        if ( ! TagsRepository::is_ready() || ! Segments::is_ready()) return $controls;

        $campaign_id = $customizer->email_campaign_id;
        $saved       = absint(EmailCampaignRepository::get_customizer_value($campaign_id, Segments::SEGMENT_SETTING)) || [] !== Segments::excluded_tag_ids($campaign_id);

        // A site that is no longer premium still gets the controls while they are set, to clear them.
        if ( ! Segments::is_available() && ! $saved) {
            $controls[Segments::SEGMENT_SETTING] = new WP_Customize_Custom_Content($wp_customize, $option_prefix . '[' . Segments::SEGMENT_SETTING . ']', [
                'label'    => __('Send to', 'mailoptin'),
                'content'  => '<p class="description customize-control-description">' . sprintf(
                        esc_html__('This campaign goes to everyone in the list. %sMailOptin Premium%s lets you send to a saved segment instead, like subscribers tagged "customer" in the UK, and leave out people with certain tags.', 'mailoptin'),
                        '<a target="_blank" href="' . esc_url(self::upsell_url('builtin_segments_campaign')) . '">',
                        '</a>'
                    ) . '</p>',
                'section'  => $section,
                'settings' => $option_prefix . '[' . Segments::SEGMENT_SETTING . ']',
                'priority' => 61,
            ]);

            return $controls;
        }

        $segments_url = Subscribers_Page::page_url('segments');

        $controls[Segments::SEGMENT_SETTING] = [
            'type'        => 'select',
            'label'       => __('Send to', 'mailoptin'),
            'section'     => $section,
            'settings'    => $option_prefix . '[' . Segments::SEGMENT_SETTING . ']',
            'choices'     => ['' => __('Everyone in the list', 'mailoptin')] + Segments::names(),
            'description' => Segments::is_available() ? sprintf(
                esc_html__('Who in the list gets it, worked out when it sends. %sManage segments%s', 'mailoptin'),
                '<a target="_blank" href="' . esc_url($segments_url) . '">',
                '</a>'
            ) : sprintf(
                esc_html__('Segments and leaving out tags need %sMailOptin Premium%s. Choose "Everyone in the list" and untick the tags below to send this campaign.', 'mailoptin'),
                '<a target="_blank" href="' . esc_url(self::upsell_url('builtin_segments_campaign')) . '">',
                '</a>'
            ),
            'priority'    => 61,
        ];

        $tags = TagsRepository::names();

        if ( ! empty($tags)) {
            $controls[Segments::EXCLUDE_TAGS_SETTING] = new WP_Customize_Multiple_Checkbox($wp_customize, $option_prefix . '[' . Segments::EXCLUDE_TAGS_SETTING . ']', [
                'label'    => __('Leave out people tagged', 'mailoptin'),
                'section'  => $section,
                'settings' => $option_prefix . '[' . Segments::EXCLUDE_TAGS_SETTING . ']',
                'choices'  => $tags,
                'priority' => 62,
            ]);
        }

        $controls['MailOptinConnect_audience_count'] = new WP_Customize_Custom_Content($wp_customize, $option_prefix . '[MailOptinConnect_audience_count]', [
            'content'  => '<p class="mo-audience-count" aria-live="polite"></p>',
            'section'  => $section,
            'settings' => $option_prefix . '[' . Segments::SEGMENT_SETTING . ']',
            'priority' => 63,
        ]);

        return $controls;
    }

    /**
     * Shows the audience controls only for built-in lists, and the number of people a campaign
     * would go to now.
     */
    public function script()
    {
        if (empty($_GET['mailoptin_email_campaign_id'])) return;

        $campaign_id = absint($_GET['mailoptin_email_campaign_id']);
        $prefix      = MO_EMAIL_CAMPAIGNS_WP_OPTION_NAME . '[' . $campaign_id . ']';
        ?>
        <script type="text/javascript">
            (function (api, $) {
                var prefix = <?php echo wp_json_encode($prefix); ?>,
                    ids = ['<?php echo Sources::CAMPAIGN_SETTING; ?>', '<?php echo Segments::SEGMENT_SETTING; ?>', '<?php echo Segments::EXCLUDE_TAGS_SETTING; ?>', 'MailOptinConnect_audience_count'],
                    request = null;

                var value = function (name) {
                    var setting = api(prefix + '[' + name + ']');
                    return setting ? setting.get() : '';
                };

                var controls = function () {
                    return ids.map(function (id) { return api.control(prefix + '[' + id + ']'); }).filter(Boolean);
                };

                var refresh = function () {
                    var builtin = value('connection_service') === 'MailOptinConnect';

                    controls().forEach(function (control) { control.container.toggle(builtin); });

                    var output = $('.mo-audience-count');
                    if ( ! builtin || ! output.length) return;

                    if ( ! value('connection_email_list') && ! JSON.parse(value('<?php echo Sources::CAMPAIGN_SETTING; ?>') || '[]').length) {
                        output.text('');
                        return;
                    }

                    if (request) request.abort();

                    output.text(<?php echo wp_json_encode(__('Counting…', 'mailoptin')); ?>);

                    request = $.post(ajaxurl, {
                        action: 'mo_campaign_audience_count',
                        security: <?php echo wp_json_encode(wp_create_nonce(self::NONCE)); ?>,
                        list_id: value('connection_email_list'),
                        segment: value('<?php echo Segments::SEGMENT_SETTING; ?>'),
                        exclude: value('<?php echo Segments::EXCLUDE_TAGS_SETTING; ?>'),
                        sources: value('<?php echo Sources::CAMPAIGN_SETTING; ?>')
                    }).done(function (response) {
                        output.text(response && response.data ? response.data : '');
                    });
                };

                // The customers, users and members picker writes its choices to its setting as JSON.
                $(document).on('change', '.mo-sources-picker input, .mo-sources-picker select', function () {
                    var root = $(this).closest('.customize-control'), value = [];
                    root.find('.mo-source-on:checked').each(function () {
                        var select = root.find('.mo-source-option[data-source="' + $(this).data('source') + '"]');
                        value.push({source: String($(this).data('source')), option: select.length ? select.val() : ''});
                    });
                    root.find('.mo-sources-value').val(JSON.stringify(value)).trigger('change');
                });

                api.bind('ready', function () {
                    ['connection_service', 'connection_email_list'].concat(ids).forEach(function (name) {
                        var setting = api(prefix + '[' + name + ']');
                        if (setting) setting.bind(refresh);
                    });

                    refresh();
                });
            })(wp.customize, jQuery);
        </script>
        <?php
    }

    public function ajax_count()
    {
        check_ajax_referer(self::NONCE, 'security');

        if ( ! current_user_has_privilege()) wp_send_json_error('', 403);

        $list_id   = absint($_POST['list_id'] ?? 0);
        $segment   = absint($_POST['segment'] ?? 0);
        $filters   = Segments::filters_for($segment, Segments::parse_tag_ids(wp_unslash($_POST['exclude'] ?? '')));
        $selection = Sources::campaign_selection(json_decode(wp_unslash((string)($_POST['sources'] ?? '')), true));

        if (is_wp_error($filters)) wp_send_json_success($filters->get_error_message());

        if (empty($selection)) {
            $count = SubscribersRepository::count_list_recipients($list_id, $filters);

            wp_send_json_success(sprintf(
                _n('Goes to %s subscriber if it sends now.', 'Goes to %s subscribers if it sends now.', $count, 'mailoptin'),
                number_format_i18n($count)
            ));
        }

        $count = Sources::count_union($selection, ['filters' => $filters, 'segment' => $segment > 0], $list_id ? SubscribersRepository::list_recipients_email_sql($list_id, $filters) : null);

        wp_send_json_success(sprintf(
            _n('Goes to %s person if it sends now.', 'Goes to %s people if it sends now.', $count, 'mailoptin'),
            number_format_i18n($count)
        ));
    }
}
