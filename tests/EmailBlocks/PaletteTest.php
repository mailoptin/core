<?php

namespace MailOptin\Tests\Core\EmailBlocks;

use MailOptin\Core\EmailBlocks\AbstractBlock;
use MailOptin\Core\EmailBlocks\Blocks;
use MailOptin\Core\EmailBlocks\Icons;
use MailOptin\Core\EmailBlocks\Style;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/** A third-party block that says nothing about its group or icon. */
class PlainBlock extends AbstractBlock
{
    public function id() { return 'plain'; }
    public function title() { return 'Plain'; }
    public function fields() { return []; }
    public function render($settings, $context, Style $style, $id) { return ''; }
}

/** A third-party block in a group of its own, with a Dashicons icon. */
class SocialBlock extends PlainBlock
{
    public function id() { return 'social'; }
    public function title() { return 'Social links'; }
    public function group() { return 'social'; }
    public function palette_icon() { return 'dashicons-share'; }
}

/**
 * The editor's "Add a block" palette: blocks grouped (basics, layout, content, shop, more) with icons.
 */
class PaletteTest extends \WP_UnitTestCase
{
    protected $email;

    public function set_up()
    {
        parent::set_up();

        \MailOptin\Core\RegisterActivation\CreateDBTables::make();
        \MailOptin\Core\DBUpdates::get_instance()->update_routine_7();
        \MailOptin\Core\Base::get_instance()->register_metadata_table();

        $this->email = ER::add_email_campaign('Palette ' . uniqid(), ER::NEWSLETTER, 'Lucid');

        add_action('mailoptin_email_blocks', function (Blocks $blocks) {
            $blocks->add(new PlainBlock());
            $blocks->add(new SocialBlock());
        });
    }

    protected function ids($group)
    {
        return array_keys($group['blocks']);
    }

    public function test_blocks_are_grouped_in_order_and_others_go_under_more()
    {
        $palette = (new Blocks())->palette_for($this->email);

        $this->assertSame(array_values(array_intersect(array_keys(Blocks::groups()), array_keys($palette))), array_keys($palette), 'Groups in their order.');
        $this->assertSame(['basics', 'layout', 'content'], array_slice(array_keys($palette), 0, 3));
        $this->assertSame('more', array_key_last($palette));
        $this->assertSame(['text', 'image', 'button', 'divider', 'spacer'], $this->ids($palette['basics']));
        $this->assertSame(['columns'], $this->ids($palette['layout']));
        $this->assertSame(['posts'], $this->ids($palette['content']));
        $this->assertSame(['plain', 'social'], $this->ids($palette['more']), 'No group, or one the palette does not know: More, last.');
        $this->assertSame('More', $palette['more']['label']);
    }

    public function test_other_plugins_can_add_a_group()
    {
        add_filter('mailoptin_email_block_groups', function ($groups) { return $groups + ['social' => 'Social']; });

        $palette = (new Blocks())->palette_for($this->email);

        $this->assertSame(['social'], $this->ids($palette['social']));
        $this->assertSame(['plain'], $this->ids($palette['more']));
        $this->assertSame('more', array_key_last($palette), 'More stays last.');
    }

    public function test_empty_groups_are_left_out()
    {
        add_filter('mailoptin_email_block_groups', function ($groups) { return $groups + ['empty' => 'Nothing here']; });

        $this->assertArrayNotHasKey('empty', (new Blocks())->palette_for($this->email));
    }

    public function test_icons()
    {
        $this->assertStringContainsString('d="' . Icons::PATHS['text'] . '"', Icons::html('text'));
        $this->assertStringContainsString('dashicons dashicons-share', Icons::html('dashicons-share'));
        $this->assertStringContainsString('d="M3 3h18"', Icons::html('M3 3h18'), 'Path data of its own.');

        // Anything else is the generic icon: no markup gets through.
        foreach (['', 'nope', '"><script>alert(1)</script>', 'M3 3h18" onload="x', 'dashicons-x" onclick="y'] as $odd) {
            $html = Icons::html($odd);
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('onload', $html);
            $this->assertStringNotContainsString('onclick', $html);
        }
        $this->assertStringContainsString('d="' . Icons::PATHS['block'] . '"', Icons::html('nope'));
        $this->assertStringContainsString('d="' . Icons::PATHS['block'] . '"', Icons::html('dashicons-x" onclick="y'), 'Not a Dashicons name: the generic icon.');
        $this->assertStringContainsString('aria-hidden="true"', Icons::html('text'));
    }

    public function test_every_built_in_block_has_a_known_group_and_icon()
    {
        foreach ((new Blocks())->all() as $id => $block) {
            if (in_array($id, ['plain', 'social'], true)) continue;

            $this->assertArrayHasKey($block->group(), Blocks::groups(), $id);
            $this->assertArrayHasKey($block->palette_icon(), Icons::PATHS, $id);
        }
    }
}
