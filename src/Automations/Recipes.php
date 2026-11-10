<?php

namespace MailOptin\Core\Automations;

use MailOptin\Core\Admin\Customizer\CustomControls\EmailContentBuilder\Misc;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\TagsRepository;

/**
 * Ready-made automations: a recipe fills in the trigger, the waits and the emails, as a draft to
 * change before it goes live. Add more with the mailoptin_automation_recipes filter.
 */
class Recipes
{
    /**
     * @return array id => [title, description, flow, trigger_id, trigger_settings, steps]. Steps:
     *               ['wait', settings], ['check', check id], ['email', subject, body HTML].
     */
    public static function all()
    {
        $recipes = [
            'cart_recovery'   => [
                'title'            => __('Abandoned cart recovery', 'mailoptin'),
                'description'      => __('Remind shoppers who left products in their cart, with a link straight back to it.', 'mailoptin'),
                'flow'             => __('Cart abandoned for an hour → email → wait 1 day → reminder if not ordered → wait 2 days → last reminder if not ordered', 'mailoptin'),
                'trigger_id'       => 'woo_cart_abandoned',
                'trigger_settings' => ['delay' => 60, 'guests' => false, 'runs' => 'every'],
                'steps'            => [
                    ['email', __('You left something in your cart', 'mailoptin'), sprintf(
                        '<p>%s</p><p>%s</p><p><a href="{{cart.url}}">%s</a></p>',
                        __('Hi {{customer.first_name|there}},', 'mailoptin'),
                        __('You left {{cart.items}} in your cart. We have kept it for you.', 'mailoptin'),
                        __('Return to your cart', 'mailoptin')
                    )],
                    ['wait', ['amount' => 1, 'unit' => 'days']],
                    ['check', 'not_recovered'],
                    ['email', __('Still thinking it over?', 'mailoptin'), sprintf(
                        '<p>%s</p><p>%s</p><p><a href="{{cart.url}}">%s</a></p>',
                        __('Hi {{customer.first_name|there}},', 'mailoptin'),
                        __('Your cart ({{cart.total}}) is still waiting: {{cart.items}}.', 'mailoptin'),
                        __('Complete your order', 'mailoptin')
                    )],
                    ['wait', ['amount' => 2, 'unit' => 'days']],
                    ['check', 'not_recovered'],
                    ['email', __('Last chance: your cart', 'mailoptin'), sprintf(
                        '<p>%s</p><p>%s</p><p><a href="{{cart.url}}">%s</a></p>',
                        __('Hi {{customer.first_name|there}},', 'mailoptin'),
                        __('This is the last reminder about the {{cart.items}} in your cart.', 'mailoptin'),
                        __('Return to your cart', 'mailoptin')
                    )],
                ],
            ],
            'review_reminder' => [
                'title'            => __('Review reminder', 'mailoptin'),
                'description'      => __('Ask customers how they are getting on with what they bought, and remind them once if they haven\'t reviewed it.', 'mailoptin'),
                'flow'             => __('Order completed → wait 10 days → email → wait 7 days → reminder if not reviewed', 'mailoptin'),
                'trigger_id'       => 'woo_order_status',
                'trigger_settings' => ['status' => 'completed', 'runs' => 'every'],
                'steps'            => [
                    ['wait', ['amount' => 10, 'unit' => 'days', 'until' => '09:00']],
                    ['email', __('How are you getting on with your order?', 'mailoptin'), sprintf(
                        '<p>%s</p><p>%s</p><p>%s</p><p><a href="{{order.url}}">%s</a></p>',
                        __('Hi {{customer.first_name|there}},', 'mailoptin'),
                        __('Your order {{order.number}} arrived a little while ago: {{order.items}}.', 'mailoptin'),
                        __('Would you tell other customers what you think? A short review helps them, and us.', 'mailoptin'),
                        __('Review your order', 'mailoptin')
                    )],
                    ['wait', ['amount' => 7, 'unit' => 'days', 'until' => '09:00']],
                    ['check', 'not_reviewed'],
                    ['email', __('A quick favour?', 'mailoptin'), sprintf(
                        '<p>%s</p><p>%s</p><p><a href="{{order.url}}">%s</a></p>',
                        __('Hi {{customer.first_name|there}},', 'mailoptin'),
                        __('If you have a minute, we would love to hear what you think of {{order.items}}.', 'mailoptin'),
                        __('Leave a review', 'mailoptin')
                    )],
                ],
            ],
            'welcome_series'  => [
                'title'            => __('Welcome series', 'mailoptin'),
                'description'      => __('Greet new subscribers over their first week.', 'mailoptin'),
                'flow'             => __('Joins a list → email → wait 2 days → email → wait 4 days → email', 'mailoptin'),
                'trigger_id'       => 'list_joined',
                'trigger_settings' => ['list_id' => self::first_list_id(), 'runs' => 'once'],
                'steps'            => [
                    ['email', __('Welcome to {{store.name}}', 'mailoptin'), sprintf(
                        '<p>%s</p><p>%s</p>',
                        __('Hi {{subscriber.first_name|there}},', 'mailoptin'),
                        __('Thanks for joining {{list.name}}. Here is what to expect from us.', 'mailoptin')
                    )],
                    ['wait', ['amount' => 2, 'unit' => 'days', 'until' => '09:00']],
                    ['email', __('Where to start', 'mailoptin'), sprintf(
                        '<p>%s</p><p>%s</p>',
                        __('Hi {{subscriber.first_name|there}},', 'mailoptin'),
                        __('Here are the things our readers like most.', 'mailoptin')
                    )],
                    ['wait', ['amount' => 4, 'unit' => 'days', 'until' => '09:00']],
                    ['email', __('What would you like to hear about?', 'mailoptin'), sprintf(
                        '<p>%s</p><p>%s</p><p><a href="{{preferences}}">%s</a></p>',
                        __('Hi {{subscriber.first_name|there}},', 'mailoptin'),
                        __('Choose the emails you want from us.', 'mailoptin'),
                        __('Choose your emails', 'mailoptin')
                    )],
                ],
            ],
            'post_purchase'   => [
                'title'            => __('Post-purchase follow-up', 'mailoptin'),
                'description'      => __('Thank customers a few days after their order arrives.', 'mailoptin'),
                'flow'             => __('Order completed → wait 3 days → email', 'mailoptin'),
                'trigger_id'       => 'woo_order_status',
                'trigger_settings' => ['status' => 'completed', 'runs' => 'every'],
                'steps'            => [
                    ['wait', ['amount' => 3, 'unit' => 'days', 'until' => '09:00']],
                    ['email', __('Thank you for your order', 'mailoptin'), sprintf(
                        '<p>%s</p><p>%s</p><p><a href="{{store.url}}">%s</a></p>',
                        __('Hi {{customer.first_name|there}},', 'mailoptin'),
                        __('Thank you for ordering {{order.items}} from {{store.name}}. We hope you love it.', 'mailoptin'),
                        __('Visit the shop', 'mailoptin')
                    )],
                ],
            ],
            'win_back'        => [
                'title'            => __('Win-back', 'mailoptin'),
                'description'      => __('Bring back customers who haven\'t ordered in a while.', 'mailoptin'),
                'flow'             => __('No order for 120 days → email → wait 14 days → email if they still haven\'t ordered', 'mailoptin'),
                'trigger_id'       => 'woo_no_order',
                'trigger_settings' => ['days' => 120, 'runs' => 'every'],
                'steps'            => [
                    ['email', __('We miss you', 'mailoptin'), sprintf(
                        '<p>%s</p><p>%s</p><p><a href="{{store.url}}">%s</a></p>',
                        __('Hi {{customer.first_name|there}},', 'mailoptin'),
                        __('It has been a while since your last order on {{customer.last_order_date}}. Here is what is new.', 'mailoptin'),
                        __('See what\'s new', 'mailoptin')
                    )],
                    ['wait', ['amount' => 14, 'unit' => 'days', 'until' => '09:00']],
                    ['check', 'not_ordered_again'],
                    ['email', __('Still thinking about us?', 'mailoptin'), sprintf(
                        '<p>%s</p><p>%s</p><p><a href="{{store.url}}">%s</a></p>',
                        __('Hi {{customer.first_name|there}},', 'mailoptin'),
                        __('We would love to see you back.', 'mailoptin'),
                        __('Visit the shop', 'mailoptin')
                    )],
                ],
            ],
            'birthday'        => [
                'title'            => __('Birthday', 'mailoptin'),
                'description'      => __('A greeting on a subscriber\'s birthday.', 'mailoptin'),
                'flow'             => __('Birthday comes round → email at 09:00', 'mailoptin'),
                'trigger_id'       => 'date_field',
                'trigger_settings' => ['field_id' => self::first_date_field_id(), 'days_before' => 0, 'time' => '09:00'],
                'steps'            => [
                    ['email', __('Happy birthday from {{store.name}}', 'mailoptin'), sprintf(
                        '<p>%s</p><p>%s</p>',
                        __('Happy birthday, {{subscriber.first_name|friend}}!', 'mailoptin'),
                        __('Wishing you a lovely day from all of us at {{store.name}}.', 'mailoptin')
                    )],
                ],
            ],
        ];

        $recipes = (array)apply_filters('mailoptin_automation_recipes', $recipes);

        // Only recipes whose trigger this site can use (no WooCommerce: no order recipes).
        return array_filter($recipes, function ($recipe) {
            $trigger = Triggers::get_instance()->get($recipe['trigger_id'] ?? '');

            return $trigger && $trigger->is_available();
        });
    }

