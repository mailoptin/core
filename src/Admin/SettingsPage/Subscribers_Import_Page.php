<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Audience\SubscriberExport as Export;
use MailOptin\Core\Audience\SubscriberImport as Import;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;
use MailOptin\Core\SendQueue\QueueRunner;

/**
 * The Import and Export screens of the Subscribers page. Server-rendered and usable without
 * JavaScript: the progress page reloads itself and imports a batch on each load.
 */
class Subscribers_Import_Page
{
    public static function import_url($import_id = '')
    {
        $url = Subscribers_Page::page_url('import');

        return $import_id ? add_query_arg('import', $import_id, $url) : $url;
    }

    /**
     * The subscribers list's current view (status, list, search, tag and field filters) as query args.
     */
    public static function view_args($request)
    {
        $args = [];

        foreach (['status', 'list', 's'] as $key) {
            if (isset($request[$key]) && is_scalar($request[$key]) && '' !== $request[$key]) $args[$key] = sanitize_text_field(wp_unslash($request[$key]));
        }

        return $args + Subscriber_Filters::to_query(Subscriber_Filters::from_request($request));
    }

    /**
     * Arguments for SubscribersRepository::query() from the list's view args.
     */
    public static function query_args($request)
    {
        return Subscriber_Filters::from_request($request) + [
                'status'  => sanitize_key(is_scalar($request['status'] ?? '') ? $request['status'] ?? '' : ''),
                'list_id' => absint(is_scalar($request['list'] ?? 0) ? $request['list'] ?? 0 : 0),
                'search'  => is_scalar($request['s'] ?? '') ? sanitize_text_field(wp_unslash($request['s'] ?? '')) : '',
            ];
    }

    public static function export_url($request)
    {
        return add_query_arg(self::view_args($request), Subscribers_Page::page_url('export'));
    }

    protected static function steps_html($current)
    {
        $steps = ['upload' => __('Upload', 'mailoptin'), 'map' => __('Match columns', 'mailoptin'), 'import' => __('Import', 'mailoptin'), 'done' => __('Done', 'mailoptin')];
        $html  = '<ol class="mo-steps">';
        $seen  = false;

        foreach ($steps as $step => $label) {
            $class = $step === $current ? 'on' : ($seen ? '' : 'done');
            if ($step === $current) $seen = true;
            $html .= sprintf('<li class="%s"%s>%s</li>', $class, $step === $current ? ' aria-current="step"' : '', esc_html($label));
        }

        return $html . '</ol>';
    }

    /* ---------------------------------------------------------------------------------------------
     * Import screens
     * ------------------------------------------------------------------------------------------ */

    public static function import_html()
    {
        $job = isset($_GET['import']) && is_scalar($_GET['import']) ? Import::get(sanitize_key($_GET['import'])) : null;

        if ( ! $job) return self::upload_html();

        if ($job['user_id'] && (int)$job['user_id'] !== get_current_user_id() && ! current_user_can('manage_options')) {
            return '<p>' . esc_html__('This import belongs to another user.', 'mailoptin') . '</p>';
        }

        switch ($job['status']) {
            case Import::STATUS_MAPPING:
                return self::map_html($job);
            case Import::STATUS_RUNNING:
                return self::progress_html(Import::run_batch($job['id']));
            default:
                return self::report_html($job);
        }
    }

    protected static function back_link()
    {
        return sprintf('<p><a href="%s">&larr; %s</a></p>', esc_url(Subscribers_Page::page_url()), esc_html__('All subscribers', 'mailoptin'));
    }

    protected static function error_notice()
    {
        $error = Subscribers_Tags_Fields::take_error();

        return $error ? sprintf('<div class="notice notice-error inline"><p>%s</p></div>', esc_html($error['message'])) : '';
    }

