<?php

use MailOptin\Core\Repositories\FlowsRepository;

$status = $created_at = '';

if (isset($flow_id)) {
    $status = FlowsRepository::get_flow_status($flow_id);
}
?>
<div id="postbox-container-1" class="postbox-container">
    <div id="side-sortables" class="">
        <div id="aw_save_box" class="postbox automatewoo-metabox no-drag">
                <button type="button" class="handlediv" aria-expanded="true">
                    <span class="toggle-indicator" aria-hidden="true"></span>
                </button>
            <div class="inside">
                <div class="submitbox" id="submitpost">
                    <table class="automatewoo-table">
                        <tbody>
                        <tr class="automatewoo-table__row">
                            <td class="automatewoo-table__col">

                                <div class="automatewoo-input-group">

                                    <label class="automatewoo-input-group__addon automatewoo-input-group__addon--pad-right automatewoo-label--weight-normal">
                                        <?= esc_html__('Status:', 'mailoptin'); ?>
                                    </label>

                                    <div class="automatewoo-input-group__input">
                                        <select name="flow_status" data-name="flow_status" class="automatewoo-field automatewoo-field--type-select">
                                            <option value="active" <?= selected($status, 'active', false) ?>><?= esc_html__('Active', 'mailoptin') ?></option>
                                            <option value="disabled" <?= selected($status, 'disabled', false) ?>><?= esc_html__('Disabled', 'mailoptin') ?></option>
                                        </select>
                                    </div>
                                </div>

                            </td>
                        </tr>
                        </tbody>
                    </table>

                    <div id="major-publishing-actions">
                        <?php if (isset($_GET['flowid'])) : ?>
                            <div id="delete-action">
                                <a class="submitdelete deletion" href="#"><?= esc_html__('Delete', 'mailoptin'); ?></a>
                            </div>
                        <?php endif; ?>
                        <div id="publishing-action">
                            <input name="save_flow" type="submit" class="button button-primary button-large" value="<?= esc_html__('Save', 'mailoptin'); ?>">
                        </div>
                        <div class="clear"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>