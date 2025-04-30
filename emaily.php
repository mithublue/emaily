<?php
/*
Plugin Name: Emaily
Description: A WordPress plugin to manage email contact lists, campaigns, forms, and email open tracking.
Version: 2.0
Author: Mithu A Quayium
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Include PhpSpreadsheet if available (for Excel file processing)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
	require_once __DIR__ . '/vendor/autoload.php';
}
use PhpOffice\PhpSpreadsheet\IOFactory;

// Include plugin functionality
require_once __DIR__ . '/emaily-campaign.php';
require_once __DIR__ . '/emaily-email-sender.php';
require_once __DIR__ . '/emaily-campaigns-dashboard.php';
require_once __DIR__ . '/emaily-form.php';
require_once __DIR__ . '/shortcode.php';
require_once __DIR__ . '/emaily-settings.php';
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/contact-list.php';

/*function emaily_register_contact_list_post_type() {
	$labels = array(
		'name'               => __('Email Contact Lists', 'emaily'),
		'singular_name'      => __('Email Contact List', 'emaily'),
		'menu_name'          => __('Email Lists', 'emaily'),
		'name_admin_bar'     => __('Email Contact List', 'emaily'),
		'add_new'            => __('Add New', 'emaily'),
		'add_new_item'       => __('Add New Email Contact List', 'emaily'),
		'new_item'           => __('New Email Contact List', 'emaily'),
		'edit_item'          => __('Edit Email Contact List', 'emaily'),
		'view_item'          => __('View Email Contact List', 'emaily'),
		'all_items'          => __('All Email Lists', 'emaily'),
		'search_items'       => __('Search Email Contact Lists', 'emaily'),
		'not_found'          => __('No email contact lists found.', 'emaily'),
	);

	$args = array(
		'labels'             => $labels,
		'public'             => false,
		'show_ui'            => true,
		'show_in_menu'       => 'emaily',
		'query_var'          => true,
		'rewrite'            => array('slug' => 'email-contact-list'),
		'capability_type'    => 'post',
		'has_archive'        => false,
		'hierarchical'       => false,
		'supports'           => array('title', 'editor'),
		'show_in_rest'       => true,
	);

	register_post_type('email_contact_list', $args);
}
add_action('init', 'emaily_register_contact_list_post_type');*/

// Add the Emaily admin menu
function emaily_admin_menu() {
	add_menu_page(
		__('Emaily', 'emaily'),
		__('Emaily', 'emaily'),
		'manage_options',
		'emaily',
		'emaily_admin_page',
		'dashicons-email',
		10
	);
	add_submenu_page(
		'emaily',
		__('Campaigns Dashboard', 'emaily'),
		__('Campaigns Dashboard', 'emaily'),
		'manage_options',
		'emaily-campaigns-dashboard',
		'emaily_campaigns_dashboard_page'
	);
	add_submenu_page(
		'emaily',
		__('Forms', 'emaily'),
		__('Forms', 'emaily'),
		'manage_options',
		'edit.php?post_type=emaily_form'
	);
	add_submenu_page(
		'emaily',
		__('Settings', 'emaily'),
		__('Settings', 'emaily'),
		'manage_options',
		'emaily-settings',
		'emaily_settings_page'
	);
}
add_action('admin_menu', 'emaily_admin_menu');

