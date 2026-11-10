<?php

namespace MailOptin\Core\EmailBlocks;

use MailOptin\Core\Admin\Customizer\EmailCampaign\EmailCampaignFactory;

/**
 * The email builder's blocks, and the one place an email's blocks become HTML: for the preview, a
 * test and the real send alike.
 */
class Blocks
{
    /** @var AbstractBlock[] */
    protected $blocks = [];

    protected $loaded = false;

    public function add(AbstractBlock $block)
    {
        $id = sanitize_key($block->id());

        if ('' === $id) return false;

        $this->blocks[$id] = $block;

        return true;
    }

    public function get($id)
    {
        $this->load();

        return $this->blocks[$id] ?? null;
    }

    /**
     * @return AbstractBlock[] id => block
     */
    public function all()
    {
        $this->load();

        return $this->blocks;
    }

    /**
     * @return AbstractBlock[] the blocks offered in this email
     */
    public function available_for($email_campaign_id)
    {
        return array_filter($this->all(), function (AbstractBlock $block) use ($email_campaign_id) {
            return $block->available_for($email_campaign_id);
        });
    }

    protected function load()
    {
        if ($this->loaded) return;

        $this->loaded = true;

        foreach ([new Builtin\Text(), new Builtin\Image(), new Builtin\Button(), new Builtin\Divider(), new Builtin\Spacer(), new Builtin\Posts()] as $block) {
            $this->add($block);
        }

        do_action('mailoptin_email_blocks', $this);
    }

    /**
     * An email's content (its saved list of blocks) as HTML rows.
     *
     * @param array $elements [['id' => ..., 'type' => ..., 'settings' => [...]], ...]
     * @param int $email_campaign_id
     * @param array $context what the email is about, for blocks that show it
     * @param object|null $template the template, when the caller already has it
     *
     * @return string
     */
    public function render($elements, $email_campaign_id, $context = [], $template = null)
    {
        $template = $template ?: EmailCampaignFactory::make($email_campaign_id);
        $style    = new Style($email_campaign_id, is_object($template) ? $template : null);
        $html     = '';

        foreach ((array)$elements as $element) {
            $html .= $this->render_one($element, $email_campaign_id, $context, $style);
        }

        return $html;
    }

    /**
     * One block's HTML, e.g. to update it alone in the preview.
     */
    public function render_one($element, $email_campaign_id, $context, Style $style)
    {
        if ( ! is_array($element) || empty($element['type'])) return '';

        $type  = sanitize_key($element['type']);
        $id    = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($element['id'] ?? ''));
        $block = $this->get($type);

        $settings = (array)($element['settings'] ?? []);
        if ($block) $settings = array_replace($block->defaults($email_campaign_id), $settings);

        // A template may draw a block its own way.
        $method = $type . '_block';
        if ($style->template && method_exists($style->template, $method)) {
            return (string)$style->template->$method($id, $settings);
        }

        return $block ? (string)$block->render($settings, (array)$context, $style, $id) : '';
    }

    /**
     * An email's content as saved by an editor: blocks that exist, each with clean settings and an id.
     *
     * @return array
     */
    public function sanitize_content($elements)
    {
        $clean = [];

        foreach ((array)$elements as $element) {
            if ( ! is_array($element)) continue;

            $block = $this->get(sanitize_key($element['type'] ?? ''));

            if ( ! $block) continue;

            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($element['id'] ?? ''));

            $clean[] = [
                'id'       => '' !== $id ? $id : wp_generate_password(10, false),
                'type'     => $block->id(),
                'settings' => $block->sanitize($element['settings'] ?? []),
            ];
        }

        return $clean;
    }

    /**
     * @return Blocks
     */
    public static function get_instance()
    {
        static $instance = null;

        if (null === $instance) $instance = new self();

        return $instance;
    }
}
