<?php

namespace MailOptin\Core\Repositories;

use MailOptin\Core\Core;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Tags of built-in list subscribers: the admin's own labels, never shown to subscribers.
 * Names are unique regardless of case. All datetimes are UTC.
 */
class TagsRepository extends AbstractRepository
{
    // DB update that created the tag and custom field tables.
    const DB_VERSION = 20;

    const MAX_LENGTH = 100;

    public static function is_ready()
    {
        return (int)get_option('mo_db_ver', 0) >= self::DB_VERSION;
    }

    public static function table()
    {
        return self::wpdb()->prefix . Core::tags_table_name;
    }

    public static function links_table()
    {
        return self::wpdb()->prefix . Core::subscriber_tags_table_name;
    }

    public static function sanitize_name($name)
    {
        // Commas separate tags wherever they're typed, so a name can't contain one.
        $name = trim(preg_replace('/\s+/', ' ', str_replace(',', ' ', sanitize_text_field((string)$name))));

        return mb_substr($name, 0, self::MAX_LENGTH);
    }

    public static function get($tag_id)
    {
        $table = self::table();

        return self::wpdb()->get_row(self::wpdb()->prepare("SELECT * FROM $table WHERE id = %d", $tag_id), 'ARRAY_A');
    }

    public static function get_by_name($name)
    {
        $table = self::table();
        $name  = self::sanitize_name($name);

        if ('' === $name) return null;

        // The column's collation ignores case, so "VIP" finds "vip".
        return self::wpdb()->get_row(self::wpdb()->prepare("SELECT * FROM $table WHERE name = %s", $name), 'ARRAY_A');
    }

    /**
     * @param string $search
     *
     * @return array tags by name, each with 'subscribers' => count
     */
    public static function all($search = '')
    {
        $table = self::table();
        $links = self::links_table();
        $where = '';

        if ('' !== trim((string)$search)) {
            $where = self::wpdb()->prepare('WHERE t.name LIKE %s', '%' . self::wpdb()->esc_like(trim($search)) . '%');
        }

        return self::wpdb()->get_results(
            "SELECT t.*, (SELECT COUNT(*) FROM $links l WHERE l.tag_id = t.id) AS subscribers FROM $table t $where ORDER BY t.name",
            'ARRAY_A'
        );
    }

    /**
     * @return array id => name, by name
     */
    public static function names()
    {
        $table = self::table();

        return wp_list_pluck(self::wpdb()->get_results("SELECT id, name FROM $table ORDER BY name", 'ARRAY_A'), 'name', 'id');
    }

    public static function count()
    {
        $table = self::table();

        return (int)self::wpdb()->get_var("SELECT COUNT(*) FROM $table");
    }

    /**
     * @return int|\WP_Error ID of the new tag, or of the existing tag with that name
     */
    public static function create($name)
    {
        $name = self::sanitize_name($name);

        if ('' === $name) return new \WP_Error('mo_tag_empty', __('Enter a tag name.', 'mailoptin'));

        $existing = self::get_by_name($name);

        if ($existing) return (int)$existing['id'];

        $inserted = self::wpdb()->insert(self::table(), ['name' => $name, 'created_at' => Subscribers::now()]);

        if ( ! $inserted) {
            // Created by another request in the meantime.
            $existing = self::get_by_name($name);

            return $existing ? (int)$existing['id'] : new \WP_Error('mo_tag_not_saved', __('The tag could not be saved.', 'mailoptin'));
        }

        return (int)self::wpdb()->insert_id;
    }

