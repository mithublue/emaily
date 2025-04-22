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

add_action('admin_enqueue_scripts', 'emaily_campaign_enqueue_scripts');
function emaily_campaign_enqueue_scripts($hook) {
	global $post_type;
	if (($hook === 'post.php' || $hook === 'post-new.php') && $post_type === 'emaily_campaign') {
		wp_enqueue_script(
			'emaily-campaign-js',
			plugin_dir_url(__FILE__) . 'assets/js/campaign.js',
			array('jquery'),
			'2.0',
			true
		);
		wp_localize_script(
			'emaily-campaign-js',
			'emailyCampaignAjax',
			array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce'    => wp_create_nonce('emaily_campaign_action'),
			)
		);
	}
}

add_action('wp_ajax_emaily_schedule_campaign', 'emaily_schedule_campaign');
function emaily_schedule_campaign() {
	check_ajax_referer('emaily_campaign_action', 'nonce');

	if (!current_user_can('manage_options')) {
		wp_send_json_error(array('message' => __('Permission denied.', 'emaily')));
	}

	$campaign_id = isset($_POST['campaign_id']) ? intval($_POST['campaign_id']) : 0;
	$schedule_time = isset($_POST['schedule_time']) ? sanitize_text_field($_POST['schedule_time']) : '';

	if (!$campaign_id || !$schedule_time) {
		wp_send_json_error(array('message' => __('Invalid campaign ID or schedule time.', 'emaily')));
	}

	$campaign = get_post($campaign_id);
	if (!$campaign || $campaign->post_type !== 'emaily_campaign') {
		wp_send_json_error(array('message' => __('Invalid campaign.', 'emaily')));
	}

	$lists = get_post_meta($campaign_id, 'emaily_campaign_lists', true);
	if (empty($lists)) {
		wp_send_json_error(array('message' => __('No contact lists selected.', 'emaily')));
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
		wp_send_json_error(array('message' => __('No recipients found in the selected lists.', 'emaily')));
	}

	update_post_meta($campaign_id, 'emaily_campaign_recipients', $recipients);
	update_post_meta($campaign_id, 'emaily_campaign_schedule', $schedule_time);

	$schedule_timestamp = strtotime($schedule_time);
	if ($schedule_timestamp < current_time('timestamp')) {
		wp_send_json_error(array('message' => __('Schedule time must be in the future.', 'emaily')));
	}

	wp_schedule_single_event($schedule_timestamp, "emaily_send_campaign_{$campaign_id}", array($campaign_id));
	wp_send_json_success(array('message' => __('Campaign scheduled successfully!', 'emaily')));
}
