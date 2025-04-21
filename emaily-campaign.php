<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Register the custom post type 'emaily_campaign'
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
		'show_in_menu'       => 'emaily', // Nest under Emaily menu
		'query_var'          => true,
		'rewrite'            => array('slug' => 'emaily-campaign'),
		'capability_type'    => 'post',
		'has_archive'        => false,
		'hierarchical'       => false,
		'supports'           => array('title', 'editor'),
		'show_in_rest'       => true, // Enable Gutenberg editor
	);

	register_post_type('emaily_campaign', $args);
}
add_action('init', 'emaily_register_campaign_post_type');

// Enqueue datetime picker scripts and styles
function emaily_campaign_enqueue_scripts($hook) {
	global $post_type;
	if (($hook === 'post.php' || $hook === 'post-new.php') && $post_type === 'emaily_campaign') {
		// Enqueue jQuery UI Datepicker (included in WordPress)
		wp_enqueue_script('jquery-ui-datepicker');
		// Enqueue jQuery UI styles (included in WordPress)
		wp_enqueue_style('jquery-ui');
		// Enqueue custom script for datepicker and pause/resume
		wp_enqueue_script(
			'emaily-campaign-js',
			plugin_dir_url(__FILE__) . 'assets/js/campaign.js',
			array('jquery', 'jquery-ui-datepicker'),
			'1.6',
			true
		);
		wp_localize_script(
			'emaily-campaign-js',
			'emailyCampaignAjax',
			array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce'    => wp_create_nonce('emaily_campaign_status'),
				'error_message' => __('An error occurred.', 'emaily'), // Localized error message
			)
		);
	}
}
add_action('admin_enqueue_scripts', 'emaily_campaign_enqueue_scripts');

// Add metabox to emaily_campaign edit page
function emaily_add_campaign_metabox() {
	add_meta_box(
		'emaily_campaign_lists',
		__('Campaign Settings', 'emaily'),
		'emaily_campaign_lists_metabox',
		'emaily_campaign',
		'side',
		'high'
	);
}
add_action('add_meta_boxes', 'emaily_add_campaign_metabox');

// Render the campaign metabox
function emaily_campaign_lists_metabox($post) {
    wp_nonce_field('emaily_campaign_lists_nonce', 'emaily_campaign_lists_nonce');

    // Get all email_contact_list posts
    $contact_lists = get_posts(array(
        'post_type'      => 'email_contact_list',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ));

    // Get saved contact list IDs
    $selected_lists = get_post_meta($post->ID, 'emaily_campaign_selected_list', true);
    $selected_lists = is_array($selected_lists) ? $selected_lists : array();

    // Get saved campaign start time
    $start_time = get_post_meta($post->ID, 'emaily_campaign_start_time', true);
    $date = $start_time ? date('Y-m-d', strtotime($start_time)) : '';
    $hour = $start_time ? date('H', strtotime($start_time)) : '';
    $minute = $start_time ? date('i', strtotime($start_time)) : '';

    ?>
    <div id="emaily-campaign-lists-metabox">
        <p><strong><?php esc_html_e('Select Contact Lists', 'emaily'); ?></strong></p>
        <p><?php esc_html_e('Select the contact lists for this campaign (required):', 'emaily'); ?></p>
        <?php if (!empty($contact_lists)) : ?>
            <?php foreach ($contact_lists as $list) : ?>
                <label>
                    <input type="checkbox" name="emaily_campaign_lists[]" value="<?php echo esc_attr($list->ID); ?>"
                        <?php checked(in_array($list->ID, $selected_lists)); ?> />
                    <?php echo esc_html($list->post_title); ?>
                </label><br />
            <?php endforeach; ?>
        <?php else : ?>
            <p><?php esc_html_e('No contact lists found. Create some lists first.', 'emaily'); ?></p>
        <?php endif; ?>

        <p><strong><?php esc_html_e('Schedule the time, when the campaign will start (required)', 'emaily'); ?></strong></p>
        <label><?php esc_html_e('Date:', 'emaily'); ?></label>
        <input type="text" name="emaily_campaign_date" id="emaily_campaign_date" value="<?php echo esc_attr($date); ?>" class="regular-text" /><br />
        <label><?php esc_html_e('Time:', 'emaily'); ?></label>
        <select name="emaily_campaign_hour" id="emaily_campaign_hour">
            <option value=""><?php esc_html_e('Hour', 'emaily'); ?></option>
            <?php for ($i = 0; $i <= 23; $i++) : ?>
                <option value="<?php echo esc_attr(sprintf('%02d', $i)); ?>" <?php selected($hour, sprintf('%02d', $i)); ?>>
                    <?php echo esc_html(sprintf('%02d', $i)); ?>
                </option>
            <?php endfor; ?>
        </select>
        <select name="emaily_campaign_minute" id="emaily_campaign_minute">
            <option value=""><?php esc_html_e('Minute', 'emaily'); ?></option>
            <?php for ($i = 0; $i < 60; $i += 5) : ?>
                <option value="<?php echo esc_attr(sprintf('%02d', $i)); ?>" <?php selected($minute, sprintf('%02d', $i)); ?>>
                    <?php echo esc_html(sprintf('%02d', $i)); ?>
                </option>
            <?php endfor; ?>
        </select>
    </div>
    <style>
        #emaily-campaign-lists-metabox {
            padding: 15px;
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
        }
        #emaily-campaign-lists-metabox label {
            display: block;
            margin: 8px 0;
            font-size: 14px;
        }
        #emaily-campaign-lists-metabox p {
            margin: 15px 0 8px;
            font-size: 14px;
        }
        #emaily_campaign_date,
        #emaily_campaign_hour,
        #emaily_campaign_minute {
            width: 100%;
            margin: 8px 0;
            padding: 6px;
            background: #fff;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        #emaily_campaign_date:focus,
        #emaily_campaign_hour:focus,
        #emaily_campaign_minute:focus {
            border-color: #007cba;
            box-shadow: 0 0 0 1px #007cba;
            outline: none;
        }
        .ui-datepicker {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            font-size: 14px;
        }
        .ui-datepicker-header {
            background: #f0f0f1;
            border-bottom: 1px solid #ccd0d4;
        }
        .ui-datepicker-calendar td a,
        .ui-datepicker-calendar td span {
            background: #fff;
            text-align: center;
            padding: 5px;
        }
        .ui-datepicker-calendar .ui-state-active {
            background: #007cba;
            color: #fff;
        }
        .ui-datepicker-calendar .ui-state-hover {
            background: #e5f5fa;
            color: #000;
        }
    </style>
    <?php
}

