<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Register shortcode for emaily_form
function emaily_form_shortcode($atts) {
	$atts = shortcode_atts(array(
		'id' => 0,
	), $atts, 'emaily_form');

	$form_id = intval($atts['id']);
	if ($form_id === 0) {
		return '<p>' . esc_html__('Invalid form ID.', 'emaily') . '</p>';
	}

	$post = get_post($form_id);
	if (!$post || $post->post_type !== 'emaily_form') {
		return '<p>' . esc_html__('Form not found.', 'emaily') . '</p>';
	}

	$fields = get_post_meta($form_id, 'emaily_form_fields', true);
	$fields = is_array($fields) ? $fields : array();
	if (empty($fields) || !in_array('Email', $fields)) {
		return '<p>' . esc_html__('Form configuration error: Email field is required.', 'emaily') . '</p>';
	}

	$form_type = get_post_meta($form_id, 'emaily_form_type', true);
	$form_type = !empty($form_type) ? $form_type : 'Embedded form';

	ob_start();
	?>
	<div class="emaily-form-wrapper" data-form-type="<?php echo esc_attr($form_type); ?>">
		<?php if ($form_type === 'Popup box') : ?>
			<button type="button" class="emaily-popup-button button">
				<?php esc_html_e('Subscribe Now', 'emaily'); ?>
			</button>
			<div class="emaily-popup-overlay" style="display: none;">
				<div class="emaily-popup-content">
					<span class="emaily-popup-close">&times;</span>
					<h2><?php echo esc_html($post->post_title); ?></h2>
					<?php echo wp_kses_post($post->post_content); ?>
					<form class="emaily-form emaily-ajax-form" data-form-id="<?php echo esc_attr($form_id); ?>">
						<?php wp_nonce_field('emaily_form_submit', 'emaily_nonce'); ?>
						<input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
						<?php foreach ($fields as $field) : ?>
							<div class="emaily-form-group">
								<label for="emaily_<?php echo esc_attr(strtolower(str_replace(' ', '_', $field))); ?>">
									<?php echo esc_html($field); ?>
									<?php if ($field === 'Email' || $field === 'Name') : ?>
										<span class="required">*</span>
									<?php endif; ?>
								</label>
								<input type="<?php echo $field === 'Email' ? 'email' : 'text'; ?>"
								       name="emaily_<?php echo esc_attr(strtolower(str_replace(' ', '_', $field))); ?>"
								       id="emaily_<?php echo esc_attr(strtolower(str_replace(' ', '_', $field))); ?>"
									<?php if ($field === 'Email' || $field === 'Name') echo 'required'; ?>>
							</div>
						<?php endforeach; ?>
						<button type="submit" class="emaily-submit-button button">
							<?php esc_html_e('Subscribe', 'emaily'); ?>
						</button>
						<div class="emaily-form-message"></div>
					</form>
				</div>
			</div>
		<?php else : ?>
			<form class="emaily-form emaily-ajax-form" data-form-id="<?php echo esc_attr($form_id); ?>">
				<?php wp_nonce_field('emaily_form_submit', 'emaily_nonce'); ?>
				<input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
				<?php foreach ($fields as $field) : ?>
					<div class="emaily-form-group">
						<label for="emaily_<?php echo esc_attr(strtolower(str_replace(' ', '_', $field))); ?>">
							<?php echo esc_html($field); ?>
							<?php if ($field === 'Email' || $field === 'Name') : ?>
								<span class="required">*</span>
							<?php endif; ?>
						</label>
						<input type="<?php echo $field === 'Email' ? 'email' : 'text'; ?>"
						       name="emaily_<?php echo esc_attr(strtolower(str_replace(' ', '_', $field))); ?>"
						       id="emaily_<?php echo esc_attr(strtolower(str_replace(' ', '_', $field))); ?>"
							<?php if ($field === 'Email' || $field === 'Name') echo 'required'; ?>>
					</div>
				<?php endforeach; ?>
				<button type="submit" class="emaily-submit-button button">
					<?php esc_html_e('Subscribe', 'emaily'); ?>
				</button>
				<div class="emaily-form-message"></div>
			</form>
		<?php endif; ?>
	</div>

	<style>
        .emaily-form-wrapper {
            max-width: 500px;
            margin: 0 auto;
            padding: 20px;
        }
        .emaily-form-group {
            margin-bottom: 15px;
        }
        .emaily-form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .emaily-form-group label .required {
            color: #dc2626;
        }
        .emaily-form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
        }
        .emaily-submit-button {
            background: #2563eb;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .emaily-submit-button:hover {
            background: #1d4ed8;
        }
        .emaily-form-message {
            margin-top: 10px;
            padding: 10px;
            border-radius: 4px;
            display: none;
        }
        .emaily-form-message.success {
            background: #f0fff0;
            border: 1px solid #46b450;
            color: #15803d;
            display: block;
        }
        .emaily-form-message.error {
            background: #fff4f4;
            border: 1px solid #dc3232;
            color: #b91c1c;
            display: block;
        }
        .emaily-popup-button {
            background: #2563eb;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .emaily-popup-button:hover {
            background: #1d4ed8;
        }
        .emaily-popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .emaily-popup-content {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            max-width: 500px;
            width: 90%;
            position: relative;
        }
        .emaily-popup-close {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 24px;
            cursor: pointer;
            color: #374151;
        }
	</style>
	<?php
	return ob_get_clean();
}
add_shortcode('emaily_form', 'emaily_form_shortcode');

