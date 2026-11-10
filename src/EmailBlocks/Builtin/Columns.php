<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\EmailBlocks\AbstractBlock;
use MailOptin\Core\EmailBlocks\Fields;
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
        '33-33-33' => [33.33, 33.33, 33.34],
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

    /** The email body's width in the templates, in pixels. */
    const CONTENT_WIDTH = 570;

    /**
     * Each column's width in pixels, without the space between columns: the most an image in it
     * may be.
     *
     * @return int[]
     */
    public static function pixel_widths($settings)
    {
        $widths = self::widths($settings['columns_layout'] ?? '');
        $gap    = min(40, absint($settings['columns_gap'] ?? 20));
        $last   = count($widths) - 1;

        return array_map(function ($width, $i) use ($gap, $last) {
            $gaps = (0 === $i || $last === $i ? 0.5 : 1) * $gap;

            return max(40, (int)floor(self::CONTENT_WIDTH * $width / 100 - $gaps));
        }, $widths, array_keys($widths));
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
        return $this->render_columns($settings, [], $id);
    }

    /**
     * @param array $settings merged over the defaults
     * @param string[] $columns each column's rows (<tr>s), already drawn
     */
    public function render_columns($settings, $columns, $id)
    {
        $widths  = self::widths($settings['columns_layout'] ?? '');
        $gap     = min(40, absint($settings['columns_gap'] ?? 20));
        $half    = $gap / 2;
        $valign  = 'middle' === ($settings['columns_valign'] ?? '') ? 'middle' : 'top';
        $class   = empty($settings['columns_stack']) ? 'mo-cols' : 'mo-cols mo-cols-stack';
        $bg      = Fields::color($settings['columns_background'] ?? '');
        $bg      = '' !== $bg ? 'background-color:' . esc_attr($bg) . ';' : '';
        $padding = Fields::padding($settings['block_padding'] ?? []);
        $id      = esc_attr($id);
        $last    = count($widths) - 1;

        $cells = '';
        foreach ($widths as $i => $width) {
            $left  = 0 === $i ? 0 : $half;
            $right = $last === $i ? 0 : $half;
            $rows  = $columns[$i] ?? '';

            $cells .= sprintf(
                '<td class="mo-col" valign="%1$s" width="%2$s%%" style="width:%2$s%%;padding:0 %3$spx 0 %4$spx;vertical-align:%1$s;"><table width="100%%" border="0" cellpadding="0" cellspacing="0" role="presentation">%5$s</table></td>',
                $valign, $width, $right, $left, $rows
            );
        }

        return <<<HTML
<tr>
    <td class="mo-email-builder-element" id="$id" style="padding:$padding;{$bg}">
        <table class="$class" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"><tr>$cells</tr></table>
    </td>
</tr>
HTML;
    }

    /**
     * Styles every email with columns needs: stacking on phones.
     */
    public static function css()
    {
        return '@media only screen and (max-width: 600px) {'
               // A plain descendant selector: the inliner drops selectors that match nothing in the
               // source (it has no <tbody>), and browsers add a <tbody>, so child selectors miss.
               . ' table.mo-cols-stack td.mo-col { display:block !important; width:100% !important; padding:0 0 12px 0 !important; box-sizing:border-box; }'
               . ' }';
    }
}
