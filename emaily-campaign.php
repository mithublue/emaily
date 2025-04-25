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
			// Remove from schedule
			//here it should be checked , if the queue empty or not, if empty then...
			$email_queue = get_post_meta($campaign_id, 'emaily_campaign_email_queue', true);
			if (!is_array($email_queue) || empty($email_queue)) {
				unset($scheduled_campaigns[$campaign_id]);
			}
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
		                        \Carbon_Fields\Field::make('text', 'emaily_sender_email', __('Sender Email', 'emaily'))
		                                            ->set_attribute('type', 'email')
		                                            ->set_default_value(get_option('admin_email'))
		                                            ->set_help_text(__('The email address from which the campaign will be sent.', 'emaily'))
		                                            ->set_required(true),
		                        \Carbon_Fields\Field::make('text', 'emaily_sender_name', __('Sender Name', 'emaily'))
		                                            ->set_default_value(get_bloginfo('name'))
		                                            ->set_help_text(__('The name displayed as the sender of the campaign.', 'emaily'))
		                                            ->set_required(true),
		                        \Carbon_Fields\Field::make('text', 'emaily_reply_to', __('Reply-To Email', 'emaily'))
		                                            ->set_attribute('type', 'email')
		                                            ->set_default_value(get_option('admin_email'))
		                                            ->set_help_text(__('The email address to which replies will be sent.', 'emaily'))
		                                            ->set_required(true),
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

// Add Test Email metabox
add_action('add_meta_boxes', 'emaily_add_test_email_metabox');
function emaily_add_test_email_metabox() {
	add_meta_box(
		'emaily_test_email',
		__('Test Email', 'emaily'),
		'emaily_test_email_metabox_callback',
		'emaily_campaign',
		'side',
		'high'
	);
}

function emaily_test_email_metabox_callback($post) {
	?>
	<p>
		<button type="button" id="emaily-test-email-button" class="button button-secondary">
			<?php _e('Send Test Email', 'emaily'); ?>
		</button>
	</p>
	<p class="description">
		<?php _e('Click to send a test email to verify the campaign.', 'emaily'); ?>
	</p>
	<?php
}

// Add Update Recipients metabox
add_action('add_meta_boxes', 'emaily_add_update_recipients_metabox');
function emaily_add_update_recipients_metabox() {
	add_meta_box(
		'emaily_update_recipients',
		__('Update Recipients', 'emaily'),
		'emaily_update_recipients_metabox_callback',
		'emaily_campaign',
		'side',
		'high'
	);
}

function emaily_update_recipients_metabox_callback($post) {
	?>
	<p>
		<button type="button" id="emaily-update-recipients-button" class="button button-secondary">
			<?php _e('Update Recipients', 'emaily'); ?>
		</button>
	</p>
	<p class="description">
		<?php _e('Click to update the recipient list from selected contact lists.', 'emaily'); ?>
	</p>
	<?php
}

// Add View Recipients metabox
add_action('add_meta_boxes', 'emaily_add_view_recipients_metabox');
function emaily_add_view_recipients_metabox() {
	add_meta_box(
		'emaily_view_recipients',
		__('View Recipients', 'emaily'),
		'emaily_view_recipients_metabox_callback',
		'emaily_campaign',
		'side',
		'high'
	);
}

function emaily_view_recipients_metabox_callback($post) {
	?>
	<p>
		<button type="button" id="emaily-view-recipients-button" class="button button-secondary">
			<?php _e('View Recipients', 'emaily'); ?>
		</button>
	</p>
	<p class="description">
		<?php _e('Click to view the recipient list for this campaign.', 'emaily'); ?>
	</p>
	<?php
}

