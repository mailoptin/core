jQuery(document).ready(function () {

    // Delegated: the customizer renders controls when their section opens, after this runs.
    jQuery(document).on('change', '.customize-control-mailoptin-checkbox-multiple input[type="checkbox"]', function () {

            var checkbox_values = jQuery(this).parents('.customize-control').find('input[type="checkbox"]:checked').map(
                function () {
                    return this.value;
                }
            ).get().join(',');

            jQuery(this).parents('.customize-control').find('input[type="hidden"]').val(checkbox_values).trigger('change');
        }
    );

});
