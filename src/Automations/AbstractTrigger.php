<?php

namespace MailOptin\Core\Automations;

use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * What starts an automation. Built-in triggers and ones added by other plugins work the same way:
 *
 *     add_action('mailoptin_automation_triggers', function (Triggers $triggers) {
 *         $triggers->add(new My_Trigger());
 *     });
 *
 * A trigger listens for its event and calls start() with the person and a context, which the
 * emails' placeholders read. Everything a trigger shows is plain fields that MailOptin draws and
 * saves, so a trigger needs no JavaScript.
 */
abstract class AbstractTrigger
{
    /** @return string unique, lowercase, e.g. "woo_order_status" */
    abstract public function id();

    /** @return string the group it's listed under, e.g. "WooCommerce" */
    abstract public function group();

    /** @return string e.g. "An order changes status" */
    abstract public function label();

    /**
     * Hook into the event. Call $this->start() when it happens, and $this->stop() when a run
     * should end early (the customer ordered, left the list…).
     */
    abstract public function listen();

    /** @return string one sentence shown under the trigger */
    public function description()
    {
        return '';
    }

    /** Whether it can be used on this site, e.g. false when WooCommerce isn't active. */
    public function is_available()
    {
        return true;
    }

    /**
     * Fields under "What starts it". Types: text, number, select, multiselect, radio, checkbox, time.
     * Each: label, type, options (array, or a callable returning one), default, description.
     *
     * @return array key => field
     */
    public function settings_fields()
    {
        return [];
    }

    /**
     * Fields this trigger adds to "Who's included" (segment and tags to leave out are always there).
     *
     * @return array key => field
     */
    public function audience_fields()
    {
        return [];
    }

    /**
     * Whether a started event passes this automation's trigger settings and its own audience fields.
     */
    public function matches($context, $settings, $audience)
    {
        return true;
    }

    /**
     * "Runs for each person": "once" (one run per person ever) or "every" (one per event).
     * Triggers that offer the choice add a "runs" field; this is the default.
     */
    public function default_runs()
    {
        return 'every';
    }

    /**
     * Placeholders the emails can use, as {{key}} or {{key|fallback}}.
     *
     * @return array key => ['label' => string, 'value' => callable($context, $run): string, 'sample' => string]
     */
    public function placeholders()
    {
        return [];
    }

    /**
     * Choices for a "Continue only if" step. The callable returns true to carry on.
     *
     * @return array id => ['label' => string, 'callback' => callable($run): bool]
     */
    public function checks()
    {
        return [];
    }

    /**
     * Checked again just before each email: a reason (like "purchased") stops the run, so an event
     * that was missed can't cause a wrong email. Empty string to carry on.
     */
    public function stop_reason($run)
    {
        return '';
    }

    /**
     * For triggers that look for people on a schedule (birthdays, no order for a while): start runs
     * for one live automation. Called hourly.
     */
    public function scan($automation)
    {
    }

    /**
     * How many recent events this automation would have started, for the "X of Y match" line.
     *
     * @return array|null ['matched' => int, 'total' => int, 'label' => string], or null to show nothing
     */
    public function count_recent($automation)
    {
        return null;
    }

    /**
     * Start this trigger's live automations for a person.
     *
     * @param string $email
     * @param string $trigger_ref what makes this event unique for the person: an order ID, a cart ID, a year
     * @param array $context IDs and values the emails and checks need; keep it small
     *
     * @return int[] run IDs started
     */
    protected function start($email, $trigger_ref, $context = [], $automation_id = 0)
    {
        return Engine::start($this, $email, (string)$trigger_ref, (array)$context, $automation_id);
    }

    /**
     * Stop this trigger's runs for a person, optionally only those started by one event.
     */
    protected function stop($email, $reason, $trigger_ref = null)
    {
        $where = ['email' => $email, 'trigger_id' => $this->id()];

        if (null !== $trigger_ref) $where['trigger_ref'] = (string)$trigger_ref;

        return \MailOptin\Core\Repositories\AutomationsRepository::stop_runs($where, $reason);
    }

    /**
     * A field's options, resolving a callable.
     */
    public static function field_options($field)
    {
        $options = $field['options'] ?? [];

        return is_callable($options) ? (array)call_user_func($options) : (array)$options;
    }

    /**
     * The subscriber with this address, if there is one.
     */
    protected static function subscriber($email)
    {
        return Subscribers::get_by_email($email);
    }
}
