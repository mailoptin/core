<?php

namespace MailOptin\Core\EmailBlocks;

use MailOptin\Core\EmailCampaigns\Newsletter\AbstractTemplate;
use MailOptin\Core\Repositories\AutomationsRepository;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * The templates broadcasts and automation emails are built in: the frame (header, footer, colours)
 * around the blocks. Register one from a plugin or theme:
 *
 *     mailoptin_register_email_template([
 *         'id'         => 'acme-shop',                 // stored with each email that uses it
 *         'name'       => 'Acme Shop',
 *         'class'      => Acme_Shop_Template::class,  // extends MailOptin\Core\EmailCampaigns\Newsletter\AbstractTemplate
 *         'screenshot' => plugins_url('acme-shop.png', __FILE__),
 *     ]);
 *
 * or on the mailoptin_email_templates action: function (Templates $templates) { $templates->register([...]); }
 *
 * The class's get_body() returns the email's HTML with {{newsletter.content}} where the blocks go,
 * inside a <table> (each block is a <tr>); content_width() is that table's width in pixels, which
 * the blocks (and columns) are drawn to; get_styles() its CSS.
 */
class Templates
{
    /** @var array id => template */
    protected $templates = [];

    protected $loaded = false;

    /** @var array email id => template id, while the editor previews another template */
    protected static $preview = [];

    public function __construct()
    {
        // The email's template class from its stored id.
        add_filter('mailoptin_register_template_class', [$this, 'template_class'], 10, 3);
        // Listed where a new broadcast's template is chosen.
        add_filter('mailoptin_registered_email_templates', [$this, 'list_for_new_broadcasts']);
    }

    /**
     * @param array $args id, name, class; screenshot, description, campaign_types (default: broadcasts
     *                    and automation emails)
     *
     * @return true|\WP_Error
     */
    public function register($args)
    {
        $args = wp_parse_args($args, [
            'id'             => '',
            'name'           => '',
            'class'          => '',
            'screenshot'     => '',
            'description'    => '',
            'campaign_types' => [ER::NEWSLETTER, AutomationsRepository::EMAIL_CAMPAIGN_TYPE],
        ]);

        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$args['id']);

        if ('' === $id || strlen($id) > 50 || ER::CODE_YOUR_OWN_TEMPLATE === $id) {
            return new \WP_Error('mo_email_template_id', __('An email template needs an id: letters, numbers, - and _.', 'mailoptin'));
        }

        if ( ! is_string($args['class']) || ! class_exists($args['class']) || ! is_subclass_of($args['class'], AbstractTemplate::class)) {
            return new \WP_Error('mo_email_template_class', sprintf(
                /* translators: %s: class name */
                __('The email template class %s must exist and extend MailOptin\Core\EmailCampaigns\Newsletter\AbstractTemplate.', 'mailoptin'),
                is_string($args['class']) ? $args['class'] : ''
            ));
        }

        $this->templates[$id] = [
            'id'             => $id,
            'name'           => '' !== (string)$args['name'] ? (string)$args['name'] : $id,
            'class'          => $args['class'],
            'screenshot'     => (string)$args['screenshot'],
            'description'    => (string)$args['description'],
            'campaign_types' => array_values(array_filter((array)$args['campaign_types'], 'is_string')),
        ];

        return true;
    }

    public function get($id)
    {
        $this->load();

        return $this->templates[$id] ?? null;
    }

    /**
     * @return array id => template
     */
    public function all()
    {
        $this->load();

        return $this->templates;
    }

    /**
     * Templates an email can use (by its type).
     */
    public function for_email($email_campaign_id)
    {
        $type = ER::get_email_campaign_type($email_campaign_id);

        return array_filter($this->all(), function ($template) use ($type) {
            return in_array($type, $template['campaign_types'], true);
        });
    }

    protected function load()
    {
        if ($this->loaded) return;

        $this->loaded = true;

        // Registered with the function before this ran: kept, built-ins don't replace them.
        $early = $this->templates;

        $this->register([
            'id'         => 'Lucid',
            'name'       => 'Lucid',
            'class'      => \MailOptin\Core\EmailCampaigns\Newsletter\Templates\Lucid::class,
            'screenshot' => MAILOPTIN_ASSETS_URL . 'images/email-templates/lucid.png',
        ]);

        $this->templates = array_merge($this->templates, $early);

        do_action('mailoptin_email_templates', $this);
    }

    /**
     * On mailoptin_register_template_class.
     */
    public function template_class($class, $email_campaign_id, $template_id)
    {
        $template_id = self::$preview[(int)$email_campaign_id] ?? $template_id;
        $template    = is_string($template_id) ? $this->get($template_id) : null;

        if ( ! $template) return $class;

        $type = ER::get_email_campaign_type($email_campaign_id);

        return in_array($type, $template['campaign_types'], true) ? $template['class'] : $class;
    }

    /**
     * On mailoptin_registered_email_templates: registered templates in the list a new broadcast's
     * template is chosen from (Lucid is there already).
     */
    public function list_for_new_broadcasts($list)
    {
        $listed = [];
        foreach ((array)$list as $item) $listed[($item['template_class'] ?? '') . '|' . ($item['campaign_type'] ?? '')] = true;

        foreach ($this->all() as $template) {
            if ( ! in_array(ER::NEWSLETTER, $template['campaign_types'], true) || isset($listed[$template['id'] . '|' . ER::NEWSLETTER])) continue;

            $list[] = [
                'name'           => $template['name'],
                'template_class' => $template['id'],
                'campaign_type'  => ER::NEWSLETTER,
                'screenshot'     => $template['screenshot'],
            ];
        }

        return $list;
    }

    /**
     * While previewing, draw an email in another template (not saved).
     */
    public static function set_preview($email_campaign_id, $template_id)
    {
        self::$preview[(int)$email_campaign_id] = (string)$template_id;
    }

    public static function clear_preview()
    {
        self::$preview = [];
    }

    /**
     * Use another template for an email.
     */
    public static function set_for_email($email_campaign_id, $template_id)
    {
        global $wpdb;

        return false !== $wpdb->update(ER::email_campaigns_table(), ['template_class' => (string)$template_id], ['id' => absint($email_campaign_id)], ['%s'], ['%d']);
    }

    /**
     * @return Templates
     */
    public static function get_instance()
    {
        static $instance = null;

        if (null === $instance) $instance = new self();

        return $instance;
    }
}