    /**
     * Create a draft automation from a recipe.
     *
     * @return int automation ID, 0 if the recipe doesn't exist
     */
    public static function create($recipe_id)
    {
        $recipe = self::all()[$recipe_id] ?? null;

        if ( ! $recipe) return 0;

        $automation_id = Repo::create([
            'name'             => $recipe['title'],
            'trigger_id'       => $recipe['trigger_id'],
            'trigger_settings' => $recipe['trigger_settings'],
            'settings'         => self::default_settings(),
            'status'           => Repo::STATUS_DRAFT,
        ]);

        if ( ! $automation_id) return 0;

        $steps = [];

        foreach ($recipe['steps'] as $step) {
            switch ($step[0]) {
                case 'wait':
                    $steps[] = ['type' => Repo::STEP_WAIT, 'settings' => $step[1]];
                    break;
                case 'check':
                    $steps[] = ['type' => Repo::STEP_CHECK, 'settings' => ['check' => $step[1]]];
                    break;
                case 'email':
                    $steps[] = ['type' => Repo::STEP_EMAIL, 'settings' => ['email_campaign_id' => self::create_email($automation_id, $step[1], $step[2])]];
                    break;
            }
        }

        Repo::save_steps($automation_id, $steps);

        return $automation_id;
    }

    public static function default_settings()
    {
        return ['send_from' => '08:00', 'send_to' => '20:00'];
    }

