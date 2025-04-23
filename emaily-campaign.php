<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Load Carbon Fields
add_action('after_setup_theme', 'emaily_load_carbon_fields');
function emaily_load_carbon_fields() {
	require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php'; // Assumes Carbon Fields is installed via Composer
	\Carbon_Fields\Carbon_Fields::boot();
}

// Register the custom post type 'emaily_campaign'
function emaily_register_campaign_post_type() {
	$campaign_labels = array(
		'name'               => __('Campaigns', 'emaily'),
		'singular_name'      => __('Campaign', 'emaily'),
		'menu_name'          => __('Campaigns', 'emaily'),
		'name_admin_bar'     => __('Campaign', 'emaily'),
		'add_new'            => __('Add New', 'emaily'),
		'add_new_item'       => __('Add New Campaign', 'emaily'),
		'new_item'           => __('New Campaign', 'emaily'),
		'edit_item'          => __('Edit Campaign', 'emaily'),
		'view_item'          => __('View Campaign', 'emaily'),
		'all_items'          => __('All Campaigns', 'emaily'),
		'search_items'       => __('Search Campaigns', 'emaily'),
		'not_found'          => __('No campaigns found.', 'emaily'),
	);

	$campaign_args = array(
		'labels'             => $campaign_labels,
		'public'             => false,
		'show_ui'            => true,
		'show_in_menu'       => 'emaily',
		'query_var'          => true,
		'rewrite'            => array('slug' => 'emaily-campaign'),
		'capability_type'    => 'post',
		'has_archive'        => false,
		'hierarchical'       => false,
		'supports'           => array('title', 'editor'),
		'show_in_rest'       => true,
	);

	register_post_type('emaily_campaign', $campaign_args);
}
add_action('init', 'emaily_register_campaign_post_type');

// Schedule a recurring event to check campaigns
function emaily_schedule_campaign_checker() {
	if (!wp_next_scheduled('emaily_check_campaigns')) {
		wp_schedule_event(time(), 'every_minute', 'emaily_check_campaigns');
	}
}
add_action('init', 'emaily_schedule_campaign_checker');

// Define the every_minute schedule
function emaily_add_schedules($schedules) {
	$schedules['every_minute'] = array(
		'interval' => 60,
		'display'  => __('Every Minute', 'emaily'),
	);
	return $schedules;
}
add_filter('cron_schedules', 'emaily_add_schedules');

// Check and trigger campaigns
function emaily_check_campaigns() {
	$current_time = current_time('timestamp');
	$scheduled_campaigns = get_option('emaily_scheduled_campaigns', array());

	foreach ($scheduled_campaigns as $campaign_id => $timestamp) {
		if ($timestamp <= $current_time) {
			// Verify campaign exists and is published
			$post = get_post($campaign_id);
			if ($post && $post->post_type === 'emaily_campaign' && $post->post_status === 'publish') {
				emaily_log($campaign_id, "Campaign scheduled time reached, triggering email sending.");
				do_action('emaily_send_campaign', $campaign_id);
			} else {
				emaily_log($campaign_id, "Campaign not found or unpublished, removing from schedule.");
			}
			exit;
			// Remove from schedule
			unset($scheduled_campaigns[$campaign_id]);
		}
	}

	// Update option with remaining schedules
	if (empty($scheduled_campaigns)) {
		delete_option('emaily_scheduled_campaigns');
	} else {
		update_option('emaily_scheduled_campaigns', $scheduled_campaigns);
	}
}
add_action('emaily_check_campaigns', 'emaily_check_campaigns');

