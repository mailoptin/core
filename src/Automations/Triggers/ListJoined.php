<?php

namespace MailOptin\Core\Automations\Triggers;

use MailOptin\Core\Automations\AbstractTrigger;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Someone joins a list: straight away on a single opt-in list, when they confirm on a double opt-in one.
 */
class ListJoined extends AbstractTrigger
{
    public function id()
    {
        return 'list_joined';
    }

    public function group()
    {
        return __('Subscribers', 'mailoptin');
    }

    public function label()
    {
        return __('Someone joins a list', 'mailoptin');
    }

    public function description()
    {
        return __('From a form, an import or an admin. On a double opt-in list, when they confirm.', 'mailoptin');
    }

    public function default_runs()
    {
        return 'once';
    }

    public function settings_fields()
    {
        return [
            'list_id' => [
                'label'   => __('List', 'mailoptin'),
                'type'    => 'select',
                'options' => function () {
                    return wp_list_pluck(ListsRepository::get_all(), 'name', 'id');
                },
            ],
            'runs'    => [
                'label'   => __('Runs for each person', 'mailoptin'),
                'type'    => 'radio',
                'options' => ['once' => __('Once', 'mailoptin'), 'every' => __('Every time they join', 'mailoptin')],
                'default' => 'once',
            ],
        ];
    }

    public function requires_subscription()
    {
        return true;
    }

    public function stops_when()
    {
        return __('They leave the list, or they unsubscribe.', 'mailoptin');
    }

    public function count_recent($automation)
    {
        $list_id = absint($automation['trigger_settings']['list_id'] ?? 0);

        if ( ! $list_id) return null;

        $join   = 'INNER JOIN ' . Subscribers::memberships_table() . ' m ON m.subscriber_id = s.id AND m.list_id = %d';
        $since  = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        $total  = \MailOptin\Core\Automations\Engine::count_audience([], $join, ['m.status = %s', 'm.subscribed_at >= %s'], [$list_id, Subscribers::STATUS_SUBSCRIBED, $since]);
        $match  = \MailOptin\Core\Automations\Engine::count_audience($automation['audience'], $join, ['m.status = %s', 'm.subscribed_at >= %s'], [$list_id, Subscribers::STATUS_SUBSCRIBED, $since]);

        if (null === $match) return null;

        return ['matched' => $match, 'total' => (int)$total, 'label' => __('people who joined the list in the last 30 days', 'mailoptin')];
    }

    public function listen()
    {
        add_action('mo_subscriber_subscribed', [$this, 'joined'], 20, 2);
        add_action('mo_subscriber_confirmed', [$this, 'confirmed'], 20, 2);
    }

    public function joined($subscriber_id, $list_id)
    {
        // Imports join thousands of people in one request: do nothing unless this list is automated.
        $watched = array_map(function ($automation) {
            return absint($automation['trigger_settings']['list_id'] ?? 0);
        }, \MailOptin\Core\Repositories\AutomationsRepository::live_for_trigger($this->id()));

        if ( ! in_array(absint($list_id), $watched, true)) return;

        $subscriber = Subscribers::get($subscriber_id);
        $membership = $subscriber ? Subscribers::get_membership($subscriber_id, $list_id) : null;

        if ( ! $membership || Subscribers::STATUS_SUBSCRIBED !== $membership['status']) return;

        // The join time makes each joining its own event, and the same joining seen twice one event.
        $this->start($subscriber['email'], 'list-' . absint($list_id) . '-' . $membership['subscribed_at'], ['list_id' => absint($list_id)]);
    }

    public function confirmed($subscriber_id, $list_ids)
    {
        foreach ((array)$list_ids as $list_id) $this->joined($subscriber_id, $list_id);
    }

    public function matches($context, $settings, $audience)
    {
        return absint($settings['list_id'] ?? 0) === absint($context['list_id'] ?? 0);
    }

    public function stop_reason($run)
    {
        $list_id = absint($run['context']['list_id'] ?? 0);

        if ( ! $run['subscriber_id']) return '';

        $membership = Subscribers::get_membership($run['subscriber_id'], $list_id);

        return $membership && Subscribers::STATUS_SUBSCRIBED === $membership['status'] ? '' : 'left_list';
    }

    public function placeholders()
    {
        return [
            'list.name' => [
                'label'  => __('List name', 'mailoptin'),
                'value'  => function ($context) {
                    $list = ListsRepository::get(absint($context['list_id'] ?? 0));

                    return $list['name'] ?? '';
                },
                'sample' => __('Newsletter', 'mailoptin'),
            ],
        ];
    }
}