// Save campaign settings
function emaily_save_campaign_lists($post_id) {
    // Check if this is an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Verify nonce
    if (!isset($_POST['emaily_campaign_lists_nonce']) || !wp_verify_nonce($_POST['emaily_campaign_lists_nonce'], 'emaily_campaign_lists_nonce')) {
        return;
    }

    // Check user permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save selected contact lists
    $selected_lists = isset($_POST['emaily_campaign_lists']) ? array_map('intval', $_POST['emaily_campaign_lists']) : array();
    update_post_meta($post_id, 'emaily_campaign_selected_list', $selected_lists);

    // Save campaign start time
    $date = isset($_POST['emaily_campaign_date']) ? sanitize_text_field($_POST['emaily_campaign_date']) : '';
    $hour = isset($_POST['emaily_campaign_hour']) ? sanitize_text_field($_POST['emaily_campaign_hour']) : '';
    $minute = isset($_POST['emaily_campaign_minute']) ? sanitize_text_field($_POST['emaily_campaign_minute']) : '';

    if ($date && $hour !== '' && $minute !== '') {
        // Combine date and time
        $datetime_str = "$date $hour:$minute:00";
        // Validate datetime format (expected: Y-m-d H:i:s)
        $datetime = DateTime::createFromFormat('Y-m-d H:i:s', $datetime_str);
        if ($datetime && $datetime->format('Y-m-d H:i:s') === $datetime_str) {
            update_post_meta($post_id, 'emaily_campaign_start_time', $datetime_str);
        } else {
            // Clear meta if invalid
            delete_post_meta($post_id, 'emaily_campaign_start_time');
        }
    } else {
        // Clear meta if incomplete
        delete_post_meta($post_id, 'emaily_campaign_start_time');
    }
}
add_action('save_post_emaily_campaign', 'emaily_save_campaign_lists');

