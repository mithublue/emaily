<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Register custom cron schedule
function emaily_cron_schedules($schedules) {
	$schedules['five_minutes'] = array(
		'interval' => 300, // 5 minutes in seconds
		'display'  => __('Every Five Minutes', 'emaily'),
	);
	return $schedules;
}
add_filter('cron_schedules', 'emaily_cron_schedules');

// Initialize email queue when publishing
function emaily_initialize_email_queue($post_id, $post, $update) {
	if ($post->post_type !== 'emaily_campaign' || $post->post_status !== 'publish') {
		return;
	}

	// Clear existing schedule and queue
	wp_clear_scheduled_hook("emaily_send_campaign_{$post_id}");
	delete_post_meta($post_id, 'emaily_campaign_email_queue');
	delete_post_meta($post_id, 'emaily_campaign_sent_emails');
	delete_post_meta($post_id, 'emaily_campaign_failed_emails');
	delete_post_meta($post_id, 'emaily_campaign_opened_emails');
	delete_post_meta($post_id, 'emaily_campaign_all_emails_sent');
	update_post_meta($post_id, 'emaily_campaign_status', 'queued');

	// Get selected contact lists
	$selected_lists = get_post_meta($post_id, 'emaily_campaign_selected_list', true);
	if (!is_array($selected_lists) || empty($selected_lists)) {
		return;
	}

	// Collect all emails
	$all_emails = array();
	foreach ($selected_lists as $list_id) {
		$emails = get_post_meta($list_id, 'email_contact_list_users', true);
		if (is_array($emails) && !empty($emails)) {
			$all_emails = array_merge($all_emails, $emails);
		}
	}

	// Remove duplicates
	$all_emails = array_unique($all_emails);

	if (empty($all_emails)) {
		return;
	}

	// Save email queue
	update_post_meta($post_id, 'emaily_campaign_email_queue', $all_emails);

	// Schedule event if not paused
	$status = get_post_meta($post_id, 'emaily_campaign_status', true);
	if ($status !== 'paused') {
		$start_time = get_post_meta($post_id, 'emaily_campaign_start_time', true);
		if ($start_time) {
			$timestamp = strtotime($start_time);
			if ($timestamp && $timestamp > current_time('timestamp')) {
				wp_schedule_event($timestamp, 'five_minutes', "emaily_send_campaign_{$post_id}");
				emaily_log($post_id, "Scheduled event for campaign at $start_time.");
			}
		}
	}
}
add_action('wp_insert_post', 'emaily_initialize_email_queue', 10, 3);

