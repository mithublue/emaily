<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Register the custom post type 'emaily_form'
function emaily_register_form_post_type() {
	$form_labels = array(
		'name'               => __('Forms', 'emaily'),
		'singular_name'      => __('Form', 'emaily'),
		'menu_name'          => __('Forms', 'emaily'),
		'name_admin_bar'     => __('Form', 'emaily'),
		'add_new'            => __('Add New', 'emaily'),
		'add_new_item'       => __('Add New Form', 'emaily'),
		'new_item'           => __('New Form', 'emaily'),
		'edit_item'          => __('Edit Form', 'emaily'),
		'view_item'          => __('View Form', 'emaily'),
		'all_items'          => __('All Forms', 'emaily'),
		'search_items'       => __('Search Forms', 'emaily'),
		'not_found'          => __('No forms found.', 'emaily'),
	);

	$form_args = array(
		'labels'             => $form_labels,
		'public'             => false,
		'show_ui'            => true,
		'show_in_menu'       => 'emaily',
		'query_var'          => true,
		'rewrite'            => array('slug' => 'emaily-form'),
		'capability_type'    => 'post',
		'has_archive'        => false,
		'hierarchical'       => false,
		'supports'           => array('title'),
		'show_in_rest'       => true,
	);

	register_post_type('emaily_form', $form_args);
}
add_action('init', 'emaily_register_form_post_type');

// Add metaboxes for emaily_form
function emaily_add_form_metaboxes() {
	add_meta_box(
		'emaily_form_builder',
		__('Form Builder', 'emaily'),
		'emaily_form_builder_metabox',
		'emaily_form',
		'normal',
		'high'
	);
	add_meta_box(
		'emaily_form_fields',
		__('Form Fields', 'emaily'),
		'emaily_form_fields_metabox',
		'emaily_form',
		'side',
		'high'
	);
	add_meta_box(
		'emaily_form_lists',
		__('Contact Lists', 'emaily'),
		'emaily_form_lists_metabox',
		'emaily_form',
		'side',
		'high'
	);
	add_meta_box(
		'emaily_form_type',
		__('Form Type', 'emaily'),
		'emaily_form_type_metabox',
		'emaily_form',
		'side',
		'high'
	);
	add_meta_box(
		'emaily_form_shortcode',
		__('Form Shortcode', 'emaily'),
		'emaily_form_shortcode_metabox',
		'emaily_form',
		'normal',
		'high'
	);
}
add_action('add_meta_boxes', 'emaily_add_form_metaboxes');

