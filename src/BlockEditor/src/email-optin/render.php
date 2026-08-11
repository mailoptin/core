<?php if (empty($attributes['id'])) return; ?>
<div <?php echo get_block_wrapper_attributes(); ?>>
	<?php echo do_shortcode(sprintf('[mo-optin-form id="%d"]', absint($attributes['id']))) ?>
</div>