// Render the Emaily admin page
function emaily_admin_page() {
	if (isset($_POST['emaily_create_list']) && check_admin_referer('emaily_create_list_action', 'emaily_nonce')) {
		wp_safe_redirect(admin_url('post-new.php?post_type=email_contact_list'));
		exit;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e('Emaily', 'emaily'); ?></h1>
		<p><?php esc_html_e('Manage your email contact lists here.', 'emaily'); ?></p>
		<form method="post" action="">
			<?php wp_nonce_field('emaily_create_list_action', 'emaily_nonce'); ?>
			<button type="submit" name="emaily_create_list" class="button button-primary">
				<?php esc_html_e('Add List', 'emaily'); ?>
			</button>
		</form>
	</div>
	<?php
}

// Add metabox to email_contact_list edit page
function emaily_add_metabox() {
	add_meta_box(
		'emaily_user_import',
		__('Import Users', 'emaily'),
		'emaily_user_import_metabox',
		'email_contact_list',
		'normal',
		'high'
	);
}
add_action('add_meta_boxes', 'emaily_add_metabox');

// Render the contact list import metabox
function emaily_user_import_metabox($post) {
	wp_nonce_field('emaily_user_import_nonce', 'emaily_user_import_nonce');
	?>
	<div id="emaily-import-metabox">
		<p><?php esc_html_e('Upload a CSV or Excel file to import users.', 'emaily'); ?></p>
		<input type="file" name="emaily_user_file" id="emaily_user_file" accept=".csv,.xlsx,.xls" />
		<button type="button" id="emaily_add_users" class="button button-primary">
			<?php esc_html_e('Add Users', 'emaily'); ?>
		</button>
		<div id="emaily-import-messages"></div>
	</div>
	<style>
        #emaily-import-metabox {
            padding: 10px;
        }
        #emaily_user_file {
            margin: 10px 0;
        }
        #emaily_add_users {
            margin: 10px 0;
        }
        #emaily-import-messages {
            margin-top: 10px;
            padding: 10px;
            border: 1px solid #ddd;
            display: none;
        }
        #emaily-import-messages.success {
            border-color: #46b450;
            background: #f0fff0;
        }
        #emaily-import-messages.error {
            border-color: #dc3232;
            background: #fff4f4;
        }
	</style>
	<?php
}

// Enqueue admin scripts and styles
function emaily_admin_enqueue($hook) {
	global $post_type;
	if (($hook === 'post.php' || $hook === 'post-new.php') && $post_type === 'email_contact_list') {
		wp_enqueue_script(
			'emaily-admin-js',
			plugin_dir_url(__FILE__) . 'assets/js/admin.js',
			array('jquery'),
			'2.0',
			true
		);
		wp_localize_script(
			'emaily-admin-js',
			'emailyAjax',
			array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce'    => wp_create_nonce('emaily_import_users'),
			)
		);
	}
	if (($hook === 'post.php' || $hook === 'post-new.php') && $post_type === 'emaily_form') {
		wp_enqueue_script('jquery-ui-core');
		wp_enqueue_script('jquery-ui-selectmenu');
		wp_enqueue_script('jquery-ui-sortable');
		wp_enqueue_script(
			'emaily-form-admin-js',
			plugin_dir_url(__FILE__) . 'assets/js/form-admin.js',
			array('jquery', 'jquery-ui-core', 'jquery-ui-selectmenu', 'jquery-ui-sortable'),
			'2.0',
			true
		);
		wp_enqueue_style(
			'emaily-admin-css',
			plugin_dir_url(__FILE__) . 'assets/css/admin-style.css',
			array(),
			'2.0'
		);
		wp_enqueue_style('jquery-ui-css', includes_url('css/jquery-ui.min.css'), array(), null);
	}
	if ($hook === 'toplevel_page_emaily' || $hook === 'emaily_page_emaily-campaigns-dashboard' || $hook === 'emaily_page_emaily-settings') {
		wp_enqueue_style(
			'emaily-admin-css',
			plugin_dir_url(__FILE__) . 'assets/css/admin-style.css',
			array(),
			'2.0'
		);
	}
}
add_action('admin_enqueue_scripts', 'emaily_admin_enqueue');

// Enqueue frontend scripts
function emaily_frontend_enqueue() {
	wp_enqueue_script(
		'emaily-form-frontend-js',
		plugin_dir_url(__FILE__) . 'assets/js/form-frontend.js',
		array('jquery'),
		'2.0',
		true
	);
	wp_localize_script(
		'emaily-form-frontend-js',
		'emailyAjax',
		array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('emaily_form_submit'),
		)
	);
}
add_action('wp_enqueue_scripts', 'emaily_frontend_enqueue');