// Render visual builder metabox
function emaily_form_builder_metabox($post) {
	wp_nonce_field('emaily_form_builder_nonce', 'emaily_form_builder_nonce');
	$fields = get_post_meta($post->ID, 'emaily_form_fields', true);
	$fields = is_array($fields) ? $fields : array();
	if (!in_array('Email', $fields)) {
		$fields = array_merge(array('Email'), $fields);
	}
	?>
	<div id="emaily-form-builder">
		<p><?php esc_html_e('Drag and drop fields to reorder them. Use the cross to remove fields.', 'emaily'); ?></p>
		<div id="emaily-form-builder-fields" class="emaily-builder-fields">
			<?php foreach ($fields as $field) : ?>
				<div class="emaily-field-block" data-field="<?php echo esc_attr($field); ?>">
					<span class="emaily-field-handle dashicons dashicons-move"></span>
					<label class="emaily-field-label"><?php echo esc_html($field); ?></label>
					<input type="text" class="emaily-field-input" disabled />
					<?php if ($field !== 'Email') : ?>
						<span class="emaily-field-remove dashicons dashicons-no-alt" title="<?php esc_attr_e('Remove field', 'emaily'); ?>"></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

// Render form fields metabox
function emaily_form_fields_metabox($post) {
	wp_nonce_field('emaily_form_fields_nonce', 'emaily_form_fields_nonce');
	$selected_fields = get_post_meta($post->ID, 'emaily_form_fields', true);
	$selected_fields = is_array($selected_fields) ? $selected_fields : array();
	if (!in_array('Email', $selected_fields)) {
		$selected_fields[] = 'Email';
	}
	$available_fields = array(
		'Email', 'Name', 'Lastname', 'Middlename', 'Phone', 'Date of birth',
		'Company name', 'Industry', 'Department', 'Job title', 'State',
		'Postal code', 'Lead source', 'Salary', 'Country', 'City', 'Tags'
	);
	?>
	<div id="emaily-form-fields-metabox">
		<p><?php esc_html_e('Select fields to add to the form:', 'emaily'); ?></p>
		<?php foreach ($available_fields as $field) : ?>
			<label>
				<input type="checkbox" name="emaily_form_fields[]" value="<?php echo esc_attr($field); ?>"
					<?php checked(in_array($field, $selected_fields)); ?>
					   class="emaily-field-checkbox" data-field="<?php echo esc_attr($field); ?>"
					<?php echo $field === 'Email' ? 'disabled checked' : ''; ?> />
				<?php echo esc_html($field); ?>
			</label><br />
		<?php endforeach; ?>
	</div>
	<?php
}

// Render contact lists metabox
function emaily_form_lists_metabox($post) {
	wp_nonce_field('emaily_form_lists_nonce', 'emaily_form_lists_nonce');
	$selected_lists = get_post_meta($post->ID, 'emaily_form_lists', true);
	$selected_lists = is_array($selected_lists) ? $selected_lists : array();
	$contact_lists = get_posts(array(
		'post_type'      => 'email_contact_list',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	));
	?>
	<div id="emaily-form-lists-metabox">
		<p class="emaily-metabox-label"><?php esc_html_e('Contact Lists', 'emaily'); ?></p>
		<?php if (empty($contact_lists)) : ?>
			<p class="emaily-notice">
				<?php
				printf(
					__('No contact lists available. <a href="%s">Create one here</a>.', 'emaily'),
					esc_url(admin_url('post-new.php?post_type=email_contact_list'))
				);
				?>
			</p>
		<?php else : ?>
			<select name="emaily_form_lists[]" id="emaily_form_lists" multiple>
				<?php foreach ($contact_lists as $list) : ?>
					<option value="<?php echo esc_attr($list->ID); ?>" <?php selected(in_array($list->ID, $selected_lists)); ?>>
						<?php echo esc_html($list->post_title); ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
	</div>
	<?php
}

// Render form type metabox
function emaily_form_type_metabox($post) {
	wp_nonce_field('emaily_form_type_nonce', 'emaily_form_type_nonce');
	$form_type = get_post_meta($post->ID, 'emaily_form_type', true);
	$form_types = array('Embedded form', 'Standalone form', 'Popup box');
	?>
	<div id="emaily-form-type-metabox">
		<p class="emaily-metabox-label"><?php esc_html_e('Form Type', 'emaily'); ?></p>
		<select name="emaily_form_type" id="emaily_form_type">
			<option value=""><?php esc_html_e('Select form type', 'emaily'); ?></option>
			<?php foreach ($form_types as $type) : ?>
				<option value="<?php echo esc_attr($type); ?>" <?php selected($form_type, $type); ?>>
					<?php echo esc_html($type); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php
}

// Render shortcode metabox
function emaily_form_shortcode_metabox($post) {
	$shortcode = '[emaily_form id="' . $post->ID . '"]';
	?>
	<div id="emaily-form-shortcode-metabox">
		<p><?php esc_html_e('Use the following shortcode to display this form:', 'emaily'); ?></p>
		<input type="text" value="<?php echo esc_attr($shortcode); ?>" readonly style="width: 100%;" />
	</div>
	<?php
}

// Save form settings
function emaily_save_form_settings($post_id) {
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}

	// Save form fields
	if (isset($_POST['emaily_form_fields_nonce']) && wp_verify_nonce($_POST['emaily_form_fields_nonce'], 'emaily_form_fields_nonce')) {
		$fields = isset($_POST['emaily_form_fields_order'])
			? array_map('sanitize_text_field', json_decode(stripslashes($_POST['emaily_form_fields_order']), true))
			: (isset($_POST['emaily_form_fields']) ? array_map('sanitize_text_field', $_POST['emaily_form_fields']) : array());
		if (!in_array('Email', $fields)) {
			$fields = array_merge(array('Email'), $fields);
		}
		update_post_meta($post_id, 'emaily_form_fields', $fields);
	}

	// Save contact lists
	if (isset($_POST['emaily_form_lists_nonce']) && wp_verify_nonce($_POST['emaily_form_lists_nonce'], 'emaily_form_lists_nonce')) {
		$lists = isset($_POST['emaily_form_lists']) ? array_map('intval', $_POST['emaily_form_lists']) : array();
		update_post_meta($post_id, 'emaily_form_lists', $lists);
	}

	// Save form type
	if (isset($_POST['emaily_form_type_nonce']) && wp_verify_nonce($_POST['emaily_form_type_nonce'], 'emaily_form_type_nonce')) {
		$form_type = isset($_POST['emaily_form_type']) ? sanitize_text_field($_POST['emaily_form_type']) : '';
		update_post_meta($post_id, 'emaily_form_type', $form_type);
	}
}
add_action('save_post_emaily_form', 'emaily_save_form_settings');

// Validate form before publishing
function emaily_validate_form_fields($data, $postarr) {
	if ($data['post_type'] !== 'emaily_form') {
		return $data;
	}

	if (in_array($data['post_status'], array('draft', 'auto-draft', 'pending'))) {
		return $data;
	}

	$fields = isset($postarr['emaily_form_fields_order'])
		? json_decode(stripslashes($postarr['emaily_form_fields_order']), true)
		: (isset($postarr['emaily_form_fields']) ? $postarr['emaily_form_fields'] : array());
	$fields = array_map('sanitize_text_field', $fields);
	if (empty($fields) || !in_array('Email', $fields)) {
		wp_die(
			__('Error: The Email field is required to publish the form.', 'emaily'),
			__('Form Validation Error', 'emaily'),
			array('back_link' => true)
		);
	}

	$lists = isset($postarr['emaily_form_lists']) ? array_map('intval', $postarr['emaily_form_lists']) : array();
	if (empty($lists)) {
		wp_die(
			__('Error: At least one contact list must be selected.', 'emaily'),
			__('Form Validation Error', 'emaily'),
			array('back_link' => true)
		);
	}

	$form_type = isset($postarr['emaily_form_type']) ? sanitize_text_field($postarr['emaily_form_type']) : '';
	if (empty($form_type)) {
		wp_die(
			__('Error: A form type must be selected.', 'emaily'),
			__('Form Validation Error', 'emaily'),
			array('back_link' => true)
		);
	}

	return $data;
}
add_filter('wp_insert_post_data', 'emaily_validate_form_fields', 10, 2);
