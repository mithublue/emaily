<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

function emaily_log($campaign_id, $message) {
	$log_dir = plugin_dir_path(__FILE__) . 'logs';
	if (!file_exists($log_dir)) {
		mkdir($log_dir, 0755, true);
	}
	$log_file = $log_dir . '/emaily.log';
	$timestamp = current_time('Y-m-d H:i:s');
	$log_message = "[$timestamp] Campaign ID: $campaign_id - $message\n";
	file_put_contents($log_file, $log_message, FILE_APPEND);
}

function emaily_send_campaign($campaign_id) {
	// Debug: Log that the function is triggered
	error_log("emaily_send_campaign triggered for campaign ID: $campaign_id");

	$campaign = get_post($campaign_id);
	if (!$campaign || $campaign->post_type !== 'emaily_campaign') {
		emaily_log($campaign_id, "Campaign sending failed: Invalid campaign ID");
		error_log("Campaign sending failed for ID $campaign_id: Invalid campaign");
		return;
	}

	$recipients = get_post_meta($campaign_id, 'emaily_campaign_recipients', true);
	if (empty($recipients) || !is_array($recipients)) {
		emaily_log($campaign_id, "Campaign sending failed: No recipients found");
		error_log("Campaign sending failed for ID $campaign_id: No recipients found");
		return;
	}

	$subject = $campaign->post_title;
	$content = $campaign->post_content;
	$preheader = get_post_meta($campaign_id, 'emaily_preheader', true);

	$sender_email = get_option('admin_email');
	$website_name = get_bloginfo('name');
	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
		"From: $website_name <$sender_email>",
		"Reply-To: <$sender_email>",
	);

	$all_fields = array(
		'email' => 'user_email',
		'name' => 'display_name',
		'lastname' => 'emaily_lastname',
		'middlename' => 'emaily_middlename',
		'phone' => 'emaily_phone',
		'date_of_birth' => 'emaily_date_of_birth',
		'company_name' => 'emaily_company_name',
		'industry' => 'emaily_industry',
		'department' => 'emaily_department',
		'job_title' => 'emaily_job_title',
		'state' => 'emaily_state',
		'postal_code' => 'emaily_postal_code',
		'lead_source' => 'emaily_lead_source',
		'salary' => 'emaily_salary',
		'country' => 'emaily_country',
		'city' => 'emaily_city',
		'tags' => 'emaily_tags',
	);

	foreach ($recipients as $email) {
		$user = get_user_by('email', $email);
		if (!$user) {
			emaily_log($campaign_id, "Recipient not found: $email");
			error_log("Recipient not found for campaign ID $campaign_id: $email");
			continue;
		}

		$status = get_user_meta($user->ID, 'emaily_verification_status', true);
		if ($status !== 'verified') {
			emaily_log($campaign_id, "Recipient not verified: $email");
			error_log("Recipient not verified for campaign ID $campaign_id: $email");
			continue;
		}

		// Replace placeholders in content
		$email_content = $content;
		foreach ($all_fields as $placeholder => $meta_key) {
			if ($meta_key === 'user_email') {
				$value = $user->user_email;
			} elseif ($meta_key === 'display_name') {
				$value = $user->display_name;
			} else {
				$value = get_user_meta($user->ID, $meta_key, true);
			}
			$value = $value ?: '';
			$email_content = str_replace("%$placeholder%", esc_html($value), $email_content);
		}

		// Add preheader as a hidden element
		$preheader_html = $preheader ? '<div style="display: none; max-height: 0; overflow: hidden;">' . esc_html($preheader) . '</div>' : '';
		$email_content = $preheader_html . $email_content;

		// Add tracking pixel
		$tracking_url = add_query_arg(
			array(
				'emaily_track' => 'open',
				'campaign_id' => $campaign_id,
				'email'       => $email,
				'token'       => wp_hash("emaily_track_{$campaign_id}_{$email}", 'emaily_track'),
			),
			home_url('/')
		);
		$email_content .= '<img src="' . esc_url($tracking_url) . '" width="1" height="1" alt="" style="display:none;">';

		$sent = wp_mail($email, $subject, $email_content, $headers);
		if ($sent) {
			emaily_log($campaign_id, "Email sent to $email");
			error_log("Email sent to $email for campaign ID $campaign_id");
		} else {
			emaily_log($campaign_id, "Failed to send email to $email");
			error_log("Failed to send email to $email for campaign ID $campaign_id");
		}
	}

	update_post_meta($campaign_id, 'emaily_campaign_status', 'sent');
	error_log("Campaign $campaign_id completed sending");
}

// Hook the function to the base action, which will catch dynamic suffixes
add_action('emaily_send_campaign', 'emaily_send_campaign', 10, 1);

