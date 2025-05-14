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

// Function to inline CSS for email content
function emaily_inline_css($content) {
	// Basic inline CSS rules for common Gutenberg blocks
	$inline_styles = array(
		'p' => 'margin: 0 0 1.2em 0; font-family: Georgia, \'Times New Roman\', Times, serif; font-size: 18px; line-height: 2; color: #333;',
		'h1' => 'font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; font-size: 32px; font-weight: 700; color: #222; margin: 1.5em 0 0.5em 0; line-height: 2;',
		'h2' => 'font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; font-size: 28px; font-weight: 600; color: #222; margin: 1.5em 0 0.5em 0; line-height: 2;',
		'h3' => 'font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; font-size: 24px; font-weight: 500; color: #222; margin: 1.5em 0 0.5em 0; line-height: 2;',
		'ul, ol' => 'margin: 0 0 1.2em 1.5em; padding: 0; font-family: Georgia, \'Times New Roman\', Times, serif; font-size: 18px; line-height: 2; color: #333;',
		'li' => 'margin: 0 0 0.5em 0; line-height: 2;',
		'a' => 'color: #0066cc; text-decoration: underline;',
		'img' => 'max-width: 100%; height: auto; display: block; margin: 1em auto; border-radius: 6px;',
		'blockquote' => 'border-left: 4px solid #ccc; padding-left: 1em; color: #666; font-style: italic; margin: 1.5em 0; font-family: Georgia, \'Times New Roman\', Times, serif; font-size: 18px;'
	);

	// Parse the content using DOMDocument to apply inline styles
	$doc = new DOMDocument();
	libxml_use_internal_errors(true); // Suppress warnings for malformed HTML
	$doc->loadHTML('<?xml encoding="UTF-8">' . $content); // Ensure UTF-8 encoding
	$xpath = new DOMXPath($doc);

	foreach ($inline_styles as $tag => $styles) {
		$elements = $xpath->query("//{$tag}");
		foreach ($elements as $element) {
			$existing_style = $element->getAttribute('style');
			$element->setAttribute('style', $existing_style ? $existing_style . ';' . $styles : $styles);
		}
	}

	// Extract the body content
	$body = '';
	foreach ($doc->getElementsByTagName('body')->item(0)->childNodes as $node) {
		$body .= $doc->saveHTML($node);
	}

	return $body;
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

	// Get email queue
	$email_queue = get_post_meta($campaign_id, 'emaily_campaign_email_queue', true);

	// Fetch recipients only if queue is not initialized (first run)
	if (!is_array($email_queue) || empty($email_queue)) {
		$recipients = emaily_get_recipients_from_lists($campaign_id);
		if (empty($recipients)) {
			emaily_log($campaign_id, "No recipients found for campaign.");
			update_post_meta($campaign_id, 'emaily_campaign_all_emails_sent', true);
			update_post_meta($campaign_id, 'emaily_campaign_status', 'completed');
			delete_transient($lock_key);
			return;
		}

		// Initialize queue with fetched recipients
		$email_queue = $recipients;
		update_post_meta($campaign_id, 'emaily_campaign_email_queue', $email_queue);
		emaily_log($campaign_id, "Initialized email queue with " . count($email_queue) . " recipients.");
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

	// Inline CSS for the content
	$content = emaily_inline_css($content);

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
			$email_queue = array_diff($email_queue, array($email));
			continue;
		}

		$personalized_content = emaily_replace_placeholders($content, $user, $placeholders);

		// Combine preheader and content
		$email_content = '';
		if ($preheader) {
			$email_content .= '<p style="margin: 0 0 10px 0; font-family: Georgia, \'Times New Roman\', Times, serif; font-size: 18px; color: #555;">' . esc_html($preheader) . '</p>';
		}

		// Wrap content in a table for better email client compatibility
		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
		</head>
		<body style="margin: 0; padding: 0; background-color: #f4f4f4; font-family: Georgia, 'Times New Roman', Times, serif;">
		<table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f4f4f4; padding: 10px;">
			<tr>
				<td align="center">
					<table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 800px; background-color: #ffffff; border-radius: 8px; overflow: hidden;">
						<tr>
							<td style="padding: 24px;">
								<div class="email_content"><?php echo $personalized_content; ?></div>
							</td>
						</tr>
					</table>
				</td>
			</tr>
		</table>
		</body>
		</html>
		<?php
		$email_content .= ob_get_clean();

		// Generate tracking pixel
		$token = wp_hash("emaily_track_{$campaign_id}_{$email}", 'emaily_track');
		$tracking_url = add_query_arg(array(
			'emaily_track' => 'open',
			'campaign_id' => $campaign_id,
			'email'       => urlencode($email),
			'token'       => $token,
		), home_url('/'));
		$tracking_pixel = '<img src="' . esc_url($tracking_url) . '" width="1" height="1" alt="" style="display: block;" />';

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
