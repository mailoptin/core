<?php
/**
 * The email editor page. Variables: $email_campaign_id, $blocks (AbstractBlock[] offered here).
 */

use MailOptin\Core\Admin\EmailEditor\EmailEditor;

if ( ! defined('ABSPATH')) exit;

$sections = ['body' => __('Body', 'mailoptin'), 'header' => __('Header', 'mailoptin'), 'footer' => __('Footer', 'mailoptin')];
$context  = EmailEditor::context_for($email_campaign_id);
$next     = $context ? $context->next_step($email_campaign_id) : null;
$is_code  = EmailEditor::is_code($email_campaign_id);
?>
<div id="mo-ee" class="mo-ee">
    <header class="mo-ee-top">
        <a class="mo-ee-back" href="<?php echo esc_url(EmailEditor::back_url($email_campaign_id)); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php esc_html_e('Back', 'mailoptin'); ?></a>
        <div class="mo-ee-where">
            <span class="mo-ee-kicker"><?php echo esc_html($context ? $context->label($email_campaign_id) : __('Editing email', 'mailoptin')); ?></span>
            <strong id="mo-ee-subject"></strong>
        </div>
        <div class="mo-ee-devices" role="group" aria-label="<?php esc_attr_e('Preview size', 'mailoptin'); ?>">
            <button type="button" class="mo-ee-device is-on" data-device="desktop" aria-pressed="true"><span class="dashicons dashicons-desktop" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e('Desktop', 'mailoptin'); ?></span></button>
            <button type="button" class="mo-ee-device" data-device="mobile" aria-pressed="false"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e('Phone', 'mailoptin'); ?></span></button>
        </div>
        <span id="mo-ee-status" class="mo-ee-status" role="status" aria-live="polite"></span>
        <button type="button" class="button" id="mo-ee-undo" title="<?php esc_attr_e('Undo (Ctrl+Z)', 'mailoptin'); ?>"><span class="dashicons dashicons-undo" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e('Undo', 'mailoptin'); ?></span></button>
        <button type="button" class="button" id="mo-ee-redo" title="<?php esc_attr_e('Redo (Ctrl+Shift+Z)', 'mailoptin'); ?>"><span class="dashicons dashicons-redo" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e('Redo', 'mailoptin'); ?></span></button>
        <details class="mo-ee-checks" id="mo-ee-checks" hidden>
            <summary class="button"></summary>
            <div class="mo-ee-checks-pop">
                <strong><?php esc_html_e('Before it goes', 'mailoptin'); ?></strong>
                <ul class="mo-ee-checks-list"></ul>
                <p class="description"><?php esc_html_e('Warnings only: nothing here stops you saving or sending.', 'mailoptin'); ?></p>
            </div>
        </details>
        <details class="mo-ee-test">
            <summary class="button"><?php esc_html_e('Send a test', 'mailoptin'); ?></summary>
            <div class="mo-ee-test-pop">
                <label for="mo-ee-test-email"><?php esc_html_e('Send to', 'mailoptin'); ?></label>
                <input type="email" id="mo-ee-test-email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
                <button type="button" class="button button-primary" id="mo-ee-test-send"><?php esc_html_e('Save and send', 'mailoptin'); ?></button>
            </div>
        </details>
        <button type="button" class="button<?php echo $next ? '' : ' button-primary'; ?>" id="mo-ee-save"><?php esc_html_e('Save', 'mailoptin'); ?></button>
        <?php if ($next) : ?>
            <a class="button button-primary" id="mo-ee-next" href="<?php echo esc_url($next['url']); ?>"><?php echo esc_html($next['label']); ?> <span aria-hidden="true">&rarr;</span></a>
        <?php endif; ?>
    </header>

    <div class="mo-ee-body">
        <aside class="mo-ee-panel">
            <div class="mo-ee-tabs" role="tablist">
                <button type="button" role="tab" class="mo-ee-tab is-on" data-panel="content" aria-selected="true"><?php esc_html_e('Content', 'mailoptin'); ?></button>
                <?php if ( ! $is_code) : ?>
                    <button type="button" role="tab" class="mo-ee-tab" data-panel="design" aria-selected="false"><?php esc_html_e('Design', 'mailoptin'); ?></button>
                <?php endif; ?>
                <button type="button" role="tab" class="mo-ee-tab" data-panel="settings" aria-selected="false"><?php esc_html_e('Settings', 'mailoptin'); ?></button>
            </div>

            <section class="mo-ee-pane is-on" data-pane="content">
                <?php if ($is_code) : ?>
                    <label for="mo-ee-code" class="mo-ee-label"><?php esc_html_e('The email\'s HTML', 'mailoptin'); ?></label>
                    <p class="description"><?php esc_html_e('A whole email, written by hand. Keep an unsubscribe link:', 'mailoptin'); ?> <code>&lt;a href="[unsubscribe]"&gt;Unsubscribe&lt;/a&gt;</code></p>
                    <textarea id="mo-ee-code" class="mo-ee-code" rows="30" spellcheck="false"></textarea>
                <?php else : ?>
                <ol id="mo-ee-blocks" class="mo-ee-blocks"></ol>
                <p class="mo-ee-empty" id="mo-ee-empty"><?php esc_html_e('No elements yet. Add one below.', 'mailoptin'); ?></p>
                <h3 class="mo-ee-h"><?php esc_html_e('Add an element', 'mailoptin'); ?></h3>
                <div class="mo-ee-add">
                    <?php foreach ($blocks as $id => $block) : ?>
                        <button type="button" class="mo-ee-add-btn" data-type="<?php echo esc_attr($id); ?>" title="<?php echo esc_attr($block->description()); ?>"><?php echo esc_html($block->title()); ?></button>
                    <?php endforeach; ?>
                    <?php if (isset($blocks['columns'], $blocks['image'], $blocks['text'])) : ?>
                        <button type="button" class="mo-ee-add-btn" data-preset="image-text" title="<?php esc_attr_e('An image on the left, text on the right. Stacks on phones.', 'mailoptin'); ?>"><?php esc_html_e('Image + text', 'mailoptin'); ?></button>
                        <button type="button" class="mo-ee-add-btn" data-preset="text-image" title="<?php esc_attr_e('Text on the left, an image on the right. Stacks on phones.', 'mailoptin'); ?>"><?php esc_html_e('Text + image', 'mailoptin'); ?></button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </section>

            <section class="mo-ee-pane" data-pane="design">
                <details class="mo-ee-section" open>
                    <summary><?php esc_html_e('Template', 'mailoptin'); ?></summary>
                    <div class="mo-ee-templates" role="radiogroup" aria-label="<?php esc_attr_e('Template', 'mailoptin'); ?>">
                        <?php foreach ($templates as $template) : ?>
                            <label class="mo-ee-template">
                                <input type="radio" name="mo-ee-template" class="mo-ee-template-input" value="<?php echo esc_attr($template['id']); ?>">
                                <?php if ('' !== $template['screenshot']) : ?>
                                    <img src="<?php echo esc_url($template['screenshot']); ?>" alt="" loading="lazy">
                                <?php endif; ?>
                                <span><?php echo esc_html($template['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php esc_html_e('Your content and colours stay when you switch.', 'mailoptin'); ?></p>
                </details>
                <div data-group="design">
                <?php foreach ($sections as $section => $label) : ?>
                    <details class="mo-ee-section" <?php echo 'body' === $section ? 'open' : ''; ?>>
                        <summary><?php echo esc_html($label); ?></summary>
                        <?php foreach (EmailEditor::design_fields() as $name => $field) {
                            if (($field['section'] ?? 'body') === $section) echo EmailEditor::field_html($name, $field);
                        } ?>
                    </details>
                <?php endforeach; ?>
                </div>
            </section>

            <section class="mo-ee-pane" data-pane="settings" data-group="settings">
                <?php foreach (EmailEditor::settings_fields() as $name => $field) echo EmailEditor::field_html($name, $field); ?>
            </section>

            <section class="mo-ee-pane mo-ee-block-pane" data-pane="block">
                <div class="mo-ee-block-head">
                    <button type="button" class="button-link mo-ee-block-done"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php esc_html_e('All elements', 'mailoptin'); ?></button>
                    <strong class="mo-ee-block-title"></strong>
                </div>
                <div class="mo-ee-block-form"></div>
            </section>
        </aside>

        <main class="mo-ee-preview" data-device="desktop">
            <span class="mo-ee-updating" id="mo-ee-updating" hidden><?php esc_html_e('Updating…', 'mailoptin'); ?></span>
            <div class="mo-ee-frame-wrap"><iframe id="mo-ee-frame" title="<?php esc_attr_e('Email preview', 'mailoptin'); ?>" sandbox="allow-same-origin"></iframe></div>
        </main>
    </div>

    <?php foreach ($blocks as $id => $block) : ?>
        <script type="text/html" id="mo-ee-form-<?php echo esc_attr($id); ?>"><?php echo EmailEditor::block_form_html($block); // Escaped field by field. ?></script>
    <?php endforeach; ?>
</div>
