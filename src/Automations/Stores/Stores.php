<?php

namespace MailOptin\Core\Automations\Stores;

use MailOptin\Core\Automations\AbstractTrigger;

/**
 * The shops automation emails can show products and discount codes from.
 */
class Stores
{
    const PURGE_HOOK = 'mailoptin_automation_coupons_purge';

    /** @var Store[]|null */
    protected static $stores = null;

    /**
     * @return Store[] id => store
     */
    public static function all()
    {
        if (null === self::$stores) {
            self::$stores = [];
            foreach ((array)apply_filters('mailoptin_automation_stores', [new WooStore()]) as $store) {
                if ($store instanceof Store) self::$stores[$store->id()] = $store;
            }
        }

        return self::$stores;
    }

    public static function get($id)
    {
        return self::all()[$id] ?? null;
    }

    /**
     * The store a trigger's runs are about, when it's running.
     *
     * @return Store|null
     */
    public static function for_trigger($trigger)
    {
        $store = $trigger instanceof AbstractTrigger ? self::get((string)$trigger->store()) : null;

        return $store && $store->active() ? $store : null;
    }

    /**
     * Daily: unused codes go some time after they expire.
     */
    public static function init()
    {
        add_action(self::PURGE_HOOK, function () {
            foreach (self::all() as $store) {
                if ($store->active()) $store->purge_coupons();
            }
        });

        add_action('init', function () {
            if ( ! wp_next_scheduled(self::PURGE_HOOK)) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK);
        });

        foreach (self::all() as $store) {
            if (method_exists($store, 'init')) $store->init();
        }
    }

    /** For tests. */
    public static function reset()
    {
        self::$stores = null;
    }
}
