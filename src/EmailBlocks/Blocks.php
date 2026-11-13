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

    /**
     * The palette's groups, in order: id => label. "more" collects blocks in a group not listed here.
     */
    public static function groups()
    {
        $groups = (array)apply_filters('mailoptin_email_block_groups', [
            'basics'  => __('Basics', 'mailoptin'),
            'layout'  => __('Layout', 'mailoptin'),
            'content' => __('Content', 'mailoptin'),
            'shop'    => __('Shop', 'mailoptin'),
        ]);

        unset($groups['more']);

        return $groups + ['more' => __('More', 'mailoptin')];
    }

    /**
     * The blocks offered in this email, by palette group (empty groups left out).
     *
     * @return array group id => ['label' => string, 'blocks' => AbstractBlock[]]
     */
    public function palette_for($email_campaign_id)
    {
        $groups = [];
        foreach (self::groups() as $id => $label) $groups[$id] = ['label' => $label, 'blocks' => []];

        foreach ($this->available_for($email_campaign_id) as $id => $block) {
            $group = sanitize_key((string)$block->group());
            $groups[isset($groups[$group]) ? $group : 'more']['blocks'][$id] = $block;
        }

        return array_filter($groups, function ($group) { return ! empty($group['blocks']); });
    }

    protected function load()
    {
        if ($this->loaded) return;

        $this->loaded = true;

        foreach ([new Builtin\Text(), new Builtin\Image(), new Builtin\Button(), new Builtin\Divider(), new Builtin\Spacer(), new Builtin\Posts(), new Builtin\Columns(), new Builtin\CartContents(), new Builtin\ProductsBought(), new Builtin\DiscountCode(), new Builtin\Products()] as $block) {
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

        // Columns draw the blocks in each column first, to fit the column; a column never holds columns.
        if ($block instanceof Builtin\Columns) {
            $columns = [];
            $widths  = Builtin\Columns::pixel_widths($settings, $style->width);
            foreach (array_values((array)($element['columns'] ?? [])) as $i => $children) {
                $columns[$i] = '';
                if ( ! isset($widths[$i])) continue;
                foreach ((array)$children as $child) {
                    if (is_array($child) && 'columns' !== ($child['type'] ?? '')) {
                        $columns[$i] .= $this->render_one($child, $email_campaign_id, $context, $style->with_width($widths[$i]));
                    }
                }
            }

            return self::with_id($block->render_columns($settings, $columns, $id, $style->width), $id);
        }

        // A template may draw a block its own way.
        $method = $type . '_block';
        if ($style->template && method_exists($style->template, $method)) {
            return (string)$style->template->$method($id, $settings);
        }

        return $block ? self::with_id((string)$block->render($settings, (array)$context, $style, $id), $id) : '';
    }

    /**
     * The block's id on its element (the first "mo-email-builder-element"), for the Customizer's
     * preview: a click there opens the block's settings.
     */
    public static function with_id($html, $id)
    {
        if ('' === $id) return $html;

        return preg_replace('/class="([^"]*\bmo-email-builder-element)"/', 'class="$1" data-id="' . esc_attr($id) . '"', $html, 1);
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
     * @return Blocks
     */
    public static function get_instance()
    {
        static $instance = null;

        if (null === $instance) $instance = new self();

        return $instance;
    }
}