// Email sending function
function emaily_send_campaign($post_id) {
	// Check global lock
	$lock_key = 'emaily_email_sending_lock';
	$lock_duration = 300; // 5 minutes
	if (get_transient($lock_key)) {
		emaily_log($post_id, "Skipped sending: Another campaign is processing.");
		return;
	}

	// Set lock
	set_transient($lock_key, true, $lock_duration);

	$post = get_post($post_id);
	if (!$post || $post->post_type !== 'emaily_campaign' || $post->post_status !== 'publish') {
		emaily_log($post_id, "Invalid campaign or unpublished, stopping.");
		wp_clear_scheduled_hook("emaily_send_campaign_{$post_id}");
		return;
	}

	// Update status to sending
	update_post_meta($post_id, 'emaily_campaign_status', 'sending');

	// Get email queue
	$email_queue = get_post_meta($post_id, 'emaily_campaign_email_queue', true);
	if (!is_array($email_queue) || empty($email_queue)) {
		// No emails to send, mark as complete
		update_post_meta($post_id, 'emaily_campaign_all_emails_sent', true);
		wp_clear_scheduled_hook("emaily_send_campaign_{$post_id}");
		emaily_log($post_id, "All emails sent, event unscheduled.");
		return;
	}

	// Get sent and failed emails
	$sent_emails = get_post_meta($post_id, 'emaily_campaign_sent_emails', true);
	$sent_emails = is_array($sent_emails) ? $sent_emails : array();
	$failed_emails = get_post_meta($post_id, 'emaily_campaign_failed_emails', true);
	$failed_emails = is_array($failed_emails) ? $failed_emails : array();

	// Batch processing (50 emails per run)
	$batch_size = 50;
	$emails_to_send = array_slice($email_queue, 0, $batch_size);

	// Get email content
	$subject = $post->post_title;
	$content = apply_filters('the_content', $post->post_content); // Process Gutenberg content
	$headers = array('Content-Type: text/html; charset=UTF-8');

	// Max retries for failed emails
	$max_retries = 3;

	// Send emails
	foreach ($emails_to_send as $email) {
		if (!is_email($email)) {
			emaily_log($post_id, "Invalid email skipped: $email");
			continue;
		}

		// Generate tracking pixel
		$token = wp_hash("emaily_track_{$post_id}_{$email}", 'emaily_track');
		$tracking_url = add_query_arg(array(
			'emaily_track' => 'open',
			'campaign_id' => $post_id,
			'email'       => urlencode($email),
			'token'       => $token,
		), home_url('/'));
		$tracking_pixel = '<img src="' . esc_url($tracking_url) . '" width="1" height="1" alt="" />';

		// Append tracking pixel to content
		$email_content = $content . $tracking_pixel;

		$retry_count = isset($failed_emails[$email]) ? $failed_emails[$email] : 0;
		$result = wp_mail($email, $subject, $email_content, $headers);

		if ($result) {
			$sent_emails[] = $email;
			if (isset($failed_emails[$email])) {
				unset($failed_emails[$email]);
			}
			emaily_log($post_id, "Email sent to $email");
		} else {
			$retry_count++;
			if ($retry_count < $max_retries) {
				$failed_emails[$email] = $retry_count;
				emaily_log($post_id, "Failed to send email to $email, retry attempt $retry_count of $max_retries");
			} else {
				$failed_emails[$email] = $retry_count;
				emaily_log($post_id, "Email to $email failed after $max_retries retries, marked as failed");
			}
		}
	}

	// Update sent and failed emails
	update_post_meta($post_id, 'emaily_campaign_sent_emails', array_unique($sent_emails));
	update_post_meta($post_id, 'emaily_campaign_failed_emails', $failed_emails);

	// Update queue
	$remaining_emails = array_diff($email_queue, $emails_to_send);
	if (empty($remaining_emails)) {
		// All emails sent
		update_post_meta($post_id, 'emaily_campaign_all_emails_sent', true);
		delete_post_meta($post_id, 'emaily_campaign_email_queue');
		wp_clear_scheduled_hook("emaily_send_campaign_{$post_id}");
		emaily_log($post_id, "All emails sent, event unscheduled.");
	} else {
		// Update queue with remaining emails
		update_post_meta($post_id, 'emaily_campaign_email_queue', $remaining_emails);
		emaily_log($post_id, "Processed " . count($emails_to_send) . " emails, " . count($remaining_emails) . " remaining.");
	}

	// Release lock
	delete_transient($lock_key);
}

// Register email sending action for each campaign dynamically
function emaily_register_campaign_cron($post_id, $post, $update) {
	if ($post->post_type === 'emaily_campaign' && $post->post_status === 'publish') {
		add_action("emaily_send_campaign_{$post_id}", 'emaily_send_campaign');
	}
}
add_action('wp_insert_post', 'emaily_register_campaign_cron', 10, 3);

