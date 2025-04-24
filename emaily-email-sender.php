<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

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

// Send campaign emails
function emaily_send_campaign($campaign_id) {
	// Check global lock to prevent concurrent sending
	$lock_key = 'emaily_email_sending_lock';
	$lock_duration = 300; // 5 minutes
	if (get_transient($lock_key)) {
		emaily_log($campaign_id, "Skipped sending: Another campaign is processing.");
		return;
	}

	// Set lock
	set_transient($lock_key, true, $lock_duration);

	$post = get_post($campaign_id);
	if (!$post || $post->post_type !== 'emaily_campaign' || $post->post_status !== 'publish') {
		emaily_log($campaign_id, "Invalid campaign or unpublished, stopping.");
		delete_transient($lock_key);
		return;
	}

	// Update status to sending
	update_post_meta($campaign_id, 'emaily_campaign_status', 'sending');

	// Get email queue, initialize if not set
	$email_queue = get_post_meta($campaign_id, 'emaily_campaign_email_queue', true);
	if (!is_array($email_queue) || empty($email_queue)) {
		$recipients = get_post_meta($campaign_id, 'emaily_campaign_recipients', true);
		if (!is_array($recipients) || empty($recipients)) {
			emaily_log($campaign_id, "No recipients found for campaign.");
			update_post_meta($campaign_id, 'emaily_campaign_all_emails_sent', true);
			update_post_meta($campaign_id, 'emaily_campaign_status', 'completed');
			delete_transient($lock_key);
			return;
		}
		// Convert recipients to queue format (flat array of emails)
		$email_queue = $recipients; // Use email addresses only
		update_post_meta($campaign_id, 'emaily_campaign_email_queue', $email_queue);
	}

	// Get sent and failed emails
	$sent_emails = get_post_meta($campaign_id, 'emaily_campaign_sent_emails', true);
	$sent_emails = is_array($sent_emails) ? $sent_emails : array();
	$failed_emails = get_post_meta($campaign_id, 'emaily_campaign_failed_emails', true);
	$failed_emails = is_array($failed_emails) ? $failed_emails : array();

	// Batch processing (50 emails per run)
	$batch_size = 50;
	$emails_to_send = array_slice($email_queue, 0, $batch_size);

	// Get email content
	$subject = $post->post_title;
	$preheader = carbon_get_post_meta($campaign_id, 'emaily_preheader');
	$content = apply_filters('the_content', $post->post_content); // Process Gutenberg content

	// Prepare email headers
	$from_email = carbon_get_post_meta($campaign_id, 'emaily_sender_email');
	$from_name = carbon_get_post_meta($campaign_id, 'emaily_sender_name');
	$reply_to = carbon_get_post_meta($campaign_id, 'emaily_reply_to');

	// Validate and sanitize
	$from_email = is_email($from_email) ? sanitize_email($from_email) : sanitize_email(get_option('admin_email'));
	$from_name = !empty($from_name) ? sanitize_text_field($from_name) : sanitize_text_field(get_bloginfo('name'));
	$reply_to = is_email($reply_to) ? sanitize_email($reply_to) : sanitize_email(get_option('admin_email'));

	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
		"From: {$from_name} <{$from_email}>",
		"Reply-To: {$reply_to}",
	);

	// Allow customization of headers
	$headers = apply_filters('emaily_email_headers', $headers, $campaign_id);

	// Max retries for failed emails
	$max_retries = 3;

	// Generate placeholders that will be matched in content and will be replaced by user data
	$placeholders = emaily_generate_placeholders();

	// Send emails
	foreach ($emails_to_send as $e => $email) {
		if (!is_email($email)) {
			// Invalid emails are removed from queue
			unset($emails_to_send[$e]);
			// Remove item by value from $email_queue
			$email_queue = array_diff($email_queue, array($email));
			emaily_log($campaign_id, "Invalid email skipped: $email");
			continue;
		}

		// Replace placeholders in content
		$user = get_user_by('email', $email);

		// Delete user if doesn't exist
		if (!$user) {
			emaily_log($campaign_id, "User not found for email: $email");
			unset($emails_to_send[$e]);
			// Remove item by value from $email_queue
			$email_queue = array_diff($email_queue, array($email));
			continue;
		}

		$personalized_content = emaily_replace_placeholders($content, $user, $placeholders);

		// Combine preheader and content
		$email_content = '';
		if ($preheader) {
			$email_content .= '<p style="color: #666; font-size: 12px; margin-bottom: 10px;">' . esc_html($preheader) . '</p>';
		}
		$email_content .= $personalized_content;

		// Generate tracking pixel
		$token = wp_hash("emaily_track_{$campaign_id}_{$email}", 'emaily_track');
		$tracking_url = add_query_arg(array(
			'emaily_track' => 'open',
			'campaign_id' => $campaign_id,
			'email'       => urlencode($email),
			'token'       => $token,
		), home_url('/'));
		$tracking_pixel = '<img src="' . esc_url($tracking_url) . '" width="1" height="1" alt="" />';

		// Append tracking pixel to content
		$email_content .= $tracking_pixel;

		$retry_count = isset($failed_emails[$email]) ? $failed_emails[$email] : 0;
		$result = wp_mail($email, $subject, $email_content, $headers);

		if ($result) {
			$sent_emails[] = $email;
			if (isset($failed_emails[$email])) {
				unset($failed_emails[$email]);
			}
			emaily_log($campaign_id, "Email sent to $email");
		} else {
			$retry_count++;
			if ($retry_count < $max_retries) {
				$failed_emails[$email] = $retry_count;
				emaily_log($campaign_id, "Failed to send email to $email, retry attempt $retry_count of $max_retries");
			} else {
				$failed_emails[$email] = $retry_count;
				emaily_log($campaign_id, "Email to $email failed after $max_retries retries, marked as failed");
			}
		}
	}

	// Update sent and failed emails
	update_post_meta($campaign_id, 'emaily_campaign_sent_emails', array_unique($sent_emails));
	update_post_meta($campaign_id, 'emaily_campaign_failed_emails', $failed_emails);

	// Update queue
	$remaining_emails = array_diff($email_queue, $emails_to_send);

	update_post_meta($campaign_id, 'emaily_campaign_recipients', array_values($remaining_emails));

	if (empty($remaining_emails)) {
		// All emails sent
		update_post_meta($campaign_id, 'emaily_campaign_all_emails_sent', true);
		update_post_meta($campaign_id, 'emaily_campaign_status', 'completed');
		delete_post_meta($campaign_id, 'emaily_campaign_email_queue');
		emaily_log($campaign_id, "All emails sent for campaign.");
	} else {
		// Update queue with remaining emails
		update_post_meta($campaign_id, 'emaily_campaign_email_queue', array_values($remaining_emails));
		emaily_log($campaign_id, "Processed " . count($emails_to_send) . " emails, " . count($remaining_emails) . " remaining.");
	}

	// Release lock
	delete_transient($lock_key);
}
add_action('emaily_send_campaign', 'emaily_send_campaign', 10, 1);
