<?php

namespace MailOptin\Core\Admin\SettingsPage;

use MailOptin\Core\Admin\UI;
use MailOptin\Core\Api\ApiKeys;
use MailOptin\Core\Api\SubscribersApi;
use W3Guy\Custom_Settings_Page_Api;

/**
 * Settings > API: create and revoke API keys for the Subscriber API.
 */
class ApiSettings
{
    const VIEW = 'api';

    /** A new key is kept here for a few minutes so the next page can show it once; never in a URL. */
    const NEW_KEY_TRANSIENT = 'mo_api_new_key_';

    public function __construct()
    {
        add_filter('mailoptin_settings_header_menu_tabs', [$this, 'tab']);

        add_action('mailoptin_admin_settings_page_pre', function ($active_menu) {
            if (self::VIEW !== $active_menu || ! $this->is_this_page()) return;

            $instance = Custom_Settings_Page_Api::instance();
            $instance->option_name('mo_api');
            $instance->page_header(__('Settings', 'mailoptin'));
            $instance->remove_h2_header();
            Settings::get_instance()->set_settings_page_instance($instance);
        });

        add_action('mailoptin_admin_settings_submenu_page_' . self::VIEW, [$this, 'render']);
        add_action('admin_init', [$this, 'handle_actions']);
    }

    public static function url($args = [])
    {
        return add_query_arg(array_merge(['view' => self::VIEW], $args), MAILOPTIN_SETTINGS_SETTINGS_PAGE);
    }

    protected function is_this_page()
    {
        return MAILOPTIN_SETTINGS_SETTINGS_SLUG === ($_GET['page'] ?? '') && self::VIEW === ($_GET['view'] ?? '');
    }

    public function tab($tabs)
    {
        $tabs[30] = ['id' => self::VIEW, 'url' => self::url(), 'label' => esc_html__('API', 'mailoptin')];

        return $tabs;
    }

