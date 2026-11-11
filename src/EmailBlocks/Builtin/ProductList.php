<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\Style;

/**
 * Products for each person, from their cart (Cart contents) or their order (Products bought): an
 * image, the name and quantity, the price, the total, and a button back to the cart or the order.
 */
abstract class ProductList extends ShopBlock
{
    abstract protected function button_default();

    public function fields()
    {
        return [
                'show_images' => ['type' => 'checkbox', 'label' => __('Product images', 'mailoptin'), 'default' => true, 'tab' => 'content'],
                'show_prices' => ['type' => 'checkbox', 'label' => __('Quantity and price', 'mailoptin'), 'default' => true, 'tab' => 'content'],
                'show_total'  => ['type' => 'checkbox', 'label' => __('Total', 'mailoptin'), 'default' => true, 'tab' => 'content'],
                'max_items'   => ['type' => 'range', 'label' => __('Show at most (products)', 'mailoptin'), 'min' => 1, 'max' => 20, 'step' => 1, 'default' => '5', 'tab' => 'content'],
            ] + $this->button_fields($this->button_default());
    }

    public function render_for($data, $args, Style $style)
    {
        $store    = $this->store($args);
        $settings = array_replace($this->defaults($style->email_campaign_id), (array)($data['settings'] ?? []));

        if ( ! $store) return '';

        $list = 'send' === ($args['mode'] ?? '') || ! empty($args['run']['id']) ? $this->items($store, $args['run']['context'] ?? []) : null;
        // Previews, and tests before anyone started the automation: sample products.
        if ( ! $list && 'send' !== ($args['mode'] ?? '')) $list = $store->sample($this->needs());
        if ( ! $list || empty($list['items'])) return '';

        $max   = max(1, min(20, absint($settings['max_items'] ?? 5)));
        $shown = array_slice($list['items'], 0, $max);
        $more  = count($list['items']) - count($shown);
        $rows  = '';
        // An image column only when a product shown has an image: the columns line up either way.
        $images = ! empty($settings['show_images']) && array_filter(array_column($shown, 'image'));

        foreach ($shown as $item) {
            $name  = esc_html($item['name']) . ( ! empty($settings['show_prices']) && $item['quantity'] > 1 ? ' <span style="color:#888888;">&times; ' . (int)$item['quantity'] . '</span>' : '');
            $name  = '' !== (string)$item['url'] ? '<a href="' . esc_url($item['url']) . '" style="color:inherit;text-decoration:none;">' . $name . '</a>' : $name;
            $image = ! $images ? '' : '<td width="64" style="width:64px;padding:8px 12px 8px 0;vertical-align:middle;">'
                . ('' !== (string)$item['image'] ? '<img src="' . esc_url($item['image']) . '" width="64" height="64" alt="" style="display:block;width:64px;height:64px;object-fit:cover;border:0;border-radius:4px;">' : '')
                . '</td>';
            $price = ! empty($settings['show_prices']) ? '<td align="right" style="padding:8px 0;vertical-align:middle;white-space:nowrap;font-weight:bold;">' . esc_html($store->money($item['total'], $list['currency'])) . '</td>' : '';

            $rows .= '<tr style="border-bottom:1px solid #eeeeee;">' . $image . '<td style="padding:8px 0;vertical-align:middle;">' . $name . '</td>' . $price . '</tr>';
        }

        $cols = 1 + ($images ? 1 : 0) + ( ! empty($settings['show_prices']) ? 1 : 0);

        if ($more > 0) {
            /* translators: %d: number of products not shown */
            $rows .= '<tr><td colspan="' . $cols . '" style="padding:8px 0;color:#888888;">' . esc_html(sprintf(_n('and %d more', 'and %d more', $more, 'mailoptin'), $more)) . '</td></tr>';
        }

        if ( ! empty($settings['show_total'])) {
            $rows .= '<tr><td colspan="' . ($cols - 1 ?: 1) . '" style="padding:10px 0 0;">' . esc_html__('Total', 'mailoptin') . '</td>'
                     . ($cols > 1 ? '<td align="right" style="padding:10px 0 0;font-weight:bold;white-space:nowrap;">' . esc_html($store->money($list['total'], $list['currency'])) . '</td>' : '') . '</tr>';
        }

        $table = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">' . $rows . '</table>';
        $pad   = \MailOptin\Core\EmailBlocks\Mjml::padding($settings['block_padding'] ?? []);

        return $this->text_row($style, $settings, $table, 15, $pad['pad_t'], 4) . $this->button_row($style, $settings, $list['url']);
    }

    /** @return array|null items, total, currency, url */
    abstract protected function items($store, $context);
}