// Handle AJAX user import
function emaily_import_users() {
	check_ajax_referer('emaily_import_users', 'nonce');

	if (!current_user_can('manage_options')) {
		wp_send_json_error(array('message' => __('Permission denied.', 'emaily')));
	}

	if (!isset($_FILES['file']) || is_array($_FILES['file']) === false) {
		wp_send_json_error(array('message' => __('No file uploaded.', 'emaily')));
	}

	$file = $_FILES['file'];
	$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

	if ($post_id === 0) {
		wp_send_json_error(array('message' => __('Invalid post ID.', 'emaily')));
	}

	$allowed_types = array('csv', 'xlsx', 'xls');
	$file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
	if (!in_array($file_ext, $allowed_types)) {
		wp_send_json_error(array('message' => __('Invalid file type. Only CSV and Excel files are allowed.', 'emaily')));
	}

	$required_fields = array('Email', 'Name');
	$all_fields = array(
		'Email', 'Name', 'Lastname', 'Middlename', 'Phone', 'Date of birth',
		'Company name', 'Industry', 'Department', 'Job title', 'State',
		'Postal code', 'Lead source', 'Salary', 'Country', 'City', '.', 'Tags'
	);

	$data = array();
	if ($file_ext === 'csv') {
		$handle = fopen($file['tmp_name'], 'r');
		$headers = fgetcsv($handle);
		$headers = array_map(function ($header) {
			return trim(preg_replace('/^\xEF\xBB\xBF/', '', $header));
		}, $headers);
		while (($row = fgetcsv($handle)) !== false) {
			if (count(array_keys($row)) - count(array_keys($headers)) != 0) {
				$row = array_slice($row, 0, count(array_keys($headers)));
			}
			$data[] = array_combine($headers, $row);
		}
		fclose($handle);
	} elseif ($file_ext === 'xlsx' || $file_ext === 'xls') {
		if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
			wp_send_json_error(array('message' => __('PhpSpreadsheet library is missing.', 'emaily')));
		}
		$spreadsheet = IOFactory::load($file['tmp_name']);
		$sheet = $spreadsheet->getActiveSheet();
		$rows = $sheet->toArray();
		$headers = array_shift($rows);
		foreach ($rows as $row) {
			$data[] = array_combine($headers, $row);
		}
	}

	foreach ($required_fields as $field) {
		if (!in_array($field, $headers)) {
			wp_send_json_error(array('message' => sprintf(__('Missing required field: %s.', 'emaily'), $field)));
		}
	}

	$user_emails = array();
	$success_count = 0;
	$skip_count = 0;
	$error_messages = array();

	foreach ($data as $row) {
		$email = sanitize_email($row['Email']);
		$name = sanitize_text_field($row['Name']);

		if (!is_email($email)) {
			$error_messages[] = sprintf(__('Invalid email: %s.', 'emaily'), $email);
			continue;
		}

		if (email_exists($email)) {
			$skip_count++;
			$user_emails[] = $email;
			$user_id = get_user_by( 'email', $email )->ID;
		} else {
			$user_id = wp_insert_user(array(
				'user_login'   => sanitize_user($username = sanitize_user(explode('@', $email)[0]), true),
				'user_email'   => $email,
				'display_name' => $name,
				'role'         => 'subscriber',
				'user_pass'    => wp_generate_password(),
			));

			if (is_wp_error($user_id)) {
				$error_messages[] = sprintf(__('Failed to create user %s: %s.', 'emaily'), $email, $user_id->get_error_message());
				continue;
			}
		}

		foreach ($all_fields as $field) {
			if ($field === 'Email' || empty($row[$field])) {
				continue;
			}

			if ( $field == '.' ) {
				$fieldname = 'gender';
			} else {
				$fieldname = $field;
			}
			$meta_key = 'emaily_' . strtolower(str_replace(' ', '_', $fieldname));
			update_user_meta($user_id, $meta_key, sanitize_text_field($row[$field]));
		}

		//make user verified
		update_user_meta($user_id, 'emaily_verification_status', 'verified' );

		$user_emails[] = $email;
		$success_count++;
	}

	if (!empty($user_emails)) {
		update_post_meta($post_id, 'email_contact_list_users', $user_emails);
	}

	$message = array();
	if ($success_count > 0) {
		$message[] = sprintf(__('Successfully added %d users.', 'emaily'), $success_count);
	}
	if ($skip_count > 0) {
		$message[] = sprintf(__('Skipped %d existing users.', 'emaily'), $skip_count);
	}
	if (!empty($error_messages)) {
		$message = array_merge($message, $error_messages);
	}

	if ($success_count > 0 || $skip_count > 0) {
		wp_send_json_success(array('message' => implode('<br>', $message)));
	} else {
		wp_send_json_error(array('message' => implode('<br>', $message)));
	}
}
add_action('wp_ajax_emaily_import_users', 'emaily_import_users');

