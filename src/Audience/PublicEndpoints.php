<?php

namespace MailOptin\Core\Audience;

use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;

/**
 * Pages subscribers reach from email links: confirm, unsubscribe and view in browser.
 */
class PublicEndpoints
{
    public function __construct()
    {
        add_action('init', [$this, 'handle_request']);
    }

    public function handle_request()
    {
        if (isset($_GET['mo_confirm'])) $this->confirm();

        if (isset($_GET['mo_unsub'])) $this->unsubscribe();

        if (isset($_GET['mo_web'])) $this->web_version();
    }

    protected function token()
    {
        return isset($_REQUEST['mo_token']) ? sanitize_text_field(wp_unslash($_REQUEST['mo_token'])) : '';
    }

    protected function invalid_link()
    {
        $message = esc_html__('This link is invalid or has expired.', 'mailoptin');

        wp_die($message, $message, ['response' => 400]);
    }

    protected function page($title, $message, $extra_html = '')
    {
        nocache_headers();

        $html = sprintf('<h1>%s</h1><p>%s</p>%s', esc_html($title), $message, $extra_html);

        wp_die($html, esc_html($title), ['response' => 200]);
    }

    public function confirm()
    {
        $subscriber = Subscribers::get(absint($_GET['mo_confirm']));

        if ( ! $subscriber || ! Tokens::check('confirm', [$subscriber['id'], $subscriber['email']], $this->token())) {
            $this->invalid_link();
        }

        // Confirm on POST only, so link scanners that open every link in an inbox can't confirm
        // signups someone else made. The page submits itself, so for people it's still one click.
        if ('POST' !== strtoupper($_SERVER['REQUEST_METHOD'] ?? '')) {
            $form = sprintf(
                '<form method="post" action="%s" id="mo-confirm-form"><button type="submit" class="button">%s</button></form><script>document.getElementById("mo-confirm-form").submit();</script>',
                esc_url(Tokens::confirm_url($subscriber)),
                esc_html__('Confirm subscription', 'mailoptin')
            );

            $this->page(
                __('Confirm your subscription', 'mailoptin'),
                esc_html__('Confirming your subscription…', 'mailoptin'),
                $form
            );
        }

        Subscription::confirm($subscriber['id']);

        $redirect = apply_filters('mo_subscription_confirmed_redirect', '', $subscriber);

        if ( ! empty($redirect)) {
            wp_safe_redirect($redirect);
            exit;
        }

        $this->page(
            __("You're subscribed", 'mailoptin'),
            esc_html__('Thanks for confirming. You will now receive our emails.', 'mailoptin'),
            sprintf('<p><a class="button" href="%s">%s</a></p>', esc_url(home_url('/')), esc_html__('Back to the site', 'mailoptin'))
        );
    }

    /**
     * GET shows a confirmation button so link scanners can't unsubscribe people.
     * POST unsubscribes; that is also what one-click unsubscribe (RFC 8058) sends.
     */
    public function unsubscribe()
    {
        $subscriber      = Subscribers::get(absint($_GET['mo_unsub']));
        $list_id         = isset($_GET['mo_list']) ? absint($_GET['mo_list']) : 0;
        $campaign_log_id = isset($_GET['mo_cl']) ? absint($_GET['mo_cl']) : 0;

        if ( ! $subscriber || ! Tokens::check('unsubscribe', [$subscriber['id'], $subscriber['email'], $list_id, $campaign_log_id], $this->token())) {
            $this->invalid_link();
        }

        $list       = $list_id ? ListsRepository::get($list_id) : null;
        $from_label = $list ? sprintf(__('from %s', 'mailoptin'), $list['name']) : __('from all our emails', 'mailoptin');

        if ('POST' === strtoupper($_SERVER['REQUEST_METHOD'] ?? '')) {

            Subscription::unsubscribe($subscriber['id'], $list_id, $campaign_log_id);

            $this->page(
                __("You've been unsubscribed", 'mailoptin'),
                sprintf(esc_html__('%1$s will no longer receive emails %2$s.', 'mailoptin'), '<strong>' . esc_html($subscriber['email']) . '</strong>', esc_html($from_label))
            );
        }

        $form = sprintf(
            '<form method="post" action="%s"><button type="submit" class="button">%s</button></form>',
            esc_url(Tokens::unsubscribe_url($subscriber, $list_id, $campaign_log_id)),
            esc_html__('Unsubscribe', 'mailoptin')
        );

        $this->page(
            __('Unsubscribe', 'mailoptin'),
            sprintf(esc_html__('Click the button below to unsubscribe %1$s %2$s.', 'mailoptin'), '<strong>' . esc_html($subscriber['email']) . '</strong>', esc_html($from_label)),
            $form
        );
    }

    public function web_version()
    {
        $campaign_log_id = absint($_GET['mo_web']);

        if ( ! Tokens::check('web', [$campaign_log_id], $this->token())) {
            $this->invalid_link();
        }

        $html = self::web_version_html($campaign_log_id);

        if (empty($html)) $this->invalid_link();

        nocache_headers();

        echo $html;
        exit;
    }

    /**
     * The campaign as a browser page. Anyone with the URL can open it, so personal links and merge tags are blanked.
     */
    public static function web_version_html($campaign_log_id)
    {
        $html = CampaignRenderer::campaign_content($campaign_log_id)['html'];

        if (empty($html)) return '';

        // Not personalized, so recipient merge tags are blanked. Connections add their own tags with the filter.
        $tags = apply_filters('mo_web_version_blank_tags', [
            '{{unsubscribe}}'           => '#',
            '{{webversion}}'            => '#',
            '{{preferences}}'           => '#',
            '{{subscriber.email}}'      => '',
            '{{subscriber.first_name}}' => '',
            '{{subscriber.last_name}}'  => '',
        ], $campaign_log_id);

        $html = CampaignRenderer::replace_tags($html, $tags, true);

        // Blank custom fields too, keeping fallbacks: "Hi {{subscriber.first_name|there}}" reads "Hi there".
        $blank = \MailOptin\Core\Repositories\TagsRepository::is_ready() ? array_map('__return_empty_string', \MailOptin\Core\Repositories\CustomFieldsRepository::merge_values(0)) : [];

        return CampaignRenderer::replace_fallback_tags($html, [], true, $blank);
    }

    /**
     * @return PublicEndpoints
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
