<?php

use MailOptin\Core\Admin\SettingsPage\Flows\AddEditFlow;
use MailOptin\Core\Repositories\FlowsRepository;

$triggers              = AddEditFlow::registered_triggers();
$registered_categories = AddEditFlow::registered_categories();

$saved_title = '';
// $flow_id is from function that calls this template.
if (isset($flow_id)) {
    $db_data      = FlowsRepository::get_flow_by_id($flow_id);
    $flow_title   = $db_data['title'];
    $trigger_name = $db_data['trigger_name'];
}
?>
<div id="postbox-container-2" class="postbox-container">
    <div id="titlediv" style="margin-bottom: 20px;">
        <div id="titlewrap">
            <input type="text" name="flow_title" value="<?= $flow_title ?>" id="title" spellcheck="true" placeholder="<?= esc_html__('Add title', 'mailoptin') ?>">
        </div>
    </div>
    <?php wp_nonce_field('mo_save_automate_flows', 'security'); ?>
    <div id="normal-sortables" class="">
        <div id="mo-flow-trigger-meta-box" class="postbox  automatewoo-metabox no-drag">
            <button type="button" class="handlediv" aria-expanded="true">
                <span class="toggle-indicator" aria-hidden="true"></span>
            </button>

            <h2 class="hndle"><span><?= esc_html__('Trigger', 'mailoptin'); ?></span></h2>

            <div class="inside">
                <table class="automatewoo-table">
                    <tbody>
                    <tr class="automatewoo-table__row" id="mo-flow-trigger-select-row">
                        <td class="automatewoo-table__col automatewoo-table__col--label">
                            <label for="mo-flow-trigger"><?= esc_html__('Trigger', 'mailoptin') ?>
                                <span class="required">*</span></label>
                        </td>
                        <td class="automatewoo-table__col automatewoo-table__col--field">
                            <select id="mo-flow-trigger" name="mo_flow_data[trigger_name]" class="automatewoo-field js-trigger-select">

                                <option value=""><?= esc_html__('Select...', 'mailoptin'); ?></option>

                                <?php if (is_array($triggers) && ! empty($triggers)) : ?>

                                    <?php foreach ($registered_categories as $categoryKey => $catLabel) : ?>

                                        <?php $category_trigger = $this->get_triggers_by_category($categoryKey); ?>

                                        <?php if ( ! empty($category_trigger)) : ?>
                                            <optgroup label="<?= $catLabel ?>">
                                                <?php foreach ($category_trigger as $trigger) : ?>
                                                    <option value="<?= $trigger['id'] ?>" <?= selected($trigger_name, $trigger['id'], false); ?>><?= $trigger['title'] ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>

                                        <?php endif; ?>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </select>
                            <div class="js-trigger-description">
                                <p id="mo-flow-trigger-description" class="aw-field-description"></p>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div id="mo-flow-rule-meta-box" class="postbox automatewoo-metabox no-drag">
            <button type="button" class="handlediv" aria-expanded="true">
                <span class="toggle-indicator" aria-hidden="true"></span>
            </button>
            <h2 class="hndle">
                <span><?= sprintf(esc_html__('Rules %s(optional)%s', 'mailoptin'), '<small>', '</small>'); ?></span>
            </h2>
            <div class="inside">
                <div id="aw-rules-container">

                    <div class="aw-rules-container">
                        <div class="aw-rule-groups">
                            <!-- rules go here -->
                        </div>
                    </div>

                    <div class="automatewoo-metabox-footer">
                        <button id="mo-flows-rule-add-btn" type="button" class="button button-primary button-large"><?= esc_html__('+ Add Rule Group', 'mailoptin'); ?></button>
                    </div>

                </div>

            </div>
        </div>
        <div id="mo-flow-action-meta-box" class="postbox  automatewoo-metabox no-drag">
            <button type="button" class="handlediv" aria-expanded="true">
                <span class="toggle-indicator" aria-hidden="true"></span>
            </button>
            <h2 class="hndle">
                <span><?= esc_html__('Actions', 'mailoptin'); ?></span>
            </h2>
            <div class="inside">

                <div class="aw-actions-container">
                    <!-- actions goes here-->
                </div>

                <div class="automatewoo-metabox-footer">
                    <a href="#" id="mo-flows-add-action" class="button button-primary button-large"><?= esc_html__('+ Add Action', 'mailoptin'); ?></a>
                </div>
            </div>
        </div>
    </div>
</div>