// Enqueue JavaScript for Test Email
add_action('admin_enqueue_scripts', 'emaily_enqueue_test_email_scripts');
function emaily_enqueue_test_email_scripts($hook) {
	if (get_current_screen()->post_type !== 'emaily_campaign' || !in_array($hook, ['post.php', 'post-new.php'])) {
		return;
	}

	wp_enqueue_script(
		'emaily-test-email',
		plugin_dir_url(__FILE__) . '/assets/js/emaily-test-email.js',
		['jquery'],
		'1.0',
		true
	);

	wp_localize_script(
		'emaily-test-email',
		'emailyTestEmail',
		[
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('emaily_test_email_nonce'),
			'post_id'  => get_the_ID(),
			'strings'  => [
				'prompt'         => __('Enter the email address to send the test email to:', 'emaily'),
				'required'       => __('Email address is required.', 'emaily'),
				'error_prefix'   => __('Error: ', 'emaily'),
				'error_generic'  => __('An error occurred while sending the test email.', 'emaily'),
			],
		]
	);
}

// Enqueue JavaScript for Update Recipients
add_action('admin_enqueue_scripts', 'emaily_enqueue_update_recipients_scripts');
function emaily_enqueue_update_recipients_scripts($hook) {
	if (get_current_screen()->post_type !== 'emaily_campaign' || !in_array($hook, ['post.php', 'post-new.php'])) {
		return;
	}

	wp_enqueue_script(
		'emaily-update-recipients',
		plugin_dir_url(__FILE__) . 'assets/js/emaily-update-recipients.js',
		['jquery'],
		'1.0',
		true
	);

	wp_localize_script(
		'emaily-update-recipients',
		'emailyUpdateRecipients',
		[
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('emaily_update_recipients_nonce'),
			'post_id'  => get_the_ID(),
			'strings'  => [
				'confirm'        => __('Are you sure you want to update the recipient list? This will overwrite the existing recipients.', 'emaily'),
				'error_generic'  => __('An error occurred while updating the recipient list.', 'emaily'),
			],
		]
	);
}

// Enqueue JavaScript for View Recipients
add_action('admin_enqueue_scripts', 'emaily_enqueue_view_recipients_scripts');
function emaily_enqueue_view_recipients_scripts($hook) {
	if (get_current_screen()->post_type !== 'emaily_campaign' || !in_array($hook, ['post.php', 'post-new.php'])) {
		return;
	}

	// Enqueue jQuery UI for dialog
	wp_enqueue_script('jquery-ui-dialog');
	wp_enqueue_style('wp-jquery-ui-dialog');

	wp_enqueue_script(
		'emaily-recipient-popup',
		plugin_dir_url(__FILE__) . 'assets/js/emaily-recipient-popup.js',
		['jquery', 'jquery-ui-dialog'],
		'1.0',
		true
	);

	wp_localize_script(
		'emaily-recipient-popup',
		'emailyViewRecipients',
		[
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('emaily_view_recipients_nonce'),
			'post_id'  => get_the_ID(),
			'strings'  => [
				'title'          => __('Recipient List', 'emaily'),
				'error_generic'  => __('An error occurred while fetching the recipient list.', 'emaily'),
				'close'          => __('Close', 'emaily'),
			],
		]
	);
}