    /**
     * An automation's email: designed in the customizer like a broadcast.
     *
     * @return int email campaign ID
     */
    public static function create_email($automation_id, $subject, $body_html = '', $template = 'Lucid')
    {
        // Email names are unique; this one is internal, the subject is what people see.
        // The name column holds 50 characters: leave room for " (99)".
        $prefix = sprintf('Automation %d: ', $automation_id);
        $base   = $prefix . mb_substr(wp_strip_all_tags($subject), 0, max(1, 45 - mb_strlen($prefix)));
        $name   = $base;
        for ($i = 2; ER::campaign_name_exist($name) && $i < 100; $i++) $name = $base . ' (' . $i . ')';

        $email_campaign_id = ER::add_email_campaign($name, Repo::EMAIL_CAMPAIGN_TYPE, $template);

        if ( ! is_int($email_campaign_id) || ! $email_campaign_id) return 0;

        $values = ['email_campaign_title' => $subject];

        if ('' !== $body_html) {
            $values['email_newsletter_content'] = wp_json_encode([[
                'id'       => wp_generate_password(8, false),
                'type'     => 'text',
                'settings' => ['text_content' => wp_kses_post($body_html)] + Misc::elements_default_fields_values($email_campaign_id)['text'],
            ]]);
        }

        ER::update_settings_by_id($email_campaign_id, $values);

        return $email_campaign_id;
    }

    protected static function first_list_id()
    {
        $lists = ListsRepository::get_all();

        return $lists ? absint($lists[0]['id']) : 0;
    }

    protected static function first_date_field_id()
    {
        if ( ! TagsRepository::is_ready()) return 0;

        foreach (Fields::all() as $field) {
            if (Fields::TYPE_DATE === $field['type']) return absint($field['id']);
        }

        return 0;
    }
}