// Handle AJAX form submission with email verification
function emaily_handle_form_submission() {
	check_ajax_referer('emaily_form_submit', 'nonce');

	$form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
	if ($form_id === 0) {
		wp_send_json_error(array('message' => __('Invalid form ID.', 'emaily')));
	}

	$fields = get_post_meta($form_id, 'emaily_form_fields', true);
	$fields = is_array($fields) ? $fields : array();
	$fields = apply_filters( 'emaily_subscription_form_fields', $fields, $form_id, $_POST );
	if (empty($fields) || !in_array('Email', $fields)) {
		wp_send_json_error(array('message' => __('Form configuration error: Email field is required.', 'emaily')));
	}

	$lists = get_post_meta($form_id, 'emaily_form_lists', true);
	$lists = is_array($lists) ? $lists : array();
	if (empty($lists)) {
		wp_send_json_error(array('message' => __('Form configuration error: No contact lists selected.', 'emaily')));
	}

	$email = isset($_POST['emaily_email']) ? sanitize_email($_POST['emaily_email']) : '';
	if (empty($email) || !is_email($email)) {
		wp_send_json_error(array('message' => __('Please enter a valid email address.', 'emaily')));
	}

	if (email_exists($email)) {
		$user = get_user_by('email', $email);
		$status = get_user_meta($user->ID, 'emaily_verification_status', true);
		if ($status === 'verified') {
			wp_send_json_error(array('message' => __('This email is already subscribed.', 'emaily')));
		} else {
			// Delete the pending user and allow resubscription
			wp_delete_user($user->ID);
		}
	}

	$name = isset($_POST['emaily_name']) ? sanitize_text_field($_POST['emaily_name']) : '';
	if (in_array('Name', $fields) && empty($name)) {
		wp_send_json_error(array('message' => __('Name is required.', 'emaily')));
	}

	$user_id = wp_insert_user(array(
		'user_login'   => sanitize_user($username = sanitize_user(explode('@', $email)[0]), true),
		'user_email'   => $email,
		'display_name' => $name ?: $email,
		'role'         => 'subscriber',
		'user_pass'    => wp_generate_password(),
	));

	if (is_wp_error($user_id)) {
		wp_send_json_error(array('message' => __('Failed to subscribe: ') . $user_id->get_error_message()));
	}

	// Store additional fields as user meta
	foreach ($fields as $field) {
		if ($field === 'Email' || $field === 'Name') {
			continue;
		}
		$field_key = 'emaily_' . strtolower(str_replace(' ', '_', $field));
		$field_value = isset($_POST[$field_key]) ? sanitize_text_field($_POST[$field_key]) : '';
		if (!empty($field_value)) {
			update_user_meta($user_id, $field_key, $field_value);
		}
	}

	// Store verification data
	$token = wp_generate_uuid4();
	update_user_meta($user_id, 'emaily_verification_status', 'pending');
	update_user_meta($user_id, 'emaily_verification_token', $token);
	update_user_meta($user_id, 'emaily_subscription_time', current_time('timestamp'));
	update_user_meta($user_id, 'emaily_contact_lists', $lists);

	// Send verification email
	$verification_link = add_query_arg(
		array(
			'emaily_verify' => '1',
			'user_id'       => $user_id,
			'token'         => $token,
		),
		home_url('/')
	);

	$subject = __('Verify Your Subscription', 'emaily');
	$message = sprintf(
		__('Hello %s,') . "\n\n" .
		__('Thank you for subscribing! Please verify your email address by clicking the link below:') . "\n\n" .
		__('%s') . "\n\n" .
		__('This link will expire in 24 hours. If you did not request this subscription, please ignore this email.') . "\n\n" .
		__('Best regards,') . "\n" .
		__('The Emaily Team'),
		$name ?: $email,
		$verification_link
	);

	$headers = array('Content-Type: text/plain; charset=UTF-8');
	$sent = wp_mail($email, $subject, $message, $headers);

	if (!$sent) {
		wp_delete_user($user_id);
		wp_send_json_error(array('message' => __('Failed to send verification email. Please try again.', 'emaily')));
	}

	$submission_message = carbon_get_theme_option('emaily_form_submission_message') ?: __('Please check your email to verify your subscription.', 'emaily');
	wp_send_json_success(array('message' => $submission_message));
}
add_action('wp_ajax_emaily_form_submit', 'emaily_handle_form_submission');
add_action('wp_ajax_nopriv_emaily_form_submit', 'emaily_handle_form_submission');

