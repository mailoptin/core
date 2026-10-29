<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Repositories\SegmentsRepository as Segments;
use MailOptin\MailOptinConnect\CampaignAudience;

/**
 * Saved segments on the Subscribers screens: the Segments tab, and saving the subscribers list's
 * tag and custom field filters as a segment. Premium; free sites see what segments are for.
 */
class Subscribers_Segments
{
    public static function is_ready()
    {
        return Subscribers_Tags_Fields::is_ready() && Segments::is_ready();
    }

    /**
     * Link to the subscribers list filtered by a segment, where it can be changed and saved again.
     */
    public static function view_url($segment)
    {
        return add_query_arg(Subscriber_Filters::to_query($segment['filters']) + ['segment' => $segment['id']], Subscribers_Page::page_url());
    }

    protected static function upsell_link($text, $campaign)
    {
        return sprintf('<a target="_blank" href="%s">%s</a>', esc_url(CampaignAudience::upsell_url($campaign)), esc_html($text));
    }

    /**
     * "Save as segment" in the subscribers list's filter bar, when it is filtered by tags or fields.
     */
    public static function save_html($filters)
    {
        if ( ! Subscriber_Filters::is_active($filters)) return '';

        if ( ! Segments::is_available()) {
            return sprintf(
                '<a class="mo-save-segment-upsell" target="_blank" href="%s">%s <span class="mo-premium-badge">%s</span></a>',
                esc_url(CampaignAudience::upsell_url('builtin_segments_list')),
                esc_html__('Save as segment', 'mailoptin'),
                esc_html__('Premium', 'mailoptin')
            );
        }

        if (Segments::uses_not_opened($filters)) {
            return '<span class="mo-save-segment-upsell description">' . esc_html__('Can\'t save "Didn\'t open" as a segment', 'mailoptin') . '</span>';
        }

        $segment = Segments::get(absint($_GET['segment'] ?? 0));
        $url     = Subscribers_Page::page_url('segments');

        ob_start();
        ?>
        <details class="mo-add-filter mo-save-segment">
            <summary><?php echo $segment ? esc_html(sprintf(__('Save segment "%s"', 'mailoptin'), $segment['name'])) : esc_html__('Save as segment', 'mailoptin'); ?></summary>
            <div class="mo-add-filter-body">
                <?php if ($segment) : ?>
                    <form method="post" action="<?php echo esc_url($url); ?>">
                        <?php wp_nonce_field('mo_update_segment_' . $segment['id']); ?>
                        <input type="hidden" name="mo_subscriber_action" value="update_segment">
                        <input type="hidden" name="segment" value="<?php echo absint($segment['id']); ?>">
                        <?php echo Subscriber_Filters::hidden_inputs($filters); ?>
                        <p><?php esc_html_e('Replace its filters with the ones shown here. Campaigns using it send to the new matches.', 'mailoptin'); ?></p>
                        <?php submit_button(__('Update segment', 'mailoptin'), 'primary small', 'submit', false); ?>
                    </form>
                    <hr>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url($url); ?>">
                    <?php wp_nonce_field('mo_save_segment'); ?>
                    <input type="hidden" name="mo_subscriber_action" value="save_segment">
                    <?php echo Subscriber_Filters::hidden_inputs($filters); ?>
                    <p>
                        <label for="mo-segment-name"><?php $segment ? esc_html_e('Or save as a new segment', 'mailoptin') : esc_html_e('Segment name', 'mailoptin'); ?></label><br>
                        <input type="text" id="mo-segment-name" class="widefat" name="name" maxlength="<?php echo Segments::MAX_LENGTH; ?>" placeholder="<?php esc_attr_e('For example: UK customers', 'mailoptin'); ?>" required>
                    </p>
                    <p class="description"><?php esc_html_e('Saves the tag and custom field filters. A campaign sent to a list can then go only to the people in it who match.', 'mailoptin'); ?></p>
                    <?php submit_button(__('Save segment', 'mailoptin'), $segment ? 'small' : 'primary small', 'submit', false); ?>
                </form>
            </div>
        </details>
        <?php
        return ob_get_clean();
    }

