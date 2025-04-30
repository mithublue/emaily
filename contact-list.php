<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Register the custom post type 'email_contact_list'
function emaily_register_contact_list_post_type() {
	$list_labels = array(
		'name'               => __('Contact Lists', 'emaily'),
		'singular_name'      => __('Contact List', 'emaily'),
		'menu_name'          => __('Contact Lists', 'emaily'),
		'name_admin_bar'     => __('Contact List', 'emaily'),
		'add_new'            => __('Add New', 'emaily'),
		'add_new_item'       => __('Add New Contact List', 'emaily'),
		'new_item'           => __('New Contact List', 'emaily'),
		'edit_item'          => __('Edit Contact List', 'emaily'),
		'view_item'          => __('View Contact List', 'emaily'),
		'all_items'          => __('All Contact Lists', 'emaily'),
		'search_items'       => __('Search Contact Lists', 'emaily'),
		'not_found'          => __('No contact lists found.', 'emaily'),
	);

	$list_args = array(
		'labels'             => $list_labels,
		'public'             => false,
		'show_ui'            => true,
		'show_in_menu'       => 'emaily',
		'query_var'          => true,
		'rewrite'            => array('slug' => 'email-contact-list'),
		'capability_type'    => 'post',
		'has_archive'        => false,
		'hierarchical'       => false,
		'supports'           => array('title'),
		'show_in_rest'       => true,
	);

	register_post_type('email_contact_list', $list_args);
}
add_action('init', 'emaily_register_contact_list_post_type');

// Add Email List metabox
add_action('add_meta_boxes', 'emaily_add_email_list_metabox');
function emaily_add_email_list_metabox() {
	add_meta_box(
		'emaily_email_list',
		__('Email List', 'emaily'),
		'emaily_email_list_metabox_callback',
		'email_contact_list',
		'normal',
		'high'
	);
}

