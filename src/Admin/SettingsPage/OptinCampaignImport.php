<?php

namespace MailOptin\Core\Admin\SettingsPage;

// Exit if accessed directly
if ( ! defined('ABSPATH')) {
    exit;
}

use MailOptin\Core\Admin\UI;
use MailOptin\Core\OptinTransfer\Exporter;
use MailOptin\Core\OptinTransfer\Importer;
use MailOptin\Core\Repositories\OptinCampaignMeta;
use W3Guy\Custom_Settings_Page_Api;

/**
 * Exporting optin campaigns to a file, and the Import screen: choose a file, review it, import as drafts.
 */
class OptinCampaignImport
{
    const TTL = HOUR_IN_SECONDS;

    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_requests']);
        // MailOptin's pages replace admin_notices with their own hook.
        add_action('mailoptin_admin_notices', [$this, 'result_notice']);
        // The "Imported" label goes once the campaign has been opened and saved in the Customizer.
        add_action('customize_save_after', [$this, 'clear_imported_label']);
    }

    public static function url($args = [])
    {
        return add_query_arg(array_merge(['view' => 'import-optins'], $args), MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE);
    }

    public static function export_url($optin_campaign_id)
    {
        return wp_nonce_url(
            add_query_arg(['action' => 'export', 'optin-campaign' => absint($optin_campaign_id)], MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE),
            'mailoptin_export_optin_campaign'
        );
    }

    protected static function key($token)
    {
        return 'mo_optin_import_' . get_current_user_id() . '_' . $token;
    }

    protected function on_this_page()
    {
        return is_admin() && isset($_GET['page']) && MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_SLUG === $_GET['page'];
    }

    public function handle_requests()
    {
        if ( ! $this->on_this_page() || ! \MailOptin\Core\current_user_has_privilege()) return;

        $action = sanitize_key($_REQUEST['action'] ?? '');
        if ('-1' === (string)($_REQUEST['action'] ?? '') || '' === $action) $action = sanitize_key($_REQUEST['action2'] ?? '');

        // One campaign, from its row.
        if ('export' === $action && ! empty($_GET['optin-campaign'])) {
            check_admin_referer('mailoptin_export_optin_campaign');
            Exporter::download([absint($_GET['optin-campaign'])]);
        }

        // Several, from the bulk actions. Caught before the list renders so the file can be sent.
        if ('bulk-export' === $action) {
            check_admin_referer('bulk-optin_forms');
            $ids = array_filter(array_map('absint', (array)($_POST['optin_campaign_id'] ?? [])));
            if ( ! empty($ids)) Exporter::download($ids);
            \MailOptin\Core\do_admin_redirect(esc_url_raw(MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE));
        }

        if (isset($_POST['mo_import_upload'])) {
            check_admin_referer('mo_optin_import_upload');
            $this->upload();
        }

        if (isset($_POST['mo_import_run'])) {
            $token = preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['token'] ?? ''));
            check_admin_referer('mo_optin_import_run_' . $token);
            $this->run($token);
        }
    }

    protected function upload()
    {
        $file = $_FILES['mo_import_file'] ?? null;

        if ( ! is_array($file) || UPLOAD_ERR_NO_FILE === ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            $this->fail(__('Choose an export file first.', 'mailoptin'));
        }

        if (UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file($file['tmp_name'])) {
            $this->fail(__("The file didn't upload. Try again, or check your server's upload size limit.", 'mailoptin'));
        }

        if ((int)$file['size'] > Importer::MAX_BYTES) {
            $this->fail(__('The file is larger than 5MB, which is too big for an export of optin campaigns.', 'mailoptin'));
        }

        $export = Importer::parse((string)file_get_contents($file['tmp_name']));
        if (is_wp_error($export)) $this->fail($export->get_error_message());

        $export['file'] = sanitize_file_name((string)$file['name']);
        $token          = wp_generate_password(20, false);
        set_transient(self::key($token), $export, self::TTL);

        \MailOptin\Core\do_admin_redirect(esc_url_raw(self::url(['review' => $token])));
    }

    protected function fail($message)
    {
        set_transient(self::key('error'), $message, 5 * MINUTE_IN_SECONDS);
        \MailOptin\Core\do_admin_redirect(esc_url_raw(self::url(['failed' => 1])));
    }

    protected function run($token)
    {
        $export = get_transient(self::key($token));
        if ( ! is_array($export)) $this->fail(__('That review has expired. Choose the file again.', 'mailoptin'));

        $selected = array_map('absint', (array)($_POST['mo_import_campaigns'] ?? []));
        if (empty($selected)) {
            \MailOptin\Core\do_admin_redirect(esc_url_raw(self::url(['review' => $token, 'none' => 1])));
        }

        $result = Importer::run($export, $selected, ! empty($_POST['mo_import_copy_images']));
        delete_transient(self::key($token));
        set_transient(self::key('result'), $result, 5 * MINUTE_IN_SECONDS);

        \MailOptin\Core\do_admin_redirect(esc_url_raw(add_query_arg('imported', 1, MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE)));
    }

    public function result_notice()
    {
        if ( ! $this->on_this_page() || empty($_GET['imported'])) return;

        $result = get_transient(self::key('result'));
        if ( ! is_array($result)) return;
        delete_transient(self::key('result'));

        $count = count($result['imported']);
        $html  = '<p><strong>' . esc_html(sprintf(_n('Imported %d campaign as a draft.', 'Imported %d campaigns as drafts.', $count, 'mailoptin'), $count)) . '</strong> ';
        $html  .= esc_html__('Open each one to check it, then turn it on.', 'mailoptin') . '</p>';
        if ( ! empty($result['skipped'])) {
            $html .= '<p>' . esc_html(sprintf(__('Not imported: %s.', 'mailoptin'), implode(', ', $result['skipped']))) . '</p>';
        }

        printf('<div class="notice notice-%s is-dismissible">%s</div>', $count ? 'success' : 'warning', $html);
    }

    public function clear_imported_label()
    {
        $id = absint($_REQUEST['mailoptin_optin_campaign_id'] ?? 0);
        if ($id) OptinCampaignMeta::delete_campaign_meta($id, 'imported_from');
    }

    /** The Import screen. */
    public function settings_admin_page()
    {
        add_action('wp_cspa_before_closing_header', function () {
            printf('<a class="add-new-h2" href="%s">%s</a>', esc_url(MAILOPTIN_OPTIN_CAMPAIGNS_SETTINGS_PAGE), esc_html__('All campaigns', 'mailoptin'));
        });
        add_filter('wp_cspa_main_content_area', [$this, 'content']);
        // The page builder wraps the content in its own form; make that one carry the file.
        add_action('wp_cspa_form_tag', function () {
            printf('enctype="multipart/form-data" action="%s"', esc_url(self::url()));
        });

        $instance = Custom_Settings_Page_Api::instance();
        $instance->page_header(__('Import optin campaigns', 'mailoptin'));
        $instance->build(true, true);
    }

    public function content()
    {
        ob_start();

        $token  = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['review'] ?? ''));
        $export = $token ? get_transient(self::key($token)) : false;

        if (is_array($export)) {
            $this->review_screen($token, $export);
        } else {
            $this->choose_screen($token ? __('That review has expired. Choose the file again.', 'mailoptin') : '');
        }

        return ob_get_clean();
    }

    protected function choose_screen($error = '')
    {
        if ( ! empty($_GET['failed'])) {
            $error = (string)get_transient(self::key('error'));
            delete_transient(self::key('error'));
        }
        ?>
        <div class="mo-import">
            <?php if ('' !== $error) echo UI::note(esc_html($error), 'bad'); ?>
            <div class="mo-import-card">
                <?php wp_nonce_field('mo_optin_import_upload'); ?>
                <h3><?php esc_html_e('Choose an export file', 'mailoptin'); ?></h3>
                <p>
                    <label for="mo-import-file"><?php esc_html_e('A .json file exported from MailOptin', 'mailoptin'); ?></label><br>
                    <input type="file" id="mo-import-file" name="mo_import_file" accept=".json,application/json" required>
                </p>
                <p class="description"><?php esc_html_e("Campaigns come in as drafts, so nothing appears on your site until you turn them on. You'll see what's in the file before anything is added.", 'mailoptin'); ?></p>
                <p><button type="submit" name="mo_import_upload" value="1" class="button button-primary"><?php esc_html_e('Review file', 'mailoptin'); ?></button></p>
            </div>
        </div>
        <?php
    }

    protected function review_screen($token, array $export)
    {
        $rows      = Importer::review($export);
        $parents   = array_filter($rows, function ($r) { return ! $r['variant_of']; });
        $attention = count(array_filter($parents, function ($r) { return 'ready' !== $r['status']; }));
        $images    = count(array_unique(array_merge([], ...array_values(array_map(function ($c) { return $c['images']; }, $export['campaigns'])))));
        $date      = $export['exported'] ? mysql2date(get_option('date_format'), $export['exported']) : '';

        $summary = sprintf(_n('%d campaign in %s', '%d campaigns in %s', count($parents), 'mailoptin'), count($parents), $export['file'] ?? 'the file');
        $from    = trim(sprintf(__('Exported from %1$s on %2$s.', 'mailoptin'), $export['site'] ?: __('another site', 'mailoptin'), $date));
        $check   = $attention ? sprintf(_n('%d needs a look before you turn it on.', '%d need a look before you turn them on.', $attention, 'mailoptin'), $attention) : __('All are ready.', 'mailoptin');
        ?>
        <div class="mo-import">
            <?php if ( ! empty($_GET['none'])) echo UI::note(esc_html__('Tick at least one campaign to import.', 'mailoptin'), 'warn'); ?>
            <?php echo UI::note('<strong>' . esc_html($summary) . '</strong><br>' . esc_html($from . ' ' . $check), $attention ? 'warn' : 'ok'); ?>

            <div class="mo-import-review">
                <?php wp_nonce_field('mo_optin_import_run_' . $token); ?>
                <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                <table class="wp-list-table widefat fixed striped mo-import-table">
                    <thead>
                    <tr>
                        <td class="check-column"><span class="screen-reader-text"><?php esc_html_e('Import', 'mailoptin'); ?></span></td>
                        <th scope="col"><?php esc_html_e('Campaign', 'mailoptin'); ?></th>
                        <th scope="col"><?php esc_html_e('After import', 'mailoptin'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) :
                        $variant = (bool)$row['variant_of'];
                        $skip = 'skip' === $row['status'];
                        $count = count($row['issues']);
                        switch ($row['status']) {
                            case 'ready':
                                $badge = UI::badge(__('Ready', 'mailoptin'), 'ok');
                                break;
                            case 'skip':
                                $badge = UI::badge(__("Can't import", 'mailoptin'), 'bad');
                                break;
                            case 'premium':
                                $badge = UI::badge(__('Needs Premium', 'mailoptin'), 'info');
                                break;
                            default:
                                $badge = UI::badge(sprintf(_n('Check %d thing', 'Check %d things', $count, 'mailoptin'), $count), 'warn');
                        }
                        $field_id = 'mo-import-' . (int)$row['id'];
                        ?>
                        <tr class="<?php echo $variant ? 'mo-import-variant' : ''; ?>">
                            <th scope="row" class="check-column">
                                <?php if ( ! $variant && ! $skip) : ?>
                                    <input type="checkbox" id="<?php echo esc_attr($field_id); ?>" name="mo_import_campaigns[]" value="<?php echo (int)$row['id']; ?>" checked>
                                <?php endif; ?>
                            </th>
                            <td>
                                <label for="<?php echo esc_attr($field_id); ?>" class="mo-import-name"><?php echo esc_html($row['name']); ?></label>
                                <div class="mo-optin-meta"><?php
                                    $meta = [UI::optin_type_label($row['type']), $row['template']];
                                    if ($variant) $meta[] = __('A/B test version, comes in with the campaign above', 'mailoptin');
                                    echo esc_html(implode(' · ', $meta));
                                    ?></div>
                            </td>
                            <td>
                                <?php echo $badge; ?>
                                <?php if ($row['issues']) : ?>
                                    <ul class="mo-import-issues">
                                        <?php foreach ($row['issues'] as $issue) echo '<li>' . esc_html($issue) . '</li>'; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($images) : ?>
                    <p class="mo-import-option">
                        <label>
                            <input type="checkbox" name="mo_import_copy_images" value="1" checked>
                            <strong><?php esc_html_e("Copy images into this site's Media Library", 'mailoptin'); ?></strong>
                        </label><br>
                        <span class="description"><?php echo esc_html(sprintf(
                                _n('%1$d image. If you leave this off, it keeps loading from %2$s.', '%1$d images. If you leave this off, they keep loading from %2$s.', $images, 'mailoptin'),
                                $images,
                                $export['site'] ?: __('the old site', 'mailoptin')
                            )); ?></span>
                    </p>
                <?php endif; ?>

                <p class="mo-import-actions">
                    <button type="submit" name="mo_import_run" value="1" class="button button-primary"><?php esc_html_e('Import as drafts', 'mailoptin'); ?></button>
                    <a class="button" href="<?php echo esc_url(self::url()); ?>"><?php esc_html_e('Choose another file', 'mailoptin'); ?></a>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * @return OptinCampaignImport
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
