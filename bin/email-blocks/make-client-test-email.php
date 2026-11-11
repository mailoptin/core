<?php
/**
 * Makes a draft broadcast that uses every email block, both column modes and the posts layouts, to
 * send to real email clients (Outlook desktop and Outlook.com, Gmail web and app, Apple Mail) or
 * through Litmus / Email on Acid before a release.
 *
 *     wp eval-file bin/email-blocks/make-client-test-email.php
 *
 * Then open it (the URL is printed), "Send a test" to each client, and compare with the editor's
 * preview. Nothing is sent by this script.
 */

use MailOptin\Core\Admin\EmailEditor\EmailEditor;
use MailOptin\Core\EmailBlocks\Blocks;
use MailOptin\Core\Repositories\EmailCampaignRepository as ER;

if ( ! defined('ABSPATH')) exit;

$id = EmailEditor::start('broadcast', 'Lucid', ['name' => 'Email client test ' . gmdate('Y-m-d H:i')]);

if (is_wp_error($id)) {
    echo $id->get_error_message() . "\n";

    return;
}

$image = includes_url('images/media/default.svg');
$posts = get_posts(['numberposts' => 3, 'fields' => 'ids', 'post_status' => 'publish']);
$text  = function ($id, $html) {
    return ['id' => $id, 'type' => 'text', 'settings' => ['text_content' => $html]];
};

$content = [
    $text('t-intro', '<h2>Every block</h2><p>Hi {{subscriber.first_name|there}}, this email has each block once, <strong>bold</strong>, <em>italic</em>, a <a href="https://mailoptin.io/">link</a>, and a list:</p><ul><li>One</li><li>Two</li></ul>'),
    ['id' => 'img-full', 'type' => 'image', 'settings' => ['image_url' => $image, 'image_alt_text' => 'A wide image', 'image_width' => '600', 'image_link' => 'https://mailoptin.io/']],
    ['id' => 'btn', 'type' => 'button', 'settings' => ['button_text' => 'A button', 'button_link' => 'https://mailoptin.io/', 'button_border_radius' => '6']],
    ['id' => 'div', 'type' => 'divider', 'settings' => []],
    ['id' => 'spc', 'type' => 'spacer', 'settings' => ['spacer_height' => '24']],
    $text('t-cols', '<h3>Columns that stack on phones</h3>'),
    ['id' => 'cols-it', 'type' => 'columns', 'settings' => ['columns_layout' => '33-67'], 'columns' => [
        [['id' => 'ci', 'type' => 'image', 'settings' => ['image_url' => $image, 'image_alt_text' => 'Narrow column image']]],
        [$text('ct', '<p>Text beside an image. On a phone it goes under it.</p>'), ['id' => 'cb', 'type' => 'button', 'settings' => ['button_text' => 'In a column', 'button_link' => 'https://mailoptin.io/']]],
    ]],
    $text('t-three', '<h3>Three columns, kept side by side on phones</h3>'),
    ['id' => 'cols-3', 'type' => 'columns', 'settings' => ['columns_layout' => '33-33-33', 'columns_stack' => false, 'columns_background' => '#f4f4f5'], 'columns' => [
        [$text('c1', '<p>One</p>')], [$text('c2', '<p>Two</p>')], [$text('c3', '<p>Three</p>')],
    ]],
];

if ($posts) {
    $content[] = $text('t-posts', '<h3>Posts, two to a row</h3>');
    $content[] = ['id' => 'posts', 'type' => 'posts', 'settings' => ['post_list' => $posts, 'post_column_count' => '2', 'read_more_text' => 'Read more']];
}

$content[] = $text('t-end', '<p>End of the test. The footer below has the unsubscribe link.</p>');

// Each block saved with its defaults, as the editor does.
foreach ($content as $i => $block) {
    $b = Blocks::get_instance()->get($block['type']);
    if ($b) $content[$i]['settings'] = array_replace($b->defaults($id), $block['settings']);
}

ER::update_settings_by_id($id, [
    'email_campaign_title'     => 'Email client test: every block',
    'email_newsletter_content' => wp_json_encode(Blocks::get_instance()->sanitize_content($content)),
]);

echo 'Draft broadcast ' . $id . ': ' . EmailEditor::url($id) . "\n";
