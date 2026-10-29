<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\Admin\SettingsPage\Subscribers_Page;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;

/**
 * CSV export of subscribers matching the list's filters. Column names match what the import
 * recognises, so an exported file can be imported again.
 */
class SubscriberExport
{
    const BATCH = 500;

    const COLUMNS_LISTS_TAGS = 'lists_tags';
    const COLUMNS_FIELDS = 'fields';
    const COLUMNS_CONSENT = 'consent';

    /**
     * A cell that a spreadsheet would run as a formula (=, +, -, @) is prefixed with an apostrophe.
     */
    public static function safe_cell($value)
    {
        $value = (string)$value;

        return '' !== $value && false !== strpos("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }

    /**
     * @param string[] $groups which optional columns to include
     *
     * @return string[] column names
     */
    public static function header($groups)
    {
        $header = [__('Email', 'mailoptin'), __('First name', 'mailoptin'), __('Last name', 'mailoptin'), __('Status', 'mailoptin')];

        if (in_array(self::COLUMNS_LISTS_TAGS, $groups, true)) {
            $header[] = __('Lists', 'mailoptin');
            if (Tags::is_ready()) $header[] = __('Tags', 'mailoptin');
        }

        if (in_array(self::COLUMNS_FIELDS, $groups, true) && Tags::is_ready()) {
            foreach (Fields::all() as $field) $header[] = $field['label'];
        }

        if (in_array(self::COLUMNS_CONSENT, $groups, true)) {
            $header = array_merge($header, [
                __('Source', 'mailoptin'),
                __('Consent', 'mailoptin'),
                __('Consent wording', 'mailoptin'),
                __('Signup IP address', 'mailoptin'),
                __('Consent date (UTC)', 'mailoptin'),
                __('Confirmed (UTC)', 'mailoptin'),
            ]);
        }

        $header[] = __('Added (UTC)', 'mailoptin');

        return $header;
    }

    /**
     * @return array cells for one subscriber
     */
    public static function row($item, $groups, $field_values)
    {
        $row = [$item['email'], $item['first_name'], $item['last_name'], $item['status']];

        if (in_array(self::COLUMNS_LISTS_TAGS, $groups, true)) {
            $row[] = implode(', ', $item['lists']);
            if (Tags::is_ready()) $row[] = implode(', ', $item['tags'] ?? []);
        }

        if (in_array(self::COLUMNS_FIELDS, $groups, true) && Tags::is_ready()) {
            foreach (Fields::all() as $field) {
                $text = $field_values[$field['id']]['value_text'] ?? null;
                // Dates stay as YYYY-MM-DD so they import again unchanged.
                $row[] = Fields::TYPE_DATE === $field['type'] ? (string)$text : Fields::display_value($field, $text);
            }
        }

        if (in_array(self::COLUMNS_CONSENT, $groups, true)) {
            $row = array_merge($row, [
                Subscribers_Page::source_label($item),
                Consent::method_label($item['consent_method']),
                (string)$item['consent_text'],
                $item['ip_address'],
                (string)$item['consent_at'],
                (string)$item['confirmed_at'],
            ]);
        }

        $row[] = $item['created_at'];

        return array_map([__CLASS__, 'safe_cell'], $row);
    }

    /**
     * Write the CSV.
     *
     * @param resource $handle
     * @param array $query arguments for SubscribersRepository::query(), without paging
     * @param string[] $groups
     *
     * @return int subscribers written
     */
    public static function write($handle, $query, $groups)
    {
        fputcsv($handle, self::header($groups), ',', '"', '');

        $page    = 1;
        $written = 0;

        do {
            $result = Subscribers::query(array_merge($query, ['per_page' => self::BATCH, 'page' => $page, 'orderby' => 'created_at', 'order' => 'ASC']));
            $items  = $result['items'];

            $values = in_array(self::COLUMNS_FIELDS, $groups, true) && Tags::is_ready() && ! empty($items)
                ? Fields::values_for_subscribers(wp_list_pluck($items, 'id'))
                : [];

            foreach ($items as $item) {
                fputcsv($handle, self::row($item, $groups, $values[(int)$item['id']] ?? []), ',', '"', '');
                $written++;
            }

            $page++;
        } while (count($items) === self::BATCH);

        return $written;
    }

    /**
     * Send the CSV as a download and stop.
     */
    public static function download($query, $groups)
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="subscribers-' . gmdate('Y-m-d') . '.csv"');

        $handle = fopen('php://output', 'w');
        // So Excel reads it as UTF-8.
        fwrite($handle, "\xEF\xBB\xBF");
        self::write($handle, $query, $groups);
        fclose($handle);

        exit;
    }
}