// AJAX handler for sending test email
add_action('wp_ajax_emaily_send_test_email', 'emaily_send_test_email');
function emaily_send_test_email() {
	check_ajax_referer('emaily_test_email_nonce', 'nonce');

	$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
	$test_email = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';

	if (!$post_id || get_post_type($post_id) !== 'emaily_campaign') {
		wp_send_json_error(__('Invalid campaign.', 'emaily'));
	}

	if (!is_email($test_email)) {
		wp_send_json_error(__('Please enter a valid email address.', 'emaily'));
	}

	$post = get_post($post_id);
	if (!$post) {
		wp_send_json_error(__('Campaign not found.', 'emaily'));
	}

	// Prepare email
	$subject = $post->post_title;
	$preheader = carbon_get_post_meta($post_id, 'emaily_preheader');
	$content = apply_filters('the_content', $post->post_content);

	// Prepare headers
	$from_email = carbon_get_post_meta($post_id, 'emaily_sender_email');
	$from_name = carbon_get_post_meta($post_id, 'emaily_sender_name');
	$reply_to = carbon_get_post_meta($post_id, 'emaily_reply_to');

	$from_email = is_email($from_email) ? sanitize_email($from_email) : sanitize_email(get_option('admin_email'));
	$from_name = !empty($from_name) ? sanitize_text_field($from_name) : sanitize_text_field(get_bloginfo('name'));
	$reply_to = is_email($reply_to) ? sanitize_email($reply_to) : sanitize_email(get_option('admin_email'));

	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
		"From: {$from_name} <{$from_email}>",
		"Reply-To: {$reply_to}",
	);

	// Replace placeholders
	$user = get_user_by('email', $test_email);
	$placeholders = emaily_generate_placeholders();
	$personalized_content = emaily_replace_placeholders($content, $user, $placeholders);

	// Combine preheader and content
	$email_content = '';
	if ($preheader) {
		$email_content .= '<p style="color: #666; font-size: 12px; margin-bottom: 10px;">' . esc_html($preheader) . '</p>';
	}
	$email_content .= $personalized_content;

	// Generate tracking pixel
	$token = wp_hash("emaily_track_{$post_id}_{$test_email}", 'emaily_track');
	$tracking_url = add_query_arg(array(
		'emaily_track' => 'open',
		'campaign_id' => $post_id,
		'email'       => urlencode($test_email),
		'token'       => $token,
	), home_url('/'));
	$tracking_pixel = '<img src="' . esc_url($tracking_url) . '" width="1" height="1" alt="" />';

	// Append tracking pixel
	$email_content .= $tracking_pixel;

	// Send email
	$result = wp_mail($test_email, $subject, $email_content, $headers);

	if ($result) {
		emaily_log($post_id, "Test email sent to $test_email");
		wp_send_json_success(__('Test email sent successfully!', 'emaily'));
	} else {
		emaily_log($post_id, "Failed to send test email to $test_email");
		wp_send_json_error(__('Failed to send test email.', 'emaily'));
	}
}

// AJAX handler for updating recipients
add_action('wp_ajax_emaily_update_recipients', 'emaily_update_recipients');
function emaily_update_recipients() {
	check_ajax_referer('emaily_update_recipients_nonce', 'nonce');

	$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

	if (!$post_id || get_post_type($post_id) !== 'emaily_campaign') {
		wp_send_json_error(__('Invalid campaign.', 'emaily'));
	}

	$post = get_post($post_id);
	if (!$post) {
		wp_send_json_error(__('Campaign not found.', 'emaily'));
	}

	// Get selected contact lists
	$lists = carbon_get_post_meta($post_id, 'emaily_campaign_lists');
	$lists = is_array($lists) ? array_map('intval', $lists) : [];

	$recipients = [];
	foreach ($lists as $list_id) {
		$list_emails = get_post_meta($list_id, 'email_contact_list_users', true);
		if (is_array($list_emails)) {
			$recipients = array_merge($recipients, $list_emails);
		}
	}

	// Ensure unique numeric array
	$recipients = array_values(array_unique($recipients));
	$recipient_count = count($recipients);

	// Update recipients meta
	update_post_meta($post_id, 'emaily_campaign_recipients', $recipients);
	emaily_log($post_id, "Recipient list updated: $recipient_count emails.");

	wp_send_json_success([
		'message' => sprintf(
			_n(
				'Recipient list updated: %d email.',
				'Recipient list updated: %d emails.',
				$recipient_count,
				'emaily'
			),
			$recipient_count
		),
		'count' => $recipient_count,
	]);
}

