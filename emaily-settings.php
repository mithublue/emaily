<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action('carbon_fields_register_fields', 'emaily_settings_fields');
function emaily_settings_fields() {
	Container::make('theme_options', __('Emaily Settings', 'emaily'))
	         ->set_page_parent('emaily')
	         ->add_tab(__('Appearance', 'emaily'), array(
		         Field::make('select', 'emaily_confirmation_page', __('Email Confirmation Page', 'emaily'))
		              ->set_options('emaily_get_pages')
		              ->set_help_text(__('Select the page where users will be redirected after email verification.', 'emaily')),
	         ))
	         ->add_tab(__('Messages', 'emaily'), array(
		         Field::make('text', 'emaily_form_submission_message', __('Form Submission Message', 'emaily'))
		              ->set_default_value(__('Please check your email to verify your subscription.', 'emaily'))
		              ->set_help_text(__('Message shown after a user submits the subscription form.', 'emaily')),
		         Field::make('text', 'emaily_confirmation_success_message', __('Email Confirmation Success Message', 'emaily'))
		              ->set_default_value(__('Your email has been verified! Thank you for subscribing.', 'emaily'))
		              ->set_help_text(__('Message shown when email verification is successful.', 'emaily')),
		         Field::make('text', 'emaily_confirmation_failed_message', __('Email Confirmation Failed Message', 'emaily'))
		              ->set_default_value(__('Email verification failed. Please try subscribing again.', 'emaily'))
		              ->set_help_text(__('Message shown when email verification fails.', 'emaily')),
	         ))
	         ->add_tab(__('reCAPTCHA', 'emaily'), array(
		         Field::make('checkbox', 'emaily_enable_recaptcha', __('Enable reCAPTCHA', 'emaily'))
		              ->set_default_value(false)
		              ->set_help_text(__('Enable Google reCAPTCHA v3 for form submissions.', 'emaily')),
		         Field::make('text', 'emaily_recaptcha_site_key', __('reCAPTCHA Site Key', 'emaily'))
		              ->set_help_text(__('Enter your Google reCAPTCHA v3 site key. Obtain it from https://www.google.com/recaptcha.', 'emaily')),
		         Field::make('text', 'emaily_recaptcha_secret_key', __('reCAPTCHA Secret Key', 'emaily'))
		              ->set_help_text(__('Enter your Google reCAPTCHA v3 secret key.', 'emaily')),
		         Field::make('checkbox', 'emaily_enable_honeypot', __('Enable Honeypot', 'emaily'))
		              ->set_default_value(true)
		              ->set_help_text(__('Enable honeypot field to trap bots.', 'emaily')),
	         ))
	         ->add_tab(__('Slack Integration', 'emaily'), array(
		         Field::make('text', 'emaily_slack_webhook_url', __('Slack Webhook URL', 'emaily'))
		              ->set_help_text(__('Enter the Slack webhook URL for sending notifications. Obtain it from your Slack workspace.', 'emaily')),
		         Field::make('checkbox', 'emaily_slack_notify_subscription', __('Notify on Subscription', 'emaily'))
		              ->set_default_value(true)
		              ->set_help_text(__('Send a Slack notification when a user subscribes.', 'emaily')),
		         Field::make('checkbox', 'emaily_slack_notify_email_open', __('Notify on Email Open', 'emaily'))
		              ->set_default_value(true)
		              ->set_help_text(__('Send a Slack notification when a campaign email is opened.', 'emaily')),
		         Field::make('checkbox', 'emaily_slack_notify_campaign_complete', __('Notify on Campaign Completion', 'emaily'))
		              ->set_default_value(true)
		              ->set_help_text(__('Send a Slack notification when a campaign finishes sending.', 'emaily')),
		         Field::make('checkbox', 'emaily_slack_notify_daily_summary', __('Notify Daily Campaign Summary', 'emaily'))
		              ->set_default_value(true)
		              ->set_help_text(__('Send a twice-daily summary of the last sent campaign’s stats.', 'emaily')),
	         ));
}

function emaily_get_pages() {
	$pages = get_pages();
	$options = array('' => __('Select a page', 'emaily'));
	foreach ($pages as $page) {
		$options[$page->ID] = $page->post_title;
	}
	return $options;
}

function emaily_settings_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e('Emaily Settings', 'emaily'); ?></h1>
		<?php \Carbon_Fields\Carbon_Fields::render(); ?>
	</div>
	<?php
}
