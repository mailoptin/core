<?php

namespace MailOptin\Core\EmailBlocks\Builtin;

use MailOptin\Core\Automations\Stores\Store;
use MailOptin\Core\Automations\Stores\Stores;
use MailOptin\Core\Automations\Triggers;
use MailOptin\Core\EmailBlocks\AbstractBlock;
use MailOptin\Core\EmailBlocks\Blocks;
use MailOptin\Core\EmailBlocks\Dynamic;
use MailOptin\Core\EmailBlocks\DynamicBlock;
use MailOptin\Core\EmailBlocks\Fields;
use MailOptin\Core\EmailBlocks\Mjml;
use MailOptin\Core\EmailBlocks\Style;
use MailOptin\Core\Repositories\AutomationsRepository as Repo;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

/**
 * A block that shows something from the shop for each person: drawn per person (DynamicBlock),
 * offered in automation emails whose trigger comes from a running shop.
 */
abstract class ShopBlock extends AbstractBlock implements DynamicBlock
{
    /** What the trigger must provide ("cart", "order"), or '' for any shop trigger. */
    abstract protected function needs();

    /** The trigger of the automation that sends this email. */
    protected static function trigger($email_campaign_id)
    {
        if ( ! $email_campaign_id || ! ER::is_automation_email($email_campaign_id)) return null;

        $owner = Repo::email_owner($email_campaign_id);

        return $owner ? Triggers::get_instance()->get($owner[0]['trigger_id']) : null;
    }

    /** Whether the block can go in a broadcast too (one email for everyone: no person's cart). */
    protected function for_broadcasts()
    {
        return false;
    }

    /**
     * Shop blocks in broadcasts: Premium, with a shop running.
     */
    public static function broadcasts_available()
    {
        return (bool)apply_filters('mailoptin_broadcast_shop_blocks_available', defined('MAILOPTIN_DETACH_LIBSODIUM')) && null !== self::broadcast_store();
    }

    /** The shop a broadcast's shop blocks show: the first one running. */
    protected static function broadcast_store()
    {
        foreach (Stores::all() as $store) {
            if ($store->active()) return $store;
        }

        return null;
    }

    protected static function is_broadcast($email_campaign_id)
    {
        return $email_campaign_id && ER::NEWSLETTER === ER::get_email_campaign_type($email_campaign_id);
    }

    public function available_for($email_campaign_id)
    {
        if (self::is_broadcast($email_campaign_id)) return $this->for_broadcasts() && self::broadcasts_available();

        $trigger = self::trigger($email_campaign_id);

        if ( ! $trigger || ! Stores::for_trigger($trigger)) return false;

        return '' === $this->needs() || in_array($this->needs(), $trigger->provides(), true);
    }

    /**
     * The marker: the block's settings and the room it has, drawn for each person later.
     */
    public function render($settings, $context, Style $style, $id)
    {
        // A broadcast is one email for everyone: drawn now, from the shop.
        if (self::is_broadcast($style->email_campaign_id)) {
            if ( ! $this->for_broadcasts() || ! self::broadcasts_available()) return '';

            return (string)$this->render_for(['id' => $id, 'settings' => $this->sanitize($settings)], ['mode' => 'broadcast', 'email_campaign_id' => $style->email_campaign_id], $style);
        }

        return Dynamic::marker($this->id(), ['id' => $id, 'width' => $style->width, 'settings' => $this->sanitize($settings)]);
    }

    /** @return Store|null */
    protected function store($args)
    {
        if ('broadcast' === ($args['mode'] ?? '')) return self::broadcast_store();

        return Stores::for_trigger($args['trigger'] ?? null);
    }

    /** Text in the email's font and text colour (a whole <tr>). */
    protected function text_row($style, $settings, $content, $size = 15, $top = 0, $bottom = 8)
    {
        $pad = Mjml::padding($settings['block_padding'] ?? []);

        return Mjml::fill('post-text', [
            'pad_t'       => $top, 'pad_b' => $bottom, 'pad_r' => $pad['pad_r'], 'pad_l' => $pad['pad_l'],
            'font_family' => esc_attr($style->font_stack($style->get('content_font_family', 'Arial'))),
            'font_size'   => $size,
            'line_height' => (int)round($size * 1.5),
            'color'       => esc_attr(Fields::color($style->get('content_text_color')) ?: '#3c434a'),
            'content'     => $content,
        ]);
    }

    /** A button (the Button block's MJML), when it has text and a link. */
    protected function button_row($style, $settings, $url)
    {
        $text = trim((string)($settings['button_text'] ?? ''));
        $pad  = Mjml::padding($settings['block_padding'] ?? []);

        if ('' === $text || '' === (string)$url) return '';

        $button = Blocks::get_instance()->get('button');

        return $button ? $button->render(array_replace($button->defaults($style->email_campaign_id), [
            'button_text'             => $text,
            'button_link'             => (string)$url,
            'button_background_color' => (string)($settings['button_background_color'] ?? ''),
            'button_color'            => (string)($settings['button_color'] ?? ''),
            'button_width'            => 60,
            'button_alignment'        => 'center',
            'block_padding'           => ['top' => 8, 'right' => $pad['pad_r'], 'bottom' => $pad['pad_b'], 'left' => $pad['pad_l']],
        ]), [], $style, '') : '';
    }

    /** Fields every shop block has: its button and its padding. */
    protected function button_fields($text)
    {
        return [
            'button_text'             => ['type' => 'text', 'label' => __('Button text', 'mailoptin'), 'description' => __('Empty: no button.', 'mailoptin'), 'default' => $text, 'tab' => 'content'],
            'button_background_color' => ['type' => 'color_picker', 'label' => __('Button colour', 'mailoptin'), 'default' => '#dc4d2f', 'tab' => 'style'],
            'button_color'            => ['type' => 'color_picker', 'label' => __('Button text colour', 'mailoptin'), 'default' => '#ffffff', 'tab' => 'style'],
            'block_padding'           => ['type' => 'dimension', 'label' => __('Padding', 'mailoptin'), 'default' => ['top' => '10', 'right' => '25', 'bottom' => '10', 'left' => '25'], 'tab' => 'style'],
        ];
    }

    public function tabs()
    {
        return ['content' => __('Content', 'mailoptin'), 'style' => __('Style', 'mailoptin')];
    }
}