// AJAX handler for viewing recipients
add_action('wp_ajax_emaily_get_recipients', 'emaily_get_recipients');
function emaily_get_recipients() {
	check_ajax_referer('emaily_view_recipients_nonce', 'nonce');

	$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
	$page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
	$per_page = 10;

	if (!$post_id || get_post_type($post_id) !== 'emaily_campaign') {
		wp_send_json_error(__('Invalid campaign.', 'emaily'));
	}

	$post = get_post($post_id);
	if (!$post) {
		wp_send_json_error(__('Campaign not found.', 'emaily'));
	}

	// Get recipients
	$recipients = get_post_meta($post_id, 'emaily_campaign_recipients', true);
	$recipients = is_array($recipients) ? $recipients : [];
	$total_recipients = count($recipients);

	// Pagination
	$total_pages = max(1, ceil($total_recipients / $per_page));
	$page = min($page, $total_pages);
	$offset = ($page - 1) * $per_page;
	$paged_recipients = array_slice($recipients, $offset, $per_page);

	// Build HTML
	ob_start();
	?>
	<div class="emaily-recipient-popup-content">
		<p><strong><?php printf(_n('Total Recipient: %d', 'Total Recipients: %d', $total_recipients, 'emaily'), $total_recipients); ?></strong></p>
		<?php if (empty($recipients)): ?>
			<p><?php _e('No recipients found for this campaign.', 'emaily'); ?></p>
		<?php else: ?>
			<table class="widefat striped">
				<thead>
				<tr>
					<th><?php _e('Email', 'emaily'); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ($paged_recipients as $email): ?>
					<tr>
						<td><?php echo esc_html($email); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="emaily-pagination" style="margin-top: 10px; text-align: center;">
				<button class="button emaily-prev-page" <?php echo $page <= 1 ? 'disabled' : ''; ?> data-page="<?php echo $page - 1; ?>">
					<?php _e('Previous', 'emaily'); ?>
				</button>
				<span style="margin: 0 10px;"><?php printf(__('Page %d of %d', 'emaily'), $page, $total_pages); ?></span>
				<button class="button emaily-next-page" <?php echo $page >= $total_pages ? 'disabled' : ''; ?> data-page="<?php echo $page + 1; ?>">
					<?php _e('Next', 'emaily'); ?>
				</button>
			</div>
		<?php endif; ?>
	</div>
	<?php
	$html = ob_get_clean();

	emaily_log($post_id, "Fetched recipients for page $page: " . count($paged_recipients) . " emails displayed.");

	wp_send_json_success(['html' => $html]);
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
function emaily_replace_placeholders($content, $user, $placeholders) {
	$replacables = [];
	foreach ($placeholders as $placeholder) {
		$replacables['%'.$placeholder.'%'] = emaily_get_user_info($user, $placeholder);
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
	/*$schedule = carbon_get_post_meta($post_id, 'emaily_campaign_schedule');
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
	}*/
}

// Save campaign settings and manage schedules
function emaily_save_campaign_settings($post_id, $post) {

	if (get_post_type($post_id) !== 'emaily_campaign') {
		return;
	}

	//if campaign is already completed, do not allow to save
	if ( get_post_meta( $post_id, 'emaily_campaign_status', true ) == 'completed' ) {
		wp_die(
			__('Campaign has run already', 'emaily'),
			__('Campaign Validation Error', 'emaily'),
			array('back_link' => true)
		);
	}

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
			foreach ($list_users as $email ) {
				if (is_email($email)) {
					$recipients[] = $email;
				}
			}
		}
	}
	$recipients = array_unique($recipients);
	update_post_meta($post_id, 'emaily_campaign_recipients', $recipients);
	emaily_log($post_id, "Updated recipients: " . count($recipients) . " emails.");

	// Save schedule
	$schedule_datetime = carbon_get_post_meta($post->ID, 'emaily_campaign_schedule');
	logger('$schedule_datetime',$schedule_datetime);
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

	//schedule post if publish, unschedule post if unpublished
	if ($post_status !== 'publish') {
		// Remove from schedule if unpublished
		if (isset($scheduled_campaigns[$post_id])) {
			unset($scheduled_campaigns[$post_id]);
			update_post_meta($post->ID, 'emaily_campaign_status', 'draft');
			emaily_log($post->ID, "Campaign unpublished, removed from schedule.");
		} elseif ($post_status === 'publish') {
			// Add to schedule if newly published
			if ($schedule_datetime) {
				$scheduled_time = strtotime($schedule_datetime);
				if ($scheduled_time !== false && $scheduled_time > current_time('timestamp')) {
					$scheduled_campaigns[$post->ID] = $scheduled_time;
					update_post_meta($post->ID, 'emaily_campaign_status', 'scheduled');
					emaily_log($post->ID, "Campaign published, scheduled for $schedule_datetime.");
				}
			}
		}
	}

	if (empty($scheduled_campaigns)) {
		delete_option('emaily_scheduled_campaigns');
	} else {
		update_option('emaily_scheduled_campaigns', $scheduled_campaigns);
	}
}
add_action('save_post', 'emaily_save_campaign_settings', 999, 2 );
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
