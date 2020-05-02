<?php

use MailOptin\Core\Admin\SettingsPage\Flows\Flows;
use MailOptin\Core\Repositories\FlowsRepository;

$triggers              = apply_filters('mo_automate_flows_triggers', []);
$registered_categories = Flows::registered_categories();

$saved_title = '';
if (isset($_GET['flowid'])) {
    $flow_id     = absint($_GET['flowid']);
    $saved_title = FlowsRepository::get_flow_title($flow_id);
}
?>
<div id="postbox-container-2" class="postbox-container">
    <div id="titlediv" style="margin-bottom: 20px;">
        <div id="titlewrap">
            <input type="text" name="flow_title" value="<?= $saved_title ?>" id="title" spellcheck="true" placeholder="<?= esc_html__('Add title', 'mailoptin') ?>">
        </div>
    </div>
    <?php wp_nonce_field('mo_save_automate_flows', 'security'); ?>
    <div id="normal-sortables" class="">
        <div id="aw_trigger_box" class="postbox  automatewoo-metabox no-drag">
            <button type="button" class="handlediv" aria-expanded="true">
                <span class="screen-reader-text"><?= esc_html__('Toggle panel: Trigger', 'mailoptin'); ?></span>
                <span class="toggle-indicator" aria-hidden="true"></span>
            </button>
            <h2 class="hndle"><span><?= esc_html__('Trigger', 'mailoptin'); ?></span></h2>

            <div class="inside">
                <table class="automatewoo-table">
                    <tbody>
                    <tr class="automatewoo-table__row" data-name="trigger_name" data-type="select" data-required="1">
                        <td class="automatewoo-table__col automatewoo-table__col--label">
                            <label for="mo-flow-trigger"><?= esc_html__('Trigger', 'mailoptin') ?>
                                <span class="required">*</span></label>
                        </td>
                        <td class="automatewoo-table__col automatewoo-table__col--field">
                            <select id="mo-flow-trigger" name="mo_flow_data[trigger_name]" class="automatewoo-field js-trigger-select">
                                <option value=""><?= esc_html__('Select...', 'mailoptin'); ?></option>
                                <?php if (is_array($triggers) && ! empty($triggers)) : ?>
                                    <?php foreach ($triggers as $categoryKey => $catTrigger) : ?>
                                        <optgroup label="<?= $registered_categories[$categoryKey]; ?>">
                                            <?php foreach ($catTrigger as $trigger) : ?>
                                                <option value="<?= $trigger['id'] ?>"><?= $trigger['title'] ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <div class="js-trigger-description">
                                <p class="aw-field-description">This trigger fires after an order is created in the database. At checkout this happens before payment is confirmed.</p>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div id="aw_rules_box" class="postbox  automatewoo-metabox no-drag">
            <button type="button" class="handlediv" aria-expanded="true"><span class="screen-reader-text">Toggle panel: Rules <small>(optional)</small></span><span class="toggle-indicator" aria-hidden="true"></span>
            </button>
            <h2 class="hndle">
                <span>Rules <small>(optional)</small></span><a href="#" class="automatewoo-help-link automatewoo-help-link--right" target="_blank"></a>
            </h2>
            <div class="inside">
                <div id="aw-rules-container">

                    <div class="aw-rules-container">
                        <div class="aw-rule-groups">
                            <p class="aw-rules-empty-message">Rules can be used to add conditional logic to workflows. Click the
                                <strong>+ Add Rule Group</strong> button to create a rule.</p>
                        </div>
                    </div>

                    <div class="automatewoo-metabox-footer">
                        <button type="button" class="js-add-rule-group button button-primary button-large">+ Add Rule Group</button>
                    </div>

                </div>

            </div>
        </div>
        <div id="aw_actions_box" class="postbox  automatewoo-metabox no-drag">
            <button type="button" class="handlediv" aria-expanded="true">
                <span class="screen-reader-text">Toggle panel: Actions</span><span class="toggle-indicator" aria-hidden="true"></span>
            </button>
            <h2 class="hndle">
                <span>Actions</span><a href="#" class="automatewoo-help-link automatewoo-help-link--right" target="_blank"></a>
            </h2>
            <div class="inside">

                <div class="aw-actions-container">


                    <div class="automatewoo-action js-open" data-action-number="1" data-automatewoo-action-name="send_email" data-automatewoo-action-group="email" data-automatewoo-action-can-be-previewed="true">

                        <div class="automatewoo-action__header">
                            <div class="row-options">
                                <a href="#" data-automatewoo-preview="">Preview</a>
                                <a class="js-edit-action" href="#">Edit</a>
                                <a class="js-delete-action" href="#">Delete</a>
                            </div>

                            <h4 class="action-title">Email - Send Email</h4>
                        </div>

                        <div class="automatewoo-action__fields" style="display: block;">
                            <table class="automatewoo-table">

                                <tbody>
                                <tr class="automatewoo-table__row" data-name="action_name" data-type="select" data-required="1">
                                    <td class="automatewoo-table__col automatewoo-table__col--label">
                                        <label>Action <span class="required">*</span></label>
                                    </td>
                                    <td class="automatewoo-table__col automatewoo-table__col--field">


                                        <select name="mo_flow_data[actions][1][action_name]" data-name="action_name" class="automatewoo-field automatewoo-field--type-select js-action-select">

                                            <option value="">[Select]</option>

                                            <optgroup label="Email">
                                                <option value="send_email" selected="selected">Send Email</option>
                                                <option value="send_email_plain">Send Email - Plain Text</option>
                                                <option value="send_email_raw">Send Email - Raw HTML [BETA]</option>
                                            </optgroup>
                                            <optgroup label="Customer">
                                                <option value="customer_change_role">Change Role</option>
                                                <option value="customer_update_meta">Update Custom Field</option>
                                                <option value="customer_add_tags">Add Tags</option>
                                                <option value="customer_remove_tags">Remove Tags</option>
                                            </optgroup>
                                            <optgroup label="Order">
                                                <option value="change_order_status">Change Status</option>
                                                <option value="update_order_meta">Update Custom Field</option>
                                                <option value="resend_order_email">Resend Order Email</option>
                                                <option value="trigger_order_action">Trigger Order Action</option>
                                                <option value="order_update_customer_shipping_note">Update Customer Provided Note</option>
                                                <option value="order_add_note">Add Note</option>
                                            </optgroup>
                                            <optgroup label="Order Item">
                                                <option value="order_item_update_meta" disabled="disabled">Update Custom Field</option>
                                            </optgroup>
                                        </select>


                                        <div class="js-action-description">
                                            <p class="aw-field-description">This action sends an HTML email using a template. The default template matches the style of your WooCommerce transactional emails.
                                                <a href="#" target="_blank">View email templates documentation</a>.
                                            </p></div>
                                    </td>
                                </tr>


                                <tr class="automatewoo-table__row" data-name="to" data-type="text" data-required="1 ">

                                    <td class="automatewoo-table__col automatewoo-table__col--label">


                                        <span class="automatewoo-help-tip automatewoo-help-tip--right woocommerce-help-tip"></span>
                                        <label>To <span class="required">*</span>
                                        </label>

                                    </td>

                                    <td class="automatewoo-table__col automatewoo-table__col--field automatewoo-field-wrap">
                                        <input type="text" name="mo_flow_data[actions][1][to]" value="hello" class="automatewoo-field automatewoo-field--type-text" placeholder="E.g. {{ customer.email }}, admin@example.org --notracking" data-automatewoo-validate="variables " required="">
                                    </td>
                                </tr>


                                <tr class="automatewoo-table__row" data-name="subject" data-type="text" data-required="1 ">

                                    <td class="automatewoo-table__col automatewoo-table__col--label">


                                        <label>Email subject <span class="required">*</span>
                                        </label>

                                    </td>

                                    <td class="automatewoo-table__col automatewoo-table__col--field automatewoo-field-wrap">
                                        <input type="text" name="mo_flow_data[actions][1][subject]" value="hi" class="automatewoo-field automatewoo-field--type-text" placeholder="" data-automatewoo-validate="variables " required="">
                                    </td>
                                </tr>


                                <tr class="automatewoo-table__row" data-name="email_heading" data-type="text" data-required="0 ">

                                    <td class="automatewoo-table__col automatewoo-table__col--label">


                                        <span class="automatewoo-help-tip automatewoo-help-tip--right woocommerce-help-tip"></span>
                                        <label>Email heading </label>

                                    </td>

                                    <td class="automatewoo-table__col automatewoo-table__col--field automatewoo-field-wrap">
                                        <input type="text" name="mo_flow_data[actions][1][email_heading]" value="" class="automatewoo-field automatewoo-field--type-text" placeholder="" data-automatewoo-validate="variables ">
                                    </td>
                                </tr>


                                <tr class="automatewoo-table__row" data-name="preheader" data-type="text" data-required="0 ">

                                    <td class="automatewoo-table__col automatewoo-table__col--label">


                                        <span class="automatewoo-help-tip automatewoo-help-tip--right woocommerce-help-tip"></span>
                                        <label>Email preheader </label>

                                    </td>

                                    <td class="automatewoo-table__col automatewoo-table__col--field automatewoo-field-wrap">
                                        <input type="text" name="mo_flow_data[actions][1][preheader]" value="" class="automatewoo-field automatewoo-field--type-text" placeholder="" data-automatewoo-validate="variables ">
                                    </td>
                                </tr>


                                <tr class="automatewoo-table__row" data-name="template" data-type="select" data-required="0 ">

                                    <td class="automatewoo-table__col automatewoo-table__col--label">


                                        <span class="automatewoo-help-tip automatewoo-help-tip--right woocommerce-help-tip"></span>
                                        <label>Template </label>

                                    </td>

                                    <td class="automatewoo-table__col automatewoo-table__col--field automatewoo-field-wrap">

                                        <select name="mo_flow_data[actions][1][template]" data-name="template" class="automatewoo-field automatewoo-field--type-select">


                                            <option value="default" selected="selected">WooCommerce Default</option>
                                            <option value="plain">None</option>

                                        </select>

                                    </td>
                                </tr>

                                </tbody>
                            </table>
                        </div>
                    </div>


                    <div class="aw-action-template " data-action-number="">

                        <div class="automatewoo-action__header">
                            <div class="row-options">
                                <a href="#" data-automatewoo-preview="">Preview</a>
                                <a class="js-edit-action" href="#">Edit</a>
                                <a class="js-delete-action" href="#">Delete</a>
                            </div>

                            <h4 class="action-title">New Action</h4>
                        </div>

                        <div class="automatewoo-action__fields">
                            <table class="automatewoo-table">

                                <tbody>
                                <tr class="automatewoo-table__row" data-name="action_name" data-type="select" data-required="1">
                                    <td class="automatewoo-table__col automatewoo-table__col--label">
                                        <label>Action <span class="required">*</span></label>
                                    </td>
                                    <td class="automatewoo-table__col automatewoo-table__col--field">


                                        <select name="" data-name="" class="automatewoo-field automatewoo-field--type-select js-action-select">

                                            <option value="">[Select]</option>

                                            <optgroup label="Email">
                                                <option value="send_email">Send Email</option>
                                                <option value="send_email_plain">Send Email - Plain Text</option>
                                                <option value="send_email_raw">Send Email - Raw HTML [BETA]</option>
                                            </optgroup>
                                            <optgroup label="Customer">
                                                <option value="customer_change_role">Change Role</option>
                                                <option value="customer_update_meta">Update Custom Field</option>
                                                <option value="customer_add_tags">Add Tags</option>
                                                <option value="customer_remove_tags">Remove Tags</option>
                                            </optgroup>
                                            <optgroup label="Order">
                                                <option value="change_order_status">Change Status</option>
                                                <option value="update_order_meta">Update Custom Field</option>
                                                <option value="resend_order_email">Resend Order Email</option>
                                                <option value="trigger_order_action">Trigger Order Action</option>
                                                <option value="order_update_customer_shipping_note">Update Customer Provided Note</option>
                                                <option value="order_add_note">Add Note</option>
                                            </optgroup>
                                            <optgroup label="Order Item">
                                                <option value="order_item_update_meta" disabled="disabled">Update Custom Field</option>
                                            </optgroup>
                                            <optgroup label="Subscription">
                                                <option value="change_subscription_status" disabled="disabled">Change Status</option>
                                                <option value="subscription_update_meta" disabled="disabled">Update Custom Field</option>
                                                <option value="subscription_send_invoice" disabled="disabled">Send Invoice</option>
                                                <option value="subscription_add_product" disabled="disabled">Add Product</option>
                                                <option value="subscription_remove_product" disabled="disabled">Remove Product</option>
                                                <option value="subscription_add_note" disabled="disabled">Add Note</option>
                                                <option value="subscription_add_coupon" disabled="disabled">Add Coupon</option>
                                                <option value="subscription_remove_coupon" disabled="disabled">Remove Coupon</option>
                                            </optgroup>
                                            <optgroup label="Memberships">
                                                <option value="memberships_change_plan">Create / Change Membership Plan For User</option>
                                                <option value="memberships_delete_user_membership">Delete Membership For User</option>
                                            </optgroup>
                                            <optgroup label="AutomateWoo">
                                                <option value="clear_queued_events">Clear Queued Events</option>
                                                <option value="change_workflow_status" disabled="disabled">Change Workflow Status</option>
                                            </optgroup>
                                            <optgroup label="Other">
                                                <option value="custom_function">Custom Function</option>
                                                <option value="change_post_status" disabled="disabled">Change Post Status</option>
                                            </optgroup>
                                            <optgroup label="Product">
                                                <option value="update_product_meta" disabled="disabled">Update Custom Field</option>
                                            </optgroup>
                                            <optgroup label="Mad Mimi">
                                                <option value="add_to_mad_mimi_list">Add Customer to List</option>
                                            </optgroup>
                                            <optgroup label="DEPRECATED">
                                                <option value="add_to_campaign_monitor">Campaign Monitor Add Customer to List [DEPRECATED]</option>
                                            </optgroup>

                                        </select>


                                        <div class="js-action-description"></div>

                                    </td>
                                </tr>


                                </tbody>
                            </table>
                        </div>
                    </div>


                </div>


                <div class="automatewoo-metabox-footer">
                    <a href="#" class="js-aw-add-action button button-primary button-large">+ Add Action</a>
                </div>
            </div>
        </div>
    </div>
</div>