    /**
     * IDs for tag names and IDs, creating tags that don't exist yet.
     *
     * @param array $tags names (strings, even "2024"), or IDs of existing tags as integers
     *
     * @return int[]
     */
    public static function ensure($tags)
    {
        $ids = [];

        foreach ((array)$tags as $tag) {
            if (is_int($tag)) {
                if (self::get($tag)) $ids[] = $tag;
                continue;
            }

            if ( ! is_string($tag)) continue;

            $id = self::create($tag);
            if ( ! is_wp_error($id)) $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return true|\WP_Error
     */
    public static function rename($tag_id, $name)
    {
        $name = self::sanitize_name($name);

        if ('' === $name) return new \WP_Error('mo_tag_empty', __('Enter a tag name.', 'mailoptin'));

        $existing = self::get_by_name($name);

        if ($existing && (int)$existing['id'] !== (int)$tag_id) {
            return new \WP_Error('mo_tag_exists', sprintf(__('There is already a tag called "%s". Merge into it instead.', 'mailoptin'), $existing['name']));
        }

        self::wpdb()->update(self::table(), ['name' => $name], ['id' => $tag_id]);

        return true;
    }

    /**
     * Move every subscriber of one tag to another, then delete the first.
     */
    public static function merge($from_id, $into_id)
    {
        $from_id = absint($from_id);
        $into_id = absint($into_id);

        if ( ! $from_id || ! $into_id || $from_id === $into_id || ! self::get($into_id)) return false;

        $links = self::links_table();

        self::wpdb()->query(
            self::wpdb()->prepare(
                "INSERT IGNORE INTO $links (subscriber_id, tag_id, created_at) SELECT subscriber_id, %d, created_at FROM $links WHERE tag_id = %d",
                $into_id,
                $from_id
            )
        );

        // Forms that applied the old tag apply the new one.
        do_action('mo_tag_merged', $from_id, $into_id);

        return self::delete($from_id);
    }

    public static function delete($tag_id)
    {
        self::wpdb()->delete(self::links_table(), ['tag_id' => $tag_id], ['%d']);

        do_action('mo_tag_deleted', (int)$tag_id);

        return (bool)self::wpdb()->delete(self::table(), ['id' => $tag_id], ['%d']);
    }

    /**
     * @param int[] $tag_ids
     * @param int[] $subscriber_ids
     *
     * @return array subscriber_id => tag IDs newly added to them
     */
    public static function add_to_subscribers($tag_ids, $subscriber_ids)
    {
        $tag_ids        = array_values(array_filter(array_map('absint', (array)$tag_ids)));
        $subscriber_ids = array_values(array_filter(array_map('absint', (array)$subscriber_ids)));

        if (empty($tag_ids) || empty($subscriber_ids)) return [];

        $existing = self::ids_for_subscribers($subscriber_ids);
        $now      = Subscribers::now();
        $added    = [];
        $values   = [];

        foreach ($subscriber_ids as $subscriber_id) {
            $new = array_diff($tag_ids, $existing[$subscriber_id] ?? []);

            foreach ($new as $tag_id) {
                $values[] = self::wpdb()->prepare('(%d, %d, %s)', $subscriber_id, $tag_id, $now);
            }

            if ( ! empty($new)) $added[$subscriber_id] = array_values($new);
        }

        foreach (array_chunk($values, 500) as $chunk) {
            self::wpdb()->query('INSERT IGNORE INTO ' . self::links_table() . ' (subscriber_id, tag_id, created_at) VALUES ' . implode(',', $chunk));
        }

        return $added;
    }

    /**
     * @return array subscriber_id => tag IDs removed from them
     */
    public static function remove_from_subscribers($tag_ids, $subscriber_ids)
    {
        $tag_ids        = array_values(array_filter(array_map('absint', (array)$tag_ids)));
        $subscriber_ids = array_values(array_filter(array_map('absint', (array)$subscriber_ids)));

        if (empty($tag_ids) || empty($subscriber_ids)) return [];

        $existing = self::ids_for_subscribers($subscriber_ids);
        $removed  = [];

        foreach ($subscriber_ids as $subscriber_id) {
            $gone = array_intersect($tag_ids, $existing[$subscriber_id] ?? []);
            if ( ! empty($gone)) $removed[$subscriber_id] = array_values($gone);
        }

        if ( ! empty($removed)) {
            self::wpdb()->query(
                'DELETE FROM ' . self::links_table() . ' WHERE tag_id IN (' . implode(',', $tag_ids) . ') AND subscriber_id IN (' . implode(',', array_keys($removed)) . ')'
            );
        }

        return $removed;
    }

    /**
     * Make a subscriber's tags exactly these.
     *
     * @return array ['added' => tag IDs, 'removed' => tag IDs]
     */
    public static function set_for_subscriber($subscriber_id, $tag_ids)
    {
        $tag_ids = array_values(array_unique(array_filter(array_map('absint', (array)$tag_ids))));
        $current = self::ids_for_subscribers([$subscriber_id])[(int)$subscriber_id] ?? [];

        $add    = array_values(array_diff($tag_ids, $current));
        $remove = array_values(array_diff($current, $tag_ids));

        self::add_to_subscribers($add, [$subscriber_id]);
        self::remove_from_subscribers($remove, [$subscriber_id]);

        return ['added' => $add, 'removed' => $remove];
    }

    /**
     * @param int[] $subscriber_ids
     *
     * @return array subscriber_id => tag IDs
     */
    public static function ids_for_subscribers($subscriber_ids)
    {
        $subscriber_ids = array_filter(array_map('absint', (array)$subscriber_ids));

        if (empty($subscriber_ids)) return [];

        $links = self::links_table();
        $rows  = self::wpdb()->get_results("SELECT subscriber_id, tag_id FROM $links WHERE subscriber_id IN (" . implode(',', $subscriber_ids) . ')', 'ARRAY_A');

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int)$row['subscriber_id']][] = (int)$row['tag_id'];
        }

        return $grouped;
    }

    /**
     * @param int[] $subscriber_ids
     *
     * @return array subscriber_id => [tag_id => name], by name
     */
    public static function for_subscribers($subscriber_ids)
    {
        $subscriber_ids = array_filter(array_map('absint', (array)$subscriber_ids));

        if (empty($subscriber_ids)) return [];

        $links = self::links_table();
        $table = self::table();
        $rows  = self::wpdb()->get_results(
            "SELECT l.subscriber_id, t.id, t.name FROM $links l INNER JOIN $table t ON t.id = l.tag_id WHERE l.subscriber_id IN (" . implode(',', $subscriber_ids) . ') ORDER BY t.name',
            'ARRAY_A'
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int)$row['subscriber_id']][(int)$row['id']] = $row['name'];
        }

        return $grouped;
    }

    /**
     * @return array tag_id => name
     */
    public static function for_subscriber($subscriber_id)
    {
        return self::for_subscribers([$subscriber_id])[(int)$subscriber_id] ?? [];
    }

    public static function delete_for_subscribers($subscriber_ids)
    {
        $subscriber_ids = array_filter(array_map('absint', (array)$subscriber_ids));

        if (empty($subscriber_ids) || ! self::is_ready()) return 0;

        return (int)self::wpdb()->query('DELETE FROM ' . self::links_table() . ' WHERE subscriber_id IN (' . implode(',', $subscriber_ids) . ')');
    }
}