// Handle email verification
function emaily_handle_verification() {
	if (!isset($_GET['emaily_verify']) || $_GET['emaily_verify'] !== '1') {
		return;
	}

	$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
	$token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

	$confirmation_page_id = carbon_get_theme_option('emaily_confirmation_page');
	$confirmation_page_url = $confirmation_page_id ? get_permalink($confirmation_page_id) : home_url('/');

	if (!$user_id || !$token) {
		wp_redirect(add_query_arg('emaily_verification_status', 'error', $confirmation_page_url));
		exit;
	}

	$user = get_user_by('ID', $user_id);
	if (!$user) {
		wp_redirect(add_query_arg('emaily_verification_status', 'error', $confirmation_page_url));
		exit;
	}

	$stored_token = get_user_meta($user_id, 'emaily_verification_token', true);
	$status = get_user_meta($user_id, 'emaily_verification_status', true);

	if ($status === 'verified') {
		wp_redirect(add_query_arg('emaily_verification_status', 'already_verified', $confirmation_page_url));
		exit;
	}

	if ($token !== $stored_token) {
		wp_redirect(add_query_arg('emaily_verification_status', 'invalid_token', $confirmation_page_url));
		exit;
	}

	// Check if the subscription has expired
	$subscription_time = get_user_meta($user_id, 'emaily_subscription_time', true);
	$expiry_time = 24 * HOUR_IN_SECONDS; // 24 hours
	if ((current_time('timestamp') - $subscription_time) > $expiry_time) {
		wp_delete_user($user_id);
		wp_redirect(add_query_arg('emaily_verification_status', 'expired', $confirmation_page_url));
		exit;
	}

	// Mark as verified and add to contact lists
	update_user_meta($user_id, 'emaily_verification_status', 'verified');
	delete_user_meta($user_id, 'emaily_verification_token');
	delete_user_meta($user_id, 'emaily_subscription_time');

	$lists = get_user_meta($user_id, 'emaily_contact_lists', true);
	$lists = is_array($lists) ? $lists : array();
	foreach ($lists as $list_id) {
		$list_users = get_post_meta($list_id, 'email_contact_list_users', true);
		$list_users = is_array($list_users) ? $list_users : array();
		if (!in_array($user->user_email, $list_users)) {
			$list_users[] = $user->user_email;
			update_post_meta($list_id, 'email_contact_list_users', $list_users);
		}
	}
	delete_user_meta($user_id, 'emaily_contact_lists');

	wp_redirect(add_query_arg('emaily_verification_status', 'success', $confirmation_page_url));
	exit;
}
add_action('init', 'emaily_handle_verification');

// Schedule cron job to clean up unverified users
function emaily_schedule_cleanup() {
	if (!wp_next_scheduled('emaily_cleanup_unverified_users')) {
		wp_schedule_event(time(), 'hourly', 'emaily_cleanup_unverified_users');
	}
}
add_action('wp', 'emaily_schedule_cleanup');

// Cleanup unverified users
function emaily_cleanup_unverified_users() {
	$users = get_users(array(
		'meta_key'     => 'emaily_verification_status',
		'meta_value'   => 'pending',
		'meta_compare' => '=',
	));

	$expiry_time = 24 * HOUR_IN_SECONDS; // 24 hours
	foreach ($users as $user) {
		$subscription_time = get_user_meta($user->ID, 'emaily_subscription_time', true);
		if ($subscription_time && (current_time('timestamp') - $subscription_time) > $expiry_time) {
			wp_delete_user($user->ID);
		}
	}
}
add_action('emaily_cleanup_unverified_users', 'emaily_cleanup_unverified_users');

// Add Audience Count column to email_contact_list
function emaily_contact_list_columns($columns) {
	$columns['audience_count'] = __('Audience Count', 'emaily');
	return $columns;
}
add_filter('manage_email_contact_list_posts_columns', 'emaily_contact_list_columns');

