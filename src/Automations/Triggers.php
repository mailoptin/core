<?php

namespace MailOptin\Core\Automations;

/**
 * The registry of triggers. Filled on "init" through the mailoptin_automation_triggers action.
 */
class Triggers
{
    /** @var AbstractTrigger[] */
    protected $triggers = [];

    protected $loaded = false;

    public function add(AbstractTrigger $trigger)
    {
        $id = sanitize_key($trigger->id());

        if ('' === $id) return false;

        $this->triggers[$id] = $trigger;

        if ($this->loaded) $trigger->listen();

        return true;
    }

    public function get($id)
    {
        $this->load();

        return $this->triggers[$id] ?? null;
    }

    /**
     * @param bool $available_only leave out triggers whose plugin isn't active
     *
     * @return AbstractTrigger[] id => trigger
     */
    public function all($available_only = true)
    {
        $this->load();

        return $available_only ? array_filter($this->triggers, function ($trigger) {
            return $trigger->is_available();
        }) : $this->triggers;
    }

    /**
     * @return array group => [id => trigger], for a grouped dropdown
     */
    public function grouped()
    {
        $groups = [];

        foreach ($this->all() as $id => $trigger) $groups[$trigger->group()][$id] = $trigger;

        return $groups;
    }

    /**
     * Collect triggers and let each one hook into its event. Runs once.
     */
    public function load()
    {
        if ($this->loaded) return;

        $this->loaded = true;

        foreach ([
                     new Triggers\ListJoined(),
                     new Triggers\TagAdded(),
                     new Triggers\DateField(),
                     new Triggers\WooOrderStatus(),
                     new Triggers\WooNoOrder(),
                     new Triggers\WooCartAbandoned(),
                 ] as $builtin) {
            $this->triggers[$builtin->id()] = $builtin;
        }

        do_action('mailoptin_automation_triggers', $this);

        // Every trigger listens; Engine::start() checks availability when the event fires,
        // so a trigger that becomes available later in the request (a DB upgrade) still works.
        foreach ($this->triggers as $trigger) $trigger->listen();
    }

    /**
     * @return Triggers
     */
    public static function get_instance()
    {
        static $instance = null;

        if (null === $instance) $instance = new self();

        return $instance;
    }
}
