<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Audience\CampaignRenderer;
use MailOptin\Core\Audience\Tracking;
use MailOptin\Core\Logging\CampaignLogRepository;
use MailOptin\Core\Repositories\CampaignActivityRepository as Activity;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SegmentsRepository;
use MailOptin\Core\Repositories\SendQueueRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use MailOptin\MailOptinConnect\CampaignAudience;

use function MailOptin\Core\current_user_has_privilege;
use function MailOptin\Core\do_admin_redirect;

/**
 * The report of a campaign sent to a built-in list: totals, opens and clicks over its first week
 * and its links. Who opened and clicked, and acting on them, is premium.
 */
class Campaign_Report
{
    const PER_PAGE = 50;

    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_actions']);
    }

    public static function url($campaign_log_id, $args = [])
    {
        return add_query_arg(['report' => absint($campaign_log_id)] + $args, MAILOPTIN_CAMPAIGN_LOG_SETTINGS_PAGE);
    }

    public static function is_requested()
    {
        return ! empty($_GET['report']) && Activity::is_ready();
    }

    protected static function percent($part, $whole)
    {
        return $whole ? number_format_i18n($part / $whole * 100, 1) . '%' : '&mdash;';
    }

    /**
     * @return array group => label
     */
    public static function groups()
    {
        return [
            'clicked'            => __('Clicked', 'mailoptin'),
            'opened_not_clicked' => __('Opened, didn\'t click', 'mailoptin'),
            'not_opened'         => __('Didn\'t open', 'mailoptin'),
            'unsubscribed'       => __('Unsubscribed', 'mailoptin'),
        ];
    }

    /**
     * The same people as a subscribers list filter, so they can be tagged in bulk, exported or
     * saved as a segment from there.
     *
     * @return array|null query args, or null when there is no equivalent filter
     */
    public static function list_filter($campaign_log_id, $group)
    {
        $campaign = absint($campaign_log_id);

        switch ($group) {
            case 'clicked':
                return ['e' => [['what' => 'clicked', 'campaign' => $campaign, 'days' => 0]]];
            case 'opened_not_clicked':
                return ['e' => [['what' => 'opened', 'campaign' => $campaign, 'days' => 0], ['what' => 'not_clicked', 'campaign' => $campaign, 'days' => 0]]];
            case 'not_opened':
                return ['e' => [['what' => 'not_opened', 'campaign' => $campaign, 'days' => 0]]];
        }

        return null;
    }

    public static function html()
    {
        $campaign_log_id = absint($_GET['report']);
        $repository      = CampaignLogRepository::instance();
        $title           = $repository->retrieveTitle($campaign_log_id);

        if (null === $title || ! Activity::sent_count($campaign_log_id) && ! array_sum(SendQueueRepository::counts($campaign_log_id))) {
            return '<div class="wrap"><p>' . esc_html__('There is no report for this campaign. Reports are for campaigns sent to a built-in list.', 'mailoptin') . '</p>'
                   . '<p><a href="' . esc_url(MAILOPTIN_CAMPAIGN_LOG_SETTINGS_PAGE) . '">&larr; ' . esc_html__('Campaign log', 'mailoptin') . '</a></p></div>';
        }

        $group = sanitize_key($_GET['people'] ?? '');

        return self::styles() . '<div class="wrap mo-report">' . self::notice_html() . (isset(self::groups()[$group]) ? self::people_html($campaign_log_id, $title, $group) : self::report_html($campaign_log_id, $title)) . '</div>';
    }

    protected static function notice_html()
    {
        $notice = sanitize_key($_GET['mo_notice'] ?? '');

        if ('tagged' === $notice) {
            return '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('Tagged %s subscriber.', 'Tagged %s subscribers.', absint($_GET['count'] ?? 0), 'mailoptin'), number_format_i18n(absint($_GET['count'] ?? 0)))) . '</p></div>';
        }

        if ('no_tags' === $notice) return '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Enter at least one tag.', 'mailoptin') . '</p></div>';

        return '';
    }

    protected static function report_html($campaign_log_id, $title)
    {
        $repository = CampaignLogRepository::instance();
        $counts     = SendQueueRepository::counts($campaign_log_id);
        $stats      = Activity::stats($campaign_log_id);
        $sent       = $stats['sent'] ?: $counts['sent'];
        $content    = CampaignRenderer::campaign_content($campaign_log_id);
        $list       = $content['list_id'] ? ListsRepository::get($content['list_id']) : null;
        $sent_at    = (string)$repository->retrieveStatusTime($campaign_log_id);
        $premium    = SegmentsRepository::is_available();
        $groups     = $premium && ! $stats['pruned'] ? Activity::engagement_counts($campaign_log_id) : [];
        $first_send = $sent_at ?: gmdate('Y-m-d H:i:s');

        ob_start();
        ?>
        <p class="mo-back"><a href="<?php echo esc_url(MAILOPTIN_CAMPAIGN_LOG_SETTINGS_PAGE); ?>">&larr; <?php esc_html_e('Campaign log', 'mailoptin'); ?></a></p>
        <h1 class="mo-report-title"><?php echo esc_html($title); ?></h1>
        <p class="mo-muted"><?php
            echo esc_html(implode(' · ', array_filter([
                $sent_at ? sprintf(__('Sent %s', 'mailoptin'), get_date_from_gmt($sent_at, get_option('date_format') . ' ' . get_option('time_format'))) : '',
                $list ? sprintf(__('to %s', 'mailoptin'), $list['name']) : '',
            ])));
            ?> · <a target="_blank" href="<?php echo esc_url(add_query_arg(['mailoptin' => 'preview-campaign', 'type' => 'html', 'id' => $campaign_log_id], home_url())); ?>"><?php esc_html_e('View email', 'mailoptin'); ?></a></p>

        <div class="mo-stats">
            <div><b><?php echo esc_html(number_format_i18n($sent)); ?></b><small><?php esc_html_e('sent', 'mailoptin'); ?><?php if ($counts['failed']) echo ' · ' . esc_html(sprintf(_n('%s failed', '%s failed', $counts['failed'], 'mailoptin'), number_format_i18n($counts['failed']))); ?></small></div>
            <div><b><?php echo self::percent($stats['openers'], $sent); ?></b><small><?php echo esc_html(sprintf(_n('opened · %s person', 'opened · %s people', $stats['openers'], 'mailoptin'), number_format_i18n($stats['openers']))); ?></small></div>
            <div><b><?php echo self::percent($stats['clickers'], $sent); ?></b><small><?php echo esc_html(sprintf(_n('clicked · %s person', 'clicked · %s people', $stats['clickers'], 'mailoptin'), number_format_i18n($stats['clickers']))); ?></small></div>
            <div><b><?php echo self::percent($stats['clickers'], $stats['openers']); ?></b><small><?php esc_html_e('of openers clicked', 'mailoptin'); ?></small></div>
            <div><b><?php echo esc_html(number_format_i18n($stats['clicks'])); ?></b><small><?php esc_html_e('clicks in all', 'mailoptin'); ?></small></div>
            <?php $unsubscribed = Activity::unsubscribed_count($campaign_log_id); ?>
            <div><b><?php echo esc_html(number_format_i18n($unsubscribed)); ?></b><small><?php echo esc_html(sprintf(__('unsubscribed · %s', 'mailoptin'), html_entity_decode(self::percent($unsubscribed, $sent)))); ?></small></div>
        </div>

        <div class="mo-report-cols">
            <div class="mo-report-main">
                <?php echo self::chart_html(Activity::daily($campaign_log_id, $first_send)); ?>
                <?php echo self::links_html($campaign_log_id, $premium); ?>
            </div>
            <div class="mo-report-side">
                <?php if ($premium && $stats['pruned']) : ?>
                    <div class="mo-card">
                        <h3><?php esc_html_e('Who opened and clicked', 'mailoptin'); ?></h3>
                        <p class="description"><?php esc_html_e('Who opened and clicked this campaign was deleted after the retention period set in Settings › Sending. The totals are kept.', 'mailoptin'); ?></p>
                    </div>
                <?php elseif ($premium) : ?>
                    <div class="mo-card">
                        <h3><?php esc_html_e('Who opened and clicked', 'mailoptin'); ?></h3>
                        <p class="mo-group-buttons">
                            <?php foreach (self::groups() as $key => $label) : ?>
                                <a class="button" href="<?php echo esc_url(self::url($campaign_log_id, ['people' => $key])); ?>"><?php echo esc_html(sprintf('%s (%s)', $label, number_format_i18n($groups[$key]))); ?></a>
                            <?php endforeach; ?>
                        </p>
                        <p class="description"><?php esc_html_e('See the people behind each number, tag them, export them or save them as a segment.', 'mailoptin'); ?></p>
                    </div>
                <?php else : ?>
                    <div class="mo-card mo-upsell">
                        <h3><?php esc_html_e('See who opened and clicked', 'mailoptin'); ?> <span class="mo-premium-badge"><?php esc_html_e('Premium', 'mailoptin'); ?></span></h3>
                        <p class="mo-muted"><?php esc_html_e('Open the people behind each number, tag them, or save them as a segment to follow up:', 'mailoptin'); ?></p>
                        <ul>
                            <li><?php esc_html_e('Send an offer to the people who clicked', 'mailoptin'); ?></li>
                            <li><?php esc_html_e('Tag people who clicked a product link', 'mailoptin'); ?></li>
                            <li><?php esc_html_e('Filter any segment by opens and clicks', 'mailoptin'); ?></li>
                        </ul>
                        <p><a class="button button-primary" target="_blank" href="<?php echo esc_url(CampaignAudience::upsell_url('builtin_campaign_report')); ?>"><?php esc_html_e('Get MailOptin Premium', 'mailoptin'); ?></a></p>
                    </div>
                <?php endif; ?>
                <div class="mo-card">
                    <h3><?php esc_html_e('About these numbers', 'mailoptin'); ?></h3>
                    <p class="description"><?php esc_html_e('Opens are counted when the email\'s images load. Apple Mail and some others load images for everyone, and others block them, so opens are an estimate. Clicks are exact, and a click also counts as an open.', 'mailoptin'); ?></p>
                    <p class="description"><?php
                        echo esc_html(Tracking::retention_days()
                            ? sprintf(__('Who opened and clicked is kept for %s days; the totals are kept after that. People who were deleted still count, without their details.', 'mailoptin'), number_format_i18n(Tracking::retention_days()))
                            : __('People who were deleted still count, without their details.', 'mailoptin'));
                        ?></p>
                    <?php if ( ! Tracking::tracks_opens() || ! Tracking::tracks_clicks()) : ?>
                        <p class="description"><?php esc_html_e('Tracking is turned off for opens or clicks in Settings › Sending, so new activity isn\'t counted.', 'mailoptin'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function chart_html($series)
    {
        $max = max(1, max(array_map('max', $series)));

        ob_start();
        ?>
        <div class="mo-card">
            <h3 class="mo-chart-head"><?php esc_html_e('First week', 'mailoptin'); ?>
                <span class="mo-legend"><i class="o"></i><?php esc_html_e('First opens', 'mailoptin'); ?><i class="c"></i><?php esc_html_e('First clicks', 'mailoptin'); ?></span></h3>
            <div class="mo-chart" role="img" aria-label="<?php esc_attr_e('People who first opened and clicked each day', 'mailoptin'); ?>">
                <?php foreach ($series as $day => $values) : ?>
                    <div class="mo-col" title="<?php echo esc_attr(sprintf(__('%1$s: %2$s opened, %3$s clicked', 'mailoptin'), $day, number_format_i18n($values[0]), number_format_i18n($values[1]))); ?>">
                        <div class="o" style="height:<?php echo round($values[0] / $max * 100, 1); ?>%"></div>
                        <div class="c" style="height:<?php echo round($values[1] / $max * 100, 1); ?>%"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="mo-axis"><?php foreach (array_keys($series) as $day) echo '<span>' . esc_html(date_i18n('M j', strtotime($day))) . '</span>'; ?></div>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function links_html($campaign_log_id, $premium)
    {
        $links = Activity::links($campaign_log_id);
        $most  = max(1, (int)max(array_merge([0], wp_list_pluck($links, 'people'))));

        ob_start();
        ?>
        <div class="mo-card">
            <h3><?php esc_html_e('Links', 'mailoptin'); ?></h3>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Link', 'mailoptin'); ?></th><th class="mo-num"><?php esc_html_e('Clicks', 'mailoptin'); ?></th><th class="mo-people-col"><?php esc_html_e('People', 'mailoptin'); ?></th></tr></thead>
                <tbody>
                <?php if (empty($links)) : ?>
                    <tr><td colspan="3"><?php esc_html_e('No tracked links. Links are tracked when the email is sent with click tracking on.', 'mailoptin'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($links as $link) : ?>
                    <tr>
                        <td class="mo-link-cell"><?php echo esc_html(preg_replace('#^https?://(www\.)?#i', '', $link['url'])); ?>
                            <?php if ('' !== $link['label']) : ?><br><span class="mo-muted">"<?php echo esc_html($link['label']); ?>"</span><?php endif; ?></td>
                        <td class="mo-num"><?php echo esc_html(number_format_i18n($link['clicks'])); ?></td>
                        <td class="mo-people-col"><span class="mo-bar"><span style="width:<?php echo round($link['people'] / $most * 100); ?>%"></span></span>
                            <?php if ($premium && $link['people']) : ?>
                                <a href="<?php echo esc_url(self::url($campaign_log_id, ['people' => 'clicked', 'link' => $link['id']])); ?>"><?php echo esc_html(number_format_i18n($link['people'])); ?></a>
                            <?php else : ?>
                                <?php echo esc_html(number_format_i18n($link['people'])); ?>
                            <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr><td class="mo-muted" colspan="3"><?php esc_html_e('Unsubscribe and preference centre links aren\'t counted as clicks.', 'mailoptin'); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function people_html($campaign_log_id, $title, $group)
    {
        $back = '<p class="mo-back"><a href="' . esc_url(self::url($campaign_log_id)) . '">&larr; ' . esc_html($title) . '</a></p>';

        if ( ! SegmentsRepository::is_available()) {
            return $back . '<div class="mo-card mo-upsell"><h3>' . esc_html__('See who opened and clicked', 'mailoptin') . ' <span class="mo-premium-badge">' . esc_html__('Premium', 'mailoptin') . '</span></h3>'
                   . '<p><a class="button button-primary" target="_blank" href="' . esc_url(CampaignAudience::upsell_url('builtin_campaign_people')) . '">' . esc_html__('Get MailOptin Premium', 'mailoptin') . '</a></p></div>';
        }

        $link_id = absint($_GET['link'] ?? 0);
        $link    = $link_id ? Activity::get_link($link_id) : null;
        $link_id = $link && (int)$link['campaign_log_id'] === $campaign_log_id ? $link_id : 0;
        $page    = max(1, absint($_GET['paged'] ?? 1));
        $people  = Activity::people($campaign_log_id, $group, $link_id, $page, self::PER_PAGE);
        if (Activity::is_pruned($campaign_log_id)) {
            return $back . '<p>' . esc_html__('Who opened and clicked this campaign was deleted after the retention period set in Settings › Sending. The totals are kept.', 'mailoptin') . '</p>';
        }

        $counts  = Activity::engagement_counts($campaign_log_id);
        $tags    = Tags::for_subscribers(wp_list_pluck($people['items'], 'id'));
        $filter  = $link_id ? null : self::list_filter($campaign_log_id, $group);

        ob_start();
        echo $back;
        ?>
        <h1 class="mo-report-title"><?php echo esc_html(sprintf(__('%1$s: "%2$s"', 'mailoptin'), self::groups()[$group], $title)); ?></h1>
        <p class="mo-pills">
            <?php foreach (self::groups() as $key => $label) : ?>
                <a class="<?php echo $key === $group && ! $link_id ? 'on' : ''; ?>" href="<?php echo esc_url(self::url($campaign_log_id, ['people' => $key])); ?>"><?php echo esc_html(sprintf('%s · %s', $label, number_format_i18n($counts[$key]))); ?></a>
            <?php endforeach; ?>
        </p>
        <?php if ($link_id) : ?>
            <p><?php echo esc_html(sprintf(__('Showing people who clicked %s', 'mailoptin'), '' !== $link['label'] ? '"' . $link['label'] . '"' : $link['url'])); ?>
                · <a href="<?php echo esc_url(self::url($campaign_log_id, ['people' => 'clicked'])); ?>"><?php esc_html_e('Any link', 'mailoptin'); ?></a></p>
        <?php endif; ?>
        <?php if ('not_opened' === $group) : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e('Opens are an estimate. Some email apps block images, so some of these people did read the email. Clicks are exact.', 'mailoptin'); ?></p></div>
        <?php endif; ?>

        <?php if ($people['total']) : ?>
            <div class="mo-people-actions">
                <form method="post" class="mo-inline-form">
                    <?php wp_nonce_field('mo_report_tag_' . $campaign_log_id); ?>
                    <input type="hidden" name="mo_report_action" value="tag">
                    <input type="hidden" name="report" value="<?php echo absint($campaign_log_id); ?>">
                    <input type="hidden" name="people" value="<?php echo esc_attr($group); ?>">
                    <input type="hidden" name="link" value="<?php echo absint($link_id); ?>">
                    <label class="screen-reader-text" for="mo-report-tags"><?php esc_html_e('Tags', 'mailoptin'); ?></label>
                    <input type="text" id="mo-report-tags" name="tags" placeholder="<?php esc_attr_e('Tags, comma separated', 'mailoptin'); ?>" required>
                    <?php submit_button(sprintf(_n('Tag %s person', 'Tag all %s', $people['total'], 'mailoptin'), number_format_i18n($people['total'])), '', 'submit', false); ?>
                </form>
                <?php if ($filter) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg($filter, Subscribers_Page::page_url())); ?>"><?php esc_html_e('Open in subscribers list', 'mailoptin'); ?></a>
                    <a class="button" href="<?php echo esc_url(Subscribers_Import_Page::export_url($filter)); ?>"><?php esc_html_e('Export CSV', 'mailoptin'); ?></a>
                <?php endif; ?>
            </div>
            <?php if ($filter && 'not_opened' !== $group) : ?>
                <p class="description"><?php esc_html_e('In the subscribers list you can save these people as a segment, or narrow them down with more filters.', 'mailoptin'); ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th><?php esc_html_e('Email', 'mailoptin'); ?></th><th><?php esc_html_e('Name', 'mailoptin'); ?></th><th><?php esc_html_e('Tags', 'mailoptin'); ?></th><th><?php esc_html_e('Status', 'mailoptin'); ?></th><th><?php esc_html_e('Last activity', 'mailoptin'); ?></th></tr></thead>
            <tbody>
            <?php if (empty($people['items'])) : ?>
                <tr><td colspan="5"><?php esc_html_e('Nobody here yet.', 'mailoptin'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($people['items'] as $person) : ?>
                <tr>
                    <td><a href="<?php echo esc_url(add_query_arg('subscriber', $person['id'], Subscribers_Page::page_url())); ?>"><strong><?php echo esc_html($person['email']); ?></strong></a></td>
                    <td><?php echo esc_html(trim($person['first_name'] . ' ' . $person['last_name'])); ?></td>
                    <td><?php foreach ($tags[(int)$person['id']] ?? [] as $tag) echo Subscribers_Tags_Fields::tag_chip($tag); ?></td>
                    <td><?php echo Subscribers_Page::status_pill($person['status']); ?></td>
                    <td class="mo-muted"><?php echo $person['last_activity'] ? esc_html(get_date_from_gmt($person['last_activity'], get_option('date_format') . ' ' . get_option('time_format'))) : '&mdash;'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $pages = (int)ceil($people['total'] / self::PER_PAGE);

        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $page,
                    'total'   => $pages,
                ]) . '</div></div>';
        }

        return ob_get_clean();
    }

    public function handle_actions()
    {
        if (empty($_POST['mo_report_action']) || 'tag' !== $_POST['mo_report_action']) return;

        $campaign_log_id = absint($_POST['report'] ?? 0);

        check_admin_referer('mo_report_tag_' . $campaign_log_id);

        if ( ! current_user_has_privilege() || ! SegmentsRepository::is_available() || ! Activity::is_ready()) wp_die(esc_html__('You are not allowed to do this.', 'mailoptin'), 403);

        $group   = sanitize_key($_POST['people'] ?? '');
        $link_id = absint($_POST['link'] ?? 0);
        $back    = self::url($campaign_log_id, array_filter(['people' => $group, 'link' => $link_id]));
        $tag_ids = Tags::ensure(array_filter(array_map('trim', explode(',', sanitize_text_field(wp_unslash($_POST['tags'] ?? ''))))));

        if (empty($tag_ids)) {
            do_admin_redirect(add_query_arg('mo_notice', 'no_tags', $back));

            return;
        }

        $names = Tags::names();
        $after = 0;
        $count = 0;

        do {
            $ids = Activity::people_ids($campaign_log_id, $group, $link_id, $after);

            if (empty($ids)) break;

            $added = Tags::add_to_subscribers($tag_ids, $ids);

            Subscribers::log_events(Subscribers::EVENT_TAGS_ADDED, array_map(function ($added_ids) use ($names) {
                return ['tags' => array_values(array_intersect_key($names, array_flip($added_ids))), 'by_admin' => get_current_user_id()];
            }, array_filter($added)));

            $count += count($ids);
            $after = end($ids);
        } while (count($ids) === 500);

        do_admin_redirect(add_query_arg(['mo_notice' => 'tagged', 'count' => $count], $back));
    }

    protected static function styles()
    {
        return '<style>
.mo-report{max-width:1240px}
.mo-report .mo-back{margin:12px 0 0}
.mo-report-title{font-size:22px;margin:10px 0 2px;padding:0}
.mo-report .mo-muted{color:#646970}
.mo-report .mo-stats{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin:12px 0 16px}
.mo-report .mo-stats div{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:10px 14px}
.mo-report .mo-stats b{display:block;font-size:24px;line-height:1.3;font-weight:600}
.mo-report .mo-stats small{display:block;color:#646970}
.mo-report-cols{display:flex;gap:16px;align-items:flex-start}
.mo-report-main{flex:2;min-width:0}.mo-report-side{flex:1}
.mo-report .mo-card{background:#fff;border:1px solid #c3c4c7;padding:4px 20px 16px;margin-bottom:16px}
.mo-report .mo-card h3{font-size:15px}
.mo-report .mo-upsell{border-left:4px solid #7e3bd0}
.mo-report .mo-upsell ul{list-style:disc;margin-left:18px;color:#50575e}
.mo-premium-badge{display:inline-block;font-size:11px;line-height:16px;padding:0 6px;border-radius:99px;background:#f0e6ff;color:#5b2a9e;margin-left:4px;font-weight:600;vertical-align:middle}
.mo-chart-head{display:flex;justify-content:space-between}
.mo-legend{font-size:12px;font-weight:400;color:#50575e}.mo-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin:0 4px 0 12px;vertical-align:-1px}
.mo-legend i.o,.mo-chart .o{background:#72aee6}.mo-legend i.c,.mo-chart .c{background:#2271b1}
.mo-chart{display:flex;align-items:flex-end;gap:6px;height:150px;border-bottom:1px solid #dcdcde}
.mo-chart .mo-col{flex:1;display:flex;align-items:flex-end;gap:2px;height:100%}
.mo-chart .mo-col div{flex:1;border-radius:2px 2px 0 0;min-height:1px}
.mo-axis{display:flex;gap:6px;font-size:11px;color:#646970;margin-top:4px}.mo-axis span{flex:1;text-align:center}
.mo-report .mo-num{width:80px}.mo-report .mo-people-col{width:220px}
.mo-report .mo-link-cell{overflow-wrap:anywhere}
.mo-bar{height:8px;background:#f0f0f1;border-radius:4px;overflow:hidden;width:120px;display:inline-block;vertical-align:middle;margin-right:8px}.mo-bar span{display:block;height:100%;background:#2271b1}
.mo-group-buttons .button{margin:0 4px 6px 0}
.mo-pills{display:flex;gap:8px;flex-wrap:wrap}
.mo-pills a{padding:5px 12px;border:1px solid #c3c4c7;border-radius:99px;text-decoration:none;color:#3c434a;background:#fff}
.mo-pills a.on{background:#2271b1;color:#fff;border-color:#2271b1}
.mo-people-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0}
.mo-report .mo-inline-form{display:inline-flex;gap:6px;align-items:center;margin:0}
.mo-report .mo-tag{display:inline-block;padding:1px 8px;margin:1px 4px 1px 0;border-radius:4px;background:#f0f0f1;border:1px solid #dcdcde;font-size:12px;line-height:18px;color:#1d2327}
.mo-report .mo-pill{display:inline-block;padding:1px 8px;border-radius:99px;font-size:12px;font-weight:600;background:#f0f0f1;color:#50575e}
.mo-report .mo-pill-subscribed{background:#edfaef;color:#00701a}.mo-report .mo-pill-pending{background:#fcf9e8;color:#8a6100}
.mo-report .mo-pill-unsubscribed,.mo-report .mo-pill-bounced,.mo-report .mo-pill-complained{background:#fcf0f1;color:#b32d2e}
@media (max-width:960px){.mo-report-cols{flex-direction:column}.mo-report-side{width:100%}.mo-report .mo-stats{grid-template-columns:repeat(3,1fr)}}
</style>';
    }

    /**
     * @return Campaign_Report
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