function emaily_email_list_metabox_callback($post) {
	$email_list = get_post_meta($post->ID, 'email_contact_list_users', true);
	$email_list = is_array($email_list) ? $email_list : [];
	$email_count = count($email_list);
	?>
	<div id="emaily-email-list-content">
		<p><strong><?php printf(_n('Total Email: %d', 'Total Emails: %d', $email_count, 'emaily'), $email_count); ?></strong></p>
		<?php if (empty($email_list)): ?>
			<p><?php _e('No emails found in this contact list.', 'emaily'); ?></p>
		<?php else: ?>
			<table class="widefat striped">
				<thead>
				<tr>
					<th><?php _e('Email', 'emaily'); ?></th>
					<th><?php _e('Name', 'emaily'); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ($email_list as $email ): ?>
					<tr>
						<td><?php echo esc_html($email); ?></td>
						<?php
						$user_data = get_user_by( 'email', $email );
						?>
						<td>
							<?php
							if ( $user_data  ) {
								echo esc_html(!empty($user_data->display_name) ? $user_data->display_name : __('N/A', 'emaily'));
							}
							?>

						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

// Add Validate Emails metabox
add_action('add_meta_boxes', 'emaily_add_validate_emails_metabox');
function emaily_add_validate_emails_metabox() {
	add_meta_box(
		'emaily_validate_emails',
		__('Validate Emails', 'emaily'),
		'emaily_validate_emails_metabox_callback',
		'email_contact_list',
		'side',
		'high'
	);
}

function emaily_validate_emails_metabox_callback($post) {
	?>
	<p>
		<button type="button" id="emaily-validate-emails-button" class="button button-secondary">
			<?php _e('Validate Emails', 'emaily'); ?>
		</button>
	</p>
	<p class="description">
		<?php _e('Click to validate emails in this contact list and remove invalid or unregistered emails.', 'emaily'); ?>
	</p>
	<?php
}

// Enqueue JavaScript for Validate Emails
add_action('admin_enqueue_scripts', 'emaily_enqueue_validate_emails_scripts');
function emaily_enqueue_validate_emails_scripts($hook) {
	if (get_current_screen()->post_type !== 'email_contact_list' || !in_array($hook, ['post.php', 'post-new.php'])) {
		return;
	}

	wp_enqueue_script(
		'emaily-contact-list',
		plugin_dir_url(__FILE__) . '/assets/js/emaily-contact-list.js',
		['jquery'],
		'1.0',
		true
	);

	wp_localize_script(
		'emaily-contact-list',
		'emailyContactList',
		[
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('emaily_validate_emails_nonce'),
			'post_id'  => get_the_ID(),
			'strings'  => [
				'confirm'        => __('Are you sure you want to validate emails? Invalid or unregistered emails will be removed.', 'emaily'),
				'error_generic'  => __('An error occurred while validating emails.', 'emaily'),
			],
			'actions'  => [
				'get_email_list' => 'emaily_get_email_list',
			],
		]
	);
}

// AJAX handler for validating emails
add_action('wp_ajax_emaily_validate_contact_list', 'emaily_validate_contact_list');
function emaily_validate_contact_list() {
	check_ajax_referer('emaily_validate_emails_nonce', 'nonce');

	$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

	if (!$post_id || get_post_type($post_id) !== 'email_contact_list') {
		wp_send_json_error(__('Invalid contact list.', 'emaily'));
	}

	$post = get_post($post_id);
	if (!$post) {
		wp_send_json_error(__('Contact list not found.', 'emaily'));
	}

	// Get current email list
	$email_list = get_post_meta($post_id, 'email_contact_list_users', true);
	if (!is_array($email_list) || empty($email_list)) {
		wp_send_json_success([
			'message' => __('No emails found in the contact list.', 'emaily'),
			'validated' => 0,
			'removed' => 0,
		]);
	}

	$valid_emails = [];
	$removed_count = 0;

	foreach ($email_list as $email) {
		// Validate email format
		if (!is_email($email)) {
			emaily_log($post_id, "Removed invalid email: $email");
			$removed_count++;
			continue;
		}

		// Check if email is registered
		$user = get_user_by('email', $email);
		if (!$user) {
			emaily_log($post_id, "Removed unregistered email: $email");
			$removed_count++;
			continue;
		}

		// Keep valid and registered email
		$valid_emails[] = $email;
	}

	// Update email list
	update_post_meta($post_id, 'email_contact_list_users', $valid_emails);

	$validated_count = count($valid_emails);
	emaily_log($post_id, "Email validation completed: $validated_count emails kept, $removed_count emails removed.");

	wp_send_json_success([
		'message' => sprintf(
			_n(
				'%d email validated, %d email removed.',
				'%d emails validated, %d emails removed.',
				$validated_count,
				'emaily'
			),
			$validated_count,
			$removed_count
		),
		'validated' => $validated_count,
		'removed' => $removed_count,
	]);
}

// AJAX handler for getting email list
add_action('wp_ajax_emaily_get_email_list', 'emaily_get_email_list');
function emaily_get_email_list() {
	check_ajax_referer('emaily_validate_emails_nonce', 'nonce');

	$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

	if (!$post_id || get_post_type($post_id) !== 'email_contact_list') {
		wp_send_json_error(__('Invalid contact list.', 'emaily'));
	}

	$post = get_post($post_id);
	if (!$post) {
		wp_send_json_error(__('Contact list not found.', 'emaily'));
	}

	// Get email list
	$email_list = get_post_meta($post_id, 'email_contact_list_users', true);
	$email_list = is_array($email_list) ? $email_list : [];
	$email_count = count($email_list);

	// Build HTML response
	ob_start();
	?>
	<p><strong><?php printf(_n('Total Email: %d', 'Total Emails: %d', $email_count, 'emaily'), $email_count); ?></strong></p>
	<?php if (empty($email_list)): ?>
		<p><?php _e('No emails found in this contact list.', 'emaily'); ?></p>
	<?php else: ?>
		<table class="widefat striped">
			<thead>
			<tr>
				<th><?php _e('Email', 'emaily'); ?></th>
				<th><?php _e('Name', 'emaily'); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ($email_list as $k => $email ): ?>
				<tr>
					<td><?php echo esc_html($email); ?></td>
					<td>
						<?php
						$user_data = get_user_by( 'email', $email );
						?>
						<?php echo esc_html(!empty($user_data['name']) ? $user_data['name'] : __('N/A', 'emaily')); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
	$html = ob_get_clean();

	wp_send_json_success(['html' => $html]);
}

// Log function (consistent with emaily-email-sender.php)
if (!function_exists('emaily_log')) {
	function emaily_log($post_id, $message) {
		$log_dir = plugin_dir_path(__FILE__) . 'logs/';
		if (!file_exists($log_dir)) {
			mkdir($log_dir, 0755, true);
		}
		$log_file = $log_dir . 'emaily.log';
		$timestamp = current_time('mysql');
		$log_message = "[$timestamp] Contact List ID $post_id: $message\n";
		file_put_contents($log_file, $log_message, FILE_APPEND);
	}
}


// Add custom columns to the users table
add_filter('manage_users_columns', 'custom_add_user_columns');
function custom_add_user_columns($columns) {
	$columns['emaily_name'] = __('Name', 'emaily');
	$columns['emaily_gender'] = __('Gender', 'emaily');
	$columns['emaily_country'] = __('Country', 'emaily');
	$columns['emaily_verification_status'] = __('Is Verified', 'emaily');
	return $columns;
}

// Populate custom columns with user meta data
add_action('manage_users_custom_column', 'custom_show_user_columns_content', 10, 3);
function custom_show_user_columns_content($value, $column_name, $user_id) {
	switch ($column_name) {
		case 'emaily_name':
			return esc_html(get_user_meta($user_id, 'emaily_name', true));
		case 'emaily_gender':
			return esc_html(get_user_meta($user_id, 'emaily_gender', true));
		case 'emaily_country':
			return esc_html(get_user_meta($user_id, 'emaily_country', true));
		case 'emaily_verification_status':
			return esc_html(get_user_meta($user_id, 'emaily_verification_status', true));
		default:
			return $value;
	}
}