// Logging function
function emaily_log($post_id, $message) {
	$post = get_post($post_id);
	$campaign_name = $post ? $post->post_title : 'Unknown';
	$log_dir = plugin_dir_path(__FILE__) . 'logs';
	$log_file = $log_dir . '/emaily.log';

	// Create logs directory if it doesn't exist
	if (!file_exists($log_dir)) {
		mkdir($log_dir, 0755, true);
	}

	// Append log with timestamp
	$timestamp = current_time('Y-m-d H:i:s');
	$log_entry = "[$timestamp] Campaign ID: $post_id ($campaign_name) - $message\n";
	file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// Add status metabox
function emaily_add_status_metabox() {
	add_meta_box(
		'emaily_campaign_status',
		__('Campaign Status', 'emaily'),
		'emaily_campaign_status_metabox',
		'emaily_campaign',
		'normal',
		'high'
	);
}
add_action('add_meta_boxes', 'emaily_add_status_metabox');

// Render status metabox
function emaily_campaign_status_metabox($post) {
    $queue = get_post_meta($post->ID, 'emaily_campaign_email_queue', true);
    $sent_emails = get_post_meta($post->ID, 'emaily_campaign_sent_emails', true);
    $failed_emails = get_post_meta($post->ID, 'emaily_campaign_failed_emails', true);
    $opened_emails = get_post_meta($post->ID, 'emaily_campaign_opened_emails', true);
    $all_sent = get_post_meta($post->ID, 'emaily_campaign_all_emails_sent', true);
    $start_time = get_post_meta($post->ID, 'emaily_campaign_start_time', true);
    $status = get_post_meta($post->ID, 'emaily_campaign_status', true);

    $queued_count = is_array($queue) ? count($queue) : 0;
    $sent_count = is_array($sent_emails) ? count($sent_emails) : 0;
    $failed_count = is_array($failed_emails) ? count($failed_emails) : 0;
    $opened_count = is_array($opened_emails) ? count($opened_emails) : 0;
    $total_count = $queued_count + $sent_count + $failed_count;

    $display_status = $status === 'paused' ? 'Paused' : ($all_sent ? 'Completed' : ($queued_count > 0 && $start_time && strtotime($start_time) <= current_time('timestamp') ? 'Sending' : ($queued_count > 0 ? 'Queued' : 'Not Started')));

    $can_toggle = $start_time && strtotime($start_time) > current_time('timestamp') && in_array($status, array('queued', 'paused'));

    ?>
    <div id="emaily-campaign-status-metabox">
        <p><strong><?php esc_html_e('Status:', 'emaily'); ?></strong> <?php echo esc_html($display_status); ?></p>
        <p><strong><?php esc_html_e('Total Emails:', 'emaily'); ?></strong> <?php echo esc_html($total_count); ?></p>
        <p><strong><?php esc_html_e('Sent Emails:', 'emaily'); ?></strong> <?php echo esc_html($sent_count); ?></p>
        <p><strong><?php esc_html_e('Queued Emails:', 'emaily'); ?></strong> <?php echo esc_html($queued_count); ?></p>
        <p><strong><?php esc_html_e('Failed Emails:', 'emaily'); ?></strong> <?php echo esc_html($failed_count); ?></p>
        <p><strong><?php esc_html_e('Opened Emails:', 'emaily'); ?></strong> <?php echo esc_html($opened_count); ?></p>
        <?php if ($start_time) : ?>
            <p><strong><?php esc_html_e('Scheduled Start:', 'emaily'); ?></strong> <?php echo esc_html($start_time); ?></p>
        <?php endif; ?>
        <?php if ($can_toggle) : ?>
            <p>
                <?php if ($status === 'queued') : ?>
                    <button type="button" class="button button-secondary" id="emaily-pause-campaign" data-post-id="<?php echo esc_attr($post->ID); ?>">
                        <?php esc_html_e('Pause Campaign', 'emaily'); ?>
                    </button>
                <?php elseif ($status === 'paused') : ?>
                    <button type="button" class="button button-primary" id="emaily-resume-campaign" data-post-id="<?php echo esc_attr($post->ID); ?>">
                        <?php esc_html_e('Resume Campaign', 'emaily'); ?>
                    </button>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <div id="emaily-status-message"></div>
    </div>
    <style>
        #emaily-campaign-status-metabox {
            padding: 15px;
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
        }
        #emaily-campaign-status-metabox p {
            margin: 10px 0;
            font-size: 14px;
        }
        #emaily-status-message {
            margin-top: 10px;
            padding: 10px;
            display: none;
        }
        #emaily-status-message.success {
            border: 1px solid #46b450;
            background: #f0fff0;
        }
        #emaily-status-message.error {
            border: 1px solid #dc3232;
            background: #fff4f4;
        }
    </style>
    <?php
}
