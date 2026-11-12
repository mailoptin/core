<?php
/**
 * The template picker: one form for every kind of email. Variables: $context (EmailContext),
 * $args (hidden fields for the context), $fields (extra HTML before the templates, escaped by the
 * caller), $submit (button label), $compact (true in a dialog).
 */

use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

if ( ! defined('ABSPATH')) exit;

$templates = $context->templates();
$first     = $templates ? array_key_first($templates) : ER::CODE_YOUR_OWN_TEMPLATE;
$starters  = \MailOptin\Core\EmailBlocks\Starters::get_instance()->for_context($context->id());
// Broadcasts start from the newsletter layout; other emails from the template's short default.
$start     = isset($starters['newsletter']) && 'broadcast' === $context->id() ? 'newsletter' : '';
?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mo-ee-picker<?php echo $compact ? ' is-compact' : ''; ?>">
    <input type="hidden" name="action" value="mo_email_new">
    <input type="hidden" name="context" value="<?php echo esc_attr($context->id()); ?>">
    <?php wp_nonce_field('mo_email_new_' . $context->id()); ?>
    <?php foreach ($args as $key => $value) : ?>
        <input type="hidden" name="args[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>">
    <?php endforeach; ?>

    <?php echo $fields; // Escaped by the caller. ?>

    <fieldset class="mo-ee-picker-grid">
        <legend class="mo-ee-picker-legend"><?php esc_html_e('Template', 'mailoptin'); ?></legend>
        <?php foreach ($templates as $template) : ?>
            <label class="mo-ee-picker-card">
                <span class="mo-ee-picker-shot">
                    <?php if ('' !== $template['screenshot']) : ?><img src="<?php echo esc_url($template['screenshot']); ?>" alt="" loading="lazy"><?php endif; ?>
                </span>
                <span class="mo-ee-picker-name">
                    <input type="radio" name="template" value="<?php echo esc_attr($template['id']); ?>" <?php checked($template['id'], $first); ?>>
                    <b><?php echo esc_html($template['name']); ?></b>
                </span>
                <?php if ('' !== $template['description']) : ?><span class="mo-ee-picker-desc"><?php echo esc_html($template['description']); ?></span><?php endif; ?>
            </label>
        <?php endforeach; ?>
        <?php if ($context->allows_code()) : ?>
            <label class="mo-ee-picker-card">
                <span class="mo-ee-picker-shot is-code"><span class="dashicons dashicons-editor-code" aria-hidden="true"></span><?php esc_html_e('Write or paste your own HTML', 'mailoptin'); ?></span>
                <span class="mo-ee-picker-name">
                    <input type="radio" name="template" value="<?php echo esc_attr(ER::CODE_YOUR_OWN_TEMPLATE); ?>" <?php checked(ER::CODE_YOUR_OWN_TEMPLATE, $first); ?>>
                    <b><?php esc_html_e('Code your own', 'mailoptin'); ?></b>
                </span>
            </label>
        <?php endif; ?>
    </fieldset>

    <?php if ($starters) : ?>
    <fieldset class="mo-ee-picker-grid mo-ee-starters" data-context="<?php echo esc_attr($context->id()); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('mo_email_starter_previews')); ?>">
        <legend class="mo-ee-picker-legend"><?php esc_html_e('Start from', 'mailoptin'); ?></legend>
        <p class="description mo-ee-starters-note"><?php esc_html_e('Ready-made content in the template you chose. Edit or delete any part; text in [brackets] is for you to replace.', 'mailoptin'); ?></p>
        <label class="mo-ee-picker-card">
            <span class="mo-ee-picker-shot mo-ee-starter-shot" data-starter=""></span>
            <span class="mo-ee-picker-name">
                <input type="radio" name="starter" value="" <?php checked('', $start); ?>>
                <b><?php esc_html_e('Simple message', 'mailoptin'); ?></b>
            </span>
            <span class="mo-ee-picker-desc"><?php esc_html_e('A greeting, a button and a sign-off.', 'mailoptin'); ?></span>
        </label>
        <?php foreach ($starters as $starter) : ?>
            <label class="mo-ee-picker-card">
                <span class="mo-ee-picker-shot mo-ee-starter-shot" data-starter="<?php echo esc_attr($starter['id']); ?>"></span>
                <span class="mo-ee-picker-name">
                    <input type="radio" name="starter" value="<?php echo esc_attr($starter['id']); ?>" <?php checked($starter['id'], $start); ?>>
                    <b><?php echo esc_html($starter['name']); ?></b>
                </span>
                <?php if ('' !== $starter['description']) : ?><span class="mo-ee-picker-desc"><?php echo esc_html($starter['description']); ?></span><?php endif; ?>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <?php endif; ?>

    <p class="mo-ee-picker-actions">
        <button type="submit" class="button button-primary"><?php echo esc_html($submit); ?></button>
        <span class="description"><?php esc_html_e('You can switch templates later in the editor\'s Design tab.', 'mailoptin'); ?></span>
    </p>
</form>
