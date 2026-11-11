<?php

namespace MailOptin\Core\EmailBlocks;

use MailOptin\Core\Admin\Customizer\EmailCampaign\EmailCampaignFactory;

/**
 * Parts of an email that differ for each person (their cart, their order, their discount code).
 * An automation email is drawn once, with its placeholders left in; such a block draws a marker
 * there instead, holding its settings, and the marker is drawn for each person as their email is
 * filled in (sent, a test, or the editor's preview with sample values).
 */
class Dynamic
{
    const PATTERN = '/<!--mo-dynamic:([A-Za-z0-9_-]+)-->/';

    /**
     * The marker a block leaves in the email.
     *
     * @param string $type the block's id
     * @param array $data what it needs to draw itself later (its settings, the width it has...)
     */
    public static function marker($type, $data)
    {
        $json = wp_json_encode(['type' => $type] + (array)$data);

        // Base64url: nothing in it can end the comment.
        return '<!--mo-dynamic:' . rtrim(strtr(base64_encode($json), '+/', '-_'), '=') . '-->';
    }

    public static function has_markers($html)
    {
        return false !== strpos((string)$html, '<!--mo-dynamic:');
    }

    /**
     * Each marker drawn for one person.
     *
     * @param array $args trigger (AbstractTrigger), run (array), mode ("send", "test" or "sample"),
     *                    email_campaign_id
     */
    public static function expand($html, $args)
    {
        if ( ! self::has_markers($html)) return $html;

        return preg_replace_callback(self::PATTERN, function ($m) use ($args) {
            $data  = json_decode((string)base64_decode(strtr($m[1], '-_', '+/')), true);
            $block = is_array($data) ? Blocks::get_instance()->get((string)($data['type'] ?? '')) : null;

            if ( ! $block instanceof DynamicBlock) return '';

            try {
                return (string)$block->render_for($data, $args, self::style((int)($args['email_campaign_id'] ?? 0), (int)($data['width'] ?? 570)));
            } catch (\Throwable $e) {
                // One person's cart mustn't stop the queue.
                error_log('MailOptin email block ' . ($data['type'] ?? '') . ': ' . $e->getMessage());

                return '';
            }
        }, $html);
    }

    /**
     * The email's style (its template's fonts), at the width the block was drawn in.
     */
    protected static function style($email_campaign_id, $width)
    {
        static $templates = [];

        if ( ! array_key_exists($email_campaign_id, $templates)) {
            $templates[$email_campaign_id] = $email_campaign_id ? EmailCampaignFactory::make($email_campaign_id) : null;
        }

        $template = $templates[$email_campaign_id];

        return (new Style($email_campaign_id, is_object($template) ? $template : null))->with_width($width);
    }
}
