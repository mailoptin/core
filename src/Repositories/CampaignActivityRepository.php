<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Opens and clicks of campaigns sent to built-in lists. All datetimes are UTC.
 *
 * Activity rows say who did what, and are deleted with the subscriber, or for a whole campaign
 * once it is older than the retention period. Totals are counted separately as they happen, so a campaign's report keeps its
 * numbers after that.
 */
class CampaignActivityRepository extends AbstractRepository
{
    // DB update that created the tracking tables.
    const DB_VERSION = 22;

    const TYPE_OPEN = 'open';
    // Clicked any link of the campaign; one row per person, for counting people who clicked.
    const TYPE_CLICK = 'click';
    // Clicked one link.
    const TYPE_LINK = 'link';

    const METRIC_OPENS = 'opens';
    const METRIC_OPENERS = 'openers';
    const METRIC_CLICKS = 'clicks';
    const METRIC_CLICKERS = 'clickers';
    const METRIC_SENT = 'sent';
    // Set once a campaign's who-did-what was deleted after the retention period. Its totals stay;
    // new hits aren't counted, as they'd count people twice.
    const METRIC_PRUNED = 'pruned';

    protected static $link_cache = [];

    public static function is_ready()
    {
        return (int)get_option('mo_db_ver', 0) >= self::DB_VERSION;
    }

    public static function links_table()
    {
        return self::wpdb()->prefix . Core::campaign_links_table_name;
    }

    public static function table()
    {
        return self::wpdb()->prefix . Core::campaign_activity_table_name;
    }

    public static function stats_table()
    {
        return self::wpdb()->prefix . Core::campaign_stats_table_name;
    }

    /* ---------------------------------------------------------------------------------------------
     * Links
     * ------------------------------------------------------------------------------------------ */

