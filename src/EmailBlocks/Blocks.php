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

        foreach ([new Builtin\Text(), new Builtin\Image(), new Builtin\Button(), new Builtin\Divider(), new Builtin\Spacer(), new Builtin\Posts(), new Builtin\Columns()] as $block) {
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

        // Columns draw the blocks in each column first; a column never holds columns.
        if ($block instanceof Builtin\Columns) {
            $columns = [];
            foreach (array_values((array)($element['columns'] ?? [])) as $i => $children) {
                $columns[$i] = '';
                foreach ((array)$children as $child) {
                    if (is_array($child) && 'columns' !== ($child['type'] ?? '')) $columns[$i] .= $this->render_one($child, $email_campaign_id, $context, $style);
                }
            }

            return $block->render_columns($settings, $columns, $id);
        }

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
    public function sanitize_content($elements, $in_column = false)
    {
        $clean = [];

        foreach ((array)$elements as $element) {
            if ( ! is_array($element)) continue;

            $block = $this->get(sanitize_key($element['type'] ?? ''));

            if ( ! $block) continue;

            $is_columns = $block instanceof Builtin\Columns;

            // One level only: columns inside a column are dropped.
            if ($is_columns && $in_column) continue;

            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($element['id'] ?? ''));

            $item = [
                'id'       => '' !== $id ? $id : wp_generate_password(10, false),
                'type'     => $block->id(),
                'settings' => $block->sanitize($element['settings'] ?? []),
            ];

            if ($is_columns) $item['columns'] = $this->sanitize_columns($element['columns'] ?? [], $item['settings']['columns_layout'] ?? '');

            $clean[] = $item;
        }

        return $clean;
    }

    /**
     * As many columns as the layout has. Blocks in columns past that move to the last one, so
     * going from three columns to two loses nothing.
     */
    protected function sanitize_columns($columns, $layout)
    {
        $count   = count(Builtin\Columns::widths($layout));
        $columns = array_values(array_filter((array)$columns, 'is_array'));
        $clean   = array_fill(0, $count, []);

        foreach ($columns as $i => $children) {
            $target         = min($i, $count - 1);
            $clean[$target] = array_merge($clean[$target], $this->sanitize_content($children, true));
        }

        return $clean;
    }

    /**
     * Every block in the content, columns' blocks included, in order.
     */
    public static function flatten($elements)
    {
        $flat = [];

        foreach ((array)$elements as $element) {
            if ( ! is_array($element)) continue;
            $flat[] = $element;
            foreach ((array)($element['columns'] ?? []) as $children) {
                foreach ((array)$children as $child) if (is_array($child)) $flat[] = $child;
            }
        }

        return $flat;
    }

    /**
     * On mailoptin_email_template_css: what blocks need in the email's styles (columns stacking on
     * phones), only when the email has them.
     */
    public static function template_css($css, $email_campaign_id = 0)
    {
        $content = json_decode((string)\MailOptin\Core\Repositories\EmailCampaignRepository::get_customizer_value($email_campaign_id, 'email_newsletter_content'), true);

        foreach (self::flatten(is_array($content) ? $content : []) as $element) {
            if ('columns' === ($element['type'] ?? '')) return $css . "\n" . Builtin\Columns::css();
        }

        return $css;
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
