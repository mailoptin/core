<?php

namespace MailOptin\Core\Api;

use MailOptin\Core\Audience\Subscription;
use MailOptin\Core\Repositories\CustomFieldsRepository as Fields;
use MailOptin\Core\Repositories\ListsRepository;
use MailOptin\Core\Repositories\SubscribersRepository as Subscribers;
use MailOptin\Core\Repositories\TagsRepository as Tags;

use function MailOptin\Core\get_capability;

/**
 * The Subscriber API: /wp-json/mailoptin/v1/subscribers, /lists, /tags and /fields.
 *
 * Authentication, for these routes only:
 * - a MailOptin API key in "Authorization: Bearer <key>" or "X-MailOptin-Key: <key>", over HTTPS;
 * - or a logged-in WordPress user who can manage MailOptin (an Application Password, or the browser
 *   with the REST nonce).
 */
class SubscribersApi
{
    const NAMESPACE = 'mailoptin/v1';

    const MAX_PER_PAGE = 100;

    /** Requests per minute per key. */
    const RATE_LIMIT = 120;

    /** Set for the request once a key checks out. */
    protected $key = null;

    /**
     * The key check's result for the request being handled. WordPress runs every method's permission
     * check on a route to build the Allow header, so without this one request would count several times
     * against the limit. Kept with the request object itself: object IDs are reused once freed.
     */
    protected $checked_request = null;
    protected $checked_result = null;

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_filter('rest_post_dispatch', [$this, 'retry_after_header'], 10, 3);
        // Early, so plugins that refuse anonymous REST requests see the key's user.
        add_filter('determine_current_user', [$this, 'user_from_key'], 30);
    }

    /**
     * Whether this request is for one of the Subscriber API's routes (not the rest of the REST API).
     */
    public static function is_api_request()
    {
        $route = isset($_GET['rest_route']) && is_string($_GET['rest_route']) ? $_GET['rest_route'] : '';

        if ('' === $route) {
            $path   = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $prefix = '/' . trim(rest_get_url_prefix(), '/') . '/';
            $at     = strpos($path, $prefix);
            $route  = false === $at ? '' : substr($path, $at + strlen($prefix) - 1);
        }

        return (bool)preg_match('#^/' . preg_quote(self::NAMESPACE, '#') . '/(subscribers|lists|tags|fields)(/|$)#', $route);
    }

    public function user_from_key($user_id)
    {
        if ($user_id || ! self::is_api_request()) return $user_id;

        $header = isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        if ('' === $header && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];

        $key = preg_match('/^Bearer\s+(' . preg_quote(ApiKeys::PREFIX, '/') . '\S+)$/i', trim($header), $m) ? $m[1] : '';
        if ('' === $key && isset($_SERVER['HTTP_X_MAILOPTIN_KEY']) && is_string($_SERVER['HTTP_X_MAILOPTIN_KEY'])) $key = trim($_SERVER['HTTP_X_MAILOPTIN_KEY']);

        if ('' === $key || ( ! is_ssl() && ! self::insecure_allowed())) return $user_id;

        $record = ApiKeys::verify($key);

        // The permission checks still decide read or write access and apply the rate limit.
        return $record ? (int)$record['user_id'] : $user_id;
    }

    /* ---------------------------------------------------------------------------------------------
     * Authentication
     * ------------------------------------------------------------------------------------------ */

    /**
     * The key sent with the request, or '' when none.
     */
    public static function key_from_request(\WP_REST_Request $request)
    {
        $header = (string)$request->get_header('authorization');

        // Some Apache setups only pass the header on as REDIRECT_HTTP_AUTHORIZATION.
        if ('' === $header && ! empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (preg_match('/^Bearer\s+(' . preg_quote(ApiKeys::PREFIX, '/') . '\S+)$/i', trim($header), $m)) return $m[1];

        return trim((string)$request->get_header('x_mailoptin_key'));
    }

    /**
     * Plain HTTP is refused for keys, except on local and development sites.
     */
    public static function insecure_allowed()
    {
        return (bool)apply_filters('mo_api_allow_http', in_array(wp_get_environment_type(), ['local', 'development'], true));
    }

    protected function check_access(\WP_REST_Request $request, $write)
    {
        if (null !== $request->get_param('api_key')) {
            return new \WP_Error('mo_api_key_in_url', __('Send the API key in the Authorization header, not in the URL, where it ends up in server logs.', 'mailoptin'), ['status' => 400]);
        }

        $key = self::key_from_request($request);

        if ('' === $key) {

            if (current_user_can(get_capability())) return true;

            return new \WP_Error('mo_api_unauthorized', __('Send a MailOptin API key in the Authorization header: "Bearer <key>". Create one under MailOptin > Settings > API.', 'mailoptin'), ['status' => 401]);
        }

        if ( ! is_ssl() && ! self::insecure_allowed()) {
            return new \WP_Error('mo_api_https_required', __('API keys only work over HTTPS.', 'mailoptin'), ['status' => 403]);
        }

        // Checked once per request: the key, the rate limit and "last used".
        if ($this->checked_request !== $request) {
            $record = ApiKeys::verify($key);

            if ( ! $record) {
                $result = new \WP_Error('mo_api_invalid_key', __('This API key is not valid, or was revoked.', 'mailoptin'), ['status' => 401]);
            } else {
                $result = $this->rate_limited($record['id']) ?: $record;

                if (is_array($result)) ApiKeys::touch($record['id'], Subscription::request_ip());
            }

            $this->checked_request = $request;
            $this->checked_result  = $result;
        }

        if (is_wp_error($this->checked_result)) return $this->checked_result;

        $record = $this->checked_result;

        if ($write && ApiKeys::ACCESS_WRITE !== $record['access']) {
            return new \WP_Error('mo_api_read_only', __('This API key is read only.', 'mailoptin'), ['status' => 403]);
        }

        // Act as the person who made the key, so changes are logged against them.
        wp_set_current_user($record['user_id']);
        SubscriberService::$key_id = $record['id'];
        $this->key                 = $record;

        return true;
    }

    protected function rate_limited($key_id)
    {
        $limit  = (int)apply_filters('mo_api_rate_limit', self::RATE_LIMIT, $key_id);
        $window = (int)floor(time() / MINUTE_IN_SECONDS);
        $cache  = 'mo_api_rate_' . $key_id . '_' . $window;

        if (wp_using_ext_object_cache()) {
            // Atomic with a persistent object cache.
            wp_cache_add($cache, 0, 'mailoptin_api', 2 * MINUTE_IN_SECONDS);
            $count = (int)wp_cache_incr($cache, 1, 'mailoptin_api');
        } else {
            // Without one, close enough: concurrent requests can slip a few over. Expired rows are
            // removed by WordPress's daily transient clean-up.
            $count = (int)get_transient($cache) + 1;
            set_transient($cache, $count, 2 * MINUTE_IN_SECONDS);
        }

        if ($limit > 0 && $count > $limit) {
            return new \WP_Error('mo_api_rate_limited', sprintf(__('Too many requests: at most %d a minute per key.', 'mailoptin'), $limit), [
                'status'      => 429,
                'retry_after' => ($window + 1) * MINUTE_IN_SECONDS - time(),
            ]);
        }

        return null;
    }

    public function retry_after_header($response, $server, $request)
    {
        if ($response instanceof \WP_REST_Response && 429 === $response->get_status()) {
            $data = $response->get_data();
            if ( ! empty($data['data']['retry_after'])) $response->header('Retry-After', (string)(int)$data['data']['retry_after']);
        }

        return $response;
    }

    public function can_read(\WP_REST_Request $request)
    {
        return $this->check_access($request, false);
    }

    public function can_write(\WP_REST_Request $request)
    {
        return $this->check_access($request, true);
    }

    /* ---------------------------------------------------------------------------------------------
     * Routes
     * ------------------------------------------------------------------------------------------ */

    public function routes()
    {
        $read  = [$this, 'can_read'];
        $write = [$this, 'can_write'];
        $one   = '/subscribers/(?P<subscriber>[^/]+)';

        register_rest_route(self::NAMESPACE, '/subscribers', [
            ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'list_subscribers'], 'permission_callback' => $read],
            ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$this, 'create_subscriber'], 'permission_callback' => $write],
        ]);

        register_rest_route(self::NAMESPACE, $one, [
            ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'get_subscriber'], 'permission_callback' => $read],
            ['methods' => 'PATCH, PUT', 'callback' => [$this, 'update_subscriber'], 'permission_callback' => $write],
            ['methods' => \WP_REST_Server::DELETABLE, 'callback' => [$this, 'delete_subscriber'], 'permission_callback' => $write],
        ]);

        register_rest_route(self::NAMESPACE, $one . '/tags', [
            ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$this, 'add_tags'], 'permission_callback' => $write],
        ]);
        register_rest_route(self::NAMESPACE, $one . '/tags/(?P<tag>[^/]+)', [
            ['methods' => \WP_REST_Server::DELETABLE, 'callback' => [$this, 'remove_tag'], 'permission_callback' => $write],
        ]);
        register_rest_route(self::NAMESPACE, $one . '/lists', [
            ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$this, 'add_lists'], 'permission_callback' => $write],
        ]);
        register_rest_route(self::NAMESPACE, $one . '/lists/(?P<list>[^/]+)', [
            ['methods' => \WP_REST_Server::DELETABLE, 'callback' => [$this, 'remove_list'], 'permission_callback' => $write],
        ]);
        register_rest_route(self::NAMESPACE, $one . '/unsubscribe', [
            ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$this, 'unsubscribe'], 'permission_callback' => $write],
        ]);

        foreach (['lists', 'tags', 'fields'] as $what) {
            register_rest_route(self::NAMESPACE, '/' . $what, [
                ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'get_' . $what], 'permission_callback' => $read],
            ]);
        }
    }

    /* ---------------------------------------------------------------------------------------------
     * Handlers
     * ------------------------------------------------------------------------------------------ */

    protected function body(\WP_REST_Request $request)
    {
        $json = $request->get_json_params();

        return is_array($json) ? $json : $request->get_body_params();
    }

    protected function subscriber(\WP_REST_Request $request)
    {
        return SubscriberService::find($request->get_param('subscriber')) ?: SubscriberService::not_found();
    }

    protected function respond($result, $status = 200)
    {
        return is_wp_error($result) ? $result : new \WP_REST_Response($result, $status);
    }

    /**
     * ISO 8601 time from the request, as UTC "Y-m-d H:i:s"; null when absent; WP_Error when unreadable.
     */
    protected function since($value, $name)
    {
        if (null === $value || '' === $value) return null;

        $time = is_string($value) ? strtotime($value) : false;

        if (false === $time) return new \WP_Error('mo_api_bad_time', sprintf(__('"%s" must be a date and time, like 2026-11-04T08:00:00Z.', 'mailoptin'), $name), ['status' => 400]);

        return gmdate('Y-m-d H:i:s', $time);
    }

    public function list_subscribers(\WP_REST_Request $request)
    {
        $args = [
            'per_page' => min(self::MAX_PER_PAGE, max(1, absint($request->get_param('per_page') ?: 20))),
            'page'     => max(1, absint($request->get_param('page') ?: 1)),
            'orderby'  => in_array($request->get_param('orderby'), ['created_at', 'updated_at', 'id', 'email'], true) ? $request->get_param('orderby') : 'created_at',
            'order'    => 'asc' === strtolower((string)$request->get_param('order')) ? 'ASC' : 'DESC',
            'search'   => sanitize_text_field((string)$request->get_param('search')),
        ];

        $status = (string)$request->get_param('status');
        if ('' !== $status) {
            $allowed = [Subscribers::STATUS_SUBSCRIBED, Subscribers::STATUS_PENDING, Subscribers::STATUS_UNSUBSCRIBED, Subscribers::STATUS_BOUNCED, Subscribers::STATUS_COMPLAINED];
            if ( ! in_array($status, $allowed, true)) {
                return new \WP_Error('mo_api_bad_status', sprintf(__('"status" must be one of: %s.', 'mailoptin'), implode(', ', $allowed)), ['status' => 400]);
            }
            $args['status'] = $status;
        }

        if (null !== $request->get_param('list')) {
            $list_id = SubscriberService::one_list($request->get_param('list'));
            if (is_wp_error($list_id)) return new \WP_Error('mo_unknown_lists', __('"list" must be the ID or name of a list.', 'mailoptin'), ['status' => 400]);
            $args['list_id'] = $list_id;
        }

        if (null !== $request->get_param('tag')) {
            $tags = SubscriberService::plan_tags((array)$request->get_param('tag'));
            if (is_wp_error($tags)) return $tags;
            if (empty($tags['ids'])) return new \WP_Error('mo_unknown_tags', __('"tag" must be the ID or name of a tag.', 'mailoptin'), ['status' => 400]);
            $args['tag_ids'] = $tags['ids'];
        }

        foreach (['created_since', 'updated_since'] as $name) {
            $since = $this->since($request->get_param($name), $name);
            if (is_wp_error($since)) return $since;
            if ($since) $args[$name] = $since;
        }

        $result = Subscribers::query($args);

        $response = new \WP_REST_Response(array_map(function ($item) {
            return SubscriberService::format($item);
        }, $result['items']));

        $response->header('X-WP-Total', (string)$result['total']);
        $response->header('X-WP-TotalPages', (string)(int)ceil($result['total'] / $args['per_page']));

        return $response;
    }

    public function get_subscriber(\WP_REST_Request $request)
    {
        $subscriber = $this->subscriber($request);

        return is_wp_error($subscriber) ? $subscriber : $this->respond(SubscriberService::format($subscriber));
    }

    public function create_subscriber(\WP_REST_Request $request)
    {
        $result = SubscriberService::upsert($this->body($request));

        if (is_wp_error($result)) return $result;

        $data = $result['subscriber'] + ['created' => $result['created'], 'confirmation_sent' => $result['confirmation_sent']];

        return new \WP_REST_Response($data, $result['created'] ? 201 : 200);
    }

    public function update_subscriber(\WP_REST_Request $request)
    {
        $subscriber = $this->subscriber($request);

        return is_wp_error($subscriber) ? $subscriber : $this->respond(SubscriberService::update($subscriber, $this->body($request)));
    }

    public function delete_subscriber(\WP_REST_Request $request)
    {
        $subscriber = $this->subscriber($request);

        if (is_wp_error($subscriber)) return $subscriber;

        SubscriberService::delete($subscriber);

        return new \WP_REST_Response(['deleted' => true, 'id' => (int)$subscriber['id']], 200);
    }

    public function add_tags(\WP_REST_Request $request)
    {
        $subscriber = $this->subscriber($request);
        if (is_wp_error($subscriber)) return $subscriber;

        $body = $this->body($request);

        return $this->respond(SubscriberService::add_tags($subscriber, $body['tags'] ?? [], ! empty($body['create_missing'])));
    }

    public function remove_tag(\WP_REST_Request $request)
    {
        $subscriber = $this->subscriber($request);

        return is_wp_error($subscriber) ? $subscriber : $this->respond(SubscriberService::remove_tag($subscriber, rawurldecode($request->get_param('tag'))));
    }

    public function add_lists(\WP_REST_Request $request)
    {
        $subscriber = $this->subscriber($request);
        if (is_wp_error($subscriber)) return $subscriber;

        $body   = $this->body($request);
        $result = SubscriberService::add_lists($subscriber, $body['lists'] ?? [], $body['consent'] ?? [], ! empty($body['create_missing']));

        if (is_wp_error($result)) return $result;

        return $this->respond($result['subscriber'] + ['confirmation_sent' => $result['confirmation_sent']]);
    }

    public function remove_list(\WP_REST_Request $request)
    {
        $subscriber = $this->subscriber($request);

        return is_wp_error($subscriber) ? $subscriber : $this->respond(SubscriberService::remove_list($subscriber, rawurldecode($request->get_param('list'))));
    }

    public function unsubscribe(\WP_REST_Request $request)
    {
        $subscriber = $this->subscriber($request);
        if (is_wp_error($subscriber)) return $subscriber;

        $body = $this->body($request);

        // No "list" (or null): from everything. Anything else must name a list.
        return $this->respond(SubscriberService::unsubscribe($subscriber, array_key_exists('list', (array)$body) ? $body['list'] : null));
    }

    public function get_lists()
    {
        return $this->respond(array_map(function ($list) {
            return ['id' => (int)$list['id'], 'name' => $list['name'], 'double_optin' => (bool)$list['double_optin']];
        }, ListsRepository::get_all()));
    }

    public function get_tags()
    {
        if ( ! Tags::is_ready()) return $this->respond([]);

        return $this->respond(array_map(function ($tag) {
            return ['id' => (int)$tag['id'], 'name' => $tag['name'], 'subscribers' => (int)($tag['subscribers'] ?? 0)];
        }, array_values(Tags::all())));
    }

    public function get_fields()
    {
        if ( ! Tags::is_ready()) return $this->respond([]);

        return $this->respond(array_map(function ($field) {
            return ['id' => (int)$field['id'], 'key' => $field['merge_key'], 'label' => $field['label'], 'type' => $field['type'], 'options' => array_values((array)$field['options'])];
        }, array_values(Fields::all())));
    }

    /**
     * @return SubscribersApi
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
