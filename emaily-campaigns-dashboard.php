<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Extend WP_List_Table for campaigns dashboard
if (!class_exists('WP_List_Table')) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Emaily_Campaigns_Table extends WP_List_Table {
	public function __construct() {
		parent::__construct(array(
			'singular' => __('Campaign', 'emaily'),
			'plural'   => __('Campaigns', 'emaily'),
			'ajax'     => false,
		));
	}

	public function get_columns() {
		return array(
			'title'           => __('Title', 'emaily'),
			'status'          => __('Status', 'emaily'),
			'total_emails'    => __('Total Emails', 'emaily'),
			'sent_emails'     => __('Sent Emails', 'emaily'),
			'queued_emails'   => __('Queued Emails', 'emaily'),
			'failed_emails'   => __('Failed Emails', 'emaily'),
			'opened_emails'   => __('Opened Emails', 'emaily'),
			'open_rate'       => __('Open Rate', 'emaily'),
			'scheduled_start' => __('Scheduled Start', 'emaily'),
		);
	}

	public function column_default($item, $column_name) {
		switch ($column_name) {
			case 'title':
				return sprintf('<a href="%s">%s</a>', esc_url(get_edit_post_link($item->ID)), esc_html($item->post_title));
			case 'status':
				$queue = get_post_meta($item->ID, 'emaily_campaign_email_queue', true);
				$all_sent = get_post_meta($item->ID, 'emaily_campaign_all_emails_sent', true);
				$start_time = get_post_meta($item->ID, 'emaily_campaign_start_time', true);
				$status = get_post_meta($item->ID, 'emaily_campaign_status', true);
				return esc_html($status === 'paused' ? 'Paused' : ($all_sent ? 'Completed' : (is_array($queue) && !empty($queue) && $start_time && strtotime($start_time) <= current_time('timestamp') ? 'Sending' : (is_array($queue) && !empty($queue) ? 'Queued' : 'Not Started'))));
			case 'total_emails':
				$queue = get_post_meta($item->ID, 'emaily_campaign_email_queue', true);
				$sent = get_post_meta($item->ID, 'emaily_campaign_sent_emails', true);
				$failed = get_post_meta($item->ID, 'emaily_campaign_failed_emails', true);
				$queued_count = is_array($queue) ? count($queue) : 0;
				$sent_count = is_array($sent) ? count($sent) : 0;
				$failed_count = is_array($failed) ? count($failed) : 0;
				return esc_html($queued_count + $sent_count + $failed_count);
			case 'sent_emails':
				$sent = get_post_meta($item->ID, 'emaily_campaign_sent_emails', true);
				return esc_html(is_array($sent) ? count($sent) : 0);
			case 'queued_emails':
				$queue = get_post_meta($item->ID, 'emaily_campaign_email_queue', true);
				return esc_html(is_array($queue) ? count($queue) : 0);
			case 'failed_emails':
				$failed = get_post_meta($item->ID, 'emaily_campaign_failed_emails', true);
				return esc_html(is_array($failed) ? count($failed) : 0);
			case 'opened_emails':
				$opened = get_post_meta($item->ID, 'emaily_campaign_opened_emails', true);
				return esc_html(is_array($opened) ? count($opened) : 0);
			case 'open_rate':
				$sent = get_post_meta($item->ID, 'emaily_campaign_sent_emails', true);
				$opened = get_post_meta($item->ID, 'emaily_campaign_opened_emails', true);
				$sent_count = is_array($sent) ? count($sent) : 0;
				$opened_count = is_array($opened) ? count($opened) : 0;
				return $sent_count > 0 ? esc_html(number_format(($opened_count / $sent_count) * 100, 2) . '%') : '0.00%';
			case 'scheduled_start':
				$start_time = get_post_meta($item->ID, 'emaily_campaign_start_time', true);
				return esc_html($start_time ? $start_time : 'N/A');
			default:
				return '';
		}
	}

	public function prepare_items() {
		$per_page = 20;
		$current_page = $this->get_pagenum();
		$args = array(
			'post_type'      => 'emaily_campaign',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $current_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$query = new WP_Query($args);
		$this->items = $query->posts;

		$this->set_pagination_args(array(
			'total_items' => $query->found_posts,
			'per_page'    => $per_page,
			'total_pages' => $query->max_num_pages,
		));

		$columns = $this->get_columns();
		$hidden = array();
		$sortable = array();
		$this->_column_headers = array($columns, $hidden, $sortable);
	}
}

// Render the campaigns dashboard page
function emaily_campaigns_dashboard_page() {
	$table = new Emaily_Campaigns_Table();
	$table->prepare_items();
	?>
	<div class="wrap">
		<h1><?php esc_html_e('Campaigns Dashboard', 'emaily'); ?></h1>
		<p><?php esc_html_e('View the status of all email campaigns.', 'emaily'); ?></p>
		<?php $table->display(); ?>
	</div>
	<?php
}

