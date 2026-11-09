<?php

namespace MailOptin\Core\Automations\Triggers;

use MailOptin\Core\Automations\AbstractTrigger;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository;

/**
 * Someone gets a tag, from a form, an import, a bulk action or the API.
 */
class TagAdded extends AbstractTrigger
{
    public function id()
    {
        return 'tag_added';
    }

    public function group()
    {
        return __('Subscribers', 'mailoptin');
    }

    public function label()
    {
        return __('Someone gets a tag', 'mailoptin');
    }

    public function description()
    {
        return __('From a form, an import, a bulk action or the API.', 'mailoptin');
    }

    public function is_available()
    {
        return TagsRepository::is_ready();
    }

    public function default_runs()
    {
        return 'once';
    }

    public function settings_fields()
    {
        return [
            'tag_id' => [
                'label'   => __('Tag', 'mailoptin'),
                'type'    => 'select',
                'options' => function () {
                    return TagsRepository::names();
                },
            ],
            'runs'   => [
                'label'   => __('Runs for each person', 'mailoptin'),
                'type'    => 'radio',
                'options' => ['once' => __('Once', 'mailoptin'), 'every' => __('Every time they get it', 'mailoptin')],
                'default' => 'once',
            ],
        ];
    }

    public function requires_subscription()
    {
        return true;
    }

    public function listen()
    {
        add_action('mo_subscriber_tags_added', [$this, 'tagged']);
    }

    public function tagged($added)
    {
        // Bulk tagging can add tags to thousands of people at once: only look anyone up when a
        // live automation watches one of the tags.
        $watched = array_map(function ($automation) {
            return absint($automation['trigger_settings']['tag_id'] ?? 0);
        }, \MailOptin\Core\Repositories\AutomationsRepository::live_for_trigger($this->id()));

        if (empty($watched)) return;

        foreach ((array)$added as $subscriber_id => $tag_ids) {
            $tag_ids = array_intersect(array_map('absint', (array)$tag_ids), $watched);

            if (empty($tag_ids)) continue;

            $subscriber = Subscribers::get($subscriber_id);

            if ( ! $subscriber) continue;

            foreach ($tag_ids as $tag_id) {
                $this->start($subscriber['email'], 'tag-' . absint($tag_id) . '-' . gmdate('YmdHis'), ['tag_id' => absint($tag_id)]);
            }
        }
    }

    public function matches($context, $settings, $audience)
    {
        return absint($settings['tag_id'] ?? 0) === absint($context['tag_id'] ?? 0);
    }

    public function stop_reason($run)
    {
        if ( ! $run['subscriber_id']) return '';

        $tag_ids = TagsRepository::ids_for_subscribers([$run['subscriber_id']])[$run['subscriber_id']] ?? [];

        return in_array(absint($run['context']['tag_id'] ?? 0), array_map('intval', $tag_ids), true) ? '' : 'tag_removed';
    }

    public function placeholders()
    {
        return [
            'tag.name' => [
                'label'  => __('Tag name', 'mailoptin'),
                'value'  => function ($context) {
                    return TagsRepository::names()[absint($context['tag_id'] ?? 0)] ?? '';
                },
                'sample' => 'vip',
            ],
        ];
    }
}