    protected static function upload_html()
    {
        $limit = min(Import::MAX_BYTES, wp_max_upload_size());

        ob_start();
        echo self::back_link();
        echo self::steps_html('upload');
        echo self::error_notice();
        ?>
        <div class="mo-columns">
            <div class="mo-col-main">
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(self::import_url()); ?>" class="mo-card">
                    <?php wp_nonce_field('mo_upload_import'); ?>
                    <input type="hidden" name="mo_subscriber_action" value="upload_import">
                    <h3><?php esc_html_e('Choose a CSV file', 'mailoptin'); ?></h3>
                    <label class="mo-drop" for="mo-import-file">
                        <b><?php esc_html_e('Choose a .csv file', 'mailoptin'); ?></b>
                        <input type="file" id="mo-import-file" name="import_file" accept=".csv,.txt,text/csv" required>
                        <span class="mo-muted"><?php printf(esc_html__('Up to %s. The first row should be column names, like Email and First name.', 'mailoptin'), esc_html(size_format($limit))); ?></span>
                    </label>
                    <p class="description"><?php esc_html_e('Exported from Mailchimp, ConvertKit, a spreadsheet or another plugin? Any CSV with an email column works. In Excel, use File › Save As › "CSV UTF-8".', 'mailoptin'); ?></p>
                    <?php submit_button(__('Upload and continue', 'mailoptin')); ?>
                </form>
            </div>
            <div class="mo-col-side">
                <div class="mo-card">
                    <h3><?php esc_html_e('Good to know', 'mailoptin'); ?></h3>
                    <p><?php esc_html_e('People who unsubscribed, bounced or complained are never added back. They\'re listed in the report.', 'mailoptin'); ?></p>
                    <p><?php esc_html_e('Existing subscribers are matched by email and updated, not duplicated.', 'mailoptin'); ?></p>
                    <p><?php esc_html_e('Nothing changes until you confirm the column matches on the next step.', 'mailoptin'); ?></p>
                </div>
            </div>
        </div>
        <?php echo self::recent_html(); ?>
        <?php
        return ob_get_clean();
    }

    protected static function status_label($job)
    {
        $labels = [
            Import::STATUS_MAPPING   => [__('Waiting for column matches', 'mailoptin'), ''],
            Import::STATUS_RUNNING   => [__('Importing', 'mailoptin'), 'pending'],
            Import::STATUS_DONE      => [__('Done', 'mailoptin'), 'subscribed'],
            Import::STATUS_CANCELLED => [__('Cancelled', 'mailoptin'), 'unsubscribed'],
        ];

        $label = $labels[$job['status']] ?? [$job['status'], ''];

        return sprintf('<span class="mo-pill mo-pill-%s">%s</span>', esc_attr($label[1]), esc_html($label[0]));
    }

