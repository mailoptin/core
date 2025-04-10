(function (api, $) {

    var show_modal = function (parent) {
        $('.mo-optin-map-custom-field-settings', parent).show();
    };

    var add_spinner = function (parent) {
        $('.mo-optin-map-custom-field-settings', parent).prepend('<div class="mo-optin-map-custom-field-spinner"></div>');
    };

    var remove_spinner = function (parent) {
        $('.mo-optin-map-custom-field-spinner', parent).remove();
    };

    var hide_modal = function (parent) {
        $('.mo-optin-map-custom-field-settings', parent).hide();
        $('.mo-optin-map-custom-field-settings-content', parent).hide();
    };

    var ajax_get_custom_fields = function (parent) {
        var payload = {
            action: 'mailoptin_customizer_optin_map_custom_field',
            optin_campaign_id: mailoptin_optin_campaign_id,
            custom_field_mappings: $("input[data-customize-setting-link*='[custom_field_mappings]']").val(),
            integration_index: parent.data('integration-index'),
            connect_service: $("select[name='connection_service']", parent).val(),
            list_id: $("select[name='connection_email_list']", parent).val(),
            custom_fields: $('.mo-fields-save-field').val(),
            security: $("input[data-customize-setting-link*='[ajax_nonce]']").val()
        };

        $('.mo-integration-block:visible .mo-optin-integration-field', parent).each(function () {
            var obj = $(this);
            payload[obj.attr('name')] = obj.val();
        });

        $.post(ajaxurl, payload, function (response) {

                if (_.isObject(response) && 'data' in response) {
                    $('.mo-optin-map-custom-field-settings', parent).show();
                    $('.mo-optin-map-custom-field-settings-content', parent).html(response.data).show();
                }

                remove_spinner(parent);
            }
        );
    };

    var save_field_mapping_data = function (parent) {
        var cache = $("input[data-customize-setting-link*='[custom_field_mappings]']"),
            existing_data_store = cache.val(),
            index = parent.data('integration-index'),
            data_store = [];

        if (typeof existing_data_store !== 'undefined') {
            try {
                data_store = JSON.parse(existing_data_store);
                if (!$.isArray(data_store)) data_store = [];
            } catch {
            }
        }

        $('.mo-optin-custom-field-select', parent).each(function () {
            var objKey = $(this).attr('name');
            if (typeof data_store[index] === 'undefined') {
                data_store[index] = {};
            }

            data_store[index][objKey] = $(this).val();
        });

        cache.val(JSON.stringify(data_store)).trigger('change');
    };

    $(function () {
        $(document).on('click', '.mo-optin-map-custom-field .map-link', function (e) {
            e.preventDefault();
            var parent = $(this).parents('.mo-integration-widget');
            show_modal(parent);
            add_spinner(parent);
            ajax_get_custom_fields(parent);
        });

        $(document).on('click', '.mo-optin-map-custom-field-close', function (e) {
            e.preventDefault();
            var parent = $(this).parents('.mo-integration-widget');
            hide_modal(parent);
        });

        $(document).on('click', '.mo-optin-field-map-save', function (e) {
            e.preventDefault();
            var parent = $(this).parents('.mo-integration-widget');
            save_field_mapping_data(parent);
            hide_modal(parent);
        });

        $(document).on('change', '.mo-optin-custom-field-select', function (e) {
            var parent = $(this).parents('.mo-integration-widget');
            save_field_mapping_data(parent);
        });

        $(document).on('mo_integration_removed', function (e, index, parent) {
            var data_store = $("input[data-customize-setting-link*='[custom_field_mappings]']");
            try {
                var old_data = JSON.parse(data_store.val());

                // remove mapping by index. see https://stackoverflow.com/a/1345122/2648410
                old_data.splice(index, 1);
                // remove null and empty from array elements.
                old_data = _.without(old_data, null, '');
                // store the data
                data_store.val(JSON.stringify(old_data)).trigger('change');

            } catch {

            }
        });
    });

})(wp.customize, jQuery);