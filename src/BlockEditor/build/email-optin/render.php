<?php if (empty($attributes['id'])) return; ?>
<div <?php echo get_block_wrapper_attributes(); ?>>
	<?php
	// older blocks saved the numeric optin id; newer ones save the optin uuid.
	$mo_optin_id = is_numeric($attributes['id']) ? absint($attributes['id']) : sanitize_text_field($attributes['id']);
	echo do_shortcode(sprintf('[mo-optin-form id="%s"]', esc_attr($mo_optin_id)));
	?>
</div>
