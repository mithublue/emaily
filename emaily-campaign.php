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
	$args = array(
		'post_type'      => 'emaily_campaign',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'     => 'emaily_campaign_scheduled',
				'value'   => '1',
				'compare' => '=',
			),
			array(
				'key'     => '_emaily_campaign_schedule', // Carbon Fields prepends underscore
				'compare' => 'EXISTS',
			),
		),
	);

	$campaigns = get_posts($args);
	foreach ($campaigns as $campaign) {
		$schedule_datetime = carbon_get_post_meta($campaign->ID, 'emaily_campaign_schedule');
		if (!$schedule_datetime) {
			continue;
		}
		$datetime = new DateTime($schedule_datetime, new DateTimeZone(wp_timezone_string()));
		$timestamp = $datetime->getTimestamp();

		if ($timestamp <= $current_time) {
			emaily_log($campaign->ID, "Campaign scheduled time reached, triggering email sending.");
			do_action('emaily_send_campaign', $campaign->ID);
			update_post_meta($campaign->ID, 'emaily_campaign_scheduled', '0');
		}
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

// Save campaign settings
function emaily_save_campaign_settings($post_id) {
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}

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
	$recipients = array_unique($recipients);
	update_post_meta($post_id, 'emaily_campaign_recipients', $recipients);
	emaily_log($post_id, "Updated recipients: " . count($recipients) . " emails.");

	// Save schedule status
	$schedule_datetime = carbon_get_post_meta($post_id, 'emaily_campaign_schedule');
	if ($schedule_datetime) {
		$datetime = new DateTime($schedule_datetime, new DateTimeZone(wp_timezone_string()));
		$timestamp = $datetime->getTimestamp();
		if ($timestamp > current_time('timestamp')) {
			update_post_meta($post_id, 'emaily_campaign_scheduled', '1');
			update_post_meta($post_id, 'emaily_campaign_status', 'scheduled');
			emaily_log($post_id, "Campaign scheduled for $schedule_datetime.");
		} else {
			update_post_meta($post_id, 'emaily_campaign_scheduled', '0');
			update_post_meta($post_id, 'emaily_campaign_status', 'draft');
			emaily_log($post_id, "Campaign schedule in the past, not scheduled.");
		}
	} else {
		update_post_meta($post_id, 'emaily_campaign_scheduled', '0');
		update_post_meta($post_id, 'emaily_campaign_status', 'draft');
		emaily_log($post_id, "Campaign schedule cleared.");
	}
}
add_action('save_post_emaily_campaign', 'emaily_save_campaign_settings');

// Unschedule on campaign deletion
function emaily_unschedule_campaign($post_id) {
	if (get_post_type($post_id) !== 'emaily_campaign') {
		return;
	}
	update_post_meta($post_id, 'emaily_campaign_scheduled', '0');
	update_post_meta($post_id, 'emaily_campaign_status', 'canceled');
	emaily_log($post_id, "Campaign deleted, unscheduled.");
}
add_action('before_delete_post', 'emaily_unschedule_campaign');

// Validate campaign before publishing
function emaily_validate_campaign($data, $postarr) {
	if ($data['post_type'] !== 'emaily_campaign') {
		return $data;
	}

	if (in_array($data['post_status'], array('draft', 'auto-draft', 'pending'))) {
		return $data;
	}

	// Get Carbon Fields data from the post meta (since $postarr won't have it directly)
	$post_id = isset($postarr['ID']) ? $postarr['ID'] : null;
	$lists = $post_id ? carbon_get_post_meta($post_id, 'emaily_campaign_lists') : array();
	$lists = is_array($lists) ? array_map('intval', $lists) : array();

	if (empty($lists)) {
		wp_die(
			__('Error: At least one contact list must be selected.', 'emaily'),
			__('Campaign Validation Error', 'emaily'),
			array('back_link' => true)
		);
	}

	$schedule_datetime = $post_id ? carbon_get_post_meta($post_id, 'emaily_campaign_schedule') : '';
	if (empty($schedule_datetime)) {
		wp_die(
			__('Error: Schedule date and time must be set.', 'emaily'),
			__('Campaign Validation Error', 'emaily'),
			array('back_link' => true)
		);
	}

	$datetime = new DateTime($schedule_datetime, new DateTimeZone(wp_timezone_string()));
	$timestamp = $datetime->getTimestamp();
	if ($timestamp <= current_time('timestamp')) {
		wp_die(
			__('Error: Schedule must be set in the future.', 'emaily'),
			__('Campaign Validation Error', 'emaily'),
			array('back_link' => true)
		);
	}

	return $data;
}
add_filter('wp_insert_post_data', 'emaily_validate_campaign', 10, 2);

// Log function (assumed to exist, duplicated here for consistency)
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