// Register Carbon Fields for campaign meta
add_action('carbon_fields_register_fields', 'emaily_campaign_fields');
function emaily_campaign_fields() {
	\Carbon_Fields\Container::make('post_meta', __('Campaign Details', 'emaily'))
	                        ->where('post_type', '=', 'emaily_campaign')
	                        ->add_fields(array(
		                        \Carbon_Fields\Field::make('textarea', 'emaily_preheader', __('Email Preheader', 'emaily'))
		                                            ->set_help_text(__('This text will appear as a preview in the email client.', 'emaily')),
		                        \Carbon_Fields\Field::make('multiselect', 'emaily_campaign_lists', __('Select Contact Lists', 'emaily'))
		                                            ->add_options('emaily_get_contact_lists')
		                                            ->set_help_text(__('Select the contact lists to send this campaign to.', 'emaily')),
		                        \Carbon_Fields\Field::make('date_time', 'emaily_campaign_schedule', __('Schedule Date & Time', 'emaily'))
		                                            ->set_storage_format('Y-m-d H:i:s')
		                                            ->set_help_text(__('Set the date and time to schedule the campaign (e.g., 2025-04-23 10:00:00). The campaign will be scheduled automatically when published.', 'emaily')),
	                        ));

	// Add help text for placeholders in the editor
	add_action('admin_footer', function() {
		if (get_current_screen()->post_type === 'emaily_campaign') {
			?>
			<script>
				jQuery(document).ready(function($) {
					if ($('#postdivrich').length) {
						$('#postdivrich').append('<p style="color: #555; font-size: 12px; margin-top: 10px;">Use placeholders like %email%, %name% in the content. These will be replaced with the recipient\'s email and name when the email is sent.</p>');
					}
				});
			</script>
			<?php
		}
	});
}

function emaily_get_contact_lists() {
	$lists = get_posts(array(
		'post_type'      => 'email_contact_list',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
	));

	$options = array();
	foreach ($lists as $list) {
		$options[$list->ID] = $list->post_title;
	}
	return $options;
}

// Replace placeholders in campaign content
function emaily_replace_placeholders($content, $email, $placeholders ) {

	//get user by email
	$user = get_user_by('email', $email);

	$replacables = [];
	foreach ( $placeholders as $placeholder ) {
		$replacables['%'.$placeholder.'%'] = emaily_get_user_info( $user, $placeholder );
	}

	return str_replace(
		array_keys($replacables),
		array_values($replacables),
		$content
	);
}

// Validate campaign meta on save
add_action('carbon_fields_post_meta_container_save', 'validate_emaily_campaign_schedule', 10, 2);
function validate_emaily_campaign_schedule($post_id, $container) {
	if (get_post_type($post_id) !== 'emaily_campaign') {
		return;
	}

	// Only validate for published posts
	$post_status = get_post_status($post_id);
	if ($post_status !== 'publish') {
		return;
	}

	// Validate contact lists
	$lists = carbon_get_post_meta($post_id, 'emaily_campaign_lists');
	$lists = is_array($lists) ? array_map('intval', $lists) : [];
	if (empty($lists)) {
		wp_die(
			__('Error: At least one contact list must be selected.', 'emaily'),
			__('Campaign Validation Error', 'emaily'),
			array('back_link' => true)
		);
	}

	// Validate schedule
	$schedule = carbon_get_post_meta($post_id, 'emaily_campaign_schedule');
	if (empty($schedule)) {
		wp_die(
			__('Error: Schedule date and time must be set.', 'emaily'),
			__('Campaign Validation Error', 'emaily'),
			array('back_link' => true)
		);
	}

	try {
		$scheduled_time = strtotime($schedule);
		$now = current_time('timestamp'); // WordPress local time
		if ($scheduled_time === false || $scheduled_time <= $now) {
			// Remove invalid schedule
			carbon_delete_post_meta($post_id, 'emaily_campaign_schedule');
			wp_die(
				__('Error: Campaign schedule must be set to a future date and time.', 'emaily'),
				__('Campaign Validation Error', 'emaily'),
				array('back_link' => true)
			);
		}
	} catch (Exception $e) {
		// Remove invalid schedule
		carbon_delete_post_meta($post_id, 'emaily_campaign_schedule');
		wp_die(
			__('Error: Invalid schedule date and time format.', 'emaily'),
			__('Campaign Validation Error', 'emaily'),
			array('back_link' => true)
		);
	}
}