    protected static function recent_html()
    {
        $jobs = Import::all();

        if (empty($jobs)) return '';

        ob_start();
        ?>
        <h2><?php esc_html_e('Recent imports', 'mailoptin'); ?></h2>
        <table class="wp-list-table widefat striped mo-imports-table">
            <thead><tr>
                <th><?php esc_html_e('File', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Started', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Status', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Added', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Updated', 'mailoptin'); ?></th>
                <th><?php esc_html_e('Skipped', 'mailoptin'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($jobs as $job) : ?>
                <tr>
                    <td><a class="row-title" href="<?php echo esc_url(self::import_url($job['id'])); ?>"><?php echo esc_html($job['name']); ?></a></td>
                    <td><?php echo esc_html(get_date_from_gmt($job['created_at'], get_option('date_format') . ' · ' . get_option('time_format'))); ?></td>
                    <td><?php echo self::status_label($job); ?></td>
                    <td><?php echo esc_html(number_format_i18n($job['counts']['added'])); ?></td>
                    <td><?php echo esc_html(number_format_i18n($job['counts']['updated'])); ?></td>
                    <td><?php echo esc_html(number_format_i18n(Import::skipped_count($job))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description"><?php printf(esc_html__('Reports are kept for %d days.', 'mailoptin'), Import::REPORT_DAYS); ?></p>
        <?php
        return ob_get_clean();
    }

    protected static function map_html($job)
    {
        $targets = Import::targets();
        $lists   = ListsRepository::get_all();
        $hourly  = max(1, QueueRunner::get_instance()->hourly_allowance() + \MailOptin\Core\Repositories\SendQueueRepository::sent_since(\MailOptin\Core\Repositories\SendQueueRepository::now(-HOUR_IN_SECONDS)));
        $premium = Import::can_import_fields();
        $fields  = Tags::is_ready() ? Fields::all() : [];

        ob_start();
        echo self::back_link();
        echo self::steps_html('map');
        echo self::error_notice();
        ?>
        <form method="post" action="<?php echo esc_url(self::import_url($job['id'])); ?>">
            <?php wp_nonce_field('mo_start_import_' . $job['id']); ?>
            <input type="hidden" name="mo_subscriber_action" value="start_import">
            <input type="hidden" name="import" value="<?php echo esc_attr($job['id']); ?>">
            <div class="mo-columns">
                <div class="mo-col-main">
                    <div class="mo-card">
                        <h3><?php esc_html_e('Match columns', 'mailoptin'); ?> <span class="mo-muted mo-normal"><?php echo esc_html(sprintf(_n('%1$s · %2$s row', '%1$s · %2$s rows', $job['total'], 'mailoptin'), $job['name'], number_format_i18n($job['total']))); ?></span></h3>
                        <table class="widefat striped mo-map-table">
                            <thead><tr>
                                <th><?php esc_html_e('Column in your file', 'mailoptin'); ?></th>
                                <th><?php esc_html_e('First row', 'mailoptin'); ?></th>
                                <th><?php esc_html_e('Import as', 'mailoptin'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($job['headers'] as $index => $header) :
                                $current = $job['mapping'][$index] ?? '';
                                $id = 'mo-map-' . $index;
                                ?>
                                <tr>
                                    <td><label for="<?php echo esc_attr($id); ?>"><code><?php echo esc_html('' !== $header ? $header : sprintf(__('Column %d', 'mailoptin'), $index + 1)); ?></code></label></td>
                                    <td class="mo-sample"><?php echo esc_html(wp_html_excerpt((string)($job['sample'][$index] ?? ''), 60, '…')); ?></td>
                                    <td>
                                        <select id="<?php echo esc_attr($id); ?>" name="mapping[<?php echo absint($index); ?>]">
                                            <option value=""><?php esc_html_e('Don\'t import', 'mailoptin'); ?></option>
                                            <?php foreach ($targets as $target => $label) : ?>
                                                <option value="<?php echo esc_attr($target); ?>" <?php selected($current, $target); ?>><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ('' !== $current) : ?><span class="mo-muted"><?php esc_html_e('matched', 'mailoptin'); ?></span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($premium) : ?>
                            <p class="description"><?php printf(esc_html__('Columns are matched by name. To import a column that has nowhere to go yet, %sadd a custom field%s, then reload this page.', 'mailoptin'), '<a target="_blank" href="' . esc_url(add_query_arg('field', 'new', Subscribers_Page::page_url('fields'))) . '">', '</a>'); ?></p>
                        <?php elseif ( ! empty($fields)) : ?>
                            <p class="description"><?php printf(esc_html__('Importing columns into custom fields is available in %sMailOptin Premium%s.', 'mailoptin'), '<a target="_blank" href="https://mailoptin.io/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=subscriber_import">', '</a>'); ?></p>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e('Columns are matched by name. Choose "Don\'t import" for columns you don\'t need.', 'mailoptin'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mo-col-side">
                    <div class="mo-card">
                        <fieldset>
                            <legend><?php esc_html_e('Add to lists', 'mailoptin'); ?></legend>
                            <?php if (empty($lists)) : ?>
                                <p><?php printf(esc_html__('There are no lists yet. %sCreate one%s first.', 'mailoptin'), '<a href="' . esc_url(Subscribers_Page::page_url('lists')) . '">', '</a>'); ?></p>
                            <?php endif; ?>
                            <?php foreach ($lists as $list) : ?>
                                <label class="mo-check"><input type="checkbox" name="lists[]" value="<?php echo absint($list['id']); ?>" <?php checked(in_array((int)$list['id'], $job['lists'], true) || (1 === count($lists))); ?>> <?php echo esc_html($list['name']); ?></label>
                            <?php endforeach; ?>
                        </fieldset>
                        <?php if (Tags::is_ready()) : ?>
                            <p>
                                <label for="mo-import-tag"><strong><?php esc_html_e('Tag everyone in this file', 'mailoptin'); ?></strong></label><br>
                                <input type="text" class="widefat" id="mo-import-tag" name="tag_all" value="<?php echo esc_attr($job['tag_all']); ?>" placeholder="<?php echo esc_attr(sprintf(__('for example imported-%s', 'mailoptin'), strtolower(date_i18n('M-Y')))); ?>">
                                <span class="description"><?php esc_html_e('Optional. Makes it easy to find or undo this import later.', 'mailoptin'); ?></span>
                            </p>
                        <?php endif; ?>
                        <fieldset>
                            <legend><?php esc_html_e('Permission', 'mailoptin'); ?></legend>
                            <label class="mo-check"><input type="radio" name="permission" value="<?php echo esc_attr(Import::PERMISSION_SUBSCRIBED); ?>" <?php checked($job['permission'], Import::PERMISSION_SUBSCRIBED); ?> required> <?php esc_html_e('They agreed to hear from me. Add them as subscribed.', 'mailoptin'); ?></label>
                            <label class="mo-check"><input type="radio" name="permission" value="<?php echo esc_attr(Import::PERMISSION_CONFIRM); ?>" <?php checked($job['permission'], Import::PERMISSION_CONFIRM); ?>> <?php esc_html_e('Send each new person a confirmation email', 'mailoptin'); ?></label>
                            <span class="description"><?php
                                $hours = (int)ceil($job['total'] / $hourly);
                                printf(
                                    esc_html__('Your choice is saved on each new person\'s consent record. Only add people as subscribed if they agreed to get your emails: importing a bought or scraped list can get your site blocked from sending. Confirmation emails go through the send queue at your hourly limit of %1$s, shared with campaigns: up to %2$s for this file.', 'mailoptin'),
                                    esc_html(number_format_i18n($hourly)),
                                    esc_html($hours > 48 ? sprintf(_n('%s day', '%s days', (int)ceil($hours / 24), 'mailoptin'), number_format_i18n(ceil($hours / 24))) : sprintf(_n('%s hour', '%s hours', $hours, 'mailoptin'), number_format_i18n($hours)))
                                );
                                ?></span>
                        </fieldset>
                        <fieldset>
                            <legend><?php esc_html_e('People already on your lists', 'mailoptin'); ?></legend>
                            <label class="mo-check"><input type="radio" name="existing" value="<?php echo esc_attr(Import::EXISTING_FILL); ?>" <?php checked($job['existing'], Import::EXISTING_FILL); ?>> <?php esc_html_e('Only fill in details that are empty', 'mailoptin'); ?></label>
                            <label class="mo-check"><input type="radio" name="existing" value="<?php echo esc_attr(Import::EXISTING_REPLACE); ?>" <?php checked($job['existing'], Import::EXISTING_REPLACE); ?>> <?php esc_html_e('Replace their details with the file\'s', 'mailoptin'); ?></label>
                            <span class="description"><?php esc_html_e('Empty cells never clear anything. Their status, lists they left and their consent record don\'t change.', 'mailoptin'); ?></span>
                        </fieldset>
                        <p>
                            <?php submit_button(sprintf(_n('Import %s row', 'Import %s rows', $job['total'], 'mailoptin'), number_format_i18n($job['total'])), 'primary', 'submit', false); ?>
                            <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['mo_subscriber_action' => 'delete_import', 'import' => $job['id']], self::import_url()), 'mo_delete_import_' . $job['id'])); ?>"><?php esc_html_e('Cancel', 'mailoptin'); ?></a>
                        </p>
                    </div>
                </div>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    protected static function progress_html($job)
    {
        if (Import::STATUS_RUNNING !== $job['status']) return self::report_html($job);

        $percent = $job['total'] ? min(100, (int)floor($job['row'] * 100 / $job['total'])) : 0;

        ob_start();
        // Reload to import the next batch; cron carries on if the page is closed.
        ?>
        <meta http-equiv="refresh" content="1">
        <?php echo self::back_link(); ?>
        <?php echo self::steps_html('import'); ?>
        <div class="mo-card mo-narrow-wide">
            <h3><?php echo esc_html(sprintf(__('Importing %s…', 'mailoptin'), $job['name'])); ?></h3>
            <div class="mo-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $percent; ?>"><span style="width:<?php echo $percent; ?>%"></span></div>
            <p><strong><?php echo esc_html(sprintf(__('%1$s of %2$s rows', 'mailoptin'), number_format_i18n(min($job['row'], $job['total'])), number_format_i18n($job['total']))); ?></strong></p>
            <p class="description"><?php esc_html_e('You can leave this page. The import carries on in the background, and its report is under Subscribers › Import CSV.', 'mailoptin'); ?></p>
            <p><a class="button mo-confirm" data-confirm="<?php esc_attr_e('Stop the import? Rows already imported stay imported.', 'mailoptin'); ?>" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['mo_subscriber_action' => 'cancel_import', 'import' => $job['id']], self::import_url($job['id'])), 'mo_cancel_import_' . $job['id'])); ?>"><?php esc_html_e('Stop import', 'mailoptin'); ?></a></p>
        </div>
        <?php
        return ob_get_clean();
    }

    protected static function report_html($job)
    {
        $types = [
            Import::PROBLEM_SUPPRESSED => [__('Unsubscribed', 'mailoptin'), 'unsubscribed'],
            Import::PROBLEM_INVALID    => [__('Not valid', 'mailoptin'), 'pending'],
            Import::PROBLEM_REPEATED   => [__('Repeated', 'mailoptin'), ''],
            Import::PROBLEM_NOTE       => [__('Note', 'mailoptin'), ''],
        ];
        $lists    = array_filter(array_map(function ($list_id) {
            $list = ListsRepository::get($list_id);

            return $list ? $list['name'] : '';
        }, $job['lists']));
        $problems = array_sum(array_intersect_key($job['counts'], $types));
        $tag_id   = '' !== $job['tag_all'] && Tags::is_ready() ? (int)(Tags::get_by_name($job['tag_all'])['id'] ?? 0) : 0;

        ob_start();
        echo self::back_link();
        echo self::steps_html('done');
        if (Import::STATUS_DONE === $job['status']) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Import finished.', 'mailoptin') . '</p></div>';
        } elseif (Import::STATUS_CANCELLED === $job['status']) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html(! empty($job['error']) ? $job['error'] : __('The import was stopped. Rows imported before that stay imported.', 'mailoptin')) . '</p></div>';
        }
        ?>
        <div class="mo-card">
            <h3><?php echo esc_html($job['name']); ?></h3>
            <div class="mo-stats">
                <div><b><?php echo esc_html(number_format_i18n($job['counts']['added'])); ?></b><?php esc_html_e('added', 'mailoptin'); ?></div>
                <div><b><?php echo esc_html(number_format_i18n($job['counts']['updated'])); ?></b><?php esc_html_e('updated', 'mailoptin'); ?></div>
                <div><b><?php echo esc_html(number_format_i18n($job['counts'][Import::PROBLEM_SUPPRESSED])); ?></b><?php esc_html_e('skipped: unsubscribed, bounced or complained', 'mailoptin'); ?></div>
                <div><b><?php echo esc_html(number_format_i18n($job['counts'][Import::PROBLEM_INVALID] + $job['counts'][Import::PROBLEM_REPEATED])); ?></b><?php esc_html_e('skipped: email not valid or repeated', 'mailoptin'); ?></div>
            </div>
            <?php if ( ! empty($lists)) : ?>
                <p><?php
                    printf(
                        esc_html(Import::PERMISSION_CONFIRM === $job['permission'] ? __('Added to %s, waiting for them to confirm by email.', 'mailoptin') : __('Added to %s as subscribed.', 'mailoptin')),
                        '<strong>' . esc_html(implode(', ', $lists)) . '</strong>'
                    );
                    if ('' !== $job['tag_all']) echo ' ' . sprintf(esc_html__('Tagged %s.', 'mailoptin'), Subscribers_Tags_Fields::tag_chip($job['tag_all']));
                    ?></p>
            <?php endif; ?>
            <?php if ( ! empty($job['log_id'])) :
                $counts = \MailOptin\Core\Repositories\SendQueueRepository::counts($job['log_id']); ?>
                <p><?php printf(esc_html__('Confirmation emails: %1$s sent, %2$s waiting in the send queue.', 'mailoptin'), esc_html(number_format_i18n($counts['sent'])), esc_html(number_format_i18n($counts['pending'] + $counts['claimed'] + $counts['sending']))); ?></p>
            <?php endif; ?>
            <p>
                <?php if ($tag_id) : ?>
                    <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['tags' => [$tag_id]], Subscribers_Page::page_url())); ?>"><?php esc_html_e('View imported subscribers', 'mailoptin'); ?></a>
                <?php else : ?>
                    <a class="button button-primary" href="<?php echo esc_url(Subscribers_Page::page_url()); ?>"><?php esc_html_e('View subscribers', 'mailoptin'); ?></a>
                <?php endif; ?>
                <?php if ($problems && is_file(Import::problems_path($job))) : ?>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['mo_subscriber_action' => 'download_import_problems', 'import' => $job['id']], self::import_url()), 'mo_download_import_problems_' . $job['id'])); ?>"><?php printf(esc_html(_n('Download the %s row with a problem', 'Download the %s rows with a problem', $problems, 'mailoptin')), esc_html(number_format_i18n($problems))); ?></a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url(self::import_url()); ?>"><?php esc_html_e('Import another file', 'mailoptin'); ?></a>
            </p>
            <?php if ( ! empty($job['problems'])) : ?>
                <table class="widefat striped mo-problems-table">
                    <thead><tr><th><?php esc_html_e('Row', 'mailoptin'); ?></th><th><?php esc_html_e('Email', 'mailoptin'); ?></th><th><?php esc_html_e('Problem', 'mailoptin'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($job['problems'] as $problem) : $type = $types[$problem['type']] ?? ['', '']; ?>
                        <tr>
                            <td><?php echo absint($problem['row']); ?></td>
                            <td><?php echo esc_html($problem['email']); ?></td>
                            <td><span class="mo-pill mo-pill-<?php echo esc_attr($type[1]); ?>"><?php echo esc_html($type[0]); ?></span> <?php echo esc_html($problem['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="mo-muted"><?php printf(esc_html__('Showing %1$s of %2$s. The report is kept for %3$d days.', 'mailoptin'), esc_html(number_format_i18n(count($job['problems']))), esc_html(number_format_i18n($problems)), Import::REPORT_DAYS); ?></p>
            <?php endif; ?>
            <p><a class="button-link mo-button-danger mo-confirm" data-confirm="<?php esc_attr_e('Delete this report? The imported subscribers are kept.', 'mailoptin'); ?>" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['mo_subscriber_action' => 'delete_import', 'import' => $job['id']], self::import_url()), 'mo_delete_import_' . $job['id'])); ?>"><?php esc_html_e('Delete report', 'mailoptin'); ?></a></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------------------------------
     * Export screen
     * ------------------------------------------------------------------------------------------ */

    public static function export_html()
    {
        $view  = self::view_args($_GET);
        $total = Subscribers::query(self::query_args($_GET) + ['per_page' => 1])['total'];
        $chips = Tags::is_ready() ? Subscriber_Filters::chips_html(Subscriber_Filters::from_request($_GET), Subscribers_Page::page_url('export')) : '';
        $count = Tags::is_ready() ? count(Fields::all()) : 0;

        ob_start();
        ?>
        <p><a href="<?php echo esc_url(add_query_arg($view, Subscribers_Page::page_url())); ?>">&larr; <?php esc_html_e('Back to the list', 'mailoptin'); ?></a></p>
        <form method="get" class="mo-card mo-narrow">
            <input type="hidden" name="page" value="<?php echo esc_attr(Subscribers_Page::SLUG); ?>">
            <input type="hidden" name="view" value="export">
            <input type="hidden" name="mo_subscriber_action" value="export">
            <?php wp_nonce_field('mo_export_subscribers', '_wpnonce', false); ?>
            <?php foreach (explode('&', http_build_query($view)) as $pair) :
                if ('' === $pair) continue;
                list($name, $value) = array_map('urldecode', array_pad(explode('=', $pair, 2), 2, '')); ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
            <?php endforeach; ?>
            <h3><?php printf(esc_html(_n('Export %s subscriber', 'Export %s subscribers', $total, 'mailoptin')), esc_html(number_format_i18n($total))); ?></h3>
            <p><?php echo empty(array_diff_key($view, ['page' => 1])) ? esc_html__('Everyone on your lists.', 'mailoptin') : esc_html__('Everyone matching the list\'s current filters and search.', 'mailoptin'); ?></p>
            <?php if ($chips) : ?><div class="mo-filter-bar mo-static-chips"><?php echo $chips; ?></div><?php endif; ?>
            <fieldset>
                <legend><?php esc_html_e('Columns', 'mailoptin'); ?></legend>
                <label class="mo-check"><input type="checkbox" checked disabled> <?php esc_html_e('Email, first and last name, status, date added', 'mailoptin'); ?></label>
                <label class="mo-check"><input type="checkbox" name="columns[]" value="<?php echo esc_attr(Export::COLUMNS_LISTS_TAGS); ?>" checked> <?php echo Tags::is_ready() ? esc_html__('Lists and tags', 'mailoptin') : esc_html__('Lists', 'mailoptin'); ?></label>
                <?php if ($count) : ?>
                    <label class="mo-check"><input type="checkbox" name="columns[]" value="<?php echo esc_attr(Export::COLUMNS_FIELDS); ?>" checked> <?php printf(esc_html__('Custom fields (%s)', 'mailoptin'), esc_html(number_format_i18n($count))); ?></label>
                <?php endif; ?>
                <label class="mo-check"><input type="checkbox" name="columns[]" value="<?php echo esc_attr(Export::COLUMNS_CONSENT); ?>"> <?php esc_html_e('Consent record: source, wording, IP address, dates', 'mailoptin'); ?></label>
            </fieldset>
            <p class="description"><?php esc_html_e('The file has personal data. Store and share it carefully. It can be imported again here.', 'mailoptin'); ?></p>
            <?php submit_button(__('Download CSV', 'mailoptin'), 'primary', '', false, $total ? [] : ['disabled' => 'disabled']); ?>
        </form>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return array|null [notice, url] to redirect to; null if it isn't one of these actions
     */
    public static function handle_action($action)
    {
        $id = isset($_REQUEST['import']) && is_scalar($_REQUEST['import']) ? sanitize_key($_REQUEST['import']) : '';

        switch ($action) {
            case 'upload_import':
                check_admin_referer('mo_upload_import');
                $job = Import::create_from_upload($_FILES['import_file'] ?? null);

                if (is_wp_error($job)) {
                    Subscribers_Tags_Fields::set_error($job->get_error_message());

                    return ['error', self::import_url()];
                }

                return ['', self::import_url($job['id'])];

            case 'start_import':
                check_admin_referer('mo_start_import_' . $id);
                $result = Import::start($id, [
                    'mapping'    => (array)($_POST['mapping'] ?? []),
                    'lists'      => (array)($_POST['lists'] ?? []),
                    'tag_all'    => wp_unslash($_POST['tag_all'] ?? ''),
                    'permission' => sanitize_key($_POST['permission'] ?? ''),
                    'existing'   => sanitize_key($_POST['existing'] ?? ''),
                ]);

                if (is_wp_error($result)) Subscribers_Tags_Fields::set_error($result->get_error_message());

                return [is_wp_error($result) ? 'error' : '', self::import_url($id)];

            case 'cancel_import':
                check_admin_referer('mo_cancel_import_' . $id);
                Import::cancel($id);

                return ['', self::import_url($id)];

            case 'delete_import':
                check_admin_referer('mo_delete_import_' . $id);
                Import::delete($id);

                return ['import_deleted', self::import_url()];

            case 'download_import_problems':
                check_admin_referer('mo_download_import_problems_' . $id);
                $job = Import::get($id);

                if ($job && is_file(Import::problems_path($job))) {
                    nocache_headers();
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . sanitize_file_name(preg_replace('/\.(csv|txt)$/i', '', $job['name']) . '-problems.csv') . '"');
                    readfile(Import::problems_path($job));
                    exit;
                }

                return ['error', self::import_url($id)];

            case 'export':
                check_admin_referer('mo_export_subscribers');
                $groups = array_values(array_intersect((array)($_GET['columns'] ?? []), [Export::COLUMNS_LISTS_TAGS, Export::COLUMNS_FIELDS, Export::COLUMNS_CONSENT]));
                Export::download(self::query_args($_GET), $groups);
        }

        return null;
    }

    public static function styles()
    {
        return '<style>
.mo-steps{display:flex;margin:0 0 16px;padding:0;list-style:none;counter-reset:mo-step;max-width:1100px}
.mo-steps li{flex:1;padding:10px 12px;margin:0;border-bottom:3px solid #dcdcde;color:#646970;font-weight:600}
.mo-steps li:before{counter-increment:mo-step;content:counter(mo-step) ". "}
.mo-steps li.on{border-color:#2271b1;color:#1d2327}
.mo-steps li.done{border-color:#00a32a;color:#1d2327}
.mo-drop{display:block;border:2px dashed #c3c4c7;border-radius:6px;padding:28px;text-align:center;margin:12px 0;background:#f6f7f7;cursor:pointer}
.mo-drop b{display:block;font-size:15px;margin-bottom:8px}
.mo-drop input{display:block;margin:0 auto 8px}
.mo-map-table td{vertical-align:middle}
.mo-sample{color:#646970;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mo-normal{font-weight:400}
.mo-narrow-wide{max-width:720px}
.mo-progress{height:10px;background:#f0f0f1;border-radius:6px;overflow:hidden;margin:12px 0}
.mo-progress span{display:block;height:100%;background:#2271b1}
.mo-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:12px 0}
.mo-stats div{border:1px solid #dcdcde;border-radius:4px;padding:10px 12px}
.mo-stats b{display:block;font-size:22px;line-height:1.3}
.mo-problems-table{margin-top:12px}
.mo-static-chips .mo-filter-chip a{display:none}
.mo-imports-table{max-width:1100px}
@media (max-width:782px){.mo-stats{grid-template-columns:repeat(2,1fr)}}
</style>';
    }
}
