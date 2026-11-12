<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Blocks;
use MailOptin\Core\EmailBlocks\Mjml;
use MailOptin\Core\EmailBlocks\Style;

/**
 * Products from the shop: chosen ones, a category, what's on sale, or the newest, one under another
 * or two or three side by side. Each shows its image, name and price (the regular price crossed out
 * when on sale) and a button to it. The same for everyone, so broadcasts can have it (Premium).
 */
class Products extends ShopBlock
{
    public function id()
    {
        return 'products';
    }

    public function title()
    {
        return __('Products', 'mailoptin');
    }

    public function description()
    {
        return __('Products from your shop, with prices and a button to each.', 'mailoptin');
    }

    protected function needs()
    {
        return '';
    }

    protected function for_broadcasts()
    {
        return true;
    }

    public function fields()
    {
        $store = self::broadcast_store();

        return [
                'product_source'   => ['type' => 'select', 'label' => __('Products', 'mailoptin'), 'choices' => [
                    'newest'   => __('The newest', 'mailoptin'),
                    'on_sale'  => __('On sale', 'mailoptin'),
                    'category' => __('From a category', 'mailoptin'),
                    'chosen'   => __('Ones I choose', 'mailoptin'),
                ], 'default' => 'newest', 'tab' => 'content'],
                'product_ids'      => ['type' => 'select', 'multiple' => true, 'label' => __('Choose products', 'mailoptin'), 'description' => __('For "Ones I choose", in this order.', 'mailoptin'), 'choices' => $store ? $store->product_choices() : [], 'default' => [], 'tab' => 'content'],
                'product_category' => ['type' => 'select', 'label' => __('Category', 'mailoptin'), 'description' => __('For "From a category".', 'mailoptin'), 'choices' => ['' => __('Choose…', 'mailoptin')] + ($store ? $store->product_categories() : []), 'default' => '', 'tab' => 'content'],
                'product_count'    => ['type' => 'range', 'label' => __('How many', 'mailoptin'), 'min' => 1, 'max' => 12, 'step' => 1, 'default' => '4', 'tab' => 'content'],
                'product_columns'  => ['type' => 'select', 'label' => __('Side by side', 'mailoptin'), 'choices' => ['1' => __('One (image on the left)', 'mailoptin'), '2' => __('Two', 'mailoptin'), '3' => __('Three', 'mailoptin')], 'default' => '2', 'tab' => 'content'],
                'show_price'       => ['type' => 'checkbox', 'label' => __('Prices', 'mailoptin'), 'default' => true, 'tab' => 'content'],
            ] + $this->button_fields(__('Shop now', 'mailoptin'));
    }

    /**
     * The chosen products as saved, whatever the list in the settings shows (it lists 300): ids the
     * shop no longer has are dropped when the products are fetched.
     */
    public function sanitize($settings)
    {
        $clean                = parent::sanitize($settings);
        $clean['product_ids'] = array_values(array_unique(array_filter(array_map(function ($id) {
            return (string)absint($id);
        }, (array)($settings['product_ids'] ?? [])), function ($id) { return '0' !== $id; })));

        return $clean;
    }

