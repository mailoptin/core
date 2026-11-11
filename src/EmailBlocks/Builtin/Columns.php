<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\AbstractBlock;
use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Mjml;
use MailOptin\Core\EmailBlocks\Style;

/**
 * Two or three columns side by side, each holding its own blocks. Saved as
 * {id, type: "columns", settings, columns: [[blocks], [blocks], ...]}. Columns don't go inside
 * columns. On phones they stack, unless turned off.
 */
class Columns extends AbstractBlock
{
    const LAYOUTS = [
        '50-50'    => [50, 50],
        '33-67'    => [33, 67],
        '67-33'    => [67, 33],
        '33-33-33' => [33.33, 33.33, 33.33],
    ];

    public function id()
    {
        return 'columns';
    }

    public function title()
    {
        return __('Columns', 'mailoptin');
    }

    public function icon()
    {
        return 'columns';
    }

    public function description()
    {
        return __('Put elements side by side.', 'mailoptin');
    }

    public function fields()
    {
        return [
            'columns_layout'     => [
                'type'    => 'select',
                'label'   => __('Layout', 'mailoptin'),
                'choices' => [
                    '50-50'    => __('Two equal columns', 'mailoptin'),
                    '33-67'    => __('Narrow, then wide', 'mailoptin'),
                    '67-33'    => __('Wide, then narrow', 'mailoptin'),
                    '33-33-33' => __('Three equal columns', 'mailoptin'),
                ],
                'default' => '50-50',
            ],
            'columns_gap'        => ['type' => 'range', 'label' => __('Space between columns (px)', 'mailoptin'), 'min' => 0, 'max' => 40, 'step' => 2, 'default' => '20'],
            'columns_valign'     => [
                'type'    => 'select',
                'label'   => __('Line up', 'mailoptin'),
                'choices' => ['top' => __('At the top', 'mailoptin'), 'middle' => __('In the middle', 'mailoptin')],
                'default' => 'top',
            ],
            'columns_stack'      => ['type' => 'checkbox', 'label' => __('Stack the columns on phones', 'mailoptin'), 'default' => true],
            'columns_background' => ['type' => 'color_picker', 'label' => __('Background colour', 'mailoptin'), 'default' => ''],
            'block_padding'      => ['type' => 'dimension', 'label' => __('Padding', 'mailoptin'), 'default' => ['top' => '0', 'bottom' => '0', 'right' => '0', 'left' => '0']],
        ];
    }

    /**
     * The space each column gives its blocks, in pixels: its share of the section (less the
     * section's padding), less the space between columns. Blocks are drawn to fit it.
     *
     * @return int[]
     */
    public static function pixel_widths($settings, $container = 570)
    {
        $padding = Mjml::padding($settings['block_padding'] ?? []);
        $inner   = $container - $padding['pad_l'] - $padding['pad_r'];
        $widths  = self::widths($settings['columns_layout'] ?? '');
        $half    = self::half_gap($settings);
        $last    = count($widths) - 1;

        return array_map(function ($width, $i) use ($inner, $half, $last) {
            $gaps = (0 === $i ? 0 : $half) + ($last === $i ? 0 : $half);

            return max(20, (int)floor($inner * $width / 100 - $gaps));
        }, $widths, array_keys($widths));
    }

    protected static function half_gap($settings)
    {
        return (int)floor(min(40, absint($settings['columns_gap'] ?? 20)) / 2);
    }

    /**
     * @return float[] each column's width in percent
     */
    public static function widths($layout)
    {
        return self::LAYOUTS[$layout] ?? self::LAYOUTS['50-50'];
    }

    public function render($settings, $context, Style $style, $id)
    {
        return $this->render_columns($settings, [], $id, $style->width);
    }

    /**
     * From MJML: a section of columns that sit side by side, and stack on phones unless turned off
     * (an mj-group). Outlook gets fixed-width cells.
     *
     * @param array $settings merged over the defaults
     * @param string[] $columns each column's rows (<tr>s), already drawn to fit pixel_widths()
     * @param int $container the width it is drawn in
     */
    public function render_columns($settings, $columns, $id, $container = 570)
    {
        $layout  = isset(self::LAYOUTS[$settings['columns_layout'] ?? '']) ? $settings['columns_layout'] : '50-50';
        $valign  = 'middle' === ($settings['columns_valign'] ?? '') ? 'middle' : 'top';
        $padding = Mjml::padding($settings['block_padding'] ?? []);
        $inner   = $container - $padding['pad_l'] - $padding['pad_r'];
        $bg      = Fields::color($settings['columns_background'] ?? '');

        $values = $padding + [
            'container'   => $container,
            'inner_width' => $inner,
            'half_gap'    => self::half_gap($settings),
            'background'  => esc_attr('' !== $bg ? $bg : 'transparent'),
        ];

        // Each share's Outlook cell, named as the build names it ("w33_33"); in a group, whole pixels.
        foreach (self::widths($layout) as $i => $width) {
            $key                = str_replace('.', '_', Mjml::number($width));
            $values['w' . $key]  = Mjml::number($inner * $width / 100);
            $values['gw' . $key] = (int)floor($inner * $width / 100);
            $values['col' . $i]  = $columns[$i] ?? '';
        }

        $name = 'columns-' . $layout . '-' . $valign . (empty($settings['columns_stack']) ? '-nostack' : '');

        return '<tr><td style="padding:0;">' . Mjml::fill($name, $values) . '</td></tr>';
    }
}