    public function handle_actions()
    {
        if ( ! $this->is_this_page() || 'POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || empty($_POST['mo_api_action'])) return;

        if ( ! ApiKeys::can_manage()) wp_die(esc_html__('You are not allowed to do this.', 'mailoptin'), 403);

        $action = sanitize_key($_POST['mo_api_action']);

        if ('create' === $action) {
            check_admin_referer('mo_api_create');

            $result = ApiKeys::create(wp_unslash($_POST['name'] ?? ''), sanitize_key($_POST['access'] ?? ''), get_current_user_id());

            if (is_wp_error($result)) {
                wp_safe_redirect(self::url(['create' => 1, 'mo_error' => 'name']));
                exit;
            }

            set_transient(self::NEW_KEY_TRANSIENT . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
            wp_safe_redirect(self::url(['created' => 1]));
            exit;
        }

        if ('revoke' === $action) {
            $id = sanitize_text_field(wp_unslash($_POST['key_id'] ?? ''));
            check_admin_referer('mo_api_revoke_' . $id);
            ApiKeys::revoke($id);
            wp_safe_redirect(self::url(['revoked' => 1]));
            exit;
        }
    }

    public function render()
    {
        if ( ! ApiKeys::can_manage()) return;

        echo '<div class="mo-ui-page mo-api">';
        echo '<div class="mo-ui-heading"><h1>' . esc_html__('Subscriber API', 'mailoptin') . '</h1><span class="mo-ui-sub">' . esc_html__('Add and update subscribers from your shop, CRM, Zapier or Make.', 'mailoptin') . '</span></div>';
        echo '<hr class="wp-header-end">';

        if ( ! empty($_GET['revoked'])) echo UI::note(esc_html__('API key revoked. Requests with it are refused from now on.', 'mailoptin'), 'ok');

        $new = ! empty($_GET['created']) ? get_transient(self::NEW_KEY_TRANSIENT . get_current_user_id()) : false;

        if (is_array($new)) {
            // Shown once.
            delete_transient(self::NEW_KEY_TRANSIENT . get_current_user_id());
            $this->created_html($new);
        } elseif ( ! empty($_GET['create'])) {
            $this->create_form_html();
        } else {
            $this->keys_html();
            $this->reference_html();
        }

        echo '</div>';
        echo self::styles();
    }

    protected function access_label($access)
    {
        return ApiKeys::ACCESS_READ === $access ? __('Read only', 'mailoptin') : __('Read and write', 'mailoptin');
    }

    protected function created_html($new)
    {
        $record = $new['record'];
        ?>
        <?php echo UI::note('<b>' . esc_html__('API key created.', 'mailoptin') . '</b> ' . esc_html__('Copy it now: for your security it won\'t be shown again. If you lose it, revoke it and create a new one.', 'mailoptin'), 'ok'); ?>
        <div class="mo-ui-card mo-ui-card-pad">
            <h2><?php echo esc_html(sprintf('%s (%s)', $record['name'], $this->access_label($record['access']))); ?></h2>
            <div class="mo-api-secret">
                <label class="screen-reader-text" for="mo-api-new-key"><?php esc_html_e('Your new API key', 'mailoptin'); ?></label>
                <input type="text" id="mo-api-new-key" readonly value="<?php echo esc_attr($new['key']); ?>" onclick="this.select()">
                <button type="button" class="mo-ui-btn mo-ui-primary" data-mo-copy="#mo-api-new-key"><?php esc_html_e('Copy', 'mailoptin'); ?></button>
            </div>
            <p class="mo-api-muted"><?php printf(esc_html__('Send it with every request, in the %s header:', 'mailoptin'), '<code>Authorization</code>'); ?></p>
            <pre><?php echo esc_html('curl ' . rest_url(SubscribersApi::NAMESPACE . '/subscribers') . " \\\n  -H \"Authorization: Bearer " . $new['key'] . '"'); ?></pre>
            <p><?php echo UI::button(__('Done, I\'ve copied it', 'mailoptin'), self::url()); ?></p>
        </div>
        <?php
        echo self::copy_script();
    }

    protected function create_form_html()
    {
        ?>
        <div class="mo-ui-card mo-ui-card-pad">
            <h2><?php esc_html_e('Create an API key', 'mailoptin'); ?></h2>
            <p class="mo-api-lead"><?php esc_html_e('Each tool gets its own key, so you can see what each one does and revoke it alone.', 'mailoptin'); ?></p>
            <?php if ( ! empty($_GET['mo_error'])) echo UI::note(esc_html__('Give the key a name.', 'mailoptin'), 'bad'); ?>
            <form method="post" action="<?php echo esc_url(self::url()); ?>">
                <?php wp_nonce_field('mo_api_create'); ?>
                <input type="hidden" name="mo_api_action" value="create">
                <div class="mo-api-field">
                    <label for="mo-api-name"><?php esc_html_e('Name', 'mailoptin'); ?></label>
                    <input type="text" id="mo-api-name" name="name" maxlength="100" required>
                    <p class="mo-api-muted"><?php esc_html_e('Where it will be used, for example "Shop checkout" or "Zapier".', 'mailoptin'); ?></p>
                </div>
                <fieldset class="mo-api-field">
                    <legend><?php esc_html_e('What it can do', 'mailoptin'); ?></legend>
                    <div class="mo-api-choices">
                        <label class="mo-api-choice"><b><input type="radio" name="access" value="read"> <?php esc_html_e('Read only', 'mailoptin'); ?></b><span><?php esc_html_e('See subscribers, lists, tags and fields. For reports and syncing out.', 'mailoptin'); ?></span></label>
                        <label class="mo-api-choice"><b><input type="radio" name="access" value="write" checked> <?php esc_html_e('Read and write', 'mailoptin'); ?></b><span><?php esc_html_e('Also add and update subscribers, tag them, change their lists, unsubscribe and delete.', 'mailoptin'); ?></span></label>
                    </div>
                </fieldset>
                <p class="mo-api-actions">
                    <button type="submit" class="mo-ui-btn mo-ui-primary"><?php esc_html_e('Create key', 'mailoptin'); ?></button>
                    <?php echo UI::button(__('Cancel', 'mailoptin'), self::url(), 'ghost'); ?>
                </p>
            </form>
        </div>
        <?php
    }

    protected function keys_html()
    {
        $keys = ApiKeys::all();
        uasort($keys, function ($a, $b) {
            return $b['created_at'] <=> $a['created_at'];
        });
        ?>
        <div class="mo-ui-card mo-ui-card-pad">
            <div class="mo-api-head"><h2><?php esc_html_e('API keys', 'mailoptin'); ?></h2><span class="mo-api-end"><?php echo UI::button(__('+ Create API key', 'mailoptin'), self::url(['create' => 1]), 'primary'); ?></span></div>
            <?php if (empty($keys)) : ?>
                <p class="mo-api-lead"><?php esc_html_e('No API keys yet. Create one for each tool that needs to add or read subscribers.', 'mailoptin'); ?></p>
            <?php else : ?>
                <div class="mo-api-scroll"><table class="mo-api-table">
                    <thead><tr>
                        <th><?php esc_html_e('Name', 'mailoptin'); ?></th>
                        <th><?php esc_html_e('Key', 'mailoptin'); ?></th>
                        <th><?php esc_html_e('Access', 'mailoptin'); ?></th>
                        <th><?php esc_html_e('Created', 'mailoptin'); ?></th>
                        <th><?php esc_html_e('Last used', 'mailoptin'); ?></th>
                        <th><span class="screen-reader-text"><?php esc_html_e('Actions', 'mailoptin'); ?></span></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($keys as $key) :
                        $user = get_userdata($key['user_id']); ?>
                        <tr>
                            <td><b><?php echo esc_html($key['name']); ?></b></td>
                            <td class="mo-api-mono"><?php echo esc_html(ApiKeys::masked($key)); ?></td>
                            <td><?php echo UI::badge($this->access_label($key['access']), ApiKeys::ACCESS_WRITE === $key['access'] ? 'info' : ''); ?></td>
                            <td class="mo-api-muted"><?php echo esc_html(sprintf(__('%1$s by %2$s', 'mailoptin'), wp_date(get_option('date_format'), $key['created_at']), $user ? $user->user_login : __('a deleted user', 'mailoptin'))); ?></td>
                            <td><?php if ($key['last_used_at']) : ?>
                                    <?php echo esc_html(sprintf(__('%s ago', 'mailoptin'), human_time_diff($key['last_used_at']))); ?><br><span class="mo-api-muted"><?php echo esc_html($key['last_used_ip']); ?></span>
                                <?php else : ?><span class="mo-api-muted"><?php esc_html_e('Never', 'mailoptin'); ?></span><?php endif; ?></td>
                            <td class="mo-api-revoke">
                                <form method="post" action="<?php echo esc_url(self::url()); ?>" onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(sprintf(__('Revoke "%s"? Tools using it stop working at once.', 'mailoptin'), $key['name']))); ?>)">
                                    <?php wp_nonce_field('mo_api_revoke_' . $key['id']); ?>
                                    <input type="hidden" name="mo_api_action" value="revoke">
                                    <input type="hidden" name="key_id" value="<?php echo esc_attr($key['id']); ?>">
                                    <button type="submit" class="mo-ui-btn mo-ui-danger"><?php esc_html_e('Revoke', 'mailoptin'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <p class="mo-api-muted"><?php esc_html_e('A key stops working if the person who created it loses access to MailOptin. WordPress Application Passwords also work, for tools that use them.', 'mailoptin'); ?></p>
            <?php endif; ?>
            <?php if ( ! is_ssl() && ! SubscribersApi::insecure_allowed()) echo UI::note(esc_html__('This site isn\'t using HTTPS, so API keys will be refused: they would travel unencrypted.', 'mailoptin'), 'warn'); ?>
        </div>
        <?php
    }

    protected function reference_html()
    {
        $base = rest_url(SubscribersApi::NAMESPACE);
        $endpoints = [
            ['GET', '/subscribers'], ['POST', '/subscribers'], ['GET', '/subscribers/{id or email}'], ['PATCH', '/subscribers/{id or email}'],
            ['DELETE', '/subscribers/{id or email}'], ['POST', '/subscribers/{…}/tags'], ['DELETE', '/subscribers/{…}/tags/{tag}'],
            ['POST', '/subscribers/{…}/lists'], ['DELETE', '/subscribers/{…}/lists/{list}'], ['POST', '/subscribers/{…}/unsubscribe'],
            ['GET', '/lists'], ['GET', '/tags'], ['GET', '/fields'],
        ];
        $example = 'curl -X POST ' . untrailingslashit($base) . "/subscribers \\\n  -H \"Authorization: Bearer YOUR_KEY\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"email\":\"maya@example.org\",\n       \"first_name\":\"Maya\",\n       \"lists\":[\"Newsletter\"],\n       \"tags\":[\"customer\"],\n       \"consent\":{\"given\":true,\n         \"text\":\"Ticked Email me offers at checkout\"}}'";
        ?>
        <div class="mo-api-grid">
            <div class="mo-ui-card mo-ui-card-pad">
                <h2><?php esc_html_e('Address', 'mailoptin'); ?></h2>
                <div class="mo-api-secret">
                    <label class="screen-reader-text" for="mo-api-base"><?php esc_html_e('API address', 'mailoptin'); ?></label>
                    <input type="text" id="mo-api-base" readonly value="<?php echo esc_attr(untrailingslashit($base)); ?>" onclick="this.select()">
                    <button type="button" class="mo-ui-btn" data-mo-copy="#mo-api-base"><?php esc_html_e('Copy', 'mailoptin'); ?></button>
                </div>
                <h2 class="mo-api-gap"><?php esc_html_e('Example: add a subscriber', 'mailoptin'); ?></h2>
                <pre><?php echo esc_html($example); ?></pre>
                <p class="mo-api-muted"><?php esc_html_e('Without "consent", the subscriber is asked to confirm by email. Someone who unsubscribed, bounced or complained is refused.', 'mailoptin'); ?></p>
            </div>
            <div class="mo-ui-card mo-ui-card-pad">
                <h2><?php esc_html_e('Endpoints', 'mailoptin'); ?></h2>
                <ul class="mo-api-endpoints">
                    <?php foreach ($endpoints as $endpoint) : ?>
                        <li><span class="mo-api-verb mo-api-<?php echo esc_attr(strtolower($endpoint[0])); ?>"><?php echo esc_html($endpoint[0]); ?></span><code><?php echo esc_html($endpoint[1]); ?></code></li>
                    <?php endforeach; ?>
                </ul>
                <p><a href="https://mailoptin.io/docs/subscriber-api/" target="_blank" rel="noopener"><?php esc_html_e('Full API documentation →', 'mailoptin'); ?></a></p>
            </div>
        </div>
        <?php
        echo self::copy_script();
    }

    protected static function copy_script()
    {
        static $printed = false;
        if ($printed) return '';
        $printed = true;

        return '<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-mo-copy]");if(!b)return;var i=document.querySelector(b.getAttribute("data-mo-copy"));if(!i)return;i.select();var done=function(){var t=b.textContent;b.textContent=' . wp_json_encode(__('Copied', 'mailoptin')) . ';setTimeout(function(){b.textContent=t;},1500);};if(navigator.clipboard){navigator.clipboard.writeText(i.value).then(done,function(){document.execCommand("copy");done();});}else{document.execCommand("copy");done();}});</script>';
    }

    protected static function styles()
    {
        return '<style>
.mo-api{max-width:1080px}
.mo-api .mo-ui-card{margin-bottom:16px}
.mo-api .mo-ui-note{margin-bottom:14px}
.mo-api h2{font-size:15px;margin:0 0 6px}
.mo-api-lead{color:#646970;margin:0 0 14px;max-width:70ch}
.mo-api-muted{color:#646970}
.mo-api-head{display:flex;align-items:center;gap:10px;margin-bottom:10px}.mo-api-head h2{margin:0}.mo-api-end{margin-left:auto}
.mo-api-table{width:100%;border-collapse:collapse}
.mo-api-table th{font-weight:500;color:#646970;text-align:left;padding:9px 12px;border-bottom:1px solid #dcdcde}
.mo-api-table td{padding:11px 12px;border-bottom:1px solid #f0f0f1;vertical-align:middle}
.mo-api-mono,.mo-api code,.mo-api pre,.mo-api-secret input{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.mo-api-mono{font-size:12px}
.mo-api-revoke{text-align:right}.mo-api-revoke form{margin:0}
.mo-api-secret{display:flex;gap:8px;align-items:center;margin:8px 0}
.mo-api-secret input{flex:1;min-height:34px;border-radius:6px}
.mo-api pre{background:#1d2327;color:#f0f0f1;border-radius:8px;padding:14px 16px;overflow:auto;font-size:12px;line-height:1.55;margin:8px 0;white-space:pre}
.mo-api-field{margin:14px 0;border:0;padding:0}
.mo-api-field>label,.mo-api-field legend{display:block;font-weight:600;margin-bottom:5px;padding:0}
.mo-api-field input[type=text]{width:100%;max-width:420px;min-height:34px;border-radius:6px}
.mo-api-choices{display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:640px}
.mo-api-choice{border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;display:block;cursor:pointer}
.mo-api-choice:has(input:checked){border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;background:#f6faff}
.mo-api-choice b{display:block;margin-bottom:2px}.mo-api-choice span{color:#646970}
.mo-api-actions{display:flex;gap:8px;margin-top:18px}
.mo-api-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}
.mo-api .mo-ui-card{min-width:0}
.mo-api-scroll{overflow-x:auto}
.mo-api-secret input{min-width:0}
.mo-api-gap{margin-top:18px!important}
.mo-api-endpoints{margin:8px 0 0;padding:0;list-style:none}
.mo-api-endpoints li{padding:5px 0;border-bottom:1px solid #f0f0f1;display:flex;gap:10px;margin:0}
.mo-api-verb{display:inline-block;min-width:54px;font-weight:600;font-size:11px;font-family:ui-monospace,Menlo,monospace}
.mo-api-get{color:#00701a}.mo-api-post{color:#2271b1}.mo-api-patch{color:#8a6100}.mo-api-delete{color:#b32d2e}
@media (max-width:782px){.mo-api-grid,.mo-api-choices{grid-template-columns:minmax(0,1fr)}.mo-api-table th:nth-child(2),.mo-api-table td:nth-child(2),.mo-api-table th:nth-child(4),.mo-api-table td:nth-child(4){display:none}.mo-api-table th,.mo-api-table td{padding:9px 6px}}
</style>';
    }

    /**
     * @return ApiSettings
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