// Validate required fields before publishing
function emaily_validate_campaign_fields($data, $postarr) {
    if ($data['post_type'] !== 'emaily_campaign') {
        return $data;
    }

    // Allow drafts without validation
    if (in_array($data['post_status'], array('draft', 'auto-draft', 'pending'))) {
        return $data;
    }

    // Check contact lists
    $selected_lists = isset($postarr['emaily_campaign_lists']) ? array_map('intval', $postarr['emaily_campaign_lists']) : array();
    if (empty($selected_lists)) {
        wp_die(
            __('Error: At least one contact list must be selected to publish the campaign.', 'emaily'),
            __('Campaign Validation Error', 'emaily'),
            array('back_link' => true)
        );
    }

    // Check datetime
    $date = isset($postarr['emaily_campaign_date']) ? sanitize_text_field($postarr['emaily_campaign_date']) : '';
    $hour = isset($postarr['emaily_campaign_hour']) ? sanitize_text_field($_POST['emaily_campaign_hour']) : '';
    $minute = isset($postarr['emaily_campaign_minute']) ? sanitize_text_field($_POST['emaily_campaign_minute']) : '';

    if (!$date || $hour === '' || $minute === '') {
        wp_die(
            __('Error: A valid start time must be set to publish the campaign.', 'emaily'),
            __('Campaign Validation Error', 'emaily'),
            array('back_link' => true)
        );
    }

    $datetime_str = "$date $hour:$minute:00";
    $datetime = DateTime::createFromFormat('Y-m-d H:i:s', $datetime_str);
    if (!$datetime || $datetime->format('Y-m-d H:i:s') !== $datetime_str) {
        wp_die(
            __('Error: The selected start time is invalid.', 'emaily'),
            __('Campaign Validation Error', 'emaily'),
            array('back_link' => true)
        );
    }

    // Ensure future date
    $now = new DateTime('now', new DateTimeZone('UTC'));
    if ($datetime <= $now) {
        wp_die(
            __('Error: The campaign start time must be in the future.', 'emaily'),
            __('Campaign Validation Error', 'emaily'),
            array('back_link' => true)
        );
    }

    return $data;
}
add_filter('wp_insert_post_data', 'emaily_validate_campaign_fields', 10, 2);

// Cleanup cron events when campaign is unpublished or deleted
function emaily_cleanup_campaign($post_id) {
    $post = get_post($post_id);
    if ($post->post_type !== 'emaily_campaign') {
        return;
    }

    // Clear cron event if unpublished or deleted
    if ($post->post_status !== 'publish') {
        wp_clear_scheduled_hook("emaily_send_campaign_{$post_id}");
        delete_post_meta($post_id, 'emaily_campaign_email_queue');
        delete_post_meta($post_id, 'emaily_campaign_sent_emails');
        delete_post_meta($post_id, 'emaily_campaign_failed_emails');
        delete_post_meta($post_id, 'emaily_campaign_all_emails_sent');
        delete_post_meta($post_id, 'emaily_campaign_status');
    }
}
add_action('save_post_emaily_campaign', 'emaily_cleanup_campaign');
add_action('trashed_post', 'emaily_cleanup_campaign');
add_action('deleted_post', 'emaily_cleanup_campaign');

// Handle AJAX pause/resume
function emaily_toggle_campaign_status() {
    check_ajax_referer('emaily_campaign_status', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'emaily')));
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $action = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';

    if ($post_id === 0 || !in_array($action, array('pause', 'resume'))) {
        wp_send_json_error(array('message' => __('Invalid request.', 'emaily')));
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'emaily_campaign' || $post->post_status !== 'publish') {
        wp_send_json_error(array('message' => __('Invalid campaign.', 'emaily')));
    }

    $start_time = get_post_meta($post_id, 'emaily_campaign_start_time', true);
    $current_status = get_post_meta($post_id, 'emaily_campaign_status', true);
    $now = current_time('timestamp');

    if (strtotime($start_time) <= $now) {
        wp_send_json_error(array('message' => __('Cannot pause/resume: Campaign has started.', 'emaily')));
    }

    if ($action === 'pause' && $current_status === 'queued') {
        update_post_meta($post_id, 'emaily_campaign_status', 'paused');
        wp_clear_scheduled_hook("emaily_send_campaign_{$post_id}");
        wp_send_json_success(array('message' => __('Campaign paused.', 'emaily'), 'status' => 'Paused'));
    } elseif ($action === 'resume' && $current_status === 'paused') {
        update_post_meta($post_id, 'emaily_campaign_status', 'queued');
        wp_schedule_event(strtotime($start_time), 'five_minutes', "emaily_send_campaign_{$post_id}");
        wp_send_json_success(array('message' => __('Campaign resumed.', 'emaily'), 'status' => 'Queued'));
    } else {
        wp_send_json_error(array('message' => __('Invalid status transition.', 'emaily')));
    }
}
add_action('wp_ajax_emaily_toggle_campaign_status', 'emaily_toggle_campaign_status');