    /**
     * ID of a campaign's link, adding it the first time it's seen.
     *
     * @return int 0 if it couldn't be saved
     */
    public static function link_id($campaign_log_id, $url, $label = '')
    {
        $campaign_log_id = absint($campaign_log_id);
        $hash            = sha1($url);

        if (isset(self::$link_cache[$campaign_log_id][$hash])) return self::$link_cache[$campaign_log_id][$hash];

        $table = self::links_table();

        self::wpdb()->query(self::wpdb()->prepare(
            "INSERT IGNORE INTO $table (campaign_log_id, url_hash, url, label) VALUES (%d, %s, %s, %s)",
            $campaign_log_id,
            $hash,
            $url,
            mb_substr(trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string)$label))), 0, 200)
        ));

        $id = (int)self::wpdb()->get_var(self::wpdb()->prepare("SELECT id FROM $table WHERE campaign_log_id = %d AND url_hash = %s", $campaign_log_id, $hash));

        if ($id) self::$link_cache[$campaign_log_id][$hash] = $id;

        return $id;
    }

    public static function flush_cache()
    {
        self::$link_cache = [];
    }

    public static function get_link($link_id)
    {
        $table = self::links_table();

        return self::wpdb()->get_row(self::wpdb()->prepare("SELECT * FROM $table WHERE id = %d", $link_id), 'ARRAY_A');
    }

    /**
     * @return array links of a campaign, most clicked first
     */
    public static function links($campaign_log_id)
    {
        $table = self::links_table();

        return self::wpdb()->get_results(self::wpdb()->prepare("SELECT * FROM $table WHERE campaign_log_id = %d ORDER BY people DESC, clicks DESC, id", $campaign_log_id), 'ARRAY_A');
    }

    /* ---------------------------------------------------------------------------------------------
     * Recording
     * ------------------------------------------------------------------------------------------ */

    protected static function bump($campaign_log_id, $metric)
    {
        $table = self::stats_table();

        self::wpdb()->query(self::wpdb()->prepare(
            "INSERT INTO $table (campaign_log_id, metric, value) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE value = value + 1",
            $campaign_log_id,
            $metric
        ));
    }

    /**
     * @return bool true the first time this subscriber did it for this campaign
     */
    protected static function hit($campaign_log_id, $subscriber_id, $type, $link_id = 0)
    {
        $table = self::table();
        $now   = Subscribers::now();

        // The primary key makes the first hit win when two arrive at once.
        $inserted = self::wpdb()->query(self::wpdb()->prepare(
            "INSERT IGNORE INTO $table (campaign_log_id, subscriber_id, type, link_id, hits, first_at, last_at) VALUES (%d, %d, %s, %d, 1, %s, %s)",
            $campaign_log_id, $subscriber_id, $type, $link_id, $now, $now
        ));

        if ($inserted) return true;

        self::wpdb()->query(self::wpdb()->prepare(
            "UPDATE $table SET hits = hits + 1, last_at = %s WHERE campaign_log_id = %d AND type = %s AND subscriber_id = %d AND link_id = %d",
            $now, $campaign_log_id, $type, $subscriber_id, $link_id
        ));

        return false;
    }

    public static function record_open($campaign_log_id, $subscriber_id)
    {
        if (self::is_pruned($campaign_log_id)) return false;

        $first = self::hit($campaign_log_id, $subscriber_id, self::TYPE_OPEN);

        self::bump($campaign_log_id, self::METRIC_OPENS);

        if ($first) self::after_first_open($campaign_log_id, $subscriber_id);

        return $first;
    }

    /**
     * A click also counts as an open: the email was read, even if its images didn't load. Not
     * when opens aren't tracked, or "opened" would only mean "clicked".
     */
    public static function record_click($campaign_log_id, $subscriber_id, $link, $counts_as_open = true)
    {
        if (self::is_pruned($campaign_log_id)) return;

        if ($counts_as_open && self::hit($campaign_log_id, $subscriber_id, self::TYPE_OPEN)) self::after_first_open($campaign_log_id, $subscriber_id);

        self::bump($campaign_log_id, self::METRIC_CLICKS);

        if (self::hit($campaign_log_id, $subscriber_id, self::TYPE_CLICK)) self::bump($campaign_log_id, self::METRIC_CLICKERS);

        $first_on_link = self::hit($campaign_log_id, $subscriber_id, self::TYPE_LINK, $link['id']);
        $table         = self::links_table();

        self::wpdb()->query(self::wpdb()->prepare(
            "UPDATE $table SET clicks = clicks + 1" . ($first_on_link ? ', people = people + 1' : '') . ' WHERE id = %d',
            $link['id']
        ));

        if ($first_on_link) {
            Subscribers::log_event($subscriber_id, Subscribers::EVENT_CLICKED, ['link_id' => (int)$link['id'], 'label' => $link['label'], 'url' => $link['url']], $campaign_log_id);
        }
    }

    /**
     * Count a campaign email sent to a subscriber, so reports don't have to count the send queue.
     */
    public static function record_sent($campaign_log_id)
    {
        self::bump($campaign_log_id, self::METRIC_SENT);
    }

    public static function is_pruned($campaign_log_id)
    {
        return (bool)self::stats($campaign_log_id)[self::METRIC_PRUNED];
    }

    protected static function after_first_open($campaign_log_id, $subscriber_id)
    {
        self::bump($campaign_log_id, self::METRIC_OPENERS);
        Subscribers::log_event($subscriber_id, Subscribers::EVENT_OPENED, [], $campaign_log_id);
    }

    /* ---------------------------------------------------------------------------------------------
     * Reports
     * ------------------------------------------------------------------------------------------ */

    protected static function metrics()
    {
        return [self::METRIC_SENT, self::METRIC_OPENS, self::METRIC_OPENERS, self::METRIC_CLICKS, self::METRIC_CLICKERS, self::METRIC_PRUNED];
    }

    /**
     * @return array metric => value, with every metric present
     */
    public static function stats($campaign_log_id)
    {
        $table = self::stats_table();
        $rows  = self::wpdb()->get_results(self::wpdb()->prepare("SELECT metric, value FROM $table WHERE campaign_log_id = %d", $campaign_log_id), 'ARRAY_A');

        return array_merge(
            array_fill_keys(self::metrics(), 0),
            array_map('intval', wp_list_pluck($rows, 'value', 'metric'))
        );
    }

    /**
     * Totals of several campaigns at once, for the campaign log.
     *
     * @return array campaign log id => stats()
     */
    public static function stats_for($campaign_log_ids)
    {
        $ids = array_filter(array_map('absint', (array)$campaign_log_ids));

        if (empty($ids)) return [];

        $table = self::stats_table();
        $rows  = self::wpdb()->get_results("SELECT campaign_log_id, metric, value FROM $table WHERE campaign_log_id IN (" . implode(',', $ids) . ')', 'ARRAY_A');
        $empty = array_fill_keys(self::metrics(), 0);
        $stats = array_fill_keys($ids, $empty);

        foreach ($rows as $row) {
            $stats[(int)$row['campaign_log_id']][$row['metric']] = (int)$row['value'];
        }

        return $stats;
    }

    /**
     * People who first opened and first clicked on each day after the campaign was sent.
     *
     * @return array Y-m-d (site time) => [opens, clicks], for $days days from $start
     */
    public static function daily($campaign_log_id, $start_utc, $days = 7)
    {
        $table  = self::table();
        $offset = (int)round((float)get_option('gmt_offset') * HOUR_IN_SECONDS);
        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $series[gmdate('Y-m-d', strtotime($start_utc) + $offset + $i * DAY_IN_SECONDS)] = [0, 0];
        }

        $rows = self::wpdb()->get_results(self::wpdb()->prepare(
            "SELECT type, DATE(DATE_ADD(first_at, INTERVAL %d SECOND)) AS day, COUNT(*) AS people FROM $table
            WHERE campaign_log_id = %d AND type IN (%s, %s) GROUP BY type, day",
            $offset, $campaign_log_id, self::TYPE_OPEN, self::TYPE_CLICK
        ), 'ARRAY_A');

        foreach ($rows as $row) {
            if (isset($series[$row['day']])) $series[$row['day']][self::TYPE_OPEN === $row['type'] ? 0 : 1] = (int)$row['people'];
        }

        return $series;
    }

    /**
     * How many people clicked, opened without clicking, and were sent it but didn't open, counted
     * the same way as people() lists them.
     */
    public static function engagement_counts($campaign_log_id)
    {
        $counts = [];

        foreach (['clicked', 'opened_not_clicked', 'not_opened', 'unsubscribed'] as $group) {
            $counts[$group] = (int)self::wpdb()->get_var('SELECT COUNT(*) ' . self::people_sql($campaign_log_id, $group));
        }

        return $counts;
    }

    /**
     * Subscribers sent a campaign. Campaigns that went to other connections have none.
     */
    public static function sent_count($campaign_log_id)
    {
        $queue = SendQueueRepository::table();

        return (int)self::wpdb()->get_var(self::wpdb()->prepare(
            "SELECT COUNT(*) FROM $queue WHERE campaign_log_id = %d AND recipient_type = 'subscriber' AND status = 'sent'",
            $campaign_log_id
        ));
    }

    public static function unsubscribed_count($campaign_log_id)
    {
        $events = Subscribers::events_table();

        return (int)self::wpdb()->get_var(self::wpdb()->prepare(
            "SELECT COUNT(DISTINCT subscriber_id) FROM $events WHERE campaign_log_id = %d AND type = %s",
            $campaign_log_id,
            Subscribers::EVENT_UNSUBSCRIBED
        ));
    }

    /**
     * The people of a campaign's report: clicked, opened_not_clicked, not_opened or unsubscribed.
     *
     * @return array [FROM ... WHERE sql, ready to run]
     */
    protected static function people_sql($campaign_log_id, $group, $link_id = 0)
    {
        $wpdb   = self::wpdb();
        $table  = self::table();
        $queue  = SendQueueRepository::table();
        $events = Subscribers::events_table();
        $subs   = Subscribers::table();

        $did = function ($type, $link = 0) use ($wpdb, $table, $campaign_log_id) {
            return $wpdb->prepare("EXISTS (SELECT 1 FROM $table a WHERE a.campaign_log_id = %d AND a.subscriber_id = s.id AND a.type = %s AND a.link_id = %d)", $campaign_log_id, $type, $link);
        };

        switch ($group) {
            case 'clicked':
                $where = $link_id ? $did(self::TYPE_LINK, absint($link_id)) : $did(self::TYPE_CLICK);
                break;
            case 'opened_not_clicked':
                $where = $did(self::TYPE_OPEN) . ' AND NOT ' . $did(self::TYPE_CLICK);
                break;
            case 'not_opened':
                $where = 'NOT ' . $did(self::TYPE_OPEN);
                break;
            case 'unsubscribed':
                $where = $wpdb->prepare("EXISTS (SELECT 1 FROM $events e WHERE e.subscriber_id = s.id AND e.campaign_log_id = %d AND e.type = %s)", $campaign_log_id, Subscribers::EVENT_UNSUBSCRIBED);
                break;
            default:
                return null;
        }

        return $wpdb->prepare(
            "FROM $queue q INNER JOIN $subs s ON s.id = q.recipient_id WHERE q.campaign_log_id = %d AND q.recipient_type = 'subscriber' AND q.status = 'sent' AND ",
            $campaign_log_id
        ) . $where;
    }

    /**
     * @return array items (subscriber rows with last_activity) and total
     */
    public static function people($campaign_log_id, $group, $link_id = 0, $page = 1, $per_page = 50)
    {
        $sql = self::people_sql($campaign_log_id, $group, $link_id);

        if ( ! $sql) return ['items' => [], 'total' => 0];

        $table = self::table();
        $wpdb  = self::wpdb();

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, (SELECT MAX(a.last_at) FROM $table a WHERE a.campaign_log_id = q.campaign_log_id AND a.subscriber_id = s.id) AS last_activity
            $sql ORDER BY last_activity DESC, s.id LIMIT %d OFFSET %d",
            $per_page,
            (max(1, $page) - 1) * $per_page
        ), 'ARRAY_A');

        return ['items' => $items, 'total' => (int)$wpdb->get_var("SELECT COUNT(*) $sql")];
    }

    /**
     * @return int[] subscriber IDs of one of people() groups, in batches by ID
     */
    public static function people_ids($campaign_log_id, $group, $link_id = 0, $after_id = 0, $limit = 500)
    {
        $sql = self::people_sql($campaign_log_id, $group, $link_id);

        if ( ! $sql) return [];

        return array_map('intval', self::wpdb()->get_col(self::wpdb()->prepare("SELECT s.id $sql AND s.id > %d ORDER BY s.id LIMIT %d", $after_id, $limit)));
    }

    /* ---------------------------------------------------------------------------------------------
     * Engagement filters
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return array what => label
     */
    public static function engagement_options()
    {
        return [
            'opened'      => __('Opened', 'mailoptin'),
            'clicked'     => __('Clicked', 'mailoptin'),
            'not_opened'  => __('Didn\'t open', 'mailoptin'),
            'not_clicked' => __('Didn\'t click', 'mailoptin'),
        ];
    }

    /**
     * @return array days => label; 0 is any time
     */
    public static function engagement_periods()
    {
        return [
            30  => __('in the last 30 days', 'mailoptin'),
            90  => __('in the last 90 days', 'mailoptin'),
            180 => __('in the last 180 days', 'mailoptin'),
            365 => __('in the last year', 'mailoptin'),
            0   => __('ever', 'mailoptin'),
        ];
    }

    /**
     * @param array $condition what, campaign (campaign log ID, 0 for any campaign), days
     *
     * @return array|null the condition cleaned up, or null if it isn't one
     */
    public static function clean_condition($condition)
    {
        if ( ! is_array($condition)) return null;

        $what = is_scalar($condition['what'] ?? '') ? sanitize_key($condition['what'] ?? '') : '';
        $days = is_scalar($condition['days'] ?? 0) ? absint($condition['days'] ?? 0) : 0;

        if ( ! isset(self::engagement_options()[$what]) || ! isset(self::engagement_periods()[$days])) return null;

        return ['what' => $what, 'campaign' => is_scalar($condition['campaign'] ?? 0) ? absint($condition['campaign'] ?? 0) : 0, 'days' => $days];
    }

    /**
     * SQL for one engagement condition on subscribers aliased "s".
     *
     * "Didn't open" means they were sent the campaign (or, for any campaign, at least one in the
     * period) and no open was seen. Opens are an estimate, so this includes people who read it with
     * images off.
     */
    public static function condition_sql($condition)
    {
        $condition = self::clean_condition($condition);

        if ( ! $condition) return null;

        $wpdb  = self::wpdb();
        $table = self::table();
        $queue = SendQueueRepository::table();
        $since = $condition['days'] ? gmdate('Y-m-d H:i:s', time() - $condition['days'] * DAY_IN_SECONDS) : null;
        $type  = in_array($condition['what'], ['opened', 'not_opened'], true) ? self::TYPE_OPEN : self::TYPE_CLICK;

        $activity = $wpdb->prepare("SELECT 1 FROM $table a WHERE a.subscriber_id = s.id AND a.type = %s", $type);
        $sent     = "SELECT 1 FROM $queue q WHERE q.recipient_id = s.id AND q.recipient_type = 'subscriber' AND q.status = 'sent'";

        if ($condition['campaign']) {
            $activity .= $wpdb->prepare(' AND a.campaign_log_id = %d', $condition['campaign']);
            $sent     .= $wpdb->prepare(' AND q.campaign_log_id = %d', $condition['campaign']);
        }

        if ($since) {
            $activity .= $wpdb->prepare(' AND a.last_at >= %s', $since);
            $sent     .= $wpdb->prepare(' AND q.sent_at >= %s', $since);
        }

        if (in_array($condition['what'], ['opened', 'clicked'], true)) return "EXISTS ($activity)";

        // Campaigns whose activity was deleted would make everyone they were sent to look unengaged.
        $stats = self::stats_table();
        $sent .= $wpdb->prepare(" AND q.campaign_log_id NOT IN (SELECT p.campaign_log_id FROM $stats p WHERE p.metric = %s)", self::METRIC_PRUNED);

        return "(EXISTS ($sent) AND NOT EXISTS ($activity))";
    }

    /**
     * Recent campaigns sent to built-in lists, for choosing one in a filter.
     *
     * @return array campaign log id => subject, newest first
     */
    public static function sent_campaigns($limit = 50)
    {
        $queue = SendQueueRepository::table();
        $logs  = self::wpdb()->prefix . Core::campaign_log_table_name;

        $rows = self::wpdb()->get_results(self::wpdb()->prepare(
            "SELECT q.campaign_log_id AS id, l.title, MIN(q.sent_at) AS first_sent FROM $queue q INNER JOIN $logs l ON l.id = q.campaign_log_id
            WHERE q.recipient_type = 'subscriber' AND q.status = 'sent' GROUP BY q.campaign_log_id, l.title ORDER BY first_sent DESC LIMIT %d",
            $limit
        ), 'ARRAY_A');

        return wp_list_pluck($rows, 'title', 'id');
    }

    /* ---------------------------------------------------------------------------------------------
     * Subscribers
     * ------------------------------------------------------------------------------------------ */

    /**
     * @return array sent, opened and clicked campaigns, and when they last opened and clicked
     */
    public static function subscriber_summary($subscriber_id)
    {
        $wpdb  = self::wpdb();
        $table = self::table();
        $queue = SendQueueRepository::table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(type = %s) AS opened, SUM(type = %s) AS clicked, MAX(IF(type = %s, last_at, NULL)) AS last_opened, MAX(IF(type = %s, last_at, NULL)) AS last_clicked
            FROM $table WHERE subscriber_id = %d",
            self::TYPE_OPEN, self::TYPE_CLICK, self::TYPE_OPEN, self::TYPE_CLICK, $subscriber_id
        ), 'ARRAY_A');

        return [
            'sent'         => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $queue WHERE recipient_type = 'subscriber' AND recipient_id = %d AND status = 'sent'", $subscriber_id)),
            'opened'       => (int)($row['opened'] ?? 0),
            'clicked'      => (int)($row['clicked'] ?? 0),
            'last_opened'  => $row['last_opened'] ?? null,
            'last_clicked' => $row['last_clicked'] ?? null,
        ];
    }

    public static function delete_for_subscribers($subscriber_ids)
    {
        $ids = array_filter(array_map('absint', (array)$subscriber_ids));

        if (empty($ids)) return;

        $table = self::table();

        self::wpdb()->query("DELETE FROM $table WHERE subscriber_id IN (" . implode(',', $ids) . ')');
    }

    /**
     * A campaign log was deleted. Its links are kept so the emails already delivered still work.
     */
    public static function delete_campaign($campaign_log_id)
    {
        $campaign_log_id = absint($campaign_log_id);

        foreach ([self::table(), self::stats_table()] as $table) {
            self::wpdb()->delete($table, ['campaign_log_id' => $campaign_log_id], ['%d']);
        }

        self::wpdb()->query(self::wpdb()->prepare(
            'DELETE FROM ' . Subscribers::events_table() . ' WHERE campaign_log_id = %d AND type IN (%s, %s)',
            $campaign_log_id, Subscribers::EVENT_OPENED, Subscribers::EVENT_CLICKED
        ));
    }

    /**
     * Every campaign log was deleted.
     */
    public static function delete_all()
    {
        self::wpdb()->query('DELETE FROM ' . self::table());
        self::wpdb()->query('DELETE FROM ' . self::stats_table());
        self::wpdb()->query(self::wpdb()->prepare('DELETE FROM ' . Subscribers::events_table() . ' WHERE type IN (%s, %s)', Subscribers::EVENT_OPENED, Subscribers::EVENT_CLICKED));
    }

    /**
     * Delete who-did-what of campaigns that started more than the retention period ago, a few
     * campaigns at a time. Totals are kept. Whole campaigns, so a report never shows people who
     * opened as not having opened.
     *
     * @return int campaigns pruned; 0 when there is nothing left to do
     */
    public static function prune($before_utc, $limit = 20)
    {
        $wpdb  = self::wpdb();
        $table = self::table();

        $campaign_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT campaign_log_id FROM $table GROUP BY campaign_log_id HAVING MIN(first_at) < %s LIMIT %d",
            $before_utc,
            $limit
        )));

        foreach ($campaign_ids as $campaign_log_id) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . self::stats_table() . ' (campaign_log_id, metric, value) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE value = 1',
                $campaign_log_id,
                self::METRIC_PRUNED
            ));

            $wpdb->delete($table, ['campaign_log_id' => $campaign_log_id], ['%d']);

            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . Subscribers::events_table() . ' WHERE campaign_log_id = %d AND type IN (%s, %s)',
                $campaign_log_id, Subscribers::EVENT_OPENED, Subscribers::EVENT_CLICKED
            ));
        }

        return count($campaign_ids);
    }
}