// Save campaign settings and manage schedules
function emaily_save_campaign_settings($post_id) {
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}

	// Get current schedules
	$scheduled_campaigns = get_option('emaily_scheduled_campaigns', array());

	// Save recipients from selected contact lists
	$lists = carbon_get_post_meta($post_id, 'emaily_campaign_lists');
	$lists = is_array($lists) ? array_map('intval', $lists) : array();
	$recipients = array();
	foreach ($lists as $list_id) {
		$list_users = get_post_meta($list_id, 'email_contact_list_users', true);
		if (is_array($list_users)) {
			$recipients = array_merge($recipients, $list_users);
		}
	}
	$recipients = array_unique($recipients, SORT_REGULAR);
	update_post_meta($post_id, 'emaily_campaign_recipients', $recipients);
	emaily_log($post_id, "Updated recipients: " . count($recipients) . " emails.");

	// Save schedule
	$schedule_datetime = carbon_get_post_meta($post_id, 'emaily_campaign_schedule');
	$post_status = get_post_status($post_id);
	if ($schedule_datetime && $post_status === 'publish') {
		$scheduled_time = strtotime($schedule_datetime);
		if ($scheduled_time !== false && $scheduled_time > current_time('timestamp')) {
			$scheduled_campaigns[$post_id] = $scheduled_time;
			update_post_meta($post_id, 'emaily_campaign_status', 'scheduled');
			emaily_log($post_id, "Campaign scheduled for $schedule_datetime.");
		} else {
			unset($scheduled_campaigns[$post_id]);
			update_post_meta($post_id, 'emaily_campaign_status', 'draft');
			emaily_log($post_id, "Campaign schedule invalid or in the past, not scheduled.");
		}
	} else {
		unset($scheduled_campaigns[$post_id]);
		update_post_meta($post_id, 'emaily_campaign_status', 'draft');
		emaily_log($post_id, "Campaign schedule cleared or not published.");
	}

	// Update or delete option
	if (empty($scheduled_campaigns)) {
		delete_option('emaily_scheduled_campaigns');
	} else {
		update_option('emaily_scheduled_campaigns', $scheduled_campaigns);
	}
}
add_action('save_post_emaily_campaign', 'emaily_save_campaign_settings');

// Handle campaign status changes (publish, unpublish, etc.)
function emaily_handle_campaign_status($new_status, $old_status, $post) {
	if ($post->post_type !== 'emaily_campaign') {
		return;
	}

	$scheduled_campaigns = get_option('emaily_scheduled_campaigns', array());

	if ($new_status !== 'publish') {
		// Remove from schedule if unpublished
		if (isset($scheduled_campaigns[$post->ID])) {
			unset($scheduled_campaigns[$post->ID]);
			update_post_meta($post->ID, 'emaily_campaign_status', 'draft');
			emaily_log($post->ID, "Campaign unpublished, removed from schedule.");
		}
	} elseif ($new_status === 'publish' && $old_status !== 'publish') {
		// Add to schedule if newly published
		$schedule_datetime = carbon_get_post_meta($post->ID, 'emaily_campaign_schedule');
		if ($schedule_datetime) {
			$scheduled_time = strtotime($schedule_datetime);
			if ($scheduled_time !== false && $scheduled_time > current_time('timestamp')) {
				$scheduled_campaigns[$post->ID] = $scheduled_time;
				update_post_meta($post->ID, 'emaily_campaign_status', 'scheduled');
				emaily_log($post->ID, "Campaign published, scheduled for $schedule_datetime.");
			}
		}
	}

	// Update or delete option
	if (empty($scheduled_campaigns)) {
		delete_option('emaily_scheduled_campaigns');
	} else {
		update_option('emaily_scheduled_campaigns', $scheduled_campaigns);
	}
}
add_action('transition_post_status', 'emaily_handle_campaign_status', 10, 3);

// Unschedule on campaign deletion
function emaily_unschedule_campaign($post_id) {
	if (get_post_type($post_id) !== 'emaily_campaign') {
		return;
	}

	$scheduled_campaigns = get_option('emaily_scheduled_campaigns', array());
	if (isset($scheduled_campaigns[$post_id])) {
		unset($scheduled_campaigns[$post_id]);
		if (empty($scheduled_campaigns)) {
			delete_option('emaily_scheduled_campaigns');
		} else {
			update_option('emaily_scheduled_campaigns', $scheduled_campaigns);
		}
	}

	update_post_meta($post_id, 'emaily_campaign_status', 'canceled');
	emaily_log($post_id, "Campaign deleted, unscheduled.");
}
add_action('before_delete_post', 'emaily_unschedule_campaign');

// Log function
if (!function_exists('emaily_log')) {
	function emaily_log($campaign_id, $message) {
		$log_dir = plugin_dir_path(__FILE__) . 'logs/';
		if (!file_exists($log_dir)) {
			mkdir($log_dir, 0755, true);
		}
		$log_file = $log_dir . 'emaily.log';
		$timestamp = current_time('mysql');
		$log_message = "[$timestamp] Campaign ID $campaign_id: $message\n";
		file_put_contents($log_file, $log_message, FILE_APPEND);
	}
}