    public static function segments_html()
    {
        $segments  = Segments::all();
        $available = Segments::is_available();
        $editing   = absint($_GET['rename'] ?? 0);
        $url       = Subscribers_Page::page_url('segments');

        ob_start();
        ?>
        <p class="mo-intro"><?php esc_html_e('A segment is a saved set of tag and custom field filters, like "tagged customer and Country is United Kingdom". Send a campaign to a list and limit it to a segment: who is in it is worked out when the campaign sends, so people who match later are included.', 'mailoptin'); ?></p>

        <?php if ( ! $available) : ?>
            <div class="mo-upsell-box">
                <h3><?php esc_html_e('Send to the right people, not the whole list', 'mailoptin'); ?></h3>
                <ul>
                    <li><?php esc_html_e('Save any filtered view of your subscribers as a segment', 'mailoptin'); ?></li>
                    <li><?php esc_html_e('Send a campaign to a segment of a list, and leave out people with certain tags', 'mailoptin'); ?></li>
                    <li><?php esc_html_e('See how many people a campaign will go to before it sends', 'mailoptin'); ?></li>
                </ul>
                <p><?php echo self::upsell_link(__('Get MailOptin Premium', 'mailoptin'), 'builtin_segments_tab'); ?></p>
            </div>
            <?php if (empty($segments)) return ob_get_clean(); ?>
            <p class="description"><?php esc_html_e('Your saved segments are kept, but campaigns using them won\'t send without Premium.', 'mailoptin'); ?></p>
        <?php endif; ?>

        <table class="wp-list-table widefat striped mo-tags-table mo-segments-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Segment', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Filters', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Subscribed', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Used by', 'mailoptin'); ?></th>
                <th class="mo-actions-col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'mailoptin'); ?></span></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($segments)) : ?>
                <tr><td colspan="5"><?php printf(
                            esc_html__('No segments yet. %sFilter the subscribers list%s by tags or custom fields, then choose "Save as segment".', 'mailoptin'),
                            '<a href="' . esc_url(Subscribers_Page::page_url()) . '">',
                            '</a>'
                        ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($segments as $segment) :
                $segment_id = $segment['id'];
                $used_by = Segments::used_by($segment_id);
                $problems = Segments::problems($segment);
                $delete_url = wp_nonce_url(add_query_arg(['mo_subscriber_action' => 'delete_segment', 'segment' => $segment_id], $url), 'mo_delete_segment_' . $segment_id);
                ?>
                <tr id="mo-segment-<?php echo $segment_id; ?>">
                    <td>
                        <?php if ($editing === $segment_id) : ?>
                            <form method="post" action="<?php echo esc_url($url); ?>" class="mo-inline-form">
                                <?php wp_nonce_field('mo_rename_segment_' . $segment_id); ?>
                                <input type="hidden" name="mo_subscriber_action" value="rename_segment">
                                <input type="hidden" name="segment" value="<?php echo $segment_id; ?>">
                                <label class="screen-reader-text" for="mo-rename-<?php echo $segment_id; ?>"><?php esc_html_e('Segment name', 'mailoptin'); ?></label>
                                <input type="text" id="mo-rename-<?php echo $segment_id; ?>" name="name" value="<?php echo esc_attr($segment['name']); ?>" maxlength="<?php echo Segments::MAX_LENGTH; ?>" required autofocus>
                                <?php submit_button(__('Save', 'mailoptin'), 'primary small', 'submit', false); ?>
                                <a href="<?php echo esc_url($url); ?>"><?php esc_html_e('Cancel', 'mailoptin'); ?></a>
                            </form>
                        <?php else : ?>
                            <strong><?php echo esc_html($segment['name']); ?></strong>
                        <?php endif; ?>
                        <?php foreach ($problems as $problem) : ?>
                            <p class="mo-segment-problem"><?php echo esc_html($problem . ' ' . __('Campaigns using it won\'t send until it is edited.', 'mailoptin')); ?></p>
                        <?php endforeach; ?>
                    </td>
                    <td><?php echo Subscriber_Filters::chips_html($segment['filters'], null); ?></td>
                    <td><?php echo empty($problems) ? '<a href="' . esc_url(self::view_url($segment)) . '">' . esc_html(number_format_i18n(Segments::count_matching($segment))) . '</a>' : '&mdash;'; ?></td>
                    <td class="mo-muted"><?php echo empty($used_by) ? '&mdash;' : esc_html(implode(', ', $used_by)); ?></td>
                    <td class="mo-actions-col">
                        <?php if ($available) : ?>
                            <a class="button button-small" href="<?php echo esc_url(self::view_url($segment)); ?>"><?php esc_html_e('View and edit', 'mailoptin'); ?></a>
                            <a class="button button-small" href="<?php echo esc_url(add_query_arg('rename', $segment_id, $url) . '#mo-segment-' . $segment_id); ?>"><?php esc_html_e('Rename', 'mailoptin'); ?></a>
                        <?php endif; ?>
                        <?php if (empty($used_by)) : ?>
                            <a class="button button-small mo-button-danger mo-confirm" data-confirm="<?php echo esc_attr(sprintf(__('Delete the segment "%s"? Its subscribers are kept.', 'mailoptin'), $segment['name'])); ?>" href="<?php echo esc_url($delete_url); ?>"><?php esc_html_e('Delete', 'mailoptin'); ?></a>
                        <?php else : ?>
                            <button type="button" class="button button-small" disabled title="<?php esc_attr_e('Choose another audience in the campaigns using it first.', 'mailoptin'); ?>"><?php esc_html_e('Delete', 'mailoptin'); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    /**
     * @return array|null [notice, redirect url], or null if it isn't one of these actions
     */
    public static function handle_action($action)
    {
        $url = Subscribers_Page::page_url('segments');

        switch ($action) {
            case 'save_segment':
                check_admin_referer('mo_save_segment');
                if ( ! Segments::is_available()) return ['error', $url];

                $filters = Subscriber_Filters::from_request($_POST);
                $result  = Segments::create(wp_unslash($_POST['name'] ?? ''), $filters);

                if (is_wp_error($result)) {
                    Subscribers_Tags_Fields::set_error($result->get_error_message());

                    return ['error', add_query_arg(Subscriber_Filters::to_query($filters), Subscribers_Page::page_url())];
                }

                return ['segment_saved', $url];

            case 'update_segment':
                $segment_id = absint($_POST['segment'] ?? 0);
                check_admin_referer('mo_update_segment_' . $segment_id);
                if ( ! Segments::is_available()) return ['error', $url];

                $filters = Subscriber_Filters::from_request($_POST);
                $result  = Segments::update($segment_id, null, $filters);

                if (is_wp_error($result)) {
                    Subscribers_Tags_Fields::set_error($result->get_error_message());

                    return ['error', add_query_arg(Subscriber_Filters::to_query($filters) + ['segment' => $segment_id], Subscribers_Page::page_url())];
                }

                return ['segment_updated', $url];

            case 'rename_segment':
                $segment_id = absint($_POST['segment'] ?? 0);
                check_admin_referer('mo_rename_segment_' . $segment_id);
                if ( ! Segments::is_available()) return ['error', $url];

                $result = Segments::update($segment_id, wp_unslash($_POST['name'] ?? ''));

                if (is_wp_error($result)) {
                    Subscribers_Tags_Fields::set_error($result->get_error_message());

                    return ['error', add_query_arg('rename', $segment_id, $url)];
                }

                return ['segment_renamed', $url];

            case 'delete_segment':
                $segment_id = absint($_GET['segment'] ?? 0);
                check_admin_referer('mo_delete_segment_' . $segment_id);

                if ( ! empty(Segments::used_by($segment_id))) {
                    Subscribers_Tags_Fields::set_error(__('That segment is used by a campaign. Choose another audience in the campaign first.', 'mailoptin'));

                    return ['error', $url];
                }

                Segments::delete($segment_id);

                return ['segment_deleted', $url];
        }

        return null;
    }

    public static function styles()
    {
        return '<style>
.mo-segments-table td{vertical-align:top}
.mo-segments-table .mo-filter-chip{padding-right:10px;margin:0 4px 4px 0}
.mo-segment-problem{color:#b32d2e;margin:4px 0 0}
.mo-save-segment{margin-left:auto}
.mo-save-segment .mo-add-filter-body{left:auto;right:0}
.mo-save-segment-upsell{margin-left:auto;padding:4px 6px;text-decoration:none}
.mo-premium-badge{display:inline-block;font-size:11px;line-height:16px;padding:0 6px;border-radius:99px;background:#f0e6ff;color:#5b2a9e;margin-left:2px}
.mo-upsell-box{max-width:72ch;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #7e3bd0;padding:4px 20px 8px;margin:12px 0 16px}
.mo-upsell-box ul{list-style:disc;margin-left:20px}
</style>';
    }
}