    public function render_for($data, $args, Style $style)
    {
        $store    = $this->store($args);
        $settings = array_replace($this->defaults($style->email_campaign_id), (array)($data['settings'] ?? []));

        if ( ! $store) return '';

        $products = $store->products([
            'source'   => (string)$settings['product_source'],
            'ids'      => (array)$settings['product_ids'],
            'category' => (string)$settings['product_category'],
            'count'    => absint($settings['product_count']) ?: 4,
        ]);

        if ( ! $products) return '';

        // Each product needs room (about 160px): fewer side by side in a narrow column.
        $count  = min(3, max(1, absint($settings['product_columns']) ?: 2), max(1, intdiv($style->width + 20, 160)));
        $layout = [1 => '33-67', 2 => '50-50', 3 => '33-33-33'][$count];
        $pad    = Mjml::padding($settings['block_padding'] ?? []);
        $grid   = ['columns_layout' => $layout, 'columns_gap' => 20, 'columns_stack' => true, 'block_padding' => ['top' => $pad['pad_t'], 'right' => $pad['pad_r'], 'bottom' => $pad['pad_b'], 'left' => $pad['pad_l']]];
        $widths = Columns::pixel_widths($grid, $style->width);
        $html   = '';

        if (1 === $count) {
            foreach ($products as $product) {
                $html .= (new Columns())->render_columns($grid, [
                    $this->image_row($product, $widths[0]),
                    $this->text_rows($product, $settings, $style->with_width($widths[1]), 'left'),
                ], '', $style->width);
            }

            return $html;
        }

        foreach (array_chunk($products, $count) as $row) {
            $columns = [];
            foreach ($row as $i => $product) {
                $columns[] = $this->image_row($product, $widths[$i]) . $this->text_rows($product, $settings, $style->with_width($widths[$i]), 'center');
            }

            $html .= (new Columns())->render_columns($grid, $columns, '', $style->width);
        }

        return $html;
    }

    protected function image_row($product, $width)
    {
        if ('' === (string)$product['image']) return '';

        return Mjml::fill('image-link', [
            'pad_t' => 0, 'pad_r' => 0, 'pad_b' => 10, 'pad_l' => 0,
            'width' => max(1, (int)$width),
            'align' => 'center',
            'src'   => esc_url($product['image']),
            'alt'   => esc_attr($product['name']),
            'href'  => esc_url($product['url']),
        ]);
    }

    /** The name, the price and the button, in the column's width. */
    protected function text_rows($product, $settings, Style $style, $align)
    {
        $font  = esc_attr($style->font_stack($style->get('content_font_family', 'Arial')));
        $color = esc_attr(\MailOptin\Core\EmailBlocks\Fields::color($style->get('content_headline_color')) ?: '#222222');
        $text  = function ($html, $size, $bottom) use ($font, $color, $align) {
            return Mjml::fill('text', [
                'pad_t' => 0, 'pad_r' => 0, 'pad_b' => $bottom, 'pad_l' => 0,
                'font_family' => $font, 'font_size' => $size, 'color' => $color,
                'content' => '<div style="text-align:' . $align . ';">' . $html . '</div>',
            ]);
        };

        $rows = $text('<a href="' . esc_url($product['url']) . '" style="color:' . $color . ';text-decoration:none;font-weight:700;">' . esc_html($product['name']) . '</a>', 16, 6);

        if ( ! empty($settings['show_price']) && '' !== (string)$product['price']) {
            // Prices are the shop's own formatted text (HTML-safe).
            $was   = '' !== (string)$product['regular'] ? ' <span style="font-weight:400;color:#7a8088;text-decoration:line-through;font-size:13px;">' . wp_kses_post($product['regular']) . '</span>' : '';
            $rows .= $text('<span style="font-weight:800;">' . wp_kses_post($product['price']) . '</span>' . $was, 16, 8);
        }

        $label  = trim((string)($settings['button_text'] ?? ''));
        $button = Blocks::get_instance()->get('button');

        if ('' !== $label && $button) {
            $rows .= $button->render(array_replace($button->defaults($style->email_campaign_id), [
                'button_text'             => $label,
                'button_link'             => (string)$product['url'],
                'button_background_color' => (string)($settings['button_background_color'] ?? ''),
                'button_color'            => (string)($settings['button_color'] ?? ''),
                'button_width'            => 'center' === $align ? 100 : 60,
                'button_alignment'        => $align,
                'button_font_size'        => 15,
                'button_padding'          => ['top' => 10, 'right' => 14, 'bottom' => 10, 'left' => 14],
                'block_padding'           => ['top' => 0, 'right' => 0, 'bottom' => 18, 'left' => 0],
            ]), [], $style, '');
        }

        return $rows;
    }
}