function emaily_contact_list_column_data($column, $post_id) {
	if ($column === 'audience_count') {
		$users = get_post_meta($post_id, 'email_contact_list_users', true);
		$count = is_array($users) ? count($users) : 0;
		echo esc_html($count);
	}
}
add_action('manage_email_contact_list_posts_custom_column', 'emaily_contact_list_column_data', 10, 2);

// Add Campaign Status and Open Rate columns to emaily_campaign
function emaily_campaign_columns($columns) {
	$columns['campaign_status'] = __('Campaign Status', 'emaily');
	$columns['open_rate'] = __('Open Rate', 'emaily');
	return $columns;
}
add_filter('manage_emaily_campaign_posts_columns', 'emaily_campaign_columns');

function emaily_campaign_column_data($column, $post_id) {
	if ($column === 'campaign_status') {
		$status = get_post_status($post_id);
		echo esc_html($status === 'publish' ? 'Sent' : 'Draft');
	}
	if ($column === 'open_rate') {
		$sent = get_post_meta($post_id, 'emaily_campaign_sent_emails', true);
		$opened = get_post_meta($post_id, 'emaily_campaign_opened_emails', true);
		$sent_count = is_array($sent) ? count($sent) : 0;
		$opened_count = is_array($opened) ? count($opened) : 0;
		if ($opened_count > 0) {
			$open_rate = esc_html(number_format(($opened_count / $sent_count) * 100, 2) . '%');
			printf('%.2f%%', $open_rate);
		} else {
			echo '0%';
		}
	}
}
add_action('manage_emaily_campaign_posts_custom_column', 'emaily_campaign_column_data', 10, 2);

// Handle email tracking endpoint
function emaily_handle_tracking() {
	if (!isset($_GET['emaily_track']) || $_GET['emaily_track'] !== 'open') {
		return;
	}

	$campaign_id = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0;
	$email = isset($_GET['email']) ? sanitize_email($_GET['email']) : '';
	$token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

	if (!$campaign_id || !$email || !$token) {
		emaily_log($campaign_id, "Tracking failed: Missing parameters (campaign_id=$campaign_id, email=$email, token=$token)");
		status_header(400);
		wp_die();
	}

	$expected_token = wp_hash("emaily_track_{$campaign_id}_{$email}", 'emaily_track');
	if ($token !== $expected_token) {
		emaily_log($campaign_id, "Tracking failed: Invalid token for email $email");
		status_header(403);
		wp_die();
	}

	$post = get_post($campaign_id);
	if (!$post || $post->post_type !== 'emaily_campaign') {
		emaily_log($campaign_id, "Tracking failed: Invalid campaign ID");
		status_header(404);
		wp_die();
	}

	$opened_emails = get_post_meta($campaign_id, 'emaily_campaign_opened_emails', true);
	$opened_emails = is_array($opened_emails) ? $opened_emails : array();

	if (!isset($opened_emails[$email])) {
		$opened_emails[$email] = current_time('mysql');
		update_post_meta($campaign_id, 'emaily_campaign_opened_emails', $opened_emails);
		emaily_log($campaign_id, "Email opened by $email");
	}

	header('Content-Type: image/png');
	echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
	wp_die();
}
add_action('init', 'emaily_handle_tracking');

// Load plugin text domain for translations
function emaily_load_textdomain() {
	load_plugin_textdomain('emaily', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'emaily_load_textdomain');

// Deactivate cron on plugin deactivation
function emaily_deactivate() {
	// Clear unverified users cleanup cron
	$timestamp = wp_next_scheduled('emaily_cleanup_unverified_users');
	if ($timestamp) {
		wp_unschedule_event($timestamp, 'emaily_cleanup_unverified_users');
	}

	// Clear all emaily_send_campaign_{$post_id} events
	$args = array(
		'post_type'      => 'emaily_campaign',
		'posts_per_page' => -1,
		'post_status'    => 'any',
	);
	$campaigns = get_posts($args);
	foreach ($campaigns as $campaign) {
		$event_hook = "emaily_send_campaign_{$campaign->ID}";
		$timestamp = wp_next_scheduled($event_hook, array($campaign->ID));
		if ($timestamp) {
			wp_unschedule_event($timestamp, $event_hook, array($campaign->ID));
		}
	}
}
register_deactivation_hook(__FILE__, 'emaily_deactivate');
