<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action('carbon_fields_register_fields', 'emaily_campaign_fields');
function emaily_campaign_fields() {
	Container::make('post_meta', __('Campaign Details', 'emaily'))
	         ->where('post_type', '=', 'emaily_campaign')
	         ->add_fields(array(
		         Field::make('textarea', 'emaily_preheader', __('Email Preheader', 'emaily'))
		              ->set_help_text(__('This text will appear as a preview in the email client.', 'emaily')),
		         Field::make('multiselect', 'emaily_campaign_lists', __('Select Contact Lists', 'emaily'))
		              ->add_options('emaily_get_contact_lists')
		              ->set_help_text(__('Select the contact lists to send this campaign to.', 'emaily')),
		         Field::make( 'date_time', 'emaily_campaign_schedule', __( 'Schedule Date & Time', 'your-text-domain' ) )
		              ->set_storage_format( 'Y-m-d H:i:s' ) // Optional: set how it's stored in the DB
		              ->set_help_text( 'Set when this campaign should be sent.' )
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

add_action('after_setup_theme', 'emaily_load_carbon_fields');
function emaily_load_carbon_fields() {
	\Carbon_Fields\Carbon_Fields::boot();
}

function emaily_register_campaign_post_type() {
	$labels = array(
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

	$args = array(
		'labels'             => $labels,
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

	register_post_type('emaily_campaign', $args);
}
add_action('init', 'emaily_register_campaign_post_type');

// Handle scheduling on post status transition
add_action('transition_post_status', 'emaily_handle_campaign_scheduling', 10, 3);
function emaily_handle_campaign_scheduling($new_status, $old_status, $post) {
	if ($post->post_type !== 'emaily_campaign') {
		return;
	}

	$campaign_id = $post->ID;
	$event_hook = "emaily_send_campaign_{$campaign_id}";

	// Unschedule the campaign if the new status is not 'publish'
	if ($new_status !== 'publish') {
		$timestamp = wp_next_scheduled($event_hook, array($campaign_id));
		if ($timestamp) {
			wp_unschedule_event($timestamp, $event_hook, array($campaign_id));
			error_log("Unscheduled campaign $campaign_id due to status change to $new_status");
		}
		return;
	}

	// If the new status is 'publish', schedule the campaign
	if ($new_status === 'publish' && $old_status !== 'publish') {
		$schedule_time = carbon_get_post_meta($campaign_id, 'emaily_campaign_schedule');
		if (empty($schedule_time)) {
			error_log("Campaign $campaign_id not scheduled: No schedule time set.");
			return;
		}

		$lists = carbon_get_post_meta($campaign_id, 'emaily_campaign_lists' );
		if (empty($lists)) {
			error_log("Campaign $campaign_id not scheduled: No contact lists selected.");
			return;
		}

		$recipients = array();
		foreach ($lists as $list_id) {
			$list_users = get_post_meta($list_id, 'email_contact_list_users', true);
			if (is_array($list_users)) {
				$recipients = array_merge($recipients, $list_users);
			}
		}
		$recipients = array_unique($recipients);

		if (empty($recipients)) {
			error_log("Campaign $campaign_id not scheduled: No recipients found in the selected lists.");
			return;
		}

		$schedule_timestamp = strtotime($schedule_time);
		if ($schedule_timestamp < current_time('timestamp')) {
			error_log("Campaign $campaign_id not scheduled: Schedule time $schedule_time is in the past.");
			return;
		}

		// Unschedule any existing event to avoid duplicates
		$timestamp = wp_next_scheduled($event_hook, array($campaign_id));
		if ($timestamp) {
			wp_unschedule_event($timestamp, $event_hook, array($campaign_id));
		}

		// Schedule the new event
		wp_schedule_single_event($schedule_timestamp, $event_hook, array($campaign_id));
		update_post_meta($campaign_id, 'emaily_campaign_recipients', $recipients);
		error_log("Scheduled campaign $campaign_id for $schedule_time");

		// Explicitly hook the dynamic event to trigger the emaily_send_campaign action
		add_action($event_hook, function($campaign_id) use ( $event_hook ) {
			error_log("Dynamic hook $event_hook triggered for campaign ID: $campaign_id");
			do_action('emaily_send_campaign', $campaign_id);
		}, 10, 1);
	}
}

// Debug: Log when the meta field is saved
add_action('carbon_fields_post_meta_container_saved', 'emaily_debug_schedule_save', 10, 2);
function emaily_debug_schedule_save($post_id, $container) {
	if (get_post_type($post_id) !== 'emaily_campaign') {
		return;
	}

	$schedule_time = isset($_POST['carbon_fields_container_campaign_details']['emaily_campaign_schedule'])
		? sanitize_text_field($_POST['carbon_fields_container_campaign_details']['emaily_campaign_schedule'])
		: '';

	$log_message = "Saving campaign $post_id - Schedule Time: " . ($schedule_time ?: 'Not set');
	error_log($log_message);

	// Fallback: Manually save the field if Carbon Fields fails
	if ($schedule_time) {
		$existing_value = get_post_meta($post_id, 'emaily_campaign_schedule', true);
		if ($existing_value !== $schedule_time) {
			update_post_meta($post_id, 'emaily_campaign_schedule', $schedule_time);
			error_log("Manually saved emaily_campaign_schedule for campaign $post_id: $schedule_time");
		}
	} elseif (isset($_POST['carbon_fields_container_campaign_details']['emaily_campaign_schedule']) && empty($schedule_time)) {
		// If the field is cleared, delete the meta
		delete_post_meta($post_id, 'emaily_campaign_schedule');
		error_log("Deleted emaily_campaign_schedule for campaign $post_id");
	}
}
